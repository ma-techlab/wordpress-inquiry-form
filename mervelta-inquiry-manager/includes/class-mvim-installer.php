<?php
/**
 * テーブルの作成と、DBの版の管理。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 有効化時と更新時に dbDelta() でテーブルを作る（FR-3、FR-9）。
 */
final class MVIM_Installer {

	/**
	 * DBの版を記録するオプション名。
	 */
	const DB_VERSION_OPTION = 'mvim_db_version';

	/**
	 * 問い合わせテーブル名（接頭辞なしの部分）。
	 */
	const TABLE_SUFFIX = 'mvim_inquiries';

	/**
	 * 問い合わせテーブルの完全な名前を返す。
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * 有効化フック。
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * 記録された版がコードの版と違うときだけ、テーブルを作り直す。
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== MVIM_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * テーブルを作成（または差分を反映）し、DBの版を記録する。
	 *
	 * DATETIME に既定値を付けないのは、厳格な SQL モード（NO_ZERO_DATE）で
	 * '0000-00-00 00:00:00' が拒否されるため。挿入時に必ず値を渡す。
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() の書式に合わせる：1行に1列、PRIMARY KEY の後は空白2つ、KEY を使う.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  inquiry_type varchar(20) NOT NULL DEFAULT '',
  company_name varchar(100) NOT NULL DEFAULT '',
  contact_name varchar(50) NOT NULL DEFAULT '',
  email varchar(254) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  message text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  staff_note text NOT NULL,
  mail_sent tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, MVIM_DB_VERSION );
	}
}
