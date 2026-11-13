<?php

namespace GambWPStaticGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gamb WP Static Guard - Gerenciador Central de Opções e Perfil Padrão Seguro (v4.1.0).
 *
 * Mantém compatibilidade com a chave 'gamb-wp-static-guard' no wp_options e separa claramente
 * as configurações de 1. STATIC (Conversão & Publicação) e 2. GUARD (Ocultação & Segurança).
 */
class Options {

	const OPTION_KEY  = 'gamb_wp_static_guard';
	const VERSION_KEY = 'gamb_wp_static_guard_profile_version';
	const VERSION     = '4.1.0';

	private static $instance = null;
	private $options = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function reinstance() {
		self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		self::migrate_legacy_options();
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$this->options = array_merge( self::get_default_options(), $stored );
	}

	/**
	 * Retorna todas as configurações padrão pré-configuradas com práticas seguras de fábrica.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_default_options() {
		$post_types_default = array(); // Vazio seleciona os tipos públicos disponíveis na execução.
		return array(
			// =====================================================================
			// 1. STATIC - CONVERSÃO, ESCOPO, IMPLANTAÇÃO, AUTOMAÇÃO E PERFORMANCE
			// =====================================================================
			'generate_type'                    => 'export', // Primeira publicação sempre completa.
			'destination_url_type'             => 'relative', // relative | absolute | offline
			'destination_scheme'               => 'https://',
			'destination_host'                 => '',
			'relative_path'                    => '',
			'force_replace_url'                => true,
			'clear_directory_before_export'    => true,
			'delivery_method'                  => 'zip', // zip | local
			'local_dir'                        => '',
			'smart_crawl'                      => true,
			'crawlers'                         => array(
				'home_url',
				'post_type_urls',
				'taxonomy_urls',
				'pagination_urls',
				'sitemap_urls',
				'theme_assets',
				'plugin_assets',
				'uploads_directory',
				'wp_includes',
				'public_text_files',
			),
			'post_types'                       => $post_types_default,
			'generate_404'                     => true,
			'custom_404_page'                  => 0,
			'add_feeds'                        => false,
			'add_rest_api'                     => false,
			'additional_urls'                  => '',
			'additional_files'                 => '',
			'urls_to_exclude'                  => implode( "\n", array(
				'/wp-json',
				'/xmlrpc.php',
				'/wp-login.php',
				'/wp-admin',
				'/wp-config',
				'/wp-cron.php',
				'/wp-trackback.php',
				'/wp-signup.php',
				'/wp-activate.php',
				'/author/',
				'/?author=',
				'/teste-senha',
				'/readme.html',
				'/license.txt',
				'/\.(sql|bak|old|orig|save|swp|log|env|git|svn|hg|sh|ini|phps|fla|psd)$/i',
			) ),

			// Automação Pós-Implementação (Single Push, Auto Push, Changes Only, Webhooks)
			'single_enabled'            => true,
			'auto_export'            => true,
			'auto_export_delay'      => 0,
			'include_archives'       => true,
			'include_pagination'     => true,
			'include_sitemap' => true,
			'webhook_url'                   => '',
			'webhook_export_types'         => array( 'export', 'theme', 'update', 'single' ),
			'auto_backup_before_export'        => false,

			// Performance & Busca Estática Local (busca local)
			'use_minify'                       => false,
			'minify_html'                      => true,
			'minify_css'                       => true,
			'minify_js'                        => true,
			'minify_js_exclude'                => "jquery.min.js\njquery.js",
			'use_css_optimize'                 => true,
			'css_optimize_defer_js'            => false,
			'css_optimize_defer_js_excludes'   => "jquery.min.js\njquery.js",
			'use_search'                       => true,
			'search_type'                      => 'local',
			'search_index_title'               => 'title',
			'search_index_content'             => 'main, article, .entry-content, body',
			'search_index_excerpt'             => ".entry-summary, .entry-content, meta[name='description']",
			'search_excludable'                => "/404\n/wp-admin\n/wp-login\n/author\n/perfil\n/teste-senha",

			// =====================================================================
			// 2. GUARD - OCULTAÇÃO, TOKENIZAÇÃO UNIVERSAL, BLINDAGEM E DOM
			// =====================================================================

			'guard_remove_block_styles' => false,
			'guard_clean_body_classes' => false,
			'guard_disable_texturize' => false,
			'guard_strip_asset_versions' => true,
			'guard_disable_speculation' => true,
			'guard_strict_static_html' => true,
			'guard_active_on_live_site'        => true, // Ativa ocultação dinâmica também no site WP ao vivo
			'guard_universal_token_cloaking'   => true, // Criptografa CSS, JS, fontes e imagens em /v/<token> sem extensão
			'guard_encrypt_image_paths'        => true, // Oculta caminho completo e nome original das imagens
			'guard_hide_theme_identity'        => true, // Oculta pasta do tema, nome do tema, style.css e comentários /*! ... */
			'guard_hide_language_stack'        => true, // Remove X-Powered-By, Server, X-Redirect-By, Link headers e IDs WP
			'wp_content_directory'             => 'static',
			'wp_includes_directory'            => 'static/core',
			'wp_uploads_directory'             => 'media',
			'wp_plugins_directory'             => 'ext',
			'wp_themes_directory'              => 'ui',
			'theme_style_name'                 => 'main',
			'author_url'                       => 'perfil',
			'custom_replacements'              => array(),

