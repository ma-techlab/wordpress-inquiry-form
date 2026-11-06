<?php
/**
 * 入力フォーム：ショートコードの表示と、送信の受け取り。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 入力フォーム（FR-1）と、送信の処理順（FR-2〜FR-4）。
 *
 * 送信先はフォームのあるページそのもの。init で受け取り、
 * 入力エラーのときは同じリクエストのまま値とエラーを表示する（入力値を一時保存しない）。
 * 成功したときだけリダイレクトする（PRG）。
 */
final class MVIM_Form {

	/**
	 * このフォームの送信であることを示す隠し項目の値.
	 */
	const ACTION_VALUE = 'submit_inquiry';

	/**
	 * Nonce のアクション名.
	 */
	const NONCE_ACTION = 'mvim_submit_inquiry';

	/**
	 * Nonce の項目名.
	 */
	const NONCE_NAME = 'mvim_nonce';

	/**
	 * 成功を示す URL の目印（入力値は URL に載せない）.
	 */
	const SUCCESS_QUERY_ARG = 'mvim_sent';

	/**
	 * フォームの外枠の id（リダイレクト後にこの位置へ移動する）.
	 */
	const ANCHOR = 'mvim-inquiry-form';

	/**
	 * スタイルシートのハンドル名.
	 */
	const STYLE_HANDLE = 'mvim-form';

	/**
	 * 再表示する入力値（このリクエストの中だけで持つ）.
	 *
	 * @var array<string, mixed>
	 */
	private static $values = array();

	/**
	 * 項目ごとのエラー（項目名 => メッセージ）.
	 *
	 * @var array<string, string>
	 */
	private static $errors = array();

	/**
	 * フォーム全体へのメッセージ.
	 *
	 * @var string
	 */
	private static $notice = '';

	/**
	 * 再表示のときに引き継ぐ表示時刻と署名（署名が正しい送信のときだけ入る）.
	 *
	 * @var array{ts: int, sig: string}|null
	 */
	private static $stamp = null;

	/**
	 * ハニーポットの項目名。ブラウザの自動入力が対象にしやすい語
	 * （website・url・name・company・email・tel など）を含めない.
	 */
	const HONEYPOT_NAME = 'mvim_hp_x7';

