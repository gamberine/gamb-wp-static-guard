<?php
// Exit if accessed directly.
if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Plugin Name:       Gamb WP Static Guard
 * Description:       Plataforma integrada dividida em 1. STATIC (Conversão e Publicação Estática de Tema/Site, Auto Push e Busca Local Fuse.js) e 2. GUARD (Tokenização Criptográfica Universal /v/<token>, Ocultação de Stack, Blindagem HTTP/Cache/REST, Sanitização DOM/WCAG e Backup Estilo Duplicator).
 * Version:           3.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Gamb
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       simply-static
 * Domain Path:       /languages
 */

if ( ! defined( "GAMB_STATIC_WP_PATH" ) ) {
	define( "GAMB_STATIC_WP_PATH", plugin_dir_path( __FILE__ ) );
}
if ( ! defined( "GAMB_STATIC_WP_URL" ) ) {
	define( "GAMB_STATIC_WP_URL", untrailingslashit( plugin_dir_url( __FILE__ ) ) );
}
if ( ! defined( "GAMB_STATIC_WP_VERSION" ) ) {
	define( "GAMB_STATIC_WP_VERSION", "3.0.1" );
}
if ( ! defined( "SIMPLY_STATIC_PATH" ) ) {
	define( "SIMPLY_STATIC_PATH", GAMB_STATIC_WP_PATH );
}
if ( ! defined( "SIMPLY_STATIC_URL" ) ) {
	define( "SIMPLY_STATIC_URL", GAMB_STATIC_WP_URL );
}
if ( ! defined( "SIMPLY_STATIC_VERSION" ) ) {
	define( "SIMPLY_STATIC_VERSION", GAMB_STATIC_WP_VERSION );
}
if ( ! defined( "SIMPLY_STATIC_PRO_VERSION" ) ) {
	define( "SIMPLY_STATIC_PRO_VERSION", GAMB_STATIC_WP_VERSION );
}

if ( ! function_exists( "gamb_wp_static_guard_deactivate_legacy" ) ) {
	/**
	 * Desativa automaticamente plugins anteriores (simply-static e gamb-static-wp) para evitar qualquer colisão.
	 */
	function gamb_wp_static_guard_deactivate_legacy() {
		$legacy = array(
			"simply-static/simply-static.php",
			"simply-static-pro/simply-static-pro.php",
			"gamb-static-wp/gamb-static-wp.php",
		);
		$active = get_option( "active_plugins", array() );
		if ( is_array( $active ) ) {
			$filtered = array_values( array_diff( $active, $legacy ) );
			if ( count( $filtered ) !== count( $active ) ) {
				update_option( "active_plugins", $filtered );
			}
		}
		if ( is_multisite() ) {
			$sitewide = get_site_option( "active_sitewide_plugins", array() );
			if ( is_array( $sitewide ) ) {
				$changed = false;
				foreach ( $legacy as $lp ) {
					if ( isset( $sitewide[ $lp ] ) ) {
						unset( $sitewide[ $lp ] );
						$changed = true;
					}
				}
				if ( $changed ) {
					update_site_option( "active_sitewide_plugins", $sitewide );
				}
			}
		}
	}
}

gamb_wp_static_guard_deactivate_legacy();

// Carrega as classes principais (enxutas, sem arquivos mortos)
require_once GAMB_STATIC_WP_PATH . "src/class-gamb-options.php";
require_once GAMB_STATIC_WP_PATH . "src/class-gamb-guard-suite.php";
require_once GAMB_STATIC_WP_PATH . "src/class-gamb-backup-service.php";
require_once GAMB_STATIC_WP_PATH . "src/class-gamb-static-engine.php";
require_once GAMB_STATIC_WP_PATH . "src/class-gamb-admin.php";

