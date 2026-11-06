<?php
/**
 * 削除時の後片付け（FR-9）：テーブル・オプション・送信回数の transient を消す。
 *
 * シングルサイトのみ対象（マルチサイトは要件書 3章で対象外）。
 *
 * @package MERVELTA_Inquiry_Manager
 */

// WordPress の削除処理から呼ばれたときだけ動かす.
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$mvim_table = $wpdb->prefix . 'mvim_inquiries';

// テーブル名は %i（識別子）で渡す。DB構造の変更とキャッシュ不要は、削除処理として意図どおり.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- 削除時に自プラグインのテーブルを消すため。WordPressにテーブル削除のAPIはない。
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $mvim_table ) );

delete_option( 'mvim_db_version' );
delete_option( 'mvim_notify_email' );

// 送信回数の transient（mvim_rl_*）は名前に IP のハッシュを含み、名前を列挙できないため LIKE で消す.
// 永続オブジェクトキャッシュを使うサイトでは options テーブルに無いが、期限（10分）で消える.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 接頭辞で一括削除する WordPress の API がないため。削除処理なのでキャッシュも不要。
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_mvim_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mvim_rl_' ) . '%'
	)
);
