<?php
/**
 * 管理者への通知メール。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保存に成功した問い合わせを wp_mail() で通知する（FR-4）。
 */
final class MVIM_Mailer {

	/**
	 * 通知メールを送る。
	 *
	 * @param array<string, mixed> $row MVIM_Repository::find() で取得した1件.
	 * @return bool wp_mail() の結果.
	 */
	public static function send_notification( array $row ): bool {
		$id      = (int) $row['id'];
		$types   = MVIM_Fields::inquiry_types();
		$labels  = MVIM_Fields::field_labels();
		$type    = (string) $row['inquiry_type'];
		$type    = self::one_line( $types[ $type ] ?? $type );
		$company = self::one_line( (string) $row['company_name'] );
		$phone   = self::one_line( (string) $row['phone'] );

		// 件名とヘッダーに入れる値は改行・制御文字を除く（ヘッダーインジェクション対策）.
		$subject = sprintf(
			/* translators: 1: 種別, 2: 会社名 */
			__( '【%1$s】%2$s 様より', 'mervelta-inquiry-manager' ),
			$type,
			$company
		);

		// 本文は text/plain。1行の項目は改行を除き、「内容」だけ改行を残す.
		$lines = array(
			__( 'Webサイトのフォームから、新しい見積依頼・問い合わせが届きました。', 'mervelta-inquiry-manager' ),
			'',
			self::line( __( '受付番号', 'mervelta-inquiry-manager' ), (string) $id ),
			self::line( __( '受付日時', 'mervelta-inquiry-manager' ), (string) $row['created_at'] ),
			self::line( $labels['inquiry_type'], $type ),
			self::line( $labels['company_name'], $company ),
			self::line( $labels['contact_name'], (string) $row['contact_name'] ),
			self::line( $labels['email'], (string) $row['email'] ),
			self::line( $labels['phone'], '' !== $phone ? $phone : __( '（未入力）', 'mervelta-inquiry-manager' ) ),
			'',
			$labels['message'] . '：',
			MVIM_Fields::normalize_newlines( (string) $row['message'] ),
			'',
			__( '管理画面で確認する：', 'mervelta-inquiry-manager' ),
			MVIM_Admin::detail_url( $id ),
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		// Reply-To には名前を付けず、検証済みのメールアドレスだけを入れる（ご返答 8）.
		$reply_to = self::one_line( (string) $row['email'] );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return wp_mail( MVIM_Settings::get_notify_email(), $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * 「項目名：値」の1行を作る。値の改行・制御文字は除く。
	 *
	 * @param string $label 項目名.
	 * @param string $value 値.
	 * @return string
	 */
	private static function line( string $label, string $value ): string {
		return $label . '：' . self::one_line( $value );
	}

	/**
	 * 改行（CR・LF）を含む制御文字を空白に置き換え、1行にする。
	 *
	 * @param string $value 値.
	 * @return string
	 */
	private static function one_line( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}
}
