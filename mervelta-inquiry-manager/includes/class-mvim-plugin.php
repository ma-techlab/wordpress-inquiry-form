<?php
/**
 * プラグイン全体のフック登録をまとめるクラス。
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フック登録の入口。
 */
final class MVIM_Plugin {

	/**
	 * 管理画面・更新・CSV出力に必要な権限（要件書 8章）。
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * 管理画面のトップメニューのスラッグ。
	 */
	const MENU_SLUG = 'mvim-inquiries';

	/**
	 * 各機能のフックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// ZIPでの上書き更新では有効化フックが動かないため、DBの版を毎回比べる.
		add_action( 'plugins_loaded', array( 'MVIM_Installer', 'maybe_upgrade' ) );

		MVIM_Form::register_hooks();

		if ( is_admin() ) {
			MVIM_Admin::register_hooks();
			MVIM_Settings::register_hooks();
		}
	}
}
