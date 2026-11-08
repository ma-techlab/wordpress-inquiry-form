#!/usr/bin/env bash
#
# LocalWP のサイトに対して、確認表（docs/TEST-CHECKLIST.md）の一部を curl で自動確認する。
#
# 確かめること：
#   5-4 送信回数の制限（5回目まで受け付け、6回目は止まる。入力エラーは数えない）
#   5-5 nonce と表示時刻の改ざん
#   7   CSV（インジェクション対策の ' の付与、BOM、見出し、件数、絞り込み）
#   8   権限（編集者・ログアウト・nonce なしでは、管理画面と CSV を開けない）
#   9   XSS とメールのヘッダーインジェクション（画面と CSV。Mailpit は目で見る）
#
# 注意：
#   - **ローカルの確認用です。本番のサイトには使わないでください。**
#     実行すると、架空のデータ（example.com、「（例）」付きの会社名）の問い合わせが数件増え、
#     そのうち1件の状態が「対応中」になり、管理メモが書き換わります。通知メールも送られます。
#   - 最初に10分待ちます。送信回数の制限（10分間に5回まで）を0回から数えるためです。
#     直前の10分に、このサイトへフォームを送っていないときだけ --no-wait で待たずに始められます。
#   - 管理者のパスワードと、編集者 editor-test のパスワードを聞きます（画面に表示しません）。
#     パスワードはコマンドの引数に載せず、権限 700 の一時フォルダのファイル経由で渡し、
#     ログインの直後に消します。一時フォルダ（cookie を含む）は、終了時・中断時に消します。
#   - 結果は画面に出すだけで、ファイルには残しません。
#   - 必要なもの：bash、curl、grep（-P が使えるもの）、python3。
#
# 使い方：
#   bash bin/check-localwp.sh             # 10分待ってから始める
#   bash bin/check-localwp.sh --no-wait   # 待たずに始める
#   BASE=http://example.local bash bin/check-localwp.sh   # サイトの URL を変える
#   FORM_URL=http://example.local/contact/ bash bin/check-localwp.sh   # フォームのページを変える
#
# 終わり方：すべて OK なら 0、NG が1件でもあれば 1、接続やログインができなければ 2。

set -uo pipefail

