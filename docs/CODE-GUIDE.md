# コードの解説：MERVELTA Inquiry Manager

面接などで「どのファイルの何行目で、何を、なぜしているか」を説明するための資料です。行番号は、この資料を作った時点（段階4の commit `78628c5` の内容）のものです。コードを変えたら行番号がずれるので、見直してください。

パスはすべてプラグインのフォルダ `mervelta-inquiry-manager/` からの相対パスです。

## 0. 全体の流れ

| 役割 | ファイル |
|---|---|
| 入口（定数・読み込み・有効化フック） | `mervelta-inquiry-manager.php` |
| フック登録をまとめる | `includes/class-mvim-plugin.php` |
| テーブルの作成 | `includes/class-mvim-installer.php` |
| 項目の定義と入力の検証 | `includes/class-mvim-fields.php` |
| SQL（読み書きはすべてここ） | `includes/class-mvim-repository.php` |
| フォームの表示と送信の処理 | `includes/class-mvim-form.php` |
| 不正送信対策（3秒・送信回数） | `includes/class-mvim-spam-guard.php` |
| 通知メール | `includes/class-mvim-mailer.php` |
| 管理画面（一覧・詳細・更新） | `includes/admin/class-mvim-admin.php`、`includes/admin/class-mvim-list-table.php` |
| CSV 出力 | `includes/class-mvim-csv-exporter.php` |
| 設定画面 | `includes/class-mvim-settings.php` |
| 削除時の後片付け | `uninstall.php` |

**分け方の考え方**：SQL は `MVIM_Repository` にだけ書き、権限と nonce の確認は「入口」（フォームの受け取り、管理画面の表示と更新、CSV 出力）の最初にまとめています。どこで何を守っているかを説明しやすくするためです。

---

## 1. 直接アクセスの防止

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `mervelta-inquiry-manager.php:16` | `ABSPATH` が定義されていなければ `exit`。ほかの PHP ファイルもすべて先頭で同じことをする | WordPress を通さずに PHP ファイルの URL を直接開かれたとき、途中まで処理が走ったりエラーで内部の情報が見えたりしないようにするため（要件書 8章） |
| `uninstall.php:11` | `ABSPATH` に加えて `WP_UNINSTALL_PLUGIN` も確かめる | 削除処理は WordPress の「プラグインの削除」から呼ばれたときだけ動かしたいため。URL を直接開いてテーブルを消されないようにする |

---

## 2. 保存（FR-3）

### 2-1 テーブルの作成

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `mervelta-inquiry-manager.php:38` | 有効化フックで `MVIM_Installer::activate()` を呼ぶ。無効化フックは登録しない | 有効化でテーブルを作り、無効化ではデータを残すため（FR-9） |
| `includes/class-mvim-installer.php:75` | `CREATE TABLE` の SQL を、`dbDelta()` の書式（1行に1列、`PRIMARY KEY` の後は空白2つ）で書く | `dbDelta()` は書式に厳しく、崩れると列の追加などを正しく判断できないため |
| `includes/class-mvim-installer.php:85` | `mail_sent` 列（通知の結果）を持つ | メールが送れなくても保存は残し、一覧で「通知失敗」と分かるようにするため（FR-4） |
| `includes/class-mvim-installer.php:93`、`:95` | `dbDelta()` を実行し、DB の版を `mvim_db_version` に記録する | 次に列を変えたとき、版を比べて作り直せるようにするため |
| `includes/class-mvim-plugin.php:34`、`includes/class-mvim-installer.php:52` | 読み込みのたびに、記録した版とコードの版を比べ、違えば作り直す | ZIP で上書き更新すると有効化フックが動かず、テーブルが古いままになるため |

DATETIME の列に既定値を付けていません（`'0000-00-00 00:00:00'` を使わない）。MySQL の厳格なモードではこの値が拒否されるためで、保存するときに必ず値を渡しています。

