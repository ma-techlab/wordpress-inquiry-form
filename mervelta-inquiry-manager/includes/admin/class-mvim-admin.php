<?php
/**
 * 管理画面のメニュー。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理メニュー「見積依頼」を追加する（FR-5）。
 *
 * 一覧・詳細・更新は段階4で実装する。段階2では、設定画面の親メニューとして使う。
 */
final class MVIM_Admin {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * 詳細画面の URL（通知メールのリンクにも使う）。
	 *
	 * @param int $id 問い合わせ ID.
	 * @return string
	 */
	public static function detail_url( int $id ): string {
		return add_query_arg(
			array(
				'page'   => MVIM_Plugin::MENU_SLUG,
				'action' => 'view',
				'id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * トップメニューと「一覧」サブメニューを追加する。
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			__( '見積依頼', 'mervelta-inquiry-manager' ),
			__( '見積依頼', 'mervelta-inquiry-manager' ),
			MVIM_Plugin::CAPABILITY,
			MVIM_Plugin::MENU_SLUG,
			array( __CLASS__, 'render_list_page' ),
			'dashicons-email-alt',
			26
		);

		// 先頭のサブメニュー名を「見積依頼」から「一覧」に変える.
		add_submenu_page(
			MVIM_Plugin::MENU_SLUG,
			__( '見積依頼', 'mervelta-inquiry-manager' ),
			__( '一覧', 'mervelta-inquiry-manager' ),
			MVIM_Plugin::CAPABILITY,
			MVIM_Plugin::MENU_SLUG,
			array( __CLASS__, 'render_list_page' )
		);
	}

	/**
	 * 一覧画面を出力する（段階4で WP_List_Table に置き換える）。
	 *
	 * @return void
	 */
	public static function render_list_page() {
		if ( ! current_user_can( MVIM_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'mervelta-inquiry-manager' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( '一覧画面は準備中です。', 'mervelta-inquiry-manager' ); ?></p>
		</div>
		<?php
	}
}
