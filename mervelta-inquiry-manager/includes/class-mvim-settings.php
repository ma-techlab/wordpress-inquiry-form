<?php
/**
 * 設定画面（通知先メールアドレス）。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API で通知先メールアドレスを1件保存する（FR-8）。
 */
final class MVIM_Settings {

	/**
	 * 通知先メールアドレスのオプション名。
	 */
	const OPTION_NOTIFY_EMAIL = 'mvim_notify_email';

	/**
	 * Settings API の設定グループ名。
	 */
	const OPTION_GROUP = 'mvim_settings';

	/**
	 * 設定画面のスラッグ。
	 */
	const PAGE_SLUG = 'mvim-settings';

	/**
	 * 同じリクエストでエラーを二重に出さないための目印。
	 *
	 * オプションがまだ無いとき、WordPress は update_option() から add_option() を呼び、
	 * sanitize_callback が2回動く。そのため、エラーの登録は1回だけにする。
	 *
	 * @var bool
	 */
	private static $error_added = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		// トップメニュー（MVIM_Admin、優先度10）の後に追加する.
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 20 );
	}

	/**
	 * 通知先メールアドレスを返す。未設定ならサイトの管理者メールアドレス（FR-4）。
	 *
	 * @return string
	 */
	public static function get_notify_email() {
		$email = get_option( self::OPTION_NOTIFY_EMAIL, '' );

		if ( is_string( $email ) && is_email( $email ) ) {
			return $email;
		}

		return (string) get_option( 'admin_email' );
	}

	/**
	 * 設定・セクション・項目を登録する。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NOTIFY_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_notify_email' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'mvim_section_notify',
			__( '通知', 'mervelta-inquiry-manager' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_NOTIFY_EMAIL,
			__( '通知先メールアドレス', 'mervelta-inquiry-manager' ),
			array( __CLASS__, 'render_notify_email_field' ),
			self::PAGE_SLUG,
			'mvim_section_notify',
			array( 'label_for' => self::OPTION_NOTIFY_EMAIL )
		);
	}

	/**
	 * 通知先メールアドレスを検証する。
	 *
	 * 空欄は「未設定」として受け付ける。形式が誤っていればエラーを出し、元の値を残す。
	 *
	 * @param mixed $value 送信された値.
	 * @return string
	 */
	public static function sanitize_notify_email( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$email = sanitize_email( $value );

		if ( is_email( $email ) ) {
			return $email;
		}

		if ( ! self::$error_added ) {
			add_settings_error(
				self::OPTION_NOTIFY_EMAIL,
				'mvim_invalid_email',
				__( '通知先メールアドレスの形式が正しくありません。変更は保存されていません。', 'mervelta-inquiry-manager' )
			);
			self::$error_added = true;
		}

		return (string) get_option( self::OPTION_NOTIFY_EMAIL, '' );
	}

	/**
	 * 「見積依頼」メニューの下に設定画面を追加する。
	 *
	 * @return void
	 */
	public static function add_page() {
		add_submenu_page(
			MVIM_Plugin::MENU_SLUG,
			__( '見積依頼の設定', 'mervelta-inquiry-manager' ),
			__( '設定', 'mervelta-inquiry-manager' ),
			MVIM_Plugin::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * 通知先メールアドレスの入力欄を出力する。
	 *
	 * @return void
	 */
	public static function render_notify_email_field() {
		$value = (string) get_option( self::OPTION_NOTIFY_EMAIL, '' );
		?>
		<input
			type="email"
			id="<?php echo esc_attr( self::OPTION_NOTIFY_EMAIL ); ?>"
			name="<?php echo esc_attr( self::OPTION_NOTIFY_EMAIL ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			aria-describedby="mvim-notify-email-description"
		>
		<p class="description" id="mvim-notify-email-description">
			<?php
			printf(
				/* translators: %s: サイトの管理者メールアドレス */
				esc_html__( '空欄の場合は、サイトの管理者メールアドレス（%s）に通知します。', 'mervelta-inquiry-manager' ),
				esc_html( (string) get_option( 'admin_email' ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * 設定画面を出力する。
	 *
	 * @return void
	 */
	public static function render_page() {
		// メニューの権限指定に加え、出力の直前でも権限を確認する（要件書 8章）.
		if ( ! current_user_can( MVIM_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'mervelta-inquiry-manager' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