if [[ $# -gt 1 || ( $# -eq 1 && "$1" != "--no-wait" ) ]]; then
	echo "使い方：bash bin/check-localwp.sh [--no-wait]" >&2
	exit 2
fi
NO_WAIT="${1:-}"

BASE="${BASE:-http://sample-seiko.local}"
# 既定は、固定ページ「お問い合わせ」（スラッグが日本語のまま）の URL.
FORM_URL="${FORM_URL:-$BASE/%e3%81%8a%e5%95%8f%e3%81%84%e5%90%88%e3%82%8f%e3%81%9b/}"
LIST_URL="$BASE/wp-admin/admin.php?page=mvim-inquiries"

WORK="$(mktemp -d)"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
trap 'exit 130' INT TERM

PASS=0
FAIL=0
RESULTS=()
ok() {
	PASS=$((PASS + 1))
	RESULTS+=("OK   $1")
	echo "OK   $1"
}
ng() {
	FAIL=$((FAIL + 1))
	RESULTS+=("NG   $1  ← $2")
	echo "NG   $1  ← $2"
}
# $1=確認の名前 $2=条件（eval で評価する）。
# サーバーから受け取った値は、条件の文字列に埋め込まず、"\$変数" の形で eval の中で展開させる
# （値に ' などが入っても、条件が壊れたり、命令として動いたりしないため）.
check() {
	if eval "$2"; then ok "$1"; else ng "$1" "${3:-条件を満たさない}"; fi
}

# ---------- 接続の方法を決める ----------
# WSL の中から LocalWP（Windows 側）のサイトに届かないことがある（*.local の名前が WSL では引けない、など）。
# そのときは、① Windows の IP アドレスへ向ける ② Windows の curl.exe を使う、の順に試す。
# WSL を使う人向けの工夫で、Linux や macOS では ① も ② も使わずに、そのまま届く.
HOST="${BASE#http://}"
HOST="${HOST%%/*}"
MODE="wsl"
RESOLVE_IP=""
reach() {
	local c null=/dev/null
	[[ "$1" == curl.exe ]] && null=NUL
	c="$("$@" -s -m 5 -o "$null" -w '%{http_code}' "$BASE/" 2> /dev/null)"
	echo "  試した結果（$1）：HTTP ${c:-000}"
	[[ "$c" =~ ^(200|301|302)$ ]]
}
echo "サイトへの接続を確かめます（$BASE）。"
if ! reach command curl; then
	WIN_IP="$(ip route show default 2> /dev/null | awk '{print $3; exit}')"
	if [[ -n "$WIN_IP" ]] && reach command curl --resolve "$HOST:80:$WIN_IP"; then
		MODE="resolve"
		RESOLVE_IP="$WIN_IP"
	elif command -v curl.exe > /dev/null && reach curl.exe; then
		MODE="win"
	else
		echo "サイトに接続できません（$BASE）。WSL からも Windows の curl.exe からも届きませんでした。" >&2
		exit 2
	fi
fi
echo "  接続の方法：$MODE"

# このスクリプトの中の curl は、すべてこの関数を通す.
DN=0
winpath() {
	if [[ "$1" == /dev/null ]]; then echo NUL; else wslpath -w "$1"; fi
}
curl() {
	case "$MODE" in
		wsl) command curl "$@" ;;
		resolve) command curl --resolve "$HOST:80:$RESOLVE_IP" "$@" ;;
		win)
			# curl.exe には Windows のパスを渡す。日本語の値は、文字コードの変換を避けるため、
			# いったんファイルに書いて「名前@ファイル」の形で渡す.
			local out=() a next=""
			for a in "$@"; do
				if [[ -n "$next" ]]; then
					if [[ "$next" == data ]]; then
						if [[ "$a" =~ ^[A-Za-z_]+@/ ]]; then
							out+=("${a%%@*}@$(winpath "${a#*@}")")
						else
							DN=$((DN + 1))
							printf '%s' "${a#*=}" > "$WORK/d$DN"
							out+=("${a%%=*}@$(winpath "$WORK/d$DN")")
						fi
					elif [[ "$a" == /* ]]; then
						out+=("$(winpath "$a")")
					else
						out+=("$a")
					fi
					next=""
					continue
				fi
				case "$a" in
					-o | -c | -b | -D)
						out+=("$a")
						next="path"
						;;
					--data-urlencode)
						out+=("$a")
						next="data"
						;;
					*) out+=("$a") ;;
				esac
			done
			curl.exe "${out[@]}"
			;;
	esac
}

# ---------- ログイン ----------
read -r -p "管理者のユーザー名: " ADMIN_USER
read -r -s -p "管理者のパスワード: " ADMIN_PASS
echo
read -r -s -p "編集者 editor-test のパスワード: " EDITOR_PASS
echo

login() { # $1=cookie のファイル $2=ユーザー名 $3=パスワード
	local pw="$WORK/pw"
	# パスワードは引数（ps で見える）に載せず、ファイル経由で渡す.
	(umask 077 && printf '%s' "$3" > "$pw")
	curl -s -m 20 -c "$1" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
		--data-urlencode "log=$2" --data-urlencode "pwd@$pw" \
		--data-urlencode "testcookie=1" \
		--data-urlencode "redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
	rm -f "$pw"
	grep -q 'wordpress_logged_in' "$1"
}
ADMIN_JAR="$WORK/admin.jar"
EDITOR_JAR="$WORK/editor.jar"
if ! login "$ADMIN_JAR" "$ADMIN_USER" "$ADMIN_PASS"; then
	unset ADMIN_PASS EDITOR_PASS
	echo "管理者でログインできませんでした。" >&2
	exit 2
fi
if ! login "$EDITOR_JAR" "editor-test" "$EDITOR_PASS"; then
	unset ADMIN_PASS EDITOR_PASS
	echo "編集者でログインできませんでした。" >&2
	exit 2
fi
unset ADMIN_PASS EDITOR_PASS
echo "ログインできました。"

if [[ "$NO_WAIT" != "--no-wait" ]]; then
	echo "送信回数の記録が消えるまで10分待ちます（5-4 を0回から数えるため）。このまま置いておいてください。"
	for i in $(seq 10 -1 1); do
		echo "  あと ${i} 分"
		sleep 60
	done
fi

# ---------- フォームの送信 ----------
hidden() { # $1=ファイル $2=name。隠し項目の値を取り出す
	grep -oP "name=\"$2\" value=\"\K[^\"]*" "$1" | head -1
}
# $1=名前 $2=表示から送信までの秒数 その後に「項目名=値」を並べる。
# 結果：$WORK/名前.html に応答の本文、$WORK/名前.meta に「HTTP コード 転送先の URL」.
submit() {
	local name="$1" wait="$2"
	shift 2
	local page="$WORK/$name.form.html"
	curl -s -m 20 -o "$page" "$FORM_URL"
	local ts sig nonce
	ts="$(hidden "$page" mvim_ts)"
	sig="$(hidden "$page" mvim_ts_sig)"
	nonce="$(hidden "$page" mvim_nonce)"
	sleep "$wait"
	local args=(--data-urlencode "mvim_action=submit_inquiry" --data-urlencode "mvim_hp_x7=")
	local kv override_ts="" override_nonce=""
	for kv in "$@"; do
		case "$kv" in
			mvim_ts=*) override_ts="${kv#mvim_ts=}" ;;
			mvim_nonce=*) override_nonce="${kv#mvim_nonce=}" ;;
			*) args+=(--data-urlencode "$kv") ;;
		esac
	done
	args+=(--data-urlencode "mvim_ts=${override_ts:-$ts}" --data-urlencode "mvim_ts_sig=$sig" --data-urlencode "mvim_nonce=${override_nonce:-$nonce}")
	curl -s -m 30 -o "$WORK/$name.html" -w '%{http_code} %{redirect_url}' "${args[@]}" "$FORM_URL" > "$WORK/$name.meta"
}
accepted() { grep -q '^303 .*mvim_sent=1' "$WORK/$1.meta"; }
body_has() { grep -qF -- "$2" "$WORK/$1.html"; }

BASIC=(mvim_inquiry_type=estimate "mvim_contact_name=山田 太郎" mvim_email=taro@example.com "mvim_message=自動確認" mvim_consent=1)

echo
echo "== 7-1・9 のデータを送信（この送信も 5-4 の回数に数える）=="
submit s1 4 mvim_inquiry_type=other "mvim_company_name==1+1" "mvim_contact_name=@SUM(1+1)" mvim_email=csv@example.com "mvim_phone=+81-3-1234-5678" "mvim_message=-2+3
2行目" mvim_consent=1
check "7-1 CSV対策用のデータが受け付けられる（1回目）" "accepted s1"

submit s2 4 mvim_inquiry_type=question "mvim_company_name=<script>alert('xss1')</script>（例）スクリプト株式会社" "mvim_contact_name=<img src=x onerror=alert('xss2')>試験 三郎" mvim_email=xss@example.com "mvim_message=<b>太字</b> <script>alert('xss3')</script>本文です。" mvim_consent=1
check "9-1 タグを含む入力が受け付けられる（2回目）" "accepted s2"

submit s3e 4 mvim_inquiry_type=question "mvim_company_name=\" autofocus onfocus=\"alert('xss4')" "mvim_contact_name=' onmouseover='alert(5)' x='" mvim_email=xss2@example.com "mvim_message=\" onclick=\"alert(6)"
check "9-2 同意なしで入力エラーになり、保存されない（回数に数えない）" "! accepted s3e && body_has s3e '同意にチェック'"
check "9-2 再表示の入力欄で、会社名の \" がエスケープされる" "body_has s3e 'value=\"&quot; autofocus onfocus=&quot;alert(&#039;xss4&#039;)\"' && ! body_has s3e 'onfocus=\"alert'"
check "9-2 再表示の入力欄で、担当者名の ' がエスケープされる" "body_has s3e '&#039; onmouseover=&#039;alert(5)&#039; x=&#039;' && ! body_has s3e \"onmouseover='alert\""
check "9-2 再表示の内容の欄で、\" がエスケープされる" "body_has s3e '&quot; onclick=&quot;alert(6)' && ! body_has s3e 'onclick=\"alert'"

submit s3 4 mvim_inquiry_type=question "mvim_company_name=\" autofocus onfocus=\"alert('xss4')" "mvim_contact_name=' onmouseover='alert(5)' x='" mvim_email=xss2@example.com "mvim_message=\" onclick=\"alert(6)" mvim_consent=1
check "9-2 同意して送ると受け付けられる（3回目）" "accepted s3"

submit s4 4 mvim_inquiry_type=estimate "mvim_company_name=（例）ヘッダー確認株式会社" "mvim_contact_name=山田
Bcc: victim@example.com" mvim_email=taro@example.com mvim_message=ヘッダー確認 mvim_consent=1
check "9-3 改行を含む担当者名でも受け付けられる（4回目）" "accepted s4"

echo
echo "== 5-4 送信回数の制限 =="
submit s5 4 "${BASIC[@]}" "mvim_company_name=（例）回数テスト5"
check "5-4 5回目は受け付けられる" "accepted s5"
submit s6 4 "${BASIC[@]}" "mvim_company_name=（例）回数テスト6"
check "5-4 6回目は止まり、保存されない" "! accepted s6 && body_has s6 '短い時間に送信が続いたため'"
check "5-4 6回目の入力値が残る" "body_has s6 '（例）回数テスト6'"

echo
echo "== 5-5 nonce と署名の改ざん（回数の検査より前に止まる）=="
submit t1 4 "${BASIC[@]}" "mvim_company_name=（例）改ざんテスト1" mvim_nonce=invalid
check "5-5 nonce を書き換えると止まる" "! accepted t1 && body_has t1 'ページの有効期限が切れたため'"
check "5-5 nonce の改ざん後も入力値が残る" "body_has t1 '（例）改ざんテスト1'"
submit t2 4 "${BASIC[@]}" "mvim_company_name=（例）改ざんテスト2" mvim_ts=1000000000
check "5-5 表示時刻を書き換えると止まる" "! accepted t2 && body_has t2 'フォームの情報を確認できませんでした'"

echo
echo "== 管理画面（管理者）=="
curl -s -m 20 -b "$ADMIN_JAR" -o "$WORK/list.html" "$LIST_URL"
check "管理者は一覧を開ける" "grep -q 'mvim-inquiries' \"\$WORK/list.html\" && grep -qF 'CSVを書き出す' \"\$WORK/list.html\""
ID_CSV="$(grep -oP 'action=view&(?:amp;|#038;)id=\K[0-9]+(?=[^>]*>=1\+1<)' "$WORK/list.html" | head -1)"
ID_XSS1="$(grep -oP 'action=view&(?:amp;|#038;)id=\K[0-9]+(?=[^>]*>（例）スクリプト株式会社)' "$WORK/list.html" | head -1)"
ID_XSS2="$(grep -oP 'action=view&(?:amp;|#038;)id=\K[0-9]+(?=[^>]*>&quot; autofocus)' "$WORK/list.html" | head -1)"
ID_HDR="$(grep -oP 'action=view&(?:amp;|#038;)id=\K[0-9]+(?=[^>]*>（例）ヘッダー確認株式会社)' "$WORK/list.html" | head -1)"
echo "  見つけた ID：CSV=$ID_CSV スクリプト=$ID_XSS1 属性=$ID_XSS2 ヘッダー=$ID_HDR"
check "回数テスト6・改ざんテストは保存されていない" "! grep -qF '回数テスト6' \"\$WORK/list.html\" && ! grep -qF '改ざんテスト' \"\$WORK/list.html\""
check "7-1 一覧の会社名が =1+1 の文字のまま" "[[ -n \"\$ID_CSV\" ]]"
check "9-1 会社名のタグが取り除かれて保存されている" "[[ -n \"\$ID_XSS1\" ]] && ! grep -qF 'alert(&#039;xss1' \"\$WORK/list.html\""
check "9-2 一覧で \" がエスケープされている" "[[ -n \"\$ID_XSS2\" ]] && ! grep -qF 'onfocus=\"alert' \"\$WORK/list.html\""
UNSAFE_RE='<script>alert|onerror=alert|onfocus="alert|onmouseover='\''alert|onclick="alert'
check "一覧に、エスケープされていない危険な文字列が無い" "! grep -qE \"\$UNSAFE_RE\" \"\$WORK/list.html\""

detail() { # $1=ID。詳細画面を $WORK/detail-ID.html に保存する
	curl -s -m 20 -b "$ADMIN_JAR" -o "$WORK/detail-$1.html" "$BASE/wp-admin/admin.php?page=mvim-inquiries&action=view&id=$1"
}
update() { # $1=ID $2=状態 $3=管理メモ。HTTP コードを出力する
	local nonce
	nonce="$(grep -oP 'name="mvim_update_nonce" value="\K[^"]+' "$WORK/detail-$1.html" | head -1)"
	curl -s -m 20 -b "$ADMIN_JAR" -o /dev/null -w '%{http_code}' \
		--data-urlencode "mvim_admin_action=update_inquiry" --data-urlencode "id=$1" \
		--data-urlencode "mvim_update_nonce=$nonce" --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=mvim-inquiries&action=view&id=$1" \
		--data-urlencode "mvim_status=$2" --data-urlencode "mvim_staff_note=$3" \
		"$BASE/wp-admin/admin.php?page=mvim-inquiries&action=view&id=$1"
}
# 詳細画面に、エスケープされていない危険な文字列や、効いてしまったタグが無いこと.
safe_detail() { ! grep -qE "$UNSAFE_RE|<b>太字" "$1"; }

if [[ -n "$ID_XSS1" ]]; then
	detail "$ID_XSS1"
	F="$WORK/detail-$ID_XSS1.html"
	check "9-1 詳細：タグが無く、文字だけが残る" "grep -qF '試験 三郎' \"\$F\" && grep -qF '本文です。' \"\$F\" && safe_detail \"\$F\""
fi
if [[ -n "$ID_XSS2" ]]; then
	detail "$ID_XSS2"
	code="$(update "$ID_XSS2" new "\" autofocus onfocus=\"alert(7)")"
	detail "$ID_XSS2"
	F="$WORK/detail-$ID_XSS2.html"
	check "9-2 管理メモを更新できる" "[[ \"\$code\" == 303 || \"\$code\" == 302 ]]"
	check "9-2 詳細と管理メモの欄で、\" がエスケープされる" "grep -qF '&quot; autofocus onfocus=&quot;alert(7)' \"\$F\" && safe_detail \"\$F\""
fi
if [[ -n "$ID_HDR" ]]; then
	detail "$ID_HDR"
	# shellcheck disable=SC2034 # check() の eval の中で使う.
	F="$WORK/detail-$ID_HDR.html"
	check "9-3 担当者名の改行が取り除かれ、1行で保存される" "grep -qF '山田 Bcc: victim@example.com' \"\$F\""
fi
if [[ -n "$ID_CSV" ]]; then
	detail "$ID_CSV"
	# shellcheck disable=SC2034 # check() の eval の中で使う.
	code="$(update "$ID_CSV" in_progress '=HYPERLINK("https://example.com","クリック")')"
	check "7-1 管理メモを入れて、状態を「対応中」にできる" "[[ \"\$code\" == 303 || \"\$code\" == 302 ]]"
fi

echo
echo "== 7 CSV =="
# CSV の行数を数える。$2 を付けると、すべての行の状態（9列目）が $2 かも調べ、「件数 True/False」を出す.
csv_count() {
	python3 -I -c '
import csv, sys
rows = list(csv.reader(open(sys.argv[1], encoding="utf-8-sig", newline="")))
if not rows or not rows[0] or rows[0][0] != "ID":
    print("CSVではない")  # エラーの画面などを、0件の CSV と取り違えないため.
    sys.exit()
rows = rows[1:]
if len(sys.argv) > 2:
    print(len(rows), all(len(r) > 8 and r[8] == sys.argv[2] for r in rows))
else:
    print(len(rows))
' "$@" 2> /dev/null
}
curl -s -m 20 -b "$ADMIN_JAR" -o "$WORK/list.html" "$LIST_URL"
CSV_PATH="$(grep -oP 'href="\K[^"]*action=mvim_export_csv[^"]*' "$WORK/list.html" | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g')"
check "CSV の URL が一覧にある（nonce 付き）" "[[ \"\$CSV_PATH\" == *_wpnonce=* ]]"
curl -s -m 20 -b "$ADMIN_JAR" -D "$WORK/csv.h" -o "$WORK/all.csv" "$CSV_PATH"
# shellcheck disable=SC2034 # check() の eval の中で使う.
TODAY="$(TZ=Asia/Tokyo date +%Y%m%d)"
check "7-2 ファイル名が inquiries-今日の日付.csv" "grep -qi \"filename=\\\"inquiries-\$TODAY.csv\\\"\" \"\$WORK/csv.h\""
check "7-2 先頭に BOM（Excel で文字化けしない）" "[[ \"\$(head -c 3 \"\$WORK/all.csv\" | od -An -tx1 | tr -d ' ')\" == efbbbf ]]"
check "7-2 見出しの行" "head -1 \"\$WORK/all.csv\" | grep -qF 'ID,受付日時,種別,会社名,担当者名,メール,電話,内容,状態,管理メモ'"
check "7-2 =1+1 の先頭に ' が付く" "grep -qF \"'=1+1\" \"\$WORK/all.csv\""
check "7-2 @SUM の先頭に ' が付く" "grep -qF \"'@SUM(1+1)\" \"\$WORK/all.csv\""
check "7-2 電話 +81 の先頭に ' が付く" "grep -qF \"'+81-3-1234-5678\" \"\$WORK/all.csv\""
check "7-2 内容 -2+3 の先頭に ' が付く" "grep -qF \"'-2+3\" \"\$WORK/all.csv\""
check "7-2 管理メモ =HYPERLINK の先頭に ' が付く" "grep -qF \"'=HYPERLINK\" \"\$WORK/all.csv\""
check "7-2 種別・状態が日本語" "grep -qF '対応中' \"\$WORK/all.csv\" && grep -qF 'その他' \"\$WORK/all.csv\""
check "9 CSV にタグが無い" "! grep -qE '<script|<img|<b>' \"\$WORK/all.csv\""
N_ALL="$(grep -oP 'すべて <span class="count">\(\K[0-9]+' "$WORK/list.html" | head -1)"
N_CSV="$(csv_count "$WORK/all.csv")"
check "7-2 CSV の件数が一覧の「すべて」と同じ（一覧 $N_ALL・CSV $N_CSV）" "[[ -n \"\$N_ALL\" && \"\$N_ALL\" == \"\$N_CSV\" ]]"
N_INP="$(grep -oP '対応中 <span class="count">\(\K[0-9]+' "$WORK/list.html" | head -1)"
# 注意：bash 5.2 以降は ${変数/パターン/置換} の置換文字列の & が「一致した文字列」になるため、
# URL を組み立てるときは置換を使わず、文字列をつなげる.
curl -s -m 20 -b "$ADMIN_JAR" -o "$WORK/inp.csv" "${CSV_PATH}&status=in_progress"
N_INP_CSV="$(csv_count "$WORK/inp.csv" 対応中)"
check "7-3 「対応中」で絞り込んだ CSV（一覧 $N_INP・CSV $N_INP_CSV）" "[[ -n \"\$N_INP\" && \"\$N_INP_CSV\" == \"\$N_INP True\" ]]"

echo
echo "== 8 権限 =="
ED_LIST="$(curl -s -m 20 -b "$EDITOR_JAR" -o "$WORK/ed-list.html" -w '%{http_code}' "$LIST_URL")"
check "8-2a 編集者は一覧を開けない（HTTP $ED_LIST）" "! grep -qF 'CSVを書き出す' \"\$WORK/ed-list.html\" && [[ \"\$ED_LIST\" == 403 || \"\$ED_LIST\" == 500 ]]"
ED_DET="$(curl -s -m 20 -b "$EDITOR_JAR" -o "$WORK/ed-det.html" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=mvim-inquiries&action=view&id=${ID_CSV:-1}")"
check "8-2b 編集者は詳細を開けない（HTTP $ED_DET）" "! grep -qF '対応状況の更新' \"\$WORK/ed-det.html\" && [[ \"\$ED_DET\" == 403 || \"\$ED_DET\" == 500 ]]"
ED_SET="$(curl -s -m 20 -b "$EDITOR_JAR" -o "$WORK/ed-set.html" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=mvim-settings")"
check "8-2c 編集者は設定を開けない（HTTP $ED_SET）" "! grep -qF '通知先メールアドレス' \"\$WORK/ed-set.html\" && [[ \"\$ED_SET\" == 403 || \"\$ED_SET\" == 500 ]]"
curl -s -m 20 -b "$EDITOR_JAR" -o "$WORK/ed-dash.html" "$BASE/wp-admin/"
check "8-2 編集者の管理画面のメニューに「見積依頼」が無い" "grep -qF 'wp-admin-bar' \"\$WORK/ed-dash.html\" && ! grep -qF 'page=mvim-inquiries' \"\$WORK/ed-dash.html\""
# shellcheck disable=SC2034 # check() の eval の中で使う.
ED_CSV_CT="$(curl -s -m 20 -b "$EDITOR_JAR" -o "$WORK/ed-csv.out" -w '%{content_type}' "$CSV_PATH")"
check "8-2d 編集者は、管理者の nonce 付き CSV の URL でもダウンロードできない" "[[ \"\$ED_CSV_CT\" != text/csv* ]] && grep -qF 'CSVを出力する権限がありません' \"\$WORK/ed-csv.out\""
# shellcheck disable=SC2034 # check() の eval の中で使う.
OUT_CSV_CT="$(curl -s -m 20 -o "$WORK/out-csv.out" -w '%{content_type}' "$CSV_PATH")"
check "8-3 ログアウトの状態では CSV がダウンロードされない" "[[ \"\$OUT_CSV_CT\" != text/csv* ]] && ! grep -qF '会社名' \"\$WORK/out-csv.out\""
# shellcheck disable=SC2034 # check() の eval の中で使う.
NONONCE_CT="$(curl -s -m 20 -b "$ADMIN_JAR" -o "$WORK/nononce.out" -w '%{content_type}' "$BASE/wp-admin/admin-post.php?action=mvim_export_csv")"
check "8-4 管理者でも nonce が無い CSV の URL では出ない" "[[ \"\$NONONCE_CT\" != text/csv* ]] && ! grep -qF '会社名' \"\$WORK/nononce.out\""

echo
echo "================ 結果 ================"
printf '%s\n' "${RESULTS[@]}"
echo "OK $PASS 件 / NG $FAIL 件"
echo "目で見る必要が残るもの：表計算ソフトでの CSV の見た目（7-2）、Mailpit のメール（9-1・9-3 の件名・ヘッダー）"

[[ $FAIL -eq 0 ]]
