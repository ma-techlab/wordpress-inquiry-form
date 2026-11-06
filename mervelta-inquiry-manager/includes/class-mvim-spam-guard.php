<?php
/**
 * 不正送信対策：3秒チェックの署名と、送信回数の制限。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 不正送信対策（FR-2）。nonce とハニーポットは MVIM_Form で確かめる。
 */
final class MVIM_Spam_Guard {

	/**
	 * フォーム表示から送信までに必要な秒数。
	 */
	const MIN_SECONDS = 3;

	/**
	 * 期間内に受け付ける送信の回数。
	 */
	const RATE_LIMIT = 5;

	/**
	 * 送信回数を数える期間（秒）。10分。
	 */
	const RATE_WINDOW = 600;

	/**
	 * 送信回数の transient 名の接頭辞（uninstall.php でも使う）。
	 */
	const TRANSIENT_PREFIX = 'mvim_rl_';

	/**
	 * 時刻の検査結果：問題なし.
	 */
	const TIME_OK = 'ok';

	/**
	 * 時刻の検査結果：3秒未満.
	 */
	const TIME_TOO_FAST = 'too_fast';

	/**
	 * 時刻の検査結果：署名が合わない・値が無い.
	 */
	const TIME_INVALID = 'invalid';

	/**
	 * フォームに埋め込む表示時刻と署名を作る。
	 *
	 * @return array{ts: int, sig: string}
	 */
	public static function issue_timestamp(): array {
		$ts = time();

		return array(
			'ts'  => $ts,
			'sig' => self::sign( 'form_ts|' . $ts ),
		);
	}

	/**
	 * 表示時刻の署名と経過秒数を確かめる。
	 *
	 * @param int    $ts  フォームの表示時刻（UNIX 時刻）.
	 * @param string $sig 署名.
	 * @return string self::TIME_* のいずれか.
	 */
	public static function check_timestamp( int $ts, string $sig ): string {
		if ( $ts <= 0 || '' === $sig ) {
			return self::TIME_INVALID;
		}

		// 時間に依存しない比較で、署名の推測を防ぐ.
		if ( ! hash_equals( self::sign( 'form_ts|' . $ts ), $sig ) ) {
			return self::TIME_INVALID;
		}

		$elapsed = time() - $ts;

		if ( $elapsed < 0 ) {
			return self::TIME_INVALID;
		}

		if ( $elapsed < self::MIN_SECONDS ) {
			return self::TIME_TOO_FAST;
		}

		return self::TIME_OK;
	}

	/**
	 * 送信元を表す transient 名を作る。
	 *
	 * IP アドレスは保存せず、サイト固有の鍵で HMAC を取った値だけを使う（要件書 6章）。
	 * 鍵が無ければ IPv4 の全件を試して元の IP を割り出せるため、ただのハッシュにはしない。
	 * X-Forwarded-For などは偽装できるので使わず、REMOTE_ADDR だけを見る。
	 *
	 * @return string
	 */
	public static function source_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = 'unknown';
		}

		return self::TRANSIENT_PREFIX . substr( self::sign( 'rate|' . $ip ), 0, 40 );
	}

	/**
	 * 次に送信できるまでの秒数を返す。制限にかかっていなければ 0。
	 *
	 * @param string $key self::source_key() の値.
	 * @return int
	 */
	public static function seconds_until_allowed( string $key ): int {
		$window = self::get_window( $key );

		if ( null === $window || $window['count'] < self::RATE_LIMIT ) {
			return 0;
		}

		return max( 1, $window['start'] + self::RATE_WINDOW - time() );
	}

	/**
	 * 送信を1回数える。
	 *
	 * 期間は最初の送信から10分の固定窓。set_transient() は保存のたびに期限を
	 * 付け直すので、最初の時刻を一緒に保存し、残り時間を期限に渡す。
	 *
	 * @param string $key self::source_key() の値.
	 * @return void
	 */
	public static function record_submission( string $key ): void {
		$window = self::get_window( $key ) ?? array(
			'start' => time(),
			'count' => 0,
		);

		++$window['count'];

		set_transient( $key, $window, max( 1, $window['start'] + self::RATE_WINDOW - time() ) );
	}

	/**
	 * 期間内の記録を取り出す。期間が過ぎていれば null。
	 *
	 * @param string $key transient 名.
	 * @return array{start: int, count: int}|null
	 */
	private static function get_window( string $key ): ?array {
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) ) {
			return null;
		}

		$start = (int) $data['start'];

		if ( time() - $start >= self::RATE_WINDOW ) {
			return null;
		}

		return array(
			'start' => $start,
			'count' => (int) $data['count'],
		);
	}

	/**
	 * サイト固有の鍵（wp_salt()）で HMAC-SHA256 を取る。
	 *
	 * @param string $data 署名する文字列.
	 * @return string
	 */
	private static function sign( string $data ): string {
		return hash_hmac( 'sha256', 'mvim|' . $data, wp_salt( 'nonce' ) );
	}
}
