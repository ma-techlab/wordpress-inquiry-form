<?php
/**
 * 管理画面：メニュー、一覧、詳細と更新。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理メニュー「見積依頼」と、一覧（FR-5）・詳細と更新（FR-6）。
 *
 * 更新は、この画面の load- フック（HTML を出す前）で受け取る。
 * 入力エラーのときは同じリクエストのまま値とエラーを表示し、成功したときだけ詳細へリダイレクトする（PRG）。
 */
final class MVIM_Admin {

	/**
	 * 更新フォームであることを示す隠し項目の値.
	 */
	const UPDATE_ACTION = 'update_inquiry';

	/**
	 * 更新の nonce のアクション名の接頭辞（後ろに対象の ID を付ける）.
	 */
	const UPDATE_NONCE_PREFIX = 'mvim_update_inquiry_';

	/**
	 * 更新の nonce の項目名.
	 */
	const UPDATE_NONCE_NAME = 'mvim_update_nonce';

	/**
	 * 更新成功を示す URL の目印.
	 */
	const UPDATED_QUERY_ARG = 'mvim_updated';

	/**
	 * 更新時のエラー（項目名 => メッセージ）.
	 *
	 * @var array<string, string>
	 */
	private static $update_errors = array();

	/**
	 * 更新時に再表示する値.
	 *
	 * @var array<string, string>
	 */
	private static $update_values = array();

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
	 * 一覧画面の URL。
	 *
	 * @return string
	 */
	public static function list_url(): string {
		return add_query_arg( 'page', MVIM_Plugin::MENU_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * URL の status を読み、決められた値（new / in_progress / done）でなければ空文字（すべて）にする。
	 *
	 * 一覧の絞り込みと CSV 出力で同じ方法を使う。
	 *
	 * @return string
	 */
	public static function requested_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 絞り込みの値を読むだけ（読み取り専用）。CSV 出力では、呼び出し元で先に nonce を検証している。値は下で選択肢と照合する。
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return array_key_exists( $status, MVIM_Fields::statuses() ) ? $status : '';
	}

	/**
	 * トップメニューと「一覧」サブメニューを追加する。
	 *
	 * @return void
	 */
	public static function add_menu() {
		$hook = add_menu_page(
			__( '見積依頼', 'mervelta-inquiry-manager' ),
			__( '見積依頼', 'mervelta-inquiry-manager' ),
			MVIM_Plugin::CAPABILITY,
			MVIM_Plugin::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
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
			array( __CLASS__, 'render_page' )
		);

		// この画面を開くときだけ、HTML を出す前に更新を受け取る.
		add_action( 'load-' . $hook, array( __CLASS__, 'maybe_handle_update' ) );
	}

	/**
	 * 更新フォームの送信のときだけ処理する。
	 *
	 * @return void
	 */
	public static function maybe_handle_update() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- この画面の更新フォームかを見分けるだけ。nonce は handle_update() で、対象の ID を含むアクション名で検証する。
		$action = isset( $_POST['mvim_admin_action'] ) ? sanitize_key( wp_unslash( $_POST['mvim_admin_action'] ) ) : '';
		if ( self::UPDATE_ACTION !== $action ) {
			return;
		}

		self::handle_update();
	}

	/**
	 * 状態と管理メモを更新する（FR-6）。
	 *
	 * 順番：権限 → nonce（対象の ID を含む）→ 対象の存在 → 値の検証 → 更新 → 詳細へリダイレクト。
	 *
	 * @return void
	 */
	private static function handle_update() {
		if ( ! current_user_can( MVIM_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'mervelta-inquiry-manager' ), 403 );
		}

		// Nonce のアクション名に含める ID を読むため、検証の直前に読む。整数に変換する.
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		// ID を含むアクション名なので、ある問い合わせのフォームの nonce を別の ID の更新に流用できない.
		check_admin_referer( self::UPDATE_NONCE_PREFIX . $id, self::UPDATE_NONCE_NAME );

		if ( 0 === $id || null === MVIM_Repository::find( $id ) ) {
			wp_die( esc_html__( '対象の見積依頼・問い合わせが見つかりません。', 'mervelta-inquiry-manager' ), 404 );
		}

