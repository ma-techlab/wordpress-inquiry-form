#!/usr/bin/env bash
#
# 公開してはいけない語（応募先・取引先の会社名、作者の個人情報など）が、
# リポジトリに入っていないかを検査する。
#
# 禁止語のリスト：.git/info/forbidden-words（1行に1語。push されない場所）
#   - 空行と、# で始まる行は読み飛ばす。
#   - 大文字・小文字は区別しない。部分一致で探す。
#   - リストそのものに社名が入るため、リポジトリのファイルには置かない。
#
# 使い方：
#   bash bin/check-public.sh           追跡しているファイルと全履歴（git log --all -p）を検索する。push の前に実行する。
#   bash bin/check-public.sh --staged  commit に入る差分（git diff --cached の追加行）だけを検索する。pre-commit の hook 用。
#
# 終わり方：
#   0 = 見つからなかった
#   1 = 禁止語が見つかった
#   2 = リストが無い・空（検査していないので、通ったことにしない）。
#       ただし --staged では、commit を止めずに警告だけ出して 0 で終わる（push の前の検査で止まる）。

set -euo pipefail

MODE="all"
if [[ "${1:-}" == "--staged" ]]; then
	MODE="staged"
elif [[ $# -gt 0 ]]; then
	echo "使い方：bash bin/check-public.sh [--staged]" >&2
	exit 2
fi

ROOT_DIR="$(git rev-parse --show-toplevel)"
LIST="$(git rev-parse --git-path info/forbidden-words)"
cd "${ROOT_DIR}"

# リストを読む（Windows の改行 CR を取り除く）.
WORDS=()
if [[ -f "${LIST}" ]]; then
	while IFS= read -r line || [[ -n "${line}" ]]; do
		line="${line%$'\r'}"
		[[ -z "${line//[[:space:]]/}" || "${line}" == \#* ]] && continue
		WORDS+=("${line}")
	done < "${LIST}"
fi

if [[ ${#WORDS[@]} -eq 0 ]]; then
	echo "警告：禁止語のリスト（${LIST}）が無いか、空です。検査していません。" >&2
	echo "      1行に1語ずつ書いてから、もう一度実行してください。" >&2
	if [[ "${MODE}" == "staged" ]]; then
		exit 0
	fi
	exit 2
fi

found=0

if [[ "${MODE}" == "staged" ]]; then
	# 追加する行だけを見る（禁止語を消す commit は止めない）.
	ADDED="$(git diff --cached --no-color -U0 | grep -E '^\+' | grep -vE '^\+\+\+ ' || true)"
	NAMES="$(git diff --cached --name-only)"
	for word in "${WORDS[@]}"; do
		if grep -F -i -q -e "${word}" <<< "${ADDED}${NAMES:+$'\n'}${NAMES}"; then
			echo "commit に入る差分に、禁止語が含まれています（リストの「${word:0:1}…」の語）：" >&2
			grep -F -i -n -e "${word}" <<< "${ADDED}" >&2 || true
			grep -F -i -e "${word}" <<< "${NAMES}" | sed 's/^/  ファイル名：/' >&2 || true
			found=1
		fi
	done
	if [[ ${found} -ne 0 ]]; then
		echo "commit を止めました。該当の行を直してから、もう一度 commit してください。" >&2
		exit 1
	fi
	exit 0
fi

for word in "${WORDS[@]}"; do
	label="リストの「${word:0:1}…」の語"

	# 追跡しているファイル（作業ツリーの中身）とファイル名.
	if git grep -I -n -F -i -e "${word}" > /dev/null 2>&1; then
		echo "追跡しているファイルに見つかりました（${label}）：" >&2
		git grep -I -n -F -i -e "${word}" >&2 || true
		found=1
	fi
	if git ls-files | grep -F -i -q -e "${word}"; then
		echo "追跡しているファイルの名前に見つかりました（${label}）：" >&2
		git ls-files | grep -F -i -e "${word}" >&2 || true
		found=1
	fi

	# 全履歴：差分・commit メッセージ・作者と commit した人の名前.
	count="$(git log --all -p --no-color --format=fuller | grep -F -i -c -e "${word}" || true)"
	if [[ "${count}" -gt 0 ]]; then
		echo "履歴（git log --all -p）に ${count} 件見つかりました（${label}）。次の commit に含まれます：" >&2
		for c in $(git rev-list --all); do
			if git show --no-color --format=fuller "${c}" | grep -F -i -q -e "${word}"; then
				git log -1 --format='  %h %s' "${c}" >&2
			fi
		done
		found=1
	fi
done

if [[ ${found} -ne 0 ]]; then
	echo "禁止語が見つかりました。公開（push）しないでください。" >&2
	exit 1
fi

echo "禁止語は見つかりませんでした（${#WORDS[@]}語。追跡しているファイルと全履歴）。"
exit 0
