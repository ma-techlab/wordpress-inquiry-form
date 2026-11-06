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
	 * 受付日時の新しい順に、1ページ分を取得する（FR-5、FR-7）。
	 *
	 * @param string $status 絞り込む状態。空文字ならすべて。呼び出し元で選択肢に含まれる値か確かめておく.
	 * @param int    $limit  件数.
	 * @param int    $offset 開始位置.
	 * @param int    $max_id この ID 以下だけを返す。0 なら上限なし（一覧の表示）.
	 *                       CSV 出力では、出力を始めたときの最大 ID を渡し、途中で保存された行で OFFSET がずれないようにする.
	 * @return array<int, array<string, mixed>>
	 */
	public static function find_page( string $status, int $limit, int $offset, int $max_id = 0 ): array {
		global $wpdb;

		$table = MVIM_Installer::table_name();

		// 上限なしのときも同じ SQL を使えるよう、PHP の整数の最大値を上限にする（BIGINT UNSIGNED の ID はこれを超えない想定）.
		$upper = $max_id > 0 ? $max_id : PHP_INT_MAX;

		// 同じ受付日時のときに順番が揺れないよう、id でも並べる。LIMIT・OFFSET も %d で渡す.
		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 独自テーブルの読み取り。管理画面で更新直後の値を見せるため、キャッシュしない。
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id <= %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$upper,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 同上。状態は %s で渡す。
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s AND id <= %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$status,
					$upper,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * いちばん大きい ID を返す。0 件なら 0（CSV 出力の範囲を固定するため）。
	 *
	 * @return int
	 */
	public static function max_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 独自テーブルの読み取り。出力を始めた時点の値が必要なため、キャッシュしない。
		$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', MVIM_Installer::table_name() ) );

		return (int) $max;
	}

	/**
	 * 状態ごとの件数を返す（FR-5 の件数表示）。
	 *
	 * @return array<string, int> 状態 => 件数。'all' に合計を入れる.
	 */
	public static function count_by_status(): array {
		global $wpdb;

		$counts = array( 'all' => 0 );
		foreach ( array_keys( MVIM_Fields::statuses() ) as $status ) {
			$counts[ $status ] = 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 独自テーブルの集計。更新直後の件数を見せるため、キャッシュしない。
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', MVIM_Installer::table_name() ),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];
			$total  = (int) $row['total'];

			// 決められた状態だけを数える（想定外の値があっても表示を崩さない）.
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = $total;
			}
			$counts['all'] += $total;
		}

		return $counts;
	}

	/**
	 * 状態と管理メモを更新し、updated_at を今の日時にする（FR-6）。
	 *
	 * @param int    $id         問い合わせ ID.
	 * @param string $status     状態。呼び出し元で選択肢に含まれる値か確かめておく.
	 * @param string $staff_note 管理メモ。呼び出し元で文字数を確かめておく.
	 * @return bool
	 */
	public static function update_status_note( int $id, string $status, string $staff_note ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 独自テーブルの更新。$wpdb->update() に書式を渡す。キャッシュは使っていない。
		$result = $wpdb->update(
			MVIM_Installer::table_name(),
			array(
				'status'     => $status,
				'staff_note' => $staff_note,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
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
