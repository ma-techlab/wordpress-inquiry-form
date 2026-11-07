# MERVELTA Inquiry Manager

WordPress 用の、見積依頼・問い合わせの受付と管理のプラグイン（最小版）です。

リポジトリ名（`wordpress-inquiry-form`）は内容が伝わる名前に、プラグインのスラッグ（`mervelta-inquiry-manager`）は他のプラグインとぶつからない名前にしています。

> **個人開発のデモです。** ポートフォリオ用に作ったもので、顧客に納品したものではありません。画面やデータはすべて架空のもの（例：「（例）株式会社サンプル精工」、`example.com` のメールアドレス）です。

## ブラウザで試す（WordPress Playground）

**[WordPress Playground で開く](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ma-techlab/wordpress-inquiry-form/main/blueprint.json)**

リンクを押すと、インストールせずに、ブラウザの中の WordPress でこのプラグインを試せます。設定は [`blueprint.json`](blueprint.json) にあります。初回に「had a hiccup」と出て止まったときは、「Try again」を押してください。

2026-11-08 に、次のとおり動くことを確かめました。

- PHP 8.3、最新の WordPress（日本語）、タイムゾーンは東京。
- 管理者でログインした状態で、「見積依頼」の一覧が開きます。架空のデータが4件（未対応2・対応中1・完了1）入っていて、受付日時は日本時間で出ます。
- 固定ページ「お問い合わせ」（`/contact/`）に `[mvim_inquiry_form]` を置いてあり、そこから送信できます。送信すると一覧に増えます。
- **メール**：Playground では送信が成功扱いになり、送信した問い合わせに「（通知失敗）」は付きません。一覧の「（例）かわせ精密株式会社」に付いている「（通知失敗）」は、表示を見せるために入れた架空のデータです。
- Playground の中のデータは、ブラウザのタブを閉じると消えます。

## 目的

製造業・建設業などの小規模事業者の WordPress サイトで、見積依頼と問い合わせを受け付け、取りこぼさずに対応状況を管理できるようにします。

ポートフォリオとしては、WordPress・PHP・DB での機能実装と、安全への配慮（権限の確認、nonce、入力の検証と出力のエスケープ、SQL の prepare、CSV インジェクション対策、不正送信対策）を説明できる作品を目指しています。

## 機能

| 機能 | 内容 |
|---|---|
| 入力フォーム | ショートコード `[mvim_inquiry_form]` を任意のページに置く（Elementor のショートコードウィジェットでも使える）。項目は種別・会社名・担当者名・メールアドレス・電話番号（任意）・内容・個人情報の取扱いへの同意 |
| 入力エラーの表示 | どの項目が誤りかを日本語で表示し、入力した値を残す。値は一時保存せず、同じリクエストの中で再表示する |
| 保存 | 専用テーブル `{接頭辞}mvim_inquiries` に保存。成功したときだけ元のページへリダイレクト（二重送信を防ぐ） |
| 通知メール | 管理者へ `wp_mail()` で通知。件名に種別と会社名、本文に全項目と管理画面へのリンク。Reply-To に訪問者のメールアドレス。送信に失敗しても保存は残し、一覧に「通知失敗」と表示 |
| 不正送信対策 | nonce、ハニーポット、フォーム表示から3秒未満の送信の拒否、同じ送信元から10分間に5回まで |
| 管理画面 | メニュー「見積依頼」。一覧（`WP_List_Table`、状態での絞り込みと件数、20件ずつ）、詳細、状態と管理メモの更新 |
| CSV 出力 | 一覧の絞り込み条件のまま書き出す。UTF-8（BOM 付き）、`inquiries-YYYYMMDD.csv` |
| 設定 | 通知先メールアドレス（未設定ならサイトの管理者メールアドレス） |
| 削除時の後片付け | プラグインを削除すると、テーブル・オプション・送信回数の記録を消す。無効化ではデータを残す |

## 動作環境

| 項目 | 条件 |
|---|---|
| WordPress | 6.5 以上 |
| PHP | 8.3 以上 |
| サイト | シングルサイト（マルチサイトは対象外） |

## 導入方法

1. 配布用 ZIP を作る（WSL などの Linux 環境で、`zip` と `unzip` が必要）。

   ```bash
   bash bin/build-zip.sh
   # → dist/mervelta-inquiry-manager.zip
   ```

