<?php
/**
 * 項目の定義（種別・状態の選択肢、項目名、文字数）と、訪問者の入力の検証。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 項目の定義と入力の検証（要件書 6章、FR-1）。
 */
final class MVIM_Fields {

	const MAX_COMPANY_NAME = 100;
	const MAX_CONTACT_NAME = 50;
	const MAX_EMAIL        = 254;
	const MAX_PHONE        = 20;
	const MAX_MESSAGE      = 2000;
	const MAX_STAFF_NOTE   = 1000;

	/**
	 * 種別の選択肢（値 => 表示名）。
	 *
	 * @return array<string, string>
	 */
	public static function inquiry_types(): array {
		return array(
			'estimate' => __( '見積依頼', 'mervelta-inquiry-manager' ),
			'question' => __( '問い合わせ', 'mervelta-inquiry-manager' ),
			'other'    => __( 'その他', 'mervelta-inquiry-manager' ),
		);
	}

	/**
	 * 対応状況の選択肢（値 => 表示名）。
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'new'         => __( '未対応', 'mervelta-inquiry-manager' ),
			'in_progress' => __( '対応中', 'mervelta-inquiry-manager' ),
			'done'        => __( '完了', 'mervelta-inquiry-manager' ),
		);
	}

	/**
	 * 項目の表示名。
	 *
	 * @return array<string, string>
	 */
	public static function field_labels(): array {
		return array(
			'inquiry_type' => __( '種別', 'mervelta-inquiry-manager' ),
			'company_name' => __( '会社名', 'mervelta-inquiry-manager' ),
			'contact_name' => __( '担当者名', 'mervelta-inquiry-manager' ),
			'email'        => __( 'メールアドレス', 'mervelta-inquiry-manager' ),
			'phone'        => __( '電話番号', 'mervelta-inquiry-manager' ),
			'message'      => __( '内容', 'mervelta-inquiry-manager' ),
			'consent'      => __( '個人情報の取扱いへの同意', 'mervelta-inquiry-manager' ),
		);
	}

	/**
	 * 訪問者の入力を整え、検証する。
	 *
	 * 受け取る値は、呼び出し元で sanitize_key() / sanitize_text_field() /
	 * sanitize_textarea_field() を通したもの。ここでは文字数・形式・選択値を確かめる。
	 *
	 * @param array<string, mixed> $input 無害化済みの入力.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate_submission( array $input ): array {
		$labels = self::field_labels();

		$values = array(
			'inquiry_type' => (string) ( $input['inquiry_type'] ?? '' ),
			'company_name' => trim( (string) ( $input['company_name'] ?? '' ) ),
			'contact_name' => trim( (string) ( $input['contact_name'] ?? '' ) ),
			'email'        => trim( (string) ( $input['email'] ?? '' ) ),
			'phone'        => self::normalize_phone( (string) ( $input['phone'] ?? '' ) ),
			'message'      => self::normalize_newlines( (string) ( $input['message'] ?? '' ) ),
			'consent'      => ! empty( $input['consent'] ),
		);

		$errors = array();

		if ( ! array_key_exists( $values['inquiry_type'], self::inquiry_types() ) ) {
			$values['inquiry_type'] = '';
			$errors['inquiry_type'] = sprintf(
				/* translators: %s: 項目名 */
				__( '%sを選択してください。', 'mervelta-inquiry-manager' ),
				$labels['inquiry_type']
			);
		}

		$message = self::check_required_length( $labels['company_name'], $values['company_name'], self::MAX_COMPANY_NAME );
		if ( '' !== $message ) {
			$errors['company_name'] = $message;
		}

		$message = self::check_required_length( $labels['contact_name'], $values['contact_name'], self::MAX_CONTACT_NAME );
		if ( '' !== $message ) {
			$errors['contact_name'] = $message;
		}

		$message = self::check_required_length( $labels['email'], $values['email'], self::MAX_EMAIL );
		if ( '' !== $message ) {
			$errors['email'] = $message;
		} elseif ( ! is_email( $values['email'] ) ) {
			$errors['email'] = sprintf(
				/* translators: %s: 項目名 */
				__( '%sの形式が正しくありません（例：taro@example.com）。', 'mervelta-inquiry-manager' ),
				$labels['email']
			);
		} else {
			$values['email'] = sanitize_email( $values['email'] );
		}

		if ( '' !== $values['phone'] ) {
			if ( mb_strlen( $values['phone'] ) > self::MAX_PHONE ) {
				$errors['phone'] = self::length_message( $labels['phone'], self::MAX_PHONE, mb_strlen( $values['phone'] ) );
			} elseif ( 1 !== preg_match( '/\A[0-9()+\- ]+\z/', $values['phone'] ) ) {
				$errors['phone'] = sprintf(
					/* translators: %s: 項目名 */
					__( '%sは、数字・ハイフン・括弧・＋・空白で入力してください。', 'mervelta-inquiry-manager' ),
					$labels['phone']
				);
			}
		}

		$message = self::check_required_length( $labels['message'], $values['message'], self::MAX_MESSAGE );
		if ( '' !== $message ) {
			$errors['message'] = $message;
		}

		if ( ! $values['consent'] ) {
			$errors['consent'] = sprintf(
				/* translators: %s: 項目名 */
				__( '%sにチェックを入れてください。', 'mervelta-inquiry-manager' ),
				$labels['consent']
			);
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * 電話番号の全角文字を半角に直す（ご返答 5）。
	 *
	 * 全角の数字・記号・空白は mb_convert_kana() で、日本語入力で混ざりやすい
	 * 長音記号やダッシュ類はハイフンに置き換える。
	 *
	 * @param string $phone 電話番号.
	 * @return string
	 */
	public static function normalize_phone( string $phone ): string {
		if ( function_exists( 'mb_convert_kana' ) ) {
			$phone = mb_convert_kana( $phone, 'as', 'UTF-8' );
		}

		$phone = str_replace( array( 'ー', 'ｰ', '‐', '‑', '–', '—', '−' ), '-', $phone );

		return trim( $phone );
	}

	/**
	 * 改行を LF にそろえる（文字数の数え方をブラウザの表示とそろえるため）。
	 *
	 * @param string $text 文字列.
	 * @return string
	 */
	public static function normalize_newlines( string $text ): string {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	}

	/**
	 * 必須と文字数を確かめ、誤りがあればメッセージを返す。
	 *
	 * @param string $label 項目名.
	 * @param string $value 値.
	 * @param int    $max   最大文字数.
	 * @return string 誤りがなければ空文字.
	 */
	public static function check_required_length( string $label, string $value, int $max ): string {
		if ( '' === $value ) {
			return sprintf(
				/* translators: %s: 項目名 */
				__( '%sを入力してください。', 'mervelta-inquiry-manager' ),
				$label
			);
		}

		$length = mb_strlen( $value );
		if ( $length > $max ) {
			return self::length_message( $label, $max, $length );
		}

		return '';
	}

	/**
	 * 文字数超過のメッセージ。
	 *
	 * @param string $label  項目名.
	 * @param int    $max    最大文字数.
	 * @param int    $length 現在の文字数.
	 * @return string
	 */
	public static function length_message( string $label, int $max, int $length ): string {
		return sprintf(
			/* translators: 1: 項目名, 2: 最大文字数, 3: 現在の文字数 */
			__( '%1$sは%2$d文字以内で入力してください（現在%3$d文字）。', 'mervelta-inquiry-manager' ),
			$label,
			$max,
			$length
		);
	}
}