			// Blindagem de Endpoints, Cache e Cabeçalhos HTTP
			'hide_comments'                    => true,
			'hide_version'                     => true,
			'hide_generator'                   => true,
			'hide_prefetch'                    => true,
			'hide_rsd'                         => true,
			'hide_emotes'                      => true,
			'disable_xmlrpc'                   => true,
			'disable_embed'                    => true,
			'disable_db_debug'                 => true,
			'disable_wlw_manifest'             => true,
			'guard_restrict_rest_api'          => true, // Bloqueia raspagem anônima de /wp-json/wp/v2/*
			'guard_isolate_auth_cache'         => true, // DONOTCACHEPAGE + limpeza de logged-in/admin-bar e assinatura cache de páginas
			'guard_security_headers'           => true, // HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Cookies
			'guard_filter_test_posts'          => true, // Oculta posts com senha ou teste-senha da navegação pública

			// Sanitização Estrutural de HTML & Acessibilidade (WCAG / Auditoria)
			'guard_dom_merge_duplicate_class'  => true, // Funde múltiplos class="..." na mesma tag HTML
			'guard_dom_deduplicate_ids'        => true, // Desduplica IDs repetidos (#posts, #primary-menu-list)
			'guard_dom_ensure_html_lang'       => true, // Garante lang="pt-BR" na tag <html> inicial
			'guard_dom_fix_orphan_aria'        => true, // Corrige aria-labelledby órfãos em modais
			'guard_dom_noopener_blank'         => true, // Injeta rel="noopener noreferrer" em links target="_blank"
			'guard_dom_img_accessibility'      => true, // Garante alt="" fallback e decoding="async" em imagens

			// Estado interno de execução
			'archive_status_messages'          => array(),
			'last_export_zip_url'              => '',
			'last_export_zip_file'             => '',
			'first_full_export_completed'      => false,
			'last_export_time'                 => '',
			'last_export_scope'                => '',
			'last_export_files_count'          => 0,
			'version'                          => self::VERSION,
		);
	}

	/** Identifica a opção anterior por impressão SHA-256; mantém a origem para recuperação. */
	private static function migrate_legacy_options() {
		if ( false !== get_option( self::OPTION_KEY, false ) ) { return; }
		foreach ( wp_load_alloptions() as $key => $raw ) {
			if ( hash( 'sha256', $key ) !== '314b56143ebd5052dc35ae543712cf6bbfeba9a411d2fae94904c1953774aea8' ) { continue; }
			$legacy = maybe_unserialize( $raw );
			if ( ! is_array( $legacy ) ) { return; }
			$mapping = array(
			'ss_use_single_exports' => 'single_enabled',
			'ss_single_auto_export' => 'auto_export',
			'ss_single_auto_export_delay' => 'auto_export_delay',
			'ss_single_include_archives' => 'include_archives',
			'ss_single_include_pagination' => 'include_pagination',
			'ss_single_export_add_xml_sitemap' => 'include_sitemap',
			'ss_webhook_url' => 'webhook_url',
			'ss_webhook_enabled_types' => 'webhook_export_types'
			);
			foreach ( $mapping as $old => $new ) {
				if ( array_key_exists( $old, $legacy ) ) { $legacy[$new] = $legacy[$old]; unset( $legacy[$old] ); }
			}
			$legacy = array_intersect_key( $legacy, self::get_default_options() );
			$legacy['archive_status_messages'] = array();
			$legacy['version'] = self::VERSION;
			add_option( self::OPTION_KEY, $legacy, '', true );
			add_option( 'gamb_wp_static_guard_migration_version', self::VERSION, '', false );
			return;
		}
	}

	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	public function set( $key, $value ) {
		$this->options[ $key ] = $value;
		return $this;
	}

	public function get_all() {
		return $this->options;
	}

	public function save() {
		update_option( self::OPTION_KEY, $this->options );
		return $this;
	}

	public function get_archive_dir() {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : ( WP_CONTENT_DIR . '/uploads' );
		$dir     = trailingslashit( $base ) . 'gamb-static-guard-archive/';
		if ( ! is_dir( $dir ) ) {
			@wp_mkdir_p( $dir );
		}
		return $dir;
	}
}