2. WordPress の管理画面「プラグイン」→「新規追加」→「プラグインのアップロード」で ZIP を選び、インストールして有効化する。有効化のときにテーブルができます。
3. 「見積依頼」→「設定」で、通知先メールアドレスを設定する（任意）。
4. 固定ページなどに `[mvim_inquiry_form]` を置く。Elementor では「ショートコード」ウィジェットに同じ文字列を入れる。

## セキュリティ上の配慮

| 対象 | していること |
|---|---|
| 権限 | 管理画面（一覧・詳細）・更新・CSV 出力のすべてで `current_user_can( 'manage_options' )` を確認する。nonce だけで守らない |
| nonce | フォーム送信、状態の更新（対象の ID を含むアクション名）、CSV 出力（専用のアクション名）で検証する |
| 入力 | `sanitize_text_field()` / `sanitize_textarea_field()` / `sanitize_email()` / `sanitize_key()` で無害化し、必須・文字数・形式・選択値（種別・状態）を検証する |
| SQL | 値を含む問い合わせはすべて `$wpdb->prepare()`（テーブル名は `%i`、`LIMIT`・`OFFSET` も `%d`）。保存・更新は `$wpdb->insert()` / `update()` に書式を渡す |
| 出力 | `esc_html()` / `esc_attr()` / `esc_url()` / `esc_textarea()` で、出力するときにエスケープする |
| メール | 件名・ヘッダー・1行の項目から改行と制御文字を除く（ヘッダーインジェクション対策）。Reply-To は検証済みのアドレスだけで、名前は付けない |
| CSV | `=` `+` `-` `@` タブ・CR・LF で始まる値の先頭に `'` を付ける（CSV インジェクション対策） |
| リダイレクト | `wp_safe_redirect()` と `wp_validate_redirect()` で自サイト内に限る。URL には「成功した」という目印だけを付け、入力値は載せない |
| 不正送信 | フォームの表示時刻はサイト固有の鍵（`wp_salt()`）の HMAC で署名する。送信回数は IP アドレスを鍵付きでハッシュ化した値で数え、IP アドレスそのものは保存しない。`REMOTE_ADDR` だけを使う（`X-Forwarded-For` は偽装できるため使わない） |
| 個人情報 | IP アドレスを保存しない。入力エラーのときも入力値を一時保存しない。メール送信の失敗をログに書かない |
| 直接アクセス | 各 PHP ファイルの先頭で `ABSPATH` を確認する |

コードのどこで何をしているかは [`docs/CODE-GUIDE.md`](docs/CODE-GUIDE.md) にまとめています。

## 制約

- **ページキャッシュがあるサイト**：フォームのページがキャッシュされると、nonce とフォームの表示時刻が古くなります。WordPress の nonce は最長24時間で無効になるため、古いキャッシュから送ると「ページの有効期限が切れたため、送信できませんでした。」と表示されます（その画面から送り直せば受け付けます）。また、表示時刻も古くなるため、3秒チェックが働きません。フォームのページはキャッシュの対象から外してください。
- **永続オブジェクトキャッシュがあるサイト**（Redis・Memcached など）：送信回数の記録（transient）は options テーブルではなくキャッシュに入るため、プラグインを削除しても消えずに残ります。記録は10分で期限が切れて消えます。
- **マルチサイトは対象外**です。サイトネットワーク全体での有効化や、削除時の各サイトの後片付けには対応していません。
- **メール**：LocalWP の Mailpit で受信内容を確認する範囲です。**実際の配信は確認していません。**
- 送信回数は、同時に送られた場合に厳密には数えられないことがあります（最小限の対策です）。
- 同じページにフォームを2つ置くと、入力エラーが両方のフォームに表示されます。
- CSV を Excel で開くと、先頭に `'` を付けた値（例：`'+81-3-…`）は `'` が見えたまま表示されます（数式として動かないようにするための仕様です）。

## 確認した範囲

| 確認 | 方法 | 状況 |
|---|---|---|
| `php -l` で全 PHP ファイルに文法エラーがない | WSL（PHP 8.5.4-0ubuntu1.5） | 済み（エラー0件） |
| PHPCS（WordPress Coding Standards 3.4.1）でエラー0件 | WSL | 済み（エラー・警告0件。行単位の除外には、各行に理由を書いています） |
| PHPCompatibilityWP（`testVersion 8.3-`）で問題0件 | WSL | 済み。ただし PHPCompatibility 9.3.5 は PHP 8.4 以降の構文を見つけられないため、8.4 以降の機能を使っていないことはコードを読んで確認しています |
| 手動の確認（要件定義書 9章の1〜10。LocalWP、PHP 8.3） | [`docs/TEST-CHECKLIST.md`](docs/TEST-CHECKLIST.md) | **一部実施**（0〜3-2。残りは実施予定） |