### 2-2 1件の保存

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-repository.php:27` | 受付日時を `current_time( 'mysql' )` で作る | サイトのタイムゾーン（例：東京）の日時で保存するため（要件書 6章） |
| `includes/class-mvim-repository.php:30` | `$wpdb->insert()` に、値の配列と書式（`%s`・`%d`）の配列を渡す | 値を SQL の文字列に直接つなげないため（SQL インジェクション対策）。書式を渡すと、WordPress が値を安全に埋め込む |
| `includes/class-mvim-repository.php:29` | `phpcs:ignore`（DirectQuery）と理由のコメント | 独自テーブルには WordPress の API がなく、`$wpdb` を使うしかないため。規則を一律には外さず、この行だけ理由を書いて外している |
| `includes/class-mvim-form.php:221`、`:222` | 保存に失敗（ID が 0）したら、入力を残したまま「受け付けられませんでした」と表示する | DB のエラーで依頼が黙って消えないようにするため |

---

## 3. 入力の受け取りと検証（FR-1）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-form.php:119`〜`:130` | 管理画面・Ajax・cron ではなく、POST で、隠し項目 `mvim_action` が `submit_inquiry` のときだけ処理に進む | `init` はすべてのリクエストで動くので、ほかのプラグインやフォームの POST に触れないようにするため |
| `includes/class-mvim-form.php:358` | フォームに隠し項目 `mvim_action` を入れる | 上の見分けに使う目印 |
| 項目名（例：`includes/class-mvim-form.php:129`） | 送る項目名にはすべて `mvim_` を付ける | WordPress は POST に `name` などの公開クエリ変数があると、表示するページの決定に使ってしまうため |
| `includes/class-mvim-form.php:149`（`$input` の配列） | 受け取った値を `wp_unslash()` してから `sanitize_text_field()` / `sanitize_textarea_field()` / `sanitize_key()` に通す | WordPress は `$_POST` にバックスラッシュを付けているので、先に外す。そのうえでタグや改行を取り除く |
| `includes/class-mvim-fields.php:76` | `validate_submission()` で、必須・文字数・形式・選択値を確かめ、値とエラーを返す | 無害化（sanitize）と検証（validate）は別の仕事。無害化した値が正しいかを、ここでまとめて確かめる |
| `includes/class-mvim-fields.php:91` | 種別が決められた値（estimate / question / other）かを `array_key_exists()` で確かめる | ブラウザの開発者ツールで選択肢の値を書き換えて送られても、決められた値以外は保存しないため |
| `includes/class-mvim-fields.php:200` | 文字数は `mb_strlen()` で数える | 日本語は1文字が3バイトなので、`strlen()` ではバイト数になってしまうため |
| `includes/class-mvim-fields.php:113`、`:120` | メールは `is_email()` で形式を確かめ、通ったものを `sanitize_email()` で整える | 要件書 6章の検証方法 |
| `includes/class-mvim-fields.php:165`、`:168` | 電話番号の全角を `mb_convert_kana()` で半角に直し、長音記号やダッシュ類はハイフンにしてから検証する | 日本語入力のまま打たれた電話番号を、エラーにせず受け付けるため |
| `includes/class-mvim-fields.php:126` | 電話番号を `\A[0-9()+\- ]+\z` で確かめる | 数字・ハイフン・括弧・＋・空白だけを許す（要件書 6章）。`\A` と `\z` で文字列全体を確かめる（`$` だと末尾の改行を見逃すため） |
| `includes/class-mvim-fields.php:180` | 内容の改行を LF にそろえる | ブラウザは改行を CRLF（2文字）で送るので、そろえないと文字数がずれるため |
| `includes/class-mvim-form.php:211` | 入力エラーなら、リダイレクトせずにそのまま戻る（同じリクエストで再表示） | 入力値を transient などに一時保存しないため。個人情報をサーバーに残さない |
| `includes/class-mvim-form.php:474` | 再表示する値は `esc_textarea()` / `esc_attr()` でエスケープして出す | 送信者が入れた `"` などで HTML が壊れたり、スクリプトが動いたりしないようにするため（XSS 対策） |

---

## 4. 不正送信対策（FR-2）

処理の順番は `includes/class-mvim-form.php:144` の `handle_submission()` にまとめています。nonce → ハニーポット → 3秒 → 送信回数 → 入力の検証 → 回数を数える → 保存 → 通知 → リダイレクト、の順です。

