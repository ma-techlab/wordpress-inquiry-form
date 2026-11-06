<?php
/**
 * 管理画面の一覧（WP_List_Table）。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * 見積依頼・問い合わせの一覧（FR-5）。
 */
final class MVIM_List_Table extends WP_List_Table {

	/**
	 * 1ページの件数.
	 */
	const PER_PAGE = 20;

	/**
	 * 絞り込み中の状態。空文字ならすべて.
	 *
	 * @var string
	 */
	private $status = '';

	/**
	 * 状態ごとの件数.
	 *
	 * @var array<string, int>
	 */
	private $counts = array();

	/**
	 * 初期化。絞り込みの値は、決められた状態だけを受け付ける。
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mvim_inquiry',
				'plural'   => 'mvim_inquiries',
				'ajax'     => false,
			)
		);

		$this->status = MVIM_Admin::requested_status();
	}

	/**
	 * 絞り込み中の状態。
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * 列。
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'created_at'   => __( '受付日時', 'mervelta-inquiry-manager' ),
			'inquiry_type' => __( '種別', 'mervelta-inquiry-manager' ),
			'company_name' => __( '会社名', 'mervelta-inquiry-manager' ),
			'contact_name' => __( '担当者名', 'mervelta-inquiry-manager' ),
			'status'       => __( '状態', 'mervelta-inquiry-manager' ),
		);
	}

	/**
	 * 表示する行を読み込む。受付日時の新しい順、1ページ20件。
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->counts = MVIM_Repository::count_by_status();
		$total        = $this->counts[ '' === $this->status ? 'all' : $this->status ];
		$current_page = $this->get_pagenum();

		$this->items = MVIM_Repository::find_page( $this->status, self::PER_PAGE, ( $current_page - 1 ) * self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * 状態の絞り込みリンクと件数（すべて / 未対応 / 対応中 / 完了）。
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$base  = add_query_arg( 'page', MVIM_Plugin::MENU_SLUG, admin_url( 'admin.php' ) );
		$views = array(
			'all' => self::view_link( $base, __( 'すべて', 'mervelta-inquiry-manager' ), $this->counts['all'] ?? 0, '' === $this->status ),
		);

		foreach ( MVIM_Fields::statuses() as $key => $label ) {
			$views[ $key ] = self::view_link(
				add_query_arg( 'status', $key, $base ),
				$label,
				$this->counts[ $key ] ?? 0,
				$key === $this->status
			);
		}

		return $views;
	}

	/**
	 * 絞り込みリンク1つ分の HTML。
	 *
	 * @param string $url     URL.
	 * @param string $label   表示名.
	 * @param int    $count   件数.
	 * @param bool   $current 選択中か.
	 * @return string
	 */
	private static function view_link( string $url, string $label, int $count, bool $current ): string {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * 0件のときの表示。
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( '見積依頼・問い合わせはありません。', 'mervelta-inquiry-manager' );
	}

	/**
	 * 個別の処理がない列。
	 *
	 * @param array<string, mixed> $item        行.
	 * @param string               $column_name 列名.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * 受付日時。
	 *
	 * @param array<string, mixed> $item 行.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( mysql2date( 'Y/m/d H:i', (string) $item['created_at'] ) );
	}

	/**
	 * 種別（日本語名）。
	 *
	 * @param array<string, mixed> $item 行.
	 * @return string
	 */
	protected function column_inquiry_type( $item ) {
		$types = MVIM_Fields::inquiry_types();
		$type  = (string) $item['inquiry_type'];

		return esc_html( $types[ $type ] ?? $type );
	}

	/**
	 * 会社名。クリックで詳細へ。
	 *
	 * @param array<string, mixed> $item 行.
	 * @return string
	 */
	protected function column_company_name( $item ) {
		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
			esc_url( MVIM_Admin::detail_url( (int) $item['id'] ) ),
			esc_html( (string) $item['company_name'] )
		);
	}

	/**
	 * 状態。通知メールが送れていなければ「通知失敗」と添える（FR-4）。
	 *
	 * @param array<string, mixed> $item 行.
	 * @return string
	 */
	protected function column_status( $item ) {
		$statuses = MVIM_Fields::statuses();
		$status   = (string) $item['status'];
		$html     = esc_html( $statuses[ $status ] ?? $status );

		if ( 0 === (int) $item['mail_sent'] ) {
			$html .= ' <span class="mvim-mail-failed" style="color:#b32d2e;font-weight:600;">' . esc_html__( '（通知失敗）', 'mervelta-inquiry-manager' ) . '</span>';
		}

		return $html;
	}
}