手動で確認した範囲（2026-11-08。LocalWP 10.2.0、PHP 8.3.36、WordPress 7.1.4、Twenty Twenty-Five、Elementor 4.3.6、Chrome 157。プラグインは 0.1.0）：

- ZIP から導入・有効化でき、テーブル（列12個）と DB の版 `1.0.0` ができる。
- 設定画面で、誤った形式の通知先はエラーが1回だけ出て保存されず、正しい形式は保存される。
- 固定ページと Elementor のショートコードウィジェットで、フォームが本文の位置に出る。Elementor の編集画面のプレビューも固まらない。
- 必須の欄が空のとき、同じページで6項目のエラーの一覧が出る。
- 正しい入力で保存され、成功後の URL に入力値が入らず、F5 で再送信にならない。全角の電話番号は半角になり、受付日時は東京時間。
- Mailpit に通知が届き、宛先・件名・本文・Reply-To（アドレスだけ）が正しく、本文の URL で詳細が開く。

未実施：無効化してもデータが残ること、通知に失敗したときの保存、入力エラー（メールの形式・文字数・電話番号の形式）と入力値の再表示、不正送信対策、一覧・詳細・更新、CSV、権限、XSS、削除時の後片付け、`debug.log` の確認。フォームの見た目を直した 0.1.1 も、これから確認します。

## 作らないもの（最小版）

- 訪問者のログイン、マイページ
- ファイル添付
- 外部 SaaS（CRM・Slack など）との連携
- 集計・分析・グラフ
- 訪問者への自動返信メール
- 多言語対応（日本語のみ。ただし文字列は翻訳関数を通しています）
- 管理者以外の権限設定
- マルチサイト

## 開発

```bash
composer install                # PHPCS・WPCS・PHPCompatibilityWP（開発用）
composer run lint               # php -l
vendor/bin/phpcs                # WordPress 規約と PHP 8.3 互換性（phpcs.xml.dist）
bash bin/build-zip.sh           # dist/mervelta-inquiry-manager.zip
bash bin/check-public.sh        # 公開してはいけない語が、ファイルと全履歴に無いか（push の前に実行）
```

`bin/check-public.sh` は、`.git/info/forbidden-words`（1行に1語。push されない場所）の語を探します。リストが無い・空のときは、検査していないことを示すため 0 以外で終わります。commit のときにも同じ検査をするには、hook を作ります（hook は push されないため、clone ごとに作る）：`printf '#!/bin/sh\nexec bash bin/check-public.sh --staged\n' > .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit`

| パス | 中身 |
|---|---|
| `mervelta-inquiry-manager/` | プラグイン本体（ZIP に入るのはここだけ） |
| `bin/build-zip.sh` | 配布用 ZIP を作るスクリプト |
| `docs/REQUIREMENTS.md` | 要件定義書 |
| `docs/TEST-CHECKLIST.md` | 手動確認の表 |
| `docs/CODE-GUIDE.md` | 主な処理の解説 |
| `composer.json`、`phpcs.xml.dist` | 検査の設定 |
| `bin/check-public.sh` | 公開してはいけない語の検査 |
| `blueprint.json` | WordPress Playground のデモの設定（ZIP には入らない） |

## 制作について

| 工程 | 担当 |
|---|---|
| 要件 | AI が案を出し、作者本人が判断して決めた |
| 実装 | Claude Code（AI のコーディング支援ツール） |
| コードのレビュー | 別の Claude Code のセッションが行い、何を直すかは作者本人が判断した |
| 動作確認・セキュリティの確認 | 作者本人が [`docs/TEST-CHECKLIST.md`](docs/TEST-CHECKLIST.md) の手順で行う（**一部実施**：2026-11-08 に 0〜3-2。残りは実施予定） |
| 説明 | 作者本人が [`docs/CODE-GUIDE.md`](docs/CODE-GUIDE.md) をもとに行う |

## ライセンス

GPL-2.0-or-later（GNU General Public License 第2版、またはそれ以降の版）。全文は [`LICENSE`](LICENSE) にあります。