### 4-1 nonce

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-form.php:361` | `wp_nonce_field()` でフォームに nonce を入れる | このサイトのフォームから送られたことを確かめるため（CSRF 対策） |
| `includes/class-mvim-form.php:146` | `wp_verify_nonce()` で確かめる | 同上 |
| `includes/class-mvim-form.php:176` | 失敗したら「有効期限が切れた」と出し、入力を残す | この応答で新しい nonce のフォームを出すので、利用者はそのまま送り直せる。入れた内容を捨てずにすむ |

### 4-2 ハニーポット

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-form.php:353`、`:355` | 人には見えない入力欄を置く（画面の外に出し、`aria-hidden`、`tabindex="-1"`） | 機械的に全部の欄を埋めるボットを見分けるため。CSS が読み込まれなくても見えないよう、インラインの style で隠す |
| `includes/class-mvim-form.php:83` | 欄の名前を `mvim_hp_x7` にする（website・name・email などの語を使わない） | ブラウザの自動入力が「ウェブサイト」などの欄だと思って値を入れると、本物の問い合わせが捨てられてしまうため |
| `includes/class-mvim-form.php:182` | 値があれば、保存せずに成功と同じリダイレクトをする | エラーを出すと、ボットに「この欄が罠だ」と分かってしまうため |

### 4-3 フォーム表示から3秒未満の送信

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-spam-guard.php:57` | フォームを出すときの時刻と、その署名を作る | 人は3秒より早く入力して送れないので、早すぎる送信をボットとみなすため |
| `includes/class-mvim-spam-guard.php:183` | 署名は `hash_hmac( 'sha256', …, wp_salt( 'nonce' ) )` | サイト固有の秘密の鍵で署名するので、時刻を書き換えると署名が合わなくなる。WP 6.5 の `wp_hash()` では SHA-256 を選べないため、`hash_hmac()` を直接使う |
| `includes/class-mvim-spam-guard.php:79` | 署名は `hash_equals()` で比べる | `===` だと、一致する文字数によって比べる時間が変わり、署名を推測される手がかりになるため |
| `includes/class-mvim-spam-guard.php:89` | 経過が3秒未満なら「短すぎます」を返す | FR-2 |
| `includes/class-mvim-form.php:167`、`:328` | 署名が正しい送信なら、エラーで再表示するフォームにも最初の表示時刻を引き継ぐ | 再表示のたびに時刻を新しくすると、同意のチェックを入れてすぐ送り直した人が、2回目も「短すぎます」で止められてしまうため |

### 4-4 送信回数（10分間に5回まで）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-spam-guard.php:106`、`:108` | 送信元は `REMOTE_ADDR` だけを使い、IP の形かを確かめる | `X-Forwarded-For` などのヘッダーは送る側が自由に書けるので、制限を逃れられてしまうため |
| `includes/class-mvim-spam-guard.php:105` | transient の名前は、IP を鍵付きの HMAC にした値から作る | IP アドレスを保存しないため（要件書 6章）。鍵を付けないハッシュだと、IPv4 の全件を試して元の IP を割り出せてしまう |
| `includes/class-mvim-spam-guard.php:140`、`:148` | 最初の送信の時刻と回数を一緒に保存し、期限には「最初の送信から10分までの残り時間」を渡す | `set_transient()` は保存のたびに期限を付け直すので、そのままだと送るたびに10分延びてしまう。最初の送信から10分の固定の窓にするため |
| `includes/class-mvim-form.php:198`、`:199` | 5回に達していれば「約N分後に」と案内する | FR-2 |
| `includes/class-mvim-form.php:218` | 入力の検証を通ったあとで1回数える | 打ち間違えた人が、入力エラーのたびに回数を使って締め出されないようにするため |

---