if ( ! function_exists( "gamb_wp_static_guard_activate" ) ) {
	/**
	 * Ações automáticas executadas imediatamente após a instalação e ativação:
	 * 1. Desativa plugins legados conflitantes.
	 * 2. Aplica o Perfil Padrão Seguro de fábrica (Static + Guard).
	 * 3. Instala o MU-Plugin Origin Shield em wp-content/mu-plugins/.
	 * 4. Cria o diretório protegido de backups e gera o Snapshot SQL inicial automático.
	 * 5. Limpa os caches (LiteSpeed Cache e WP Object Cache) para ativar a tokenização /v/<token> imediatamente.
	 */
	function gamb_wp_static_guard_activate() {
		gamb_wp_static_guard_deactivate_legacy();
		\Simply_Static\Gamb_Security_Suite::apply_hardened_profile();

		// Gera snapshot inicial de segurança do banco de dados caso ainda não exista nenhum backup
		$existing_backups = \Simply_Static\Backup_Service::list_backups();
		if ( empty( $existing_backups ) ) {
			$bk = \Simply_Static\Backup_Service::create_backup( "db" );
			if ( is_array( $bk ) && ! empty( $bk["filename"] ) ) {
				\Simply_Static\Static_Engine::log_activity( "Ação automática pós-instalação: Snapshot SQL inicial de segurança criado (" . $bk["filename"] . ")." );
			}
		}
		\Simply_Static\Static_Engine::log_activity( "Plugin Gamb WP Static Guard v3.0.0 ativado com Perfil Padrão Seguro e Tokenização Universal (/v/<token>) em operação." );
	}
}
register_activation_hook( __FILE__, "gamb_wp_static_guard_activate" );

if ( ! function_exists( "gamb_wp_static_guard_deactivate" ) ) {
	function gamb_wp_static_guard_deactivate() {
		wp_clear_scheduled_hook( "gamb_static_guard_auto_export_cron" );
	}
}
register_deactivation_hook( __FILE__, "gamb_wp_static_guard_deactivate" );

// Inicializa os motores 1. STATIC e 2. GUARD
add_action( "plugins_loaded", function() {
	\Simply_Static\Gamb_Security_Suite::get_instance();
	\Simply_Static\Static_Engine::get_instance();
	\Simply_Static\Admin_Controller::get_instance();
}, 1 );

// Download seguro de pacotes de Backup (exclusivo para administradores)
add_action( "admin_post_gamb_download_backup", function() {
	if ( ! current_user_can( "manage_options" ) ) {
		wp_die( "Acesso negado." );
	}
	$file = isset( $_GET["file"] ) ? sanitize_file_name( wp_unslash( $_GET["file"] ) ) : "";
	check_admin_referer( "gamb_download_backup_" . $file );
	$backup_dir = \Simply_Static\Backup_Service::get_backup_dir();
	$path       = trailingslashit( $backup_dir ) . $file;
	if ( ! is_file( $path ) ) {
		wp_die( "Arquivo de backup não encontrado." );
	}
	header( "Content-Type: application/octet-stream" );
	header( "Content-Disposition: attachment; filename=\"" . $file . "\"" );
	header( "Content-Length: " . filesize( $path ) );
	header( "Cache-Control: no-cache, no-store, must-revalidate" );
	@readfile( $path );
	exit;
} );

// Download seguro do pacote ZIP estático exportado
add_action( "admin_post_gamb_download_static_zip", function() {
	if ( ! current_user_can( "manage_options" ) ) {
		wp_die( "Acesso negado." );
	}
	$file = isset( $_GET["file"] ) ? sanitize_file_name( wp_unslash( $_GET["file"] ) ) : "";
	check_admin_referer( "gamb_download_static_" . $file );
	$uploads  = wp_upload_dir();
	$base_dir = isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
	$path     = trailingslashit( $base_dir ) . $file;
	if ( ! is_file( $path ) ) {
		wp_die( "Pacote estático ZIP não encontrado." );
	}
	header( "Content-Type: application/zip" );
	header( "Content-Disposition: attachment; filename=\"" . $file . "\"" );
	header( "Content-Length: " . filesize( $path ) );
	header( "Cache-Control: no-cache, no-store, must-revalidate" );
	@readfile( $path );
	exit;
} );