	/**
	 * 同じページに複数置かれたときに id を重ねないための番号.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * フックを登録する。
	 *
	 * ショートコードは管理画面側（Elementor の編集画面のプレビュー描画など）でも使うため、常に登録する。
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_submission' ), 20 );
	}

	/**
	 * ショートコードを登録する。
	 *
	 * @return void
	 */
	public static function register_shortcode() {
		add_shortcode( 'mvim_inquiry_form', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * このフォームの送信のときだけ処理する。ほかの POST には触れない。
	 *
	 * @return void
	 */
	public static function maybe_handle_submission() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- このフォームの送信かを見分けるだけで、値は使わない。nonce は handle_submission() の最初で検証する。
		$action = isset( $_POST['mvim_action'] ) ? sanitize_key( wp_unslash( $_POST['mvim_action'] ) ) : '';
		if ( self::ACTION_VALUE !== $action ) {
			return;
		}

		self::handle_submission();
	}

	/**
	 * 送信を処理する。
	 *
	 * 順番：nonce → ハニーポット → 3秒 → 送信回数 → 入力の検証 → 回数を数える → 保存 → 通知 → リダイレクト。
	 *
	 * @return void
	 */
	private static function handle_submission() {
		$nonce_ok = isset( $_POST[ self::NONCE_NAME ] )
			&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION );

		// 再表示のために入力値を読む。どの経路でも、エスケープして送信者本人の画面にだけ返す.
		$input = array(
			'inquiry_type' => isset( $_POST['mvim_inquiry_type'] ) ? sanitize_key( wp_unslash( $_POST['mvim_inquiry_type'] ) ) : '',
			'company_name' => isset( $_POST['mvim_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mvim_company_name'] ) ) : '',
			'contact_name' => isset( $_POST['mvim_contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mvim_contact_name'] ) ) : '',
			'email'        => isset( $_POST['mvim_email'] ) ? sanitize_text_field( wp_unslash( $_POST['mvim_email'] ) ) : '',
			'phone'        => isset( $_POST['mvim_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['mvim_phone'] ) ) : '',
			'message'      => isset( $_POST['mvim_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mvim_message'] ) ) : '',
			'consent'      => isset( $_POST['mvim_consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['mvim_consent'] ) ),
		);

		$honeypot = isset( $_POST[ self::HONEYPOT_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_NAME ] ) ) : '';
		$ts       = isset( $_POST['mvim_ts'] ) ? absint( wp_unslash( $_POST['mvim_ts'] ) ) : 0;
		$ts_sig   = isset( $_POST['mvim_ts_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['mvim_ts_sig'] ) ) : '';

		self::$values = $input;

		// 署名が正しければ、再表示のフォームにも最初の表示時刻を引き継ぐ.
		// 再表示のたびに時刻を新しくすると、直してすぐ送り直した人が「短すぎます」で止められるため.
		$time_check = MVIM_Spam_Guard::check_timestamp( $ts, $ts_sig );
		if ( MVIM_Spam_Guard::TIME_INVALID !== $time_check ) {
			self::$stamp = array(
				'ts'  => $ts,
				'sig' => $ts_sig,
			);
		}

		// 1. nonce。この応答で新しい nonce のフォームを出すので、そのまま再送信できる.
		if ( ! $nonce_ok ) {
			self::$notice = __( 'ページの有効期限が切れたため、送信できませんでした。内容を確認して、もう一度送信してください。', 'mervelta-inquiry-manager' );
			return;
		}

		// 2. ハニーポット。値があれば保存せず、成功と同じ表示にする（ボットに見破られないため）.
		if ( '' !== $honeypot ) {
			self::redirect_success();
		}

		// 3. フォーム表示から3秒未満、または署名が合わない.
		if ( MVIM_Spam_Guard::TIME_TOO_FAST === $time_check ) {
			self::$notice = __( '送信までの時間が短すぎます。内容を確認して、もう一度送信してください。', 'mervelta-inquiry-manager' );
			return;
		}
		if ( MVIM_Spam_Guard::TIME_OK !== $time_check ) {
			self::$notice = __( 'フォームの情報を確認できませんでした。もう一度送信してください。', 'mervelta-inquiry-manager' );
			return;
		}

		// 4. 送信回数（10分間に5回まで）.
		$source = MVIM_Spam_Guard::source_key();
		$wait   = MVIM_Spam_Guard::seconds_until_allowed( $source );
		if ( $wait > 0 ) {
			self::$notice = sprintf(
				/* translators: %d: 待つ分数 */
				__( '短い時間に送信が続いたため、受け付けを一時的に止めています。約%d分後に、もう一度送信してください。', 'mervelta-inquiry-manager' ),
				(int) ceil( $wait / MINUTE_IN_SECONDS )
			);
			return;
		}

		// 5. 入力の検証。エラーのときは数えない.
		$result       = MVIM_Fields::validate_submission( $input );
		self::$values = $result['values'];
		if ( ! empty( $result['errors'] ) ) {
			self::$errors = $result['errors'];
			self::$notice = __( '入力内容に誤りがあります。各項目のメッセージを確認してください。', 'mervelta-inquiry-manager' );
			return;
		}

		// 6. ここまで通過した送信を1回数える.
		MVIM_Spam_Guard::record_submission( $source );

		// 7. 保存.
		$id = MVIM_Repository::insert( $result['values'] );
		if ( 0 === $id ) {
			self::$notice = __( '申し訳ありません。送信を受け付けられませんでした。時間をおいて、もう一度送信してください。', 'mervelta-inquiry-manager' );
			return;
		}

		// 8. 通知。失敗しても保存は取り消さない。成功したときだけ mail_sent を 1 にする.
		$row = MVIM_Repository::find( $id );
		if ( null !== $row && MVIM_Mailer::send_notification( $row ) ) {
			MVIM_Repository::mark_mail_sent( $id );
		}

		// 9. 二重送信を防ぐため、成功の目印だけを付けて元のページへ戻す.
		self::redirect_success();
	}

	/**
	 * 成功の目印を付けて、元のページへリダイレクトする。
	 *
	 * @return never
	 */
	private static function redirect_success() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url = add_query_arg( self::SUCCESS_QUERY_ARG, '1', remove_query_arg( self::SUCCESS_QUERY_ARG, $uri ) );

		// 自サイト以外の行き先（例：「//別ホスト/」で始まる URI）は、管理画面ではなくトップページへ戻す.
		$url = wp_validate_redirect( $url, add_query_arg( self::SUCCESS_QUERY_ARG, '1', home_url( '/' ) ) );

		// 303 で、ブラウザに GET で読み直させる。wp_safe_redirect() でも外部サイトへは飛ばさない.
		wp_safe_redirect( $url . '#' . self::ANCHOR, 303 );
		exit;
	}

	/**
	 * ショートコードの出力。echo せず文字列を返す。
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		++self::$instance;
		$suffix = ( 1 === self::$instance ) ? '' : '-' . self::$instance;

		self::enqueue_style();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示を「受け付けました」に切り替える目印だけで、データの保存や変更はしない。
		$sent   = isset( $_GET[ self::SUCCESS_QUERY_ARG ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::SUCCESS_QUERY_ARG ] ) );
		$posted = ! empty( self::$values ) || '' !== self::$notice;

		ob_start();
		?>
		<div class="mvim-form-wrap" id="<?php echo esc_attr( self::ANCHOR . $suffix ); ?>">
			<?php
			if ( $sent && ! $posted ) {
				self::render_success();
			} else {
				self::render_form( $suffix );
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * スタイルシートを読み込む。
	 *
	 * @return void
	 */
	private static function enqueue_style() {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, MVIM_PLUGIN_URL . 'assets/css/form.css', array(), MVIM_VERSION );
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * 成功時の表示。
	 *
	 * @return void
	 */
	private static function render_success() {
		?>
		<div class="mvim-notice mvim-notice--success" role="status">
			<p><?php esc_html_e( 'お問い合わせを受け付けました。内容を確認のうえ、担当者よりご連絡します。', 'mervelta-inquiry-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * フォームを出力する。
	 *
	 * @param string $suffix id の接尾辞.
	 * @return void
	 */
	private static function render_form( string $suffix ) {
		$values = wp_parse_args(
			self::$values,
			array(
				'inquiry_type' => '',
				'company_name' => '',
				'contact_name' => '',
				'email'        => '',
				'phone'        => '',
				'message'      => '',
				'consent'      => false,
			)
		);
		$stamp  = self::$stamp ?? MVIM_Spam_Guard::issue_timestamp();

		if ( '' !== self::$notice ) {
			self::render_error_summary( $suffix );
		}
		?>
		<form class="mvim-form" method="post" action="<?php echo esc_url( remove_query_arg( self::SUCCESS_QUERY_ARG ) ); ?>" novalidate>
			<p class="mvim-form__lead">
				<?php esc_html_e( '「必須」の項目は必ず入力してください。', 'mervelta-inquiry-manager' ); ?>
			</p>

			<?php
			self::render_type_field( (string) $values['inquiry_type'], $suffix );
			self::render_input_field( 'company_name', 'text', (string) $values['company_name'], $suffix, true, 'organization', self::max_hint( MVIM_Fields::MAX_COMPANY_NAME ) );
			self::render_input_field( 'contact_name', 'text', (string) $values['contact_name'], $suffix, true, 'name', self::max_hint( MVIM_Fields::MAX_CONTACT_NAME ) );
			self::render_input_field( 'email', 'email', (string) $values['email'], $suffix, true, 'email', __( '例：taro@example.com', 'mervelta-inquiry-manager' ) );
			self::render_input_field( 'phone', 'tel', (string) $values['phone'], $suffix, false, 'tel', __( '例：03-1234-5678', 'mervelta-inquiry-manager' ) );
			self::render_message_field( (string) $values['message'], $suffix );
			self::render_consent_field( (bool) $values['consent'], $suffix );
			?>

			<?php
			// ハニーポット：人には見えず、読み上げもされない欄。ボットが値を入れると保存しない.
			// 名前・id・ラベルには、ブラウザやパスワード管理ツールの自動入力が反応しやすい語を使わない.
			?>
			<div class="mvim-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
				<label for="<?php echo esc_attr( 'mvim-hp-x7' . $suffix ); ?>"><?php esc_html_e( '（入力不要）', 'mervelta-inquiry-manager' ); ?></label>
				<input type="text" id="<?php echo esc_attr( 'mvim-hp-x7' . $suffix ); ?>" name="<?php echo esc_attr( self::HONEYPOT_NAME ); ?>" value="" tabindex="-1" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
			</div>

			<input type="hidden" name="mvim_action" value="<?php echo esc_attr( self::ACTION_VALUE ); ?>">
			<input type="hidden" name="mvim_ts" value="<?php echo esc_attr( (string) $stamp['ts'] ); ?>">
			<input type="hidden" name="mvim_ts_sig" value="<?php echo esc_attr( $stamp['sig'] ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, false ); ?>

			<p class="mvim-form__actions">
				<button type="submit" class="mvim-button"><?php esc_html_e( '送信する', 'mervelta-inquiry-manager' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * フォーム上部のエラーのまとめ。項目ごとのエラーへのリンクも出す。
	 *
	 * @param string $suffix id の接尾辞.
	 * @return void
	 */
	private static function render_error_summary( string $suffix ) {
		?>
		<div class="mvim-notice mvim-notice--error" role="alert">
			<p><?php echo esc_html( self::$notice ); ?></p>
			<?php if ( ! empty( self::$errors ) ) : ?>
				<ul class="mvim-notice__list">
					<?php foreach ( self::$errors as $field => $message ) : ?>
						<li><a href="<?php echo esc_attr( '#' . self::field_id( $field, $suffix ) ); ?>"><?php echo esc_html( $message ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 種別の選択欄。
	 *
	 * @param string $value  選択中の値.
	 * @param string $suffix id の接尾辞.
	 * @return void
	 */
	private static function render_type_field( string $value, string $suffix ) {
		$field = 'inquiry_type';
		?>
		<div class="<?php echo esc_attr( self::field_class( $field ) ); ?>">
			<?php self::render_label( $field, $suffix, true ); ?>
			<select
				id="<?php echo esc_attr( self::field_id( $field, $suffix ) ); ?>"
				name="mvim_inquiry_type"
				required
				aria-required="true"
				<?php self::render_aria( $field, $suffix, false ); ?>
			>
				<option value=""><?php esc_html_e( '選択してください', 'mervelta-inquiry-manager' ); ?></option>
				<?php foreach ( MVIM_Fields::inquiry_types() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php self::render_messages( $field, $suffix, '' ); ?>
		</div>
		<?php
	}

	/**
	 * 1行の入力欄。
	 *
	 * @param string $field        項目名.
	 * @param string $type         input の type.
	 * @param string $value        値.
	 * @param string $suffix       id の接尾辞.
	 * @param bool   $required     必須か.
	 * @param string $autocomplete autocomplete 属性.
	 * @param string $hint         補足.
	 * @return void
	 */
	private static function render_input_field( string $field, string $type, string $value, string $suffix, bool $required, string $autocomplete, string $hint ) {
		?>
		<div class="<?php echo esc_attr( self::field_class( $field ) ); ?>">
			<?php self::render_label( $field, $suffix, $required ); ?>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				id="<?php echo esc_attr( self::field_id( $field, $suffix ) ); ?>"
				name="<?php echo esc_attr( 'mvim_' . $field ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
				class="mvim-input"
				<?php if ( $required ) : ?>
					required
					aria-required="true"
				<?php endif; ?>
				<?php self::render_aria( $field, $suffix, '' !== $hint ); ?>
			>
			<?php self::render_messages( $field, $suffix, $hint ); ?>
		</div>
		<?php
	}

	/**
	 * 内容の入力欄。
	 *
	 * @param string $value  値.
	 * @param string $suffix id の接尾辞.
	 * @return void
	 */
	private static function render_message_field( string $value, string $suffix ) {
		$field = 'message';
		?>
		<div class="<?php echo esc_attr( self::field_class( $field ) ); ?>">
			<?php self::render_label( $field, $suffix, true ); ?>
			<textarea
				id="<?php echo esc_attr( self::field_id( $field, $suffix ) ); ?>"
				name="mvim_message"
				rows="8"
				class="mvim-input mvim-input--textarea"
				required
				aria-required="true"
				<?php self::render_aria( $field, $suffix, true ); ?>
			><?php echo esc_textarea( $value ); ?></textarea>
			<?php self::render_messages( $field, $suffix, self::max_hint( MVIM_Fields::MAX_MESSAGE ) ); ?>
		</div>
		<?php
	}

	/**
	 * 個人情報の取扱いへの同意欄（保存はしない）。
	 *
	 * @param bool   $checked チェック済みか.
	 * @param string $suffix  id の接尾辞.
	 * @return void
	 */
	private static function render_consent_field( bool $checked, string $suffix ) {
		$field = 'consent';
		?>
		<div class="<?php echo esc_attr( self::field_class( $field ) . ' mvim-field--checkbox' ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( self::field_id( $field, $suffix ) ); ?>"
				name="mvim_consent"
				value="1"
				required
				aria-required="true"
				<?php checked( $checked ); ?>
				<?php self::render_aria( $field, $suffix, false ); ?>
			>
			<label class="mvim-label mvim-label--inline" for="<?php echo esc_attr( self::field_id( $field, $suffix ) ); ?>">
				<?php esc_html_e( '個人情報の取扱いに同意する', 'mervelta-inquiry-manager' ); ?>
				<span class="mvim-badge mvim-badge--required"><?php esc_html_e( '必須', 'mervelta-inquiry-manager' ); ?></span>
			</label>
			<?php self::render_messages( $field, $suffix, '' ); ?>
		</div>
		<?php
	}

	/**
	 * 項目のラベルと「必須」「任意」の表示。
	 *
	 * @param string $field    項目名.
	 * @param string $suffix   id の接尾辞.
	 * @param bool   $required 必須か.
	 * @return void
	 */
	private static function render_label( string $field, string $suffix, bool $required ) {
		$labels = MVIM_Fields::field_labels();
		?>
		<label class="mvim-label" for="<?php echo esc_attr( self::field_id( $field, $suffix ) ); ?>">
			<?php echo esc_html( $labels[ $field ] ); ?>
			<?php if ( $required ) : ?>
				<span class="mvim-badge mvim-badge--required"><?php esc_html_e( '必須', 'mervelta-inquiry-manager' ); ?></span>
			<?php else : ?>
				<span class="mvim-badge mvim-badge--optional"><?php esc_html_e( '任意', 'mervelta-inquiry-manager' ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Aria-invalid と aria-describedby を出力する。
	 *
	 * @param string $field    項目名.
	 * @param string $suffix   id の接尾辞.
	 * @param bool   $has_hint 補足があるか.
	 * @return void
	 */
	private static function render_aria( string $field, string $suffix, bool $has_hint ) {
		$ids = array();
		if ( $has_hint ) {
			$ids[] = self::field_id( $field, $suffix ) . '-hint';
		}
		if ( isset( self::$errors[ $field ] ) ) {
			$ids[] = self::field_id( $field, $suffix ) . '-error';
			echo ' aria-invalid="true"';
		}
		if ( ! empty( $ids ) ) {
			echo ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"';
		}
	}

	/**
	 * 補足とエラーメッセージを出力する。
	 *
	 * @param string $field  項目名.
	 * @param string $suffix id の接尾辞.
	 * @param string $hint   補足.
	 * @return void
	 */
	private static function render_messages( string $field, string $suffix, string $hint ) {
		$id = self::field_id( $field, $suffix );

		if ( '' !== $hint ) {
			echo '<p class="mvim-hint" id="' . esc_attr( $id . '-hint' ) . '">' . esc_html( $hint ) . '</p>';
		}
		if ( isset( self::$errors[ $field ] ) ) {
			echo '<p class="mvim-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( self::$errors[ $field ] ) . '</p>';
		}
	}

	/**
	 * 項目の外枠の class。
	 *
	 * @param string $field 項目名.
	 * @return string
	 */
	private static function field_class( string $field ): string {
		$class = 'mvim-field mvim-field--' . str_replace( '_', '-', $field );

		if ( isset( self::$errors[ $field ] ) ) {
			$class .= ' mvim-field--has-error';
		}

		return $class;
	}

	/**
	 * 入力欄の id。
	 *
	 * @param string $field  項目名.
	 * @param string $suffix id の接尾辞.
	 * @return string
	 */
	private static function field_id( string $field, string $suffix ): string {
		return 'mvim-' . str_replace( '_', '-', $field ) . $suffix;
	}

	/**
	 * 「N文字以内」の補足。
	 *
	 * @param int $max 最大文字数.
	 * @return string
	 */
	private static function max_hint( int $max ): string {
		return sprintf(
			/* translators: %d: 最大文字数 */
			__( '%d文字以内', 'mervelta-inquiry-manager' ),
			$max
		);
	}
}