## 5. 通知メール（FR-4）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-mailer.php:88` | 件名・ヘッダー・1行の項目から、改行を含む制御文字を空白に置き換える | 値に改行と `Bcc:` などを入れられると、メールのヘッダーを追加される（ヘッダーインジェクション）ため |
| `includes/class-mvim-mailer.php:33` | 件名を `【種別】会社名 様より` の形にする | 受信箱で、種別と会社名がひと目で分かるようにするため |
| `includes/class-mvim-mailer.php:63`、`:64` | Reply-To は、改行を除いて `is_email()` を通ったアドレスだけにし、名前は付けない | 名前の部分は自由な文字列なのでヘッダーを壊されやすい。検証済みのアドレスだけなら安全に返信先にできる |
| `includes/class-mvim-mailer.php:67` | `wp_mail()` の結果（true / false）を返す | 送信に成功したかを記録するため |
| `includes/class-mvim-form.php:229`、`:230` | 送信に成功したときだけ `mail_sent` を 1 にする。失敗しても保存は取り消さない | 依頼そのものは失わず、送れなかったことを一覧で分かるようにするため |

本文は text/plain で送り、HTML として解釈されないので、本文の中でスクリプトは動きません。「内容」の改行はそのまま残しています（本文はヘッダーではないため）。

---

## 6. 成功時のリダイレクト（PRG）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-form.php:247` | `wp_validate_redirect()` で、行き先が自サイトでなければトップページにする | `//別のホスト/` で始まる URL などで、外部サイトに飛ばされないようにするため |
| `includes/class-mvim-form.php:250` | `wp_safe_redirect( …, 303 )` のあと `exit` | 303 にすると、ブラウザは GET で読み直す。再読み込みしても同じ POST が送り直されない（二重送信の防止）。`exit` しないと、後の処理が動いてしまう |
| `includes/class-mvim-form.php:266` | URL の `mvim_sent=1` を見て「受け付けました」を出す | URL に付けるのは成功の目印だけで、入力値は載せない（個人情報がブラウザの履歴やログに残らないようにするため） |
| `includes/class-mvim-form.php:269`、`:281` | ショートコードは `ob_start()` で文字列を作り、`return` する | ショートコードが echo すると、本文の決まった位置ではなくページの先頭などに出てしまうため。Elementor の編集画面でも同じ |

---

## 7. 権限の確認（要件書 8章）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-plugin.php:20` | 必要な権限を `manage_options`（管理者）に決めて、定数にする | 確認する権限を1か所で決め、どこでも同じものを使うため |
| `includes/admin/class-mvim-admin.php:108` | メニューを `manage_options` で登録する | 編集者などにはメニューが出ず、URL を直接開いても WordPress が止める |
| `includes/admin/class-mvim-admin.php:213` | 一覧・詳細を出す前に、もう一度 `current_user_can()` を確かめる | メニューの設定だけに頼らず、出力する場所でも確かめるため（多重の防御） |
| `includes/admin/class-mvim-admin.php:161` | 更新の処理の最初に `current_user_can()` | nonce は「このフォームから送られたか」しか分からず、「この人に許されているか」は分からないため。nonce だけで守らない |
| `includes/class-mvim-csv-exporter.php:65` | CSV 出力の最初に `current_user_can()` | 同上。CSV の URL は admin-post.php なので、メニューの権限が効かない。ここで確かめないと、URL を知っている編集者が個人情報を書き出せてしまう |
| `includes/class-mvim-csv-exporter.php:40` | `admin_post_`（ログイン中）だけに登録し、`admin_post_nopriv_` には登録しない | ログインしていない人は、そもそも CSV の処理に届かないようにするため |
| `includes/class-mvim-settings.php:185` | 設定画面を出す前に `current_user_can()` | 一覧と同じ考え方 |

---

## 8. nonce（管理画面）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/admin/class-mvim-admin.php:386` | 更新フォームの nonce のアクション名を `mvim_update_inquiry_{ID}` にする | ある問い合わせのフォームの nonce を、別の ID の更新に流用されないようにするため |
| `includes/admin/class-mvim-admin.php:169` | `check_admin_referer()` で、同じアクション名（ID 付き）で確かめる | 同上。合わなければ WordPress が処理を止める |
| `includes/class-mvim-csv-exporter.php:55`、`:68` | CSV の URL は専用のアクション名 `mvim_export_csv` の nonce 付きで作り、出力の前に `check_admin_referer()` で確かめる | 管理者がほかのサイトに置かれたリンクを踏まされて、知らないうちに CSV を書き出させられる（CSRF）のを防ぐため |

