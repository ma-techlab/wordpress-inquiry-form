<?php
/**
 * CSV出力。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 一覧の絞り込み条件のまま CSV を書き出す（FR-7）。
 *
 * 管理画面の HTML が出力される前に処理できるよう、admin-post.php で受ける。
 */
final class MVIM_Csv_Exporter {

	/**
	 * Admin-post.php の action 名と nonce のアクション名（CSV 専用）.
	 */
	const ACTION = 'mvim_export_csv';

	/**
	 * 一度に読む件数（全件を一度にメモリへ載せない）.
	 */
	const CHUNK = 500;

	/**
	 * 先頭にあると表計算ソフトが数式として扱う文字（CSVインジェクション対策）.
	 */
	const FORMULA_CHARS = array( '=', '+', '-', '@', "\t", "\r", "\n" );

	/**
	 * フックを登録する。ログインしていない人用（nopriv）は登録しない。
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * CSV 出力の URL（nonce 付き）。
	 *
	 * @param string $status 絞り込み中の状態。空文字ならすべて.
	 * @return string
	 */
	public static function url( string $status ): string {
		$args = array( 'action' => self::ACTION );
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * CSV を出力して終了する。
	 *
	 * @return void
	 */
	public static function handle() {
		// 権限 → nonce の順に確かめる。nonce だけでは守らない（要件書 8章）.
		if ( ! current_user_can( MVIM_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'CSVを出力する権限がありません。', 'mervelta-inquiry-manager' ), 403 );
		}
		check_admin_referer( self::ACTION );

		// 絞り込みは一覧と同じ方法で読み、決められた値だけを受け付ける.
		$status = MVIM_Admin::requested_status();

		if ( headers_sent() ) {
			wp_die( esc_html__( 'CSVを出力できませんでした。', 'mervelta-inquiry-manager' ), 500 );
		}

		// ほかの処理が出力をためていれば捨て、ヘッダーより前に何も出さない.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$filename = 'inquiries-' . wp_date( 'Ymd' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// ファイルではなく出力（php://output）へ書く。WP_Filesystem は出力先に使えない.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// Excel で文字化けしないよう、UTF-8 の BOM を付ける.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- 出力（php://output）へ書くため。WP_Filesystem は出力先に使えない。
		fwrite( $out, "\xEF\xBB\xBF" );

		self::put_row( $out, self::header_row() );

		// 出力を始めたときの最大 ID を1回だけ読み、それ以下に固定する.
		// 途中で新しい依頼が保存されても、OFFSET がずれて同じ行が2回出ないようにするため.
		$max_id = MVIM_Repository::max_id();
		if ( 0 === $max_id ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 出力（php://output）を閉じるため。WP_Filesystem は出力先に使えない。
			exit;
		}

		$offset = 0;
		do {
			$rows    = MVIM_Repository::find_page( $status, self::CHUNK, $offset, $max_id );
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				self::put_row( $out, self::to_csv_row( $row ) );
			}
			$offset += self::CHUNK;
		} while ( self::CHUNK === $fetched );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 出力（php://output）を閉じるため。WP_Filesystem は出力先に使えない。
		fclose( $out );
		exit;
	}

	/**
	 * 見出し行。
	 *
	 * @return array<int, string>
	 */
	private static function header_row(): array {
		return array(
			__( 'ID', 'mervelta-inquiry-manager' ),
			__( '受付日時', 'mervelta-inquiry-manager' ),
			__( '種別', 'mervelta-inquiry-manager' ),
			__( '会社名', 'mervelta-inquiry-manager' ),
			__( '担当者名', 'mervelta-inquiry-manager' ),
			__( 'メール', 'mervelta-inquiry-manager' ),
			__( '電話', 'mervelta-inquiry-manager' ),
			__( '内容', 'mervelta-inquiry-manager' ),
			__( '状態', 'mervelta-inquiry-manager' ),
			__( '管理メモ', 'mervelta-inquiry-manager' ),
		);
	}

	/**
	 * 1件を CSV の1行にする。種別と状態は日本語名で出す。
	 *
	 * @param array<string, mixed> $row 行.
	 * @return array<int, string>
	 */
	private static function to_csv_row( array $row ): array {
		$types    = MVIM_Fields::inquiry_types();
		$statuses = MVIM_Fields::statuses();
		$type     = (string) $row['inquiry_type'];
		$status   = (string) $row['status'];

		return array(
			(string) (int) $row['id'],
			(string) $row['created_at'],
			$types[ $type ] ?? $type,
			(string) $row['company_name'],
			(string) $row['contact_name'],
			(string) $row['email'],
			(string) $row['phone'],
			(string) $row['message'],
			$statuses[ $status ] ?? $status,
			(string) $row['staff_note'],
		);
	}

	/**
	 * 数式として扱われる文字で始まる値の先頭に ' を付ける。
	 *
	 * @param string $value 値.
	 * @return string
	 */
	public static function neutralize( string $value ): string {
		if ( '' !== $value && in_array( $value[0], self::FORMULA_CHARS, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * 1行を書き出す。
	 *
	 * Escape 引数は省略せず '' を渡す（PHP 8.4 以降、省略すると非推奨の警告が出るため）。
	 * '' にすると、値の中の " は "" に二重化されるだけになり、RFC 4180 の形になる。
	 *
	 * @param resource           $out    出力先.
	 * @param array<int, string> $fields 値.
	 * @return void
	 */
	private static function put_row( $out, array $fields ): void {
		fputcsv( $out, array_map( array( __CLASS__, 'neutralize' ), $fields ), ',', '"', '' );
	}
}
