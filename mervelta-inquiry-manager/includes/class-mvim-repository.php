<?php
/**
 * 問い合わせテーブルへの読み書き。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SQL はすべてこのクラスに置く（要件書 8章：prepare() と、insert()/update() の書式指定）。
 */
final class MVIM_Repository {

	/**
	 * 問い合わせを1件保存する（FR-3）。
	 *
	 * @param array<string, mixed> $values MVIM_Fields::validate_submission() で検証済みの値.
	 * @return int 保存した ID。失敗したら 0.
	 */
	public static function insert( array $values ): int {
		global $wpdb;

		// 受付日時はサイトのタイムゾーン（要件書 6章）.
		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- 独自テーブルへの保存。WordPressに独自テーブル用のAPIはなく、$wpdb->insert() に書式を渡して安全に保存する。
		$result = $wpdb->insert(
			MVIM_Installer::table_name(),
			array(
				'inquiry_type' => (string) $values['inquiry_type'],
				'company_name' => (string) $values['company_name'],
				'contact_name' => (string) $values['contact_name'],
				'email'        => (string) $values['email'],
				'phone'        => (string) $values['phone'],
				'message'      => (string) $values['message'],
				'status'       => 'new',
				'staff_note'   => '',
				'mail_sent'    => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * ID で1件取得する。
	 *
	 * @param int $id 問い合わせ ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 独自テーブルの読み取り。保存・更新の直後に読むため、キャッシュで古い値を返さないようにする。
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', MVIM_Installer::table_name(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * 通知メールの送信に成功したことを記録する（FR-4）。
	 *
	 * 送信結果はシステムの記録なので、updated_at（管理者による最終更新）は変えない。
	 *
	 * @param int $id 問い合わせ ID.
	 * @return bool
	 */
	public static function mark_mail_sent( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 独自テーブルの更新。$wpdb->update() に書式を渡す。キャッシュは使っていない。
		$result = $wpdb->update(
			MVIM_Installer::table_name(),
			array( 'mail_sent' => 1 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}
}