---

## 9. 管理画面：一覧・詳細・更新（FR-5、FR-6）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/admin/class-mvim-admin.php:98`、`:100` | 絞り込みの `status` を `sanitize_key()` し、決められた値（new / in_progress / done）でなければ「すべて」にする | 想定外の値で SQL や表示がおかしくならないようにするため。一覧と CSV で同じ関数を使い、条件がずれないようにする |
| `includes/class-mvim-repository.php:96`、`:108` | `LIMIT` と `OFFSET` も `prepare()` の `%d` で渡す | ページ番号は URL から来る値なので、数字以外が SQL に入らないようにするため |
| `includes/class-mvim-repository.php:96` | 並び順は `created_at DESC, id DESC` | 受付日時が同じ行があると順番が揺れて、ページの境目で行が重複したり抜けたりするため。ID で順番を固定する |
| `includes/admin/class-mvim-list-table.php:84` | `prepare_items()` で、件数・1ページ20件・今のページの行を読み、ページ送りを設定する | `WP_List_Table` の決まった使い方（FR-5） |
| `includes/admin/class-mvim-list-table.php:212` | `mail_sent` が 0 なら「（通知失敗）」と添える | FR-4 |
| `includes/admin/class-mvim-list-table.php:196` | 会社名は `esc_html()`、リンク先は `esc_url()` で出す | 出力するときにエスケープする（要件書 8章） |
| `includes/admin/class-mvim-admin.php:130` | 更新は、この画面の `load-` フック（HTML を出す前）で受け取る | 入力エラー（管理メモの文字数超過など）のときに、同じ画面で入力を消さずにエラーを出せるため。成功したら詳細へリダイレクトする（PRG） |
| `includes/admin/class-mvim-admin.php:184`、`:189` | 状態は決められた値か、管理メモは1,000文字以内かを確かめる | 開発者ツールで選択肢を書き換えても、決められた値以外は保存しないため |
| `includes/class-mvim-repository.php:186` | 更新するとき `updated_at` を今の日時にする | 最終更新日時を表示するため |
| `includes/admin/class-mvim-admin.php:202` | 更新に成功したら、`mvim_updated=1` を付けて詳細へ 303 でリダイレクト | 再読み込みで更新が送り直されないようにし、「更新しました」を出すため |
| `includes/admin/class-mvim-admin.php:349` | 内容は `esc_html()` で出し、改行は CSS（`white-space: pre-wrap`）で見せる | `nl2br()` で `<br>` を足すとエスケープの順番を気にする必要が出るので、HTML を足さずに改行を見せる |

---

## 10. CSV 出力（FR-7）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-csv-exporter.php:65`、`:68` | 権限 → nonce の順に確かめる | 7・8 のとおり |
| `includes/class-mvim-csv-exporter.php:71` | 絞り込みは一覧と同じ `requested_status()` で読む | 「今の絞り込み条件のまま」書き出すため。条件の読み方を1つにする |
| `includes/class-mvim-csv-exporter.php:73`、`:79` | すでにヘッダーが送られていれば止め、ためられた出力は捨てる | CSV の前にほかの出力（空白など）が混ざると、ヘッダーが送れなかったり、ファイルの先頭が壊れたりするため |
| `includes/class-mvim-csv-exporter.php:85`〜`:87` | `Content-Type`、`Content-Disposition`（ファイル名）、`X-Content-Type-Options: nosniff` を送る | ブラウザにダウンロードさせ、中身を HTML などと推測して表示させないため |
| `includes/class-mvim-csv-exporter.php:97` | 先頭に UTF-8 の BOM（`EF BB BF`）を書く | BOM がないと、Excel が Shift_JIS だと思って文字化けするため |
| `includes/class-mvim-csv-exporter.php:103`、`:111` | 出力を始めたときの最大 ID を1回だけ読み、500件ずつ読むときに「その ID 以下」に限る | 書き出しの途中で新しい依頼が保存されると、OFFSET がずれて同じ行が2回出ることがあるため。全件を一度に読まないのは、件数が多くてもメモリを使いすぎないため |
| `includes/class-mvim-csv-exporter.php:32`、`:177` | `=` `+` `-` `@` タブ・CR・LF で始まる値の先頭に `'` を付ける | Excel などがこれらで始まる値を数式として実行するため（CSV インジェクション）。`'` を付けると文字として扱われる |
| `includes/class-mvim-csv-exporter.php:195` | `fputcsv()` の escape 引数に `''` を明示的に渡す | PHP 8.4 以降は省略すると非推奨の警告が出るため。`''` にすると `"` は `""` に二重化されるだけになり、Excel が読む形（RFC 4180）になる |
| `includes/class-mvim-csv-exporter.php:150` | 種別と状態は日本語名で出す | CSV を受け取った人が、コード（`estimate` など）を読み替えずにすむため |

