<?php
/**
 * Plugin Name: Gamb WP Static Guard
 * Plugin URI: https://github.com/gamberine/gamb-wp-static-guard
 * Description: Exportação estática, publicação local, pesquisa no navegador e controles de exposição para WordPress.
 * Version: 4.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Gamb
 * Author URI: https://github.com/gamberine
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: gamb-wp-static-guard
 * Update URI: https://github.com/gamberine/gamb-wp-static-guard
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'GAMB_STATIC_WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GAMB_STATIC_WP_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'GAMB_STATIC_WP_VERSION', '4.1.0' );
foreach ( array( 'options', 'frontend-policy', 'guard-suite', 'backup-service', 'static-engine', 'admin' ) as $module ) {
    require_once GAMB_STATIC_WP_PATH . 'src/class-gamb-' . $module . '.php';
}
function gamb_wp_static_guard_activate() {
    \GambWPStaticGuard\Options::instance()->save();
    \GambWPStaticGuard\Gamb_Security_Suite::maybe_install_mu_plugin();
    \GambWPStaticGuard\Static_Engine::log_activity( 'Gamb WP Static Guard ' . GAMB_STATIC_WP_VERSION . ' ativado. Configurações existentes preservadas.' );
}
register_activation_hook( __FILE__, 'gamb_wp_static_guard_activate' );
function gamb_wp_static_guard_deactivate() { wp_clear_scheduled_hook( 'gamb_static_guard_auto_export_cron' ); }
register_deactivation_hook( __FILE__, 'gamb_wp_static_guard_deactivate' );
add_action( 'plugins_loaded', static function() {
    \GambWPStaticGuard\Frontend_Policy::register();
    \GambWPStaticGuard\Gamb_Security_Suite::get_instance();
    \GambWPStaticGuard\Static_Engine::get_instance();
    \GambWPStaticGuard\Admin_Controller::get_instance();
}, 1 );

/** Downloads administrativos não podem conter saída anterior ao arquivo binário. */
function gamb_wp_static_guard_download( $path, $type ) {
    while ( ob_get_level() > 0 ) { if ( ! @ob_end_clean() ) { break; } }
    header( 'Content-Type: ' . $type );
    header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'Cache-Control: private, no-store' );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $path );
    exit;
}
add_action( 'admin_post_gamb_download_backup', static function() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Acesso não autorizado.' ); }
    $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
    check_admin_referer( 'gamb_download_backup_' . $file );
    $dir = realpath( \GambWPStaticGuard\Backup_Service::get_backup_dir() );
    $path = $dir ? realpath( $dir . DIRECTORY_SEPARATOR . $file ) : false;
    if ( ! $path || dirname( $path ) !== $dir || ! is_file( $path ) || ! preg_match( '/^gamb-(?:db|full)-backup-.*\.(?:zip|sql)$/', $file ) ) { wp_die( 'Pacote não encontrado.' ); }
    gamb_wp_static_guard_download( $path, 'application/octet-stream' );
} );
add_action( 'admin_post_gamb_download_static_zip', static function() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Acesso não autorizado.' ); }
    $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
    check_admin_referer( 'gamb_download_static_' . $file );
    $uploads = wp_upload_dir();
    $dir = realpath( $uploads['basedir'] );
    $path = $dir ? realpath( $dir . DIRECTORY_SEPARATOR . $file ) : false;
    if ( ! $path || dirname( $path ) !== $dir || ! is_file( $path ) || ! preg_match( '/^gamb-static-.*\.zip$/', $file ) ) { wp_die( 'Pacote estático não encontrado.' ); }
    gamb_wp_static_guard_download( $path, 'application/zip' );
} );