		$status = isset( $_POST['mvim_status'] ) ? sanitize_key( wp_unslash( $_POST['mvim_status'] ) ) : '';
		$note   = isset( $_POST['mvim_staff_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mvim_staff_note'] ) ) : '';
		$note   = MVIM_Fields::normalize_newlines( $note );

		self::$update_values = array(
			'status'     => $status,
			'staff_note' => $note,
		);

		if ( ! array_key_exists( $status, MVIM_Fields::statuses() ) ) {
			self::$update_errors['status'] = __( '状態を選択肢から選んでください。', 'mervelta-inquiry-manager' );
		}

		$length = mb_strlen( $note );
		if ( $length > MVIM_Fields::MAX_STAFF_NOTE ) {
			self::$update_errors['staff_note'] = MVIM_Fields::length_message( __( '管理メモ', 'mervelta-inquiry-manager' ), MVIM_Fields::MAX_STAFF_NOTE, $length );
		}

		if ( ! empty( self::$update_errors ) ) {
			return;
		}

		if ( ! MVIM_Repository::update_status_note( $id, $status, $note ) ) {
			self::$update_errors['general'] = __( '更新に失敗しました。時間をおいて、もう一度お試しください。', 'mervelta-inquiry-manager' );
			return;
		}

		wp_safe_redirect( add_query_arg( self::UPDATED_QUERY_ARG, '1', self::detail_url( $id ) ), 303 );
		exit;
	}

	/**
	 * 一覧または詳細を出力する。
	 *
	 * @return void
	 */
	public static function render_page() {
		// メニューの権限指定に加え、出力の直前でも権限を確認する（要件書 8章）.
		if ( ! current_user_can( MVIM_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'mervelta-inquiry-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 画面の切り替え（読み取りだけ）。
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示する ID（読み取りだけ）。整数に変換する。
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( 'view' === $action && $id > 0 ) {
			self::render_detail( $id );
			return;
		}

		self::render_list();
	}

	/**
	 * 一覧画面。
	 *
	 * @return void
	 */
	private static function render_list() {
		require_once MVIM_PLUGIN_DIR . 'includes/admin/class-mvim-list-table.php';

		$table = new MVIM_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( '見積依頼', 'mervelta-inquiry-manager' ); ?></h1>
			<a href="<?php echo esc_url( MVIM_Csv_Exporter::url( $table->get_status() ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'CSVを書き出す（今の絞り込み条件）', 'mervelta-inquiry-manager' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( MVIM_Plugin::MENU_SLUG ); ?>">
				<?php if ( '' !== $table->get_status() ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $table->get_status() ); ?>">
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * 詳細画面と、状態・管理メモの更新フォーム。
	 *
	 * @param int $id 問い合わせ ID.
	 * @return void
	 */
	private static function render_detail( int $id ) {
		$row = MVIM_Repository::find( $id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '見積依頼の詳細', 'mervelta-inquiry-manager' ); ?></h1>
			<p><a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( '一覧に戻る', 'mervelta-inquiry-manager' ); ?></a></p>

			<?php
			if ( null === $row ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( '対象の見積依頼・問い合わせが見つかりません。', 'mervelta-inquiry-manager' ) . '</p></div>';
			} else {
				self::render_detail_notices();
				self::render_detail_table( $row );
				self::render_update_form( $row );
			}
			?>
		</div>
		<?php
	}

	/**
	 * 更新の結果を表示する。
	 *
	 * @return void
	 */
	private static function render_detail_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 「更新しました」を出す目印だけで、データは変えない。
		$updated = isset( $_GET[ self::UPDATED_QUERY_ARG ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::UPDATED_QUERY_ARG ] ) );

		if ( ! empty( self::$update_errors ) ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( '更新できませんでした。', 'mervelta-inquiry-manager' ); ?></p>
				<ul>
					<?php foreach ( self::$update_errors as $message ) : ?>
						<li><?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		} elseif ( $updated ) {
			?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '更新しました。', 'mervelta-inquiry-manager' ); ?></p></div>
			<?php
		}
	}

	/**
	 * 全項目の表示（FR-6）。
	 *
	 * @param array<string, mixed> $row 行.
	 * @return void
	 */
	private static function render_detail_table( array $row ) {
		$types    = MVIM_Fields::inquiry_types();
		$statuses = MVIM_Fields::statuses();
		$labels   = MVIM_Fields::field_labels();
		$type     = (string) $row['inquiry_type'];
		$status   = (string) $row['status'];
		$email    = (string) $row['email'];
		?>
		<table class="widefat striped mvim-detail" style="max-width:60rem;">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'ID', 'mervelta-inquiry-manager' ); ?></th><td><?php echo esc_html( (string) (int) $row['id'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( '受付日時', 'mervelta-inquiry-manager' ); ?></th><td><?php echo esc_html( mysql2date( 'Y/m/d H:i:s', (string) $row['created_at'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( '最終更新日時', 'mervelta-inquiry-manager' ); ?></th><td><?php echo esc_html( mysql2date( 'Y/m/d H:i:s', (string) $row['updated_at'] ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html( $labels['inquiry_type'] ); ?></th><td><?php echo esc_html( $types[ $type ] ?? $type ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html( $labels['company_name'] ); ?></th><td><?php echo esc_html( (string) $row['company_name'] ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html( $labels['contact_name'] ); ?></th><td><?php echo esc_html( (string) $row['contact_name'] ); ?></td></tr>
				<tr>
					<th scope="row"><?php echo esc_html( $labels['email'] ); ?></th>
					<td>
						<?php if ( is_email( $email ) ) : ?>
							<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $email ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr><th scope="row"><?php echo esc_html( $labels['phone'] ); ?></th><td><?php echo esc_html( (string) $row['phone'] ); ?></td></tr>
				<tr>
					<th scope="row"><?php echo esc_html( $labels['message'] ); ?></th>
					<td style="white-space:pre-wrap;"><?php echo esc_html( (string) $row['message'] ); ?></td>
				</tr>
				<tr><th scope="row"><?php esc_html_e( '状態', 'mervelta-inquiry-manager' ); ?></th><td><?php echo esc_html( $statuses[ $status ] ?? $status ); ?></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( '通知メール', 'mervelta-inquiry-manager' ); ?></th>
					<td>
						<?php if ( 1 === (int) $row['mail_sent'] ) : ?>
							<?php esc_html_e( '送信済み', 'mervelta-inquiry-manager' ); ?>
						<?php else : ?>
							<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( '通知失敗', 'mervelta-inquiry-manager' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '管理メモ', 'mervelta-inquiry-manager' ); ?></th>
					<td style="white-space:pre-wrap;"><?php echo esc_html( (string) $row['staff_note'] ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * 状態と管理メモの更新フォーム。
	 *
	 * @param array<string, mixed> $row 行.
	 * @return void
	 */
	private static function render_update_form( array $row ) {
		$id     = (int) $row['id'];
		$status = self::$update_values['status'] ?? (string) $row['status'];
		$note   = self::$update_values['staff_note'] ?? (string) $row['staff_note'];
		?>
		<h2><?php esc_html_e( '対応状況の更新', 'mervelta-inquiry-manager' ); ?></h2>
		<form method="post" action="<?php echo esc_url( self::detail_url( $id ) ); ?>">
			<input type="hidden" name="mvim_admin_action" value="<?php echo esc_attr( self::UPDATE_ACTION ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( self::UPDATE_NONCE_PREFIX . $id, self::UPDATE_NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mvim-status"><?php esc_html_e( '状態', 'mervelta-inquiry-manager' ); ?></label></th>
					<td>
						<select id="mvim-status" name="mvim_status">
							<?php foreach ( MVIM_Fields::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mvim-staff-note"><?php esc_html_e( '管理メモ', 'mervelta-inquiry-manager' ); ?></label></th>
					<td>
						<textarea id="mvim-staff-note" name="mvim_staff_note" rows="6" class="large-text" aria-describedby="mvim-staff-note-hint"><?php echo esc_textarea( $note ); ?></textarea>
						<p class="description" id="mvim-staff-note-hint">
							<?php
							printf(
								/* translators: %d: 最大文字数 */
								esc_html__( '%d文字以内。訪問者には表示されません。', 'mervelta-inquiry-manager' ),
								(int) MVIM_Fields::MAX_STAFF_NOTE
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '更新する', 'mervelta-inquiry-manager' ) ); ?>
		</form>
		<?php
	}
}
