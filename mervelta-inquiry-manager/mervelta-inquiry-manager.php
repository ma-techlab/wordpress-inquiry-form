<?php
/**
 * Plugin Name:       MERVELTA Inquiry Manager
 * Description:       見積依頼・問い合わせの受付と管理（ポートフォリオ用デモ）。
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            ma-techlab
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mervelta-inquiry-manager
 *
 * @package MERVELTA_Inquiry_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MVIM_VERSION', '0.1.0' );
define( 'MVIM_DB_VERSION', '1.0.0' );
define( 'MVIM_PLUGIN_FILE', __FILE__ );
define( 'MVIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MVIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-plugin.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-installer.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-settings.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-fields.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-repository.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-spam-guard.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-mailer.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-form.php';
require_once MVIM_PLUGIN_DIR . 'includes/class-mvim-csv-exporter.php';
require_once MVIM_PLUGIN_DIR . 'includes/admin/class-mvim-admin.php';

// 有効化：テーブルを作る（FR-9）。無効化ではデータを残すため、無効化フックは登録しない.
register_activation_hook( __FILE__, array( 'MVIM_Installer', 'activate' ) );

MVIM_Plugin::init();