---

## 11. 設定画面（FR-8）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `includes/class-mvim-settings.php:73` | Settings API（`register_setting()` など）で登録する | 保存の処理・nonce・権限の確認を WordPress に任せられるため（`options.php` が確かめる） |
| `includes/class-mvim-settings.php:116`、`:118` | 空欄は「未設定」として受け付け、それ以外は `sanitize_email()` と `is_email()` で確かめる | 誤ったアドレスが保存されて、通知が届かなくなるのを防ぐため |
| `includes/class-mvim-settings.php:40`、`:123` | 形式が誤っていればエラーを1回だけ出し、元の値を返す | オプションがまだ無いとき、WordPress は検証を2回呼ぶ。そのままだとエラーが二重に出るため |
| `includes/class-mvim-settings.php:58`〜`:65` | 未設定ならサイトの管理者メールアドレスに送る | FR-4 |

---

## 12. 削除時の後片付け（FR-9）

| 場所 | 何をしているか | なぜ |
|---|---|---|
| `uninstall.php:21` | `DROP TABLE IF EXISTS %i` を `prepare()` で実行する | テーブル名も `%i`（識別子）で渡し、SQL を文字列でつながないため |
| `uninstall.php:23`、`:24` | オプション（DB の版、通知先）を消す | 要件書 FR-9 |
| `uninstall.php:31` | 送信回数の transient を `LIKE` で消す（`esc_like()` で `_` などをエスケープ） | 名前に IP のハッシュが入っていて、名前を列挙できないため。永続オブジェクトキャッシュのサイトでは options テーブルに無いので消せないが、10分で期限が切れる（README の制約） |

---

## 13. 検査（PHPCS）で行単位に外した規則

規則を一律には外していません。外した行には、`phpcs:ignore 規則名 -- 理由` の形で理由を書いています。種類は次のとおりです。

| 規則 | 外した場所 | 理由 |
|---|---|---|
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` / `NoCaching` | `includes/class-mvim-repository.php` の各メソッド、`uninstall.php` | 独自テーブルには WordPress の API がなく、`$wpdb` を使うしかない。更新の直後に古い値を見せないため、キャッシュもしない |
| `WordPress.DB.DirectDatabaseQuery.SchemaChange` | `uninstall.php:20` | 削除時にテーブルを消すのが目的のため |
| `WordPress.Security.NonceVerification.Missing` | `includes/class-mvim-form.php:128`、`includes/admin/class-mvim-admin.php:144` | 「このフォームの送信か」を見分けるだけで、nonce はその直後の処理で検証する |
| `WordPress.Security.NonceVerification.Recommended` | 一覧の絞り込み、画面の切り替え、成功・更新の目印（GET） | 読み取りだけで、データを変えないため |
| `WordPress.WP.AlternativeFunctions.file_system_operations_fwrite` / `fclose` | `includes/class-mvim-csv-exporter.php` | ファイルではなく出力（`php://output`）に書くため、WP_Filesystem は使えない |

すべての `phpcs:ignore` について、「規則を外さずに検査すると、その行に本当に指摘が出るか」を照らし合わせ、不要な除外が無いことを確かめています。
