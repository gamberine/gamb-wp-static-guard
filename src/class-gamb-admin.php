<?php

namespace Simply_Static;

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Gamb WP Static Guard — Controlador Administrativo 100% em Português (pt-BR).
 *
 * Separa claramente os dois assuntos principais:
 * 1. STATIC — Conversão e publicação das páginas/tema em formato estático
 * 2. GUARD  — Ocultação de dados sensíveis, tokenização /v/<token>, blindagem e backup
 */
class Admin_Controller {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( "admin_menu", array( $this, "register_admin_menu" ) );
		add_action( "admin_enqueue_scripts", array( $this, "enqueue_admin_assets" ) );
		add_action( "rest_api_init", array( $this, "register_rest_routes" ) );
		add_action( "add_meta_boxes", array( $this, "register_single_push_meta_box" ) );
		add_action( "admin_bar_menu", array( $this, "register_admin_bar_node" ), 100 );
		add_filter( "plugin_action_links_gamb-wp-static-guard/gamb-wp-static-guard.php", array( $this, "add_plugin_action_links" ) );
	}

	public function add_plugin_action_links( $links ) {
		$url = esc_url( admin_url( "admin.php?page=simply-static-generate" ) );
		array_unshift( $links, '<a href="' . $url . '" style="font-weight:700;color:#4f46e5;">Painel Static &amp; Guard</a>' );
		return $links;
	}

	public function register_admin_menu() {
		$cap  = "manage_options";
		$icon = GAMB_STATIC_WP_URL . "/assets/gamb-wp-static-guard-icon.svg";

		add_menu_page(
			"Gamb WP Static Guard",
			"Gamb Static Guard",
			$cap,
			"simply-static-generate",
			array( $this, "render_admin_page" ),
			$icon,
			76
		);

		add_submenu_page(
			"simply-static-generate",
			"Painel Static & Guard",
			"1. Static & 2. Guard",
			$cap,
			"simply-static-generate",
			array( $this, "render_admin_page" )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( (string) $hook, "simply-static" ) === false ) {
			return;
		}
		wp_enqueue_style(
			"gamb-guard-admin-css",
			GAMB_STATIC_WP_URL . "/assets/admin-ui.css",
			array(),
			GAMB_STATIC_WP_VERSION
		);
		wp_enqueue_script(
			"gamb-guard-admin-js",
			GAMB_STATIC_WP_URL . "/assets/admin-ui.js",
			array(),
			GAMB_STATIC_WP_VERSION,
			true
		);
		wp_localize_script(
			"gamb-guard-admin-js",
			"gambGuardData",
			array(
				"restUrl" => esc_url_raw( rest_url( "simplystatic/v1/" ) ),
				"nonce"   => wp_create_nonce( "wp_rest" ),
			)
		);
	}

	public function register_admin_bar_node( $admin_bar ) {
		if ( ! is_admin() || ! current_user_can( "manage_options" ) ) {
			return;
		}
		$admin_bar->add_node(
			array(
				"id"    => "gamb-static-guard-bar",
				"title" => "🛡 Gamb Static Guard",
				"href"  => admin_url( "admin.php?page=simply-static-generate" ),
			)
		);
	}

	public function register_single_push_meta_box() {
		if ( ! Options::instance()->get( "ss_use_single_exports", true ) || ! current_user_can( "publish_pages" ) ) {
			return;
		}
		$pts = get_post_types( array( "public" => true ), "names" );
		unset( $pts["attachment"] );
		foreach ( $pts as $pt ) {
			add_meta_box(
				"gamb_single_push_box",
				"Gamb WP Static Guard — Publicação Estática",
				array( $this, "render_single_push_box" ),
				$pt,
				"side",
				"high"
			);
		}
	}

	public function render_single_push_box( $post ) {
		if ( "publish" !== $post->post_status ) {
			echo "<p>Publique este conteúdo para liberar a exportação estática individual (Single Push).</p>";
			return;
		}
		$rest_url = esc_url_raw( rest_url( "simplystatic/v1/apply-single" ) );
		$nonce    = wp_create_nonce( "wp_rest" );
		?>
		<p style="font-size:12.5px;color:#475569;margin-top:0;">
			Atualize apenas esta página e os recursos do tema no pacote estático sem precisar reprocessar todo o site.
		</p>
		<button type="button" class="button button-primary" id="gamb-single-push-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="width:100%;text-align:center;">
			⚡ Converter &amp; Publicar Esta Página Agora
		</button>
		<div id="gamb-single-push-msg" style="margin-top:8px;font-size:12px;font-weight:600;color:#059669;"></div>
		<script>
		(function(){
			var b = document.getElementById('gamb-single-push-btn');
			var m = document.getElementById('gamb-single-push-msg');
			if(!b) return;
			b.addEventListener('click', function(){
				b.disabled = true;
				m.style.color = '#4f46e5';
				m.textContent = 'Convertendo página e aplicando blindagem Guard...';
				fetch('<?php echo esc_js( $rest_url ); ?>', {
					method: 'POST',
					headers: {'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( $nonce ); ?>'},
					body: JSON.stringify({post_id: b.getAttribute('data-post-id')})
				}).then(function(r){ return r.json(); }).then(function(res){
					if (!res || !res.success) throw new Error(res && res.message ? res.message : 'Falha ao exportar.');
					b.disabled = false;
					m.style.color = '#059669';
					m.textContent = '✓ Página exportada com sucesso!';
				}).catch(function(){
					b.disabled = false;
					m.style.color = '#dc2626';
					m.textContent = 'Erro ao exportar página.';
				});
			});
		})();
		</script>
		<?php
	}

	public function register_rest_routes() {
		$perm = function () {
			return current_user_can( "manage_options" );
		};

		register_rest_route( "simplystatic/v1", "/settings", array(
			array(
				"methods"             => "GET",
				"callback"            => array( $this, "rest_get_settings" ),
				"permission_callback" => $perm,
			),
			array(
				"methods"             => "POST",
				"callback"            => array( $this, "rest_save_settings" ),
				"permission_callback" => $perm,
			),
		) );

		register_rest_route( "simplystatic/v1", "/settings/reset", array(
			"methods"             => "POST",
			"callback"            => array( $this, "rest_reset_settings" ),
			"permission_callback" => $perm,
		) );

		register_rest_route( "simplystatic/v1", "/start-export", array(
			"methods"             => "POST",
			"callback"            => array( $this, "rest_start_export" ),
			"permission_callback" => $perm,
		) );

		register_rest_route( "simplystatic/v1", "/apply-single", array(
			"methods"             => "POST",
			"callback"            => array( $this, "rest_apply_single" ),
			"permission_callback" => function () {
				return current_user_can( "publish_pages" );
			},
		) );

		register_rest_route( "simplystatic/v1", "/backups", array(
			"methods"             => "GET",
			"callback"            => array( $this, "rest_list_backups" ),
			"permission_callback" => $perm,
		) );

		register_rest_route( "simplystatic/v1", "/backup", array(
			"methods"             => "POST",
			"callback"            => array( $this, "rest_create_backup" ),
			"permission_callback" => $perm,
		) );

		register_rest_route( "simplystatic/v1", "/backup/delete", array(
			"methods"             => "POST",
			"callback"            => array( $this, "rest_delete_backup" ),
			"permission_callback" => $perm,
		) );
	}

	public function rest_get_settings() {
		return rest_ensure_response( Options::instance()->get_all() );
	}

	public function rest_save_settings( $request ) {
		$params = is_object( $request ) && method_exists( $request, "get_params" ) ? $request->get_params() : array();
		$opts   = Options::instance();

		$bool_keys = array(
			"force_replace_url",
			"clear_directory_before_export",
			"smart_crawl",
			"generate_404",
			"add_feeds",
			"add_rest_api",
			"ss_use_single_exports",
			"ss_single_auto_export",
			"ss_single_include_archives",
			"ss_single_include_pagination",
			"ss_single_export_add_xml_sitemap",
			"auto_backup_before_export",
			"use_minify",
			"minify_html",
			"minify_css",
			"minify_js",
			"use_css_optimize",
			"css_optimize_defer_js",
			"use_search",
			"guard_active_on_live_site",
			"guard_universal_token_cloaking",
			"guard_encrypt_image_paths",
			"guard_hide_theme_identity",
			"guard_hide_language_stack",
			"hide_comments",
			"hide_version",
			"hide_generator",
			"hide_prefetch",
			"hide_rsd",
			"hide_emotes",
			"disable_xmlrpc",
			"disable_embed",
			"disable_db_debug",
			"disable_wlw_manifest",
			"guard_restrict_rest_api",
			"guard_isolate_auth_cache",
			"guard_security_headers",
			"guard_filter_test_posts",
			"guard_dom_merge_duplicate_class",
			"guard_dom_deduplicate_ids",
			"guard_dom_ensure_html_lang",
			"guard_dom_fix_orphan_aria",
			"guard_dom_noopener_blank",
			"guard_dom_img_accessibility",
		);

		$textarea_keys = array(
			"additional_urls",
			"additional_files",
			"urls_to_exclude",
			"minify_js_exclude",
			"css_optimize_defer_js_excludes",
			"search_excludable",
		);

		foreach ( $params as $k => $v ) {
			if ( in_array( $k, $bool_keys, true ) ) {
				$opts->set( $k, filter_var( $v, FILTER_VALIDATE_BOOLEAN ) );
			} elseif ( in_array( $k, $textarea_keys, true ) ) {
				$opts->set( $k, sanitize_textarea_field( (string) $v ) );
			} elseif ( "post_types" === $k && is_array( $v ) ) {
				$opts->set( $k, array_map( "sanitize_key", $v ) );
			} elseif ( is_scalar( $v ) ) {
				$opts->set( $k, sanitize_text_field( (string) $v ) );
			}
		}

		$opts->save();
		Gamb_Security_Suite::purge_page_caches();

		return rest_ensure_response( array( "status" => 200, "success" => true ) );
	}

	public function rest_reset_settings() {
		Gamb_Security_Suite::apply_hardened_profile();
		Static_Engine::log_activity( "Perfil Padrão Seguro de Fábrica restaurado com sucesso." );
		return rest_ensure_response( array( "status" => 200, "success" => true ) );
	}

	public function rest_start_export( $request ) {
		$params = is_object( $request ) && method_exists( $request, "get_params" ) ? $request->get_params() : array();
		$scope  = ! empty( $params["type"] ) ? sanitize_key( $params["type"] ) : "";
		$res    = Static_Engine::get_instance()->run_export( $scope );
		return rest_ensure_response( $res );
	}

	public function rest_apply_single( $request ) {
		$params  = is_object( $request ) && method_exists( $request, "get_params" ) ? $request->get_params() : array();
		$post_id = ! empty( $params["post_id"] ) ? absint( $params["post_id"] ) : 0;
		$res     = Static_Engine::get_instance()->run_export( "single", $post_id );
		return rest_ensure_response( $res );
	}

	public function rest_list_backups() {
		return rest_ensure_response( array( "success" => true, "backups" => Backup_Service::list_backups() ) );
	}

	public function rest_create_backup( $request ) {
		$params = is_object( $request ) && method_exists( $request, "get_params" ) ? $request->get_params() : array();
		$type   = ! empty( $params["type"] ) && "db" === $params["type"] ? "db" : "full";
		$res    = Backup_Service::create_backup( $type );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( "backup_error", $res->get_error_message(), array( "status" => 500 ) );
		}
		Static_Engine::log_activity( "Backup criado com sucesso: " . $res["filename"] . " (" . $res["size_formatted"] . ")" );
		return rest_ensure_response( $res );
	}

	public function rest_delete_backup( $request ) {
		$params = is_object( $request ) && method_exists( $request, "get_params" ) ? $request->get_params() : array();
		$fname  = ! empty( $params["filename"] ) ? sanitize_file_name( $params["filename"] ) : "";
		$ok     = Backup_Service::delete_backup( $fname );
		return rest_ensure_response( array( "success" => (bool) $ok ) );
	}

	public function render_admin_page() {
		$opts        = Options::instance();
		$all         = $opts->get_all();
		$backups     = Backup_Service::list_backups();
		$diagnostics = Backup_Service::run_diagnostics();
		$logs        = is_array( $all["archive_status_messages"] ) ? $all["archive_status_messages"] : array();
		$public_pts  = get_post_types( array( "public" => true ), "objects" );
		unset( $public_pts["attachment"] );
		$saved_pts   = is_array( $all["post_types"] ) ? $all["post_types"] : array();
		?>
		<div id="gamb-guard-admin-app" class="plugin-settings-container">
			<!-- BARRA LATERAL (max-width: 306px; padding: 25px; background: #fff) -->
			<aside class="sidebar">
				<div class="gamb-logo-wrap">
					<img src="<?php echo esc_url( GAMB_STATIC_WP_URL . "/assets/gamb-wp-static-guard-logo.svg" ); ?>" alt="Gamb WP Static Guard" />
					<span class="gamb-version-pill">🛡 v<?php echo esc_html( GAMB_STATIC_WP_VERSION ); ?> • Pré-Configurado &amp; Ativo</span>
				</div>

				<!-- AÇÃO RÁPIDA DE CONVERSÃO ESTÁTICA -->
				<div class="gamb-quick-export-box">
					<label for="gamb-quick-scope">Escopo de Conversão Estática</label>
					<select id="gamb-quick-scope">
						<option value="theme" <?php selected( $all["generate_type"], "theme" ); ?>>⚡ Somente arquivos do tema (sem páginas)</option>
						<option value="update" <?php selected( $all["generate_type"], "update" ); ?>>🔄 Atualizar site (reconstrução completa)</option>
						<option value="export" <?php selected( $all["generate_type"], "export" ); ?>>🌐 Site Completo (Full Site)</option>
					</select>
					<button type="button" class="gamb-btn-primary gamb-run-export-btn">Converter &amp; Publicar Agora</button>
				</div>

				<!-- ASSUNTO 1: STATIC (CONVERSÃO & PUBLICAÇÃO) -->
				<div class="gamb-nav-group gamb-nav-group-static">
					<div class="gamb-nav-group-title">
						<span>1. STATIC — Conversão</span>
						<span>HTML/ZIP</span>
					</div>
					<button type="button" class="gamb-nav-item active" data-tab="static-publish">
						<span>Painel &amp; Publicação</span>
						<span class="gamb-nav-badge">Início</span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="static-scope">
						<span>Escopo &amp; URLs</span>
						<span class="gamb-nav-badge">Tema/Site</span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="static-deploy">
						<span>Destino de Implantação</span>
						<span class="gamb-nav-badge"><?php echo esc_html( strtoupper( (string) $all["delivery_method"] ) ); ?></span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="static-automation">
						<span>Automação Pós-Publicação</span>
						<span class="gamb-nav-badge">Auto</span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="static-optimize">
						<span>Performance &amp; Busca (Fuse.js)</span>
						<span class="gamb-nav-badge">Ativo</span>
					</button>
				</div>

				<!-- ASSUNTO 2: GUARD (OCULTAÇÃO, TOKENIZAÇÃO & SEGURANÇA) -->
				<div class="gamb-nav-group gamb-nav-group-guard">
					<div class="gamb-nav-group-title">
						<span>2. GUARD — Segurança</span>
						<span>Blindado</span>
					</div>
					<button type="button" class="gamb-nav-item" data-tab="guard-cloaking">
						<span>Tokenização &amp; Ocultação</span>
						<span class="gamb-nav-badge">/v/token</span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="guard-hardening">
						<span>Blindagem HTTP, Cache &amp; API</span>
						<span class="gamb-nav-badge">HSTS/CSP</span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="guard-dom">
						<span>Sanitização HTML &amp; WCAG</span>
						<span class="gamb-nav-badge">DOM</span>
					</button>
					<button type="button" class="gamb-nav-item" data-tab="guard-backup">
						<span>Backup (Duplicator) &amp; Saúde</span>
						<span class="gamb-nav-badge"><?php echo count( $backups ); ?></span>
					</button>
				</div>
			</aside>

			<!-- ÁREA PRINCIPAL DAS ABAS -->
			<main class="gamb-main-content">

				<!-- =================================================================
				     ABA 1: PAINEL & PUBLICAÇÃO (1. STATIC)
				     ================================================================= -->
				<section id="panel-static-publish" class="gamb-tab-panel active">
					<div class="gamb-tab-tip">
						<div class="gamb-tab-tip-icon">💡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Uso — Qual modo de conversão escolher?</h4>
							<p>
								• <strong>Somente o Tema (Prioridade — Padrão)</strong>: converte, minifica e tokeniza exclusivamente os arquivos visuais do tema (<code>CSS</code>, <code>JS</code>, fontes e imagens de layout) em poucos segundos, sem precisar varrer milhares de posts ou fotos de produtos.<br/>
								• <strong>Atualizar site (reconstrução completa)</strong>: atualiza rapidamente somente as páginas ou produtos modificados recentemente.<br/>
								• <strong>Site Completo (Full Site)</strong>: gera todas as páginas, produtos, taxonomias, índice de busca <code>Fuse.js</code>, <code>404.html</code>, <code>.htaccess</code> e <code>_headers</code>.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Resumo Operacional — 1. Static &amp; 2. Guard</h3>
							<span class="gamb-badge-pillar gamb-badge-static">1. STATIC — Publicação</span>
						</div>

						<div class="gamb-status-grid">
							<div class="gamb-stat-box">
								<div class="gamb-stat-label">Escopo Prioritário Padrão</div>
								<div class="gamb-stat-value" id="stat-last-scope"><?php echo esc_html( $all["last_export_scope"] ?: "Somente arquivos do tema (sem páginas)" ); ?></div>
							</div>
							<div class="gamb-stat-box">
								<div class="gamb-stat-label">Última Conversão Realizada</div>
								<div class="gamb-stat-value" id="stat-last-time"><?php echo esc_html( $all["last_export_time"] ?: "Pronto para exportar" ); ?></div>
							</div>
							<div class="gamb-stat-box">
								<div class="gamb-stat-label">Arquivos Processados</div>
								<div class="gamb-stat-value" id="stat-last-count"><?php echo (int) $all["last_export_files_count"]; ?> arquivos</div>
							</div>
							<div class="gamb-stat-box">
								<div class="gamb-stat-label">Status do 2. Guard (Ao Vivo)</div>
								<div class="gamb-stat-value" style="color:#059669;">Ativo (/v/&lt;token&gt; + Blindagem)</div>
							</div>
						</div>

						<div id="gamb-latest-zip-wrap" style="margin-bottom:18px;">
							<?php if ( ! empty( $all["last_export_zip_url"] ) ) : ?>
								<a href="<?php echo esc_url( $all["last_export_zip_url"] ); ?>" class="gamb-btn-primary" style="max-width:340px;">
									⬇ Baixar Último Pacote Estático ZIP (<?php echo esc_html( $all["last_export_zip_file"] ); ?>)
								</a>
							<?php endif; ?>
						</div>

						<h4 style="margin:18px 0 8px;font-size:13.5px;color:#334155;">Registro de Atividades (Activity Log)</h4>
						<div class="gamb-log-list" id="gamb-activity-log-box">
							<?php if ( empty( $logs ) ) : ?>
								<div class="gamb-log-item">Plugin instalado e pré-configurado com o Perfil Padrão Seguro v3.0.0. Clique em "Converter &amp; Publicar Agora" para iniciar.</div>
							<?php else : ?>
								<?php foreach ( $logs as $entry ) : ?>
									<div class="gamb-log-item">
										<span class="gamb-log-time">[<?php echo esc_html( $entry["datetime"] ?? "" ); ?>]</span>
										<span><?php echo esc_html( $entry["message"] ?? "" ); ?></span>
										<?php if ( ! empty( $entry["link"]["url"] ) ) : ?>
											<a class="gamb-log-link" href="<?php echo esc_url( $entry["link"]["url"] ); ?>"><?php echo esc_html( $entry["link"]["label"] ?? "Baixar" ); ?></a>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<!-- =================================================================
				     ABA 2: ESCOPO & URLS (1. STATIC)
				     ================================================================= -->
				<section id="panel-static-scope" class="gamb-tab-panel">
					<div class="gamb-tab-tip">
						<div class="gamb-tab-tip-icon">💡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Escopo &amp; Substituição de URLs</h4>
							<p>
								Mantenha <strong>Caminho Relativo (Relative Path)</strong> selecionado para que os arquivos exportados funcionem em qualquer domínio ou CDN sem jamais revelar a URL interna do servidor WordPress. As rotas sensíveis (<code>/wp-json</code>, <code>/xmlrpc.php</code>, <code>/wp-login.php</code>, <code>/author/</code>, <code>/teste-senha</code>) já vêm bloqueadas por padrão.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Definição de Escopo e Reescrita de URLs</h3>
							<span class="gamb-badge-pillar gamb-badge-static">1. STATIC — Escopo</span>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-generate_type">Modo Padrão de Conversão Estática</label>
							<select id="field-generate_type" class="gamb-select" data-opt="generate_type">
								<option value="theme" <?php selected( $all["generate_type"], "theme" ); ?>>Somente arquivos do tema (CSS, JS, fontes e imagens; sem páginas HTML)</option>
								<option value="update" <?php selected( $all["generate_type"], "update" ); ?>>Atualizar site (reconstrução completa para sincronizar listagens e busca)</option>
								<option value="export" <?php selected( $all["generate_type"], "export" ); ?>>Site Completo (Full Site — Todas as páginas, CPTs, taxonomias e uploads)</option>
							</select>
							<div class="gamb-field-help">Para publicar um site navegável, selecione Site Completo. Somente o Tema exporta recursos e não gera index.html. Atualizar e Single Push também reconstroem o site completo para sincronizar listagens e busca.</div>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-destination_url_type">Modo de Substituição de URLs</label>
							<select id="field-destination_url_type" class="gamb-select" data-opt="destination_url_type">
								<option value="relative" <?php selected( $all["destination_url_type"], "relative" ); ?>>Caminhos Relativos (Recomendado — oculta o domínio de origem e funciona em qualquer domínio)</option>
								<option value="absolute" <?php selected( $all["destination_url_type"], "absolute" ); ?>>URLs Absolutas (Especificar domínio público abaixo)</option>
								<option value="offline" <?php selected( $all["destination_url_type"], "offline" ); ?>>Uso Offline (Navegação local via ./ e ../ sem servidor web)</option>
							</select>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-destination_host">Domínio Público (Opcional — usado apenas no modo URL Absoluta)</label>
							<input type="text" id="field-destination_host" class="gamb-input" data-opt="destination_host" value="<?php echo esc_attr( $all["destination_host"] ); ?>" placeholder="exemplo.com.br" />
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label">Tipos de Conteúdo (Post Types) a Incluir no Modo Site Completo</label>
							<div class="gamb-toggle-grid">
								<?php foreach ( $public_pts as $pt_slug => $pt_obj ) : ?>
									<label class="gamb-toggle-card">
										<input type="checkbox" class="gamb-pt-checkbox" value="<?php echo esc_attr( $pt_slug ); ?>" <?php checked( in_array( $pt_slug, $saved_pts, true ) ); ?> />
										<span>
											<span class="gamb-toggle-title"><?php echo esc_html( $pt_obj->labels->name ); ?> (<code><?php echo esc_html( $pt_slug ); ?></code>)</span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-urls_to_exclude">URLs e Extensões Excluídas por Segurança (uma por linha)</label>
							<textarea id="field-urls_to_exclude" class="gamb-textarea" rows="6" data-opt="urls_to_exclude"><?php echo esc_textarea( $all["urls_to_exclude"] ); ?></textarea>
							<div class="gamb-field-help">Impede que endpoints internos, backups ou páginas de teste (como <code>/teste-senha</code>) entrem no pacote estático.</div>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-additional_urls">URLs Adicionais para Incluir (uma por linha)</label>
							<textarea id="field-additional_urls" class="gamb-textarea" rows="3" data-opt="additional_urls"><?php echo esc_textarea( $all["additional_urls"] ); ?></textarea>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 3: DESTINO DE IMPLANTAÇÃO (1. STATIC)
				     ================================================================= -->
				<section id="panel-static-deploy" class="gamb-tab-panel">
					<div class="gamb-tab-tip">
						<div class="gamb-tab-tip-icon">💡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Implantação (Deploy)</h4>
							<p>
								Para baixar o pacote pronto com <code>.htaccess</code> e <code>_headers</code> já configurados, utilize <strong>Arquivo ZIP</strong>. Se o site estático roda na mesma hospedagem em outra pasta pública, escolha <strong>Diretório Local</strong> para publicação instantânea sem upload manual.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Método de Entrega dos Arquivos Estáticos</h3>
							<span class="gamb-badge-pillar gamb-badge-static">1. STATIC — Deploy</span>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-delivery_method">Destino de Implantação</label>
							<select id="field-delivery_method" class="gamb-select" data-opt="delivery_method">
								<option value="zip" <?php selected( $all["delivery_method"], "zip" ); ?>>Arquivo ZIP (Download direto pelo painel)</option>
								<option value="local" <?php selected( $all["delivery_method"], "local" ); ?>>Diretório Local no Servidor</option>
								<option disabled value="github" <?php selected( $all["delivery_method"], "github" ); ?>>GitHub (não implementado neste motor)</option>
								<option disabled value="aws-s3" <?php selected( $all["delivery_method"], "aws-s3" ); ?>>AWS S3 (não implementado neste motor)</option>
								<option disabled value="ftp" <?php selected( $all["delivery_method"], "ftp" ); ?>>FTP (não implementado neste motor)</option>
								<option disabled value="sftp" <?php selected( $all["delivery_method"], "sftp" ); ?>>SFTP (não implementado neste motor)</option>
							</select>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-local_dir">Caminho do Diretório Local (Quando selecionado "Diretório Local")</label>
							<input type="text" id="field-local_dir" class="gamb-input" data-opt="local_dir" value="<?php echo esc_attr( $all["local_dir"] ); ?>" placeholder="<?php echo esc_attr( ABSPATH . "public-static/" ); ?>" />
							<div class="gamb-field-help">Caminho físico no servidor onde os arquivos estáticos e o <code>.htaccess</code> serão gravados.</div>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-github_repository">GitHub: Usuário / Repositório / Branch / Token (Opcional)</label>
							<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:680px;">
								<input type="text" class="gamb-input" data-opt="github_user" value="<?php echo esc_attr( $all["github_user"] ); ?>" placeholder="Usuário ou Organização GitHub" />
								<input type="text" class="gamb-input" data-opt="github_repository" value="<?php echo esc_attr( $all["github_repository"] ); ?>" placeholder="Nome do Repositório" />
								<input type="text" class="gamb-input" data-opt="github_branch" value="<?php echo esc_attr( $all["github_branch"] ); ?>" placeholder="Branch (ex: main ou gh-pages)" />
								<input type="password" class="gamb-input" data-opt="github_personal_access_token" value="<?php echo esc_attr( $all["github_personal_access_token"] ); ?>" placeholder="Personal Access Token (ghp_...)" />
							</div>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label">AWS S3: Bucket / Região / Access Key / Secret Key (Opcional)</label>
							<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:680px;">
								<input type="text" class="gamb-input" data-opt="aws_bucket" value="<?php echo esc_attr( $all["aws_bucket"] ); ?>" placeholder="Nome do Bucket S3" />
								<input type="text" class="gamb-input" data-opt="aws_region" value="<?php echo esc_attr( $all["aws_region"] ); ?>" placeholder="Região (ex: us-east-1, sa-east-1)" />
								<input type="text" class="gamb-input" data-opt="aws_access_key" value="<?php echo esc_attr( $all["aws_access_key"] ); ?>" placeholder="AWS Access Key ID" />
								<input type="password" class="gamb-input" data-opt="aws_access_secret" value="<?php echo esc_attr( $all["aws_access_secret"] ); ?>" placeholder="AWS Secret Access Key" />
							</div>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 4: AUTOMAÇÃO PÓS-PUBLICAÇÃO (1. STATIC)
				     ================================================================= -->
				<section id="panel-static-automation" class="gamb-tab-panel">
					<div class="gamb-tab-tip">
						<div class="gamb-tab-tip-icon">💡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Automação Pós-Implementação</h4>
							<p>
								Com <strong>Auto Push</strong> e <strong>Single Push</strong> ativos (padrão), toda vez que você criar ou editar uma página ou produto e clicar em <em>Publicar/Atualizar</em>, o plugin regera automaticamente a versão estática daquela página em segundo plano sem precisar reprocessar todo o site.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Automação de Novas Páginas e Atualizações</h3>
							<span class="gamb-badge-pillar gamb-badge-static">1. STATIC — Automação</span>
						</div>

						<div class="gamb-toggle-grid" style="margin-bottom:18px;">
							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="ss_use_single_exports" <?php checked( $all["ss_use_single_exports"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Habilitar Botão Single Push no Editor</span>
									<span class="gamb-toggle-desc">Exibe o botão "Converter &amp; Publicar Esta Página Agora" na lateral de cada página/produto.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="ss_single_auto_export" <?php checked( $all["ss_single_auto_export"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Auto Push ao Publicar ou Atualizar</span>
									<span class="gamb-toggle-desc">Exporta automaticamente a página/produto em segundo plano assim que for salvo no WordPress.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="auto_backup_before_export" <?php checked( $all["auto_backup_before_export"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Backup SQL Automático Antes de Exportar</span>
									<span class="gamb-toggle-desc">Gera um snapshot de segurança do banco de dados antes de cada exportação estática.</span>
								</span>
							</label>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-ss_webhook_url">Webhook Pós-Publicação (URL opcional para notificar CDN / CI/CD)</label>
							<input type="url" id="field-ss_webhook_url" class="gamb-input" data-opt="ss_webhook_url" value="<?php echo esc_attr( $all["ss_webhook_url"] ); ?>" placeholder="https://api.cloudflare.com/client/v4/pages/webhooks/..." />
							<div class="gamb-field-help">Envia um POST JSON automaticamente sempre que uma exportação ou atualização automática terminar.</div>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 5: PERFORMANCE & BUSCA LOCAL FUSE.JS (1. STATIC)
				     ================================================================= -->
				<section id="panel-static-optimize" class="gamb-tab-panel">
					<div class="gamb-tab-tip">
						<div class="gamb-tab-tip-icon">💡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Performance &amp; Busca Local Sem Banco de Dados</h4>
							<p>
								A busca estática com <strong>Fuse.js</strong> substitui a consulta <code>/?s=</code> do WordPress por um índice JSON local instantâneo, eliminando 100% do risco de <em>SQL Injection</em> e sobrecarga no banco. A minificação já preserva automaticamente o <code>jQuery</code> e o <code>GTranslate</code>.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Minificação Segura, Defer JS e Busca Estática (Fuse.js)</h3>
							<span class="gamb-badge-pillar gamb-badge-static">1. STATIC — Performance</span>
						</div>

						<div class="gamb-toggle-grid" style="margin-bottom:18px;">
							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="use_minify" <?php checked( $all["use_minify"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Ativar Minificação (HTML, CSS e JS)</span>
									<span class="gamb-toggle-desc">Compacta folhas de estilo, scripts e marcação HTML nos arquivos exportados.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="css_optimize_defer_js" <?php checked( $all["css_optimize_defer_js"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Adiar Scripts Não-Críticos (Defer JS)</span>
									<span class="gamb-toggle-desc">Elimina bloqueio de renderização preservando automaticamente jQuery e GTranslate.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="use_search" <?php checked( $all["use_search"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Busca Estática Local (Fuse.js)</span>
									<span class="gamb-toggle-desc">Gera índice JSON estático para busca instantânea sem necessidade de PHP/MySQL.</span>
								</span>
							</label>
						</div>

						<div class="gamb-field-row">
							<label class="gamb-label" for="field-minify_js_exclude">Scripts Protegidos da Minificação e do Defer (um por linha)</label>
							<textarea id="field-minify_js_exclude" class="gamb-textarea" rows="3" data-opt="minify_js_exclude"><?php echo esc_textarea( $all["minify_js_exclude"] ); ?></textarea>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 6: TOKENIZAÇÃO & OCULTAÇÃO /v/<token> (2. GUARD)
				     ================================================================= -->
				<section id="panel-guard-cloaking" class="gamb-tab-panel">
					<div class="gamb-tab-tip guard-tip">
						<div class="gamb-tab-tip-icon">🛡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Segurança — Como funciona a Tokenização Universal (/v/&lt;token&gt;)</h4>
							<p>
								Com esta camada ativa (padrão), nenhum visitante ou scanner (como <em>Wappalyzer</em>, <em>BuiltWith</em> ou <em>WPScan</em>) consegue ver <code>wp-content</code>, <code>wp-includes</code>, o nome do tema, caminhos completos de imagens ou extensões (<code>.css</code>, <code>.js</code>, <code>.php</code>). Todos os recursos públicos passam a ser servidos por tokens criptografados sem extensão (ex.: <code>/v/D7i_a3UB...</code>), inclusive fontes <code>url(...)</code> dentro dos arquivos CSS e bandeiras do <code>GTranslate</code>!
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Tokenização Criptográfica Universal e Ocultação de Stack</h3>
							<span class="gamb-badge-pillar gamb-badge-guard">2. GUARD — Ocultação</span>
						</div>

						<div class="gamb-toggle-grid" style="margin-bottom:18px;">
							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_active_on_live_site" <?php checked( $all["guard_active_on_live_site"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Ativar Ocultação Dinâmica no Site Ao Vivo</span>
									<span class="gamb-toggle-desc">Aplica a camuflagem em tempo real também nas páginas públicas do WordPress, além da exportação estática.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_universal_token_cloaking" <?php checked( $all["guard_universal_token_cloaking"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Tokenização Criptográfica Universal (/v/&lt;token&gt;)</span>
									<span class="gamb-toggle-desc">Substitui todas as URLs de CSS, JS, fontes e plugins por tokens criptografados sem extensão e resolve url(...) internos do CSS.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_encrypt_image_paths" <?php checked( $all["guard_encrypt_image_paths"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Ocultar Caminho Completo de Imagens e Uploads</span>
									<span class="gamb-toggle-desc">Criptografa o caminho de todas as imagens de produtos e galerias para não expor ano/mês nem a pasta uploads.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_hide_theme_identity" <?php checked( $all["guard_hide_theme_identity"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Ocultar Pasta do Tema, Nome do Tema e Comentários CSS/JS</span>
									<span class="gamb-toggle-desc">Remove cabeçalhos /* Theme Name */ do style.css, banners /*! Bootstrap/jQuery */ e classes theme-* no HTML.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_hide_language_stack" <?php checked( $all["guard_hide_language_stack"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Ocultar Identificação de Linguagem (PHP / WordPress)</span>
									<span class="gamb-toggle-desc">Remove X-Powered-By, Server, X-Redirect-By, Link headers, id="...-css/-js" e //# sourceURL.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="hide_comments" <?php checked( $all["hide_comments"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Remover Comentários HTML (&lt;!-- ... --&gt;)</span>
									<span class="gamb-toggle-desc">Elimina comentários de código do tema e assinaturas de rodapé do LiteSpeed Cache / Yoast.</span>
								</span>
							</label>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 7: BLINDAGEM HTTP, CACHE & ENDPOINTS (2. GUARD)
				     ================================================================= -->
				<section id="panel-guard-hardening" class="gamb-tab-panel">
					<div class="gamb-tab-tip guard-tip">
						<div class="gamb-tab-tip-icon">🛡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Blindagem — Proteção de Cache Autenticado, REST API e Cabeçalhos HTTP</h4>
							<p>
								Esta aba corrige automaticamente os alertas de alta prioridade das auditorias de segurança: impede que sessões autenticadas vazem para o cache público do <strong>LiteSpeed Cache</strong>, bloqueia o acesso anônimo a <code>/wp-json/wp/v2/*</code> (protegendo campos ACF de produtos e lista de usuários) e injeta os cabeçalhos <code>HSTS</code>, <code>CSP</code>, <code>X-Frame-Options</code> e <code>X-Content-Type-Options</code>.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Blindagem de Servidor, Cache, Sessão e Endpoints WordPress</h3>
							<span class="gamb-badge-pillar gamb-badge-guard">2. GUARD — Blindagem</span>
						</div>

						<div class="gamb-toggle-grid" style="margin-bottom:18px;">
							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_isolate_auth_cache" <?php checked( $all["guard_isolate_auth_cache"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Isolar Sessão Autenticada do Cache Público (LiteSpeed)</span>
									<span class="gamb-toggle-desc">Define DONOTCACHEPAGE e envia X-LiteSpeed-Cache-Control: private, no-store para usuários logados, removendo classes logged-in/admin-bar do HTML público.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_restrict_rest_api" <?php checked( $all["guard_restrict_rest_api"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Bloquear Raspagem Anônima da REST API (/wp-json/wp/v2/*)</span>
									<span class="gamb-toggle-desc">Impede que visitantes não autenticados listem usuários, produtos ou campos ACF via JSON.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_security_headers" <?php checked( $all["guard_security_headers"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Cabeçalhos HTTP de Segurança &amp; Cookies Blindados</span>
									<span class="gamb-toggle-desc">Envia HSTS, CSP, X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, Referrer-Policy e flags HttpOnly/Secure/SameSite=Lax.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="disable_xmlrpc" <?php checked( $all["disable_xmlrpc"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Desativar XML-RPC e Pingbacks</span>
									<span class="gamb-toggle-desc">Bloqueia ataques de força bruta amplificados e DDoS via /xmlrpc.php.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_filter_test_posts" <?php checked( $all["guard_filter_test_posts"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Ocultar Conteúdos de Teste / Protegidos por Senha</span>
									<span class="gamb-toggle-desc">Remove automaticamente posts com senha ou slug "teste-senha" dos links de navegação anterior/próximo.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="hide_generator" <?php checked( $all["hide_generator"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Remover Meta Generator, RSD, WLW Manifest e Emotes</span>
									<span class="gamb-toggle-desc">Limpa metadados desnecessários do &lt;head&gt; que identificam a versão do WordPress.</span>
								</span>
							</label>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 8: SANITIZAÇÃO HTML & ACESSIBILIDADE WCAG (2. GUARD)
				     ================================================================= -->
				<section id="panel-guard-dom" class="gamb-tab-panel">
					<div class="gamb-tab-tip guard-tip">
						<div class="gamb-tab-tip-icon">🛡</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Qualidade Estrutural — Autocorreção Dinâmica de HTML e Acessibilidade</h4>
							<p>
								Sem precisar editar os arquivos PHP do tema, o sanitizador DOM corrige em tempo real defeitos estruturais comuns apontados em auditorias: funde atributos <code>class</code> duplicados na mesma tag (ex.: <code>&lt;article&gt;</code>), desduplica IDs repetidos (ex.: <code>#posts</code> e <code>#primary-menu-list</code>), garante <code>lang="pt-BR"</code> no <code>&lt;html&gt;</code>, corrige <code>aria-labelledby</code> órfãos em modais e adiciona <code>rel="noopener noreferrer"</code> em links externos.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Sanitizador Automático de DOM, HTML Válido e Acessibilidade (WCAG)</h3>
							<span class="gamb-badge-pillar gamb-badge-guard">2. GUARD — Qualidade DOM</span>
						</div>

						<div class="gamb-toggle-grid" style="margin-bottom:18px;">
							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_dom_merge_duplicate_class" <?php checked( $all["guard_dom_merge_duplicate_class"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Fundir Atributos class="..." Duplicados na Mesma Tag</span>
									<span class="gamb-toggle-desc">Combina múltiplos atributos class em um único atributo HTML válido (ex.: na tag &lt;article&gt;).</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_dom_deduplicate_ids" <?php checked( $all["guard_dom_deduplicate_ids"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Desduplicar IDs Repetidos no Documento</span>
									<span class="gamb-toggle-desc">Garante unicidade de IDs repetidos (como #posts e #primary-menu-list) numerando ocorrências secundárias.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_dom_ensure_html_lang" <?php checked( $all["guard_dom_ensure_html_lang"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Garantir lang="pt-BR" Direto na Tag &lt;html&gt;</span>
									<span class="gamb-toggle-desc">Injeta o idioma principal do site diretamente no HTML entregue pelo servidor antes do carregamento de scripts.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_dom_fix_orphan_aria" <?php checked( $all["guard_dom_fix_orphan_aria"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Reparar Referências aria-labelledby Órfãs em Modais</span>
									<span class="gamb-toggle-desc">Substitui aria-labelledby="modalLabel" sem destino por um rótulo acessível válido.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_dom_noopener_blank" <?php checked( $all["guard_dom_noopener_blank"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Forçar rel="noopener noreferrer" em Links target="_blank"</span>
									<span class="gamb-toggle-desc">Protege todos os links externos que abrem em nova aba contra tabnabbing reverso.</span>
								</span>
							</label>

							<label class="gamb-toggle-card">
								<input type="checkbox" data-opt="guard_dom_img_accessibility" <?php checked( $all["guard_dom_img_accessibility"] ); ?> />
								<span>
									<span class="gamb-toggle-title">Garantir alt="" Fallback e decoding="async" em Imagens</span>
									<span class="gamb-toggle-desc">Evita imagens sem atributo alt no validador WCAG e melhora a decodificação assíncrona.</span>
								</span>
							</label>
						</div>

						<button type="button" class="gamb-btn-primary gamb-save-settings-btn" style="max-width:260px;">Salvar Configurações</button>
					</div>
				</section>

				<!-- =================================================================
				     ABA 9: BACKUP (ESTILO DUPLICATOR) & DIAGNÓSTICO (2. GUARD)
				     ================================================================= -->
				<section id="panel-guard-backup" class="gamb-tab-panel">
					<div class="gamb-tab-tip guard-tip">
						<div class="gamb-tab-tip-icon">💾</div>
						<div class="gamb-tab-tip-body">
							<h4>Dica de Segurança Operacional — Backup Estilo Duplicator &amp; Diagnóstico</h4>
							<p>
								Gere a qualquer momento um pacote completo (<code>database.sql</code> com todas as tabelas + <code>manifest.json</code> + tema ativo e plugins) armazenado em diretório protegido contra acesso público (<code>wp-content/uploads/gamb-backups/</code>). Na ativação do plugin, um snapshot inicial do banco já é criado automaticamente.
							</p>
						</div>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Gerenciador de Backups (Estilo Duplicator)</h3>
							<span class="gamb-badge-pillar gamb-badge-guard">2. GUARD — Backup</span>
						</div>

						<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
							<button type="button" class="gamb-btn-primary gamb-create-backup-btn" data-backup-type="full" style="max-width:310px;">
								📦 Criar Backup Completo (SQL + Tema + Plugins)
							</button>
							<button type="button" class="gamb-btn-secondary gamb-create-backup-btn" data-backup-type="db">
								🗄 Criar Snapshot Rápido (Somente Banco SQL)
							</button>
							<button type="button" class="gamb-btn-secondary" id="gamb-restore-defaults-btn" style="margin-left:auto;">
								🔄 Restaurar Perfil Padrão Seguro de Fábrica
							</button>
						</div>

						<table class="gamb-table">
							<thead>
								<tr>
									<th>Arquivo de Backup</th>
									<th>Tipo</th>
									<th>Tamanho</th>
									<th>Data de Criação</th>
									<th>Ações</th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $backups ) ) : ?>
									<tr>
										<td colspan="5" style="color:#64748b;">Nenhum backup listado ainda. Clique em um dos botões acima para gerar seu pacote.</td>
									</tr>
								<?php else : ?>
									<?php foreach ( $backups as $bk ) : ?>
										<tr>
											<td><strong><?php echo esc_html( $bk["filename"] ); ?></strong></td>
											<td><?php echo esc_html( $bk["type_label"] ); ?></td>
											<td><?php echo esc_html( $bk["size_formatted"] ); ?></td>
											<td><?php echo esc_html( $bk["created_at"] ); ?></td>
											<td>
												<a href="<?php echo esc_url( $bk["download_url"] ); ?>" class="gamb-btn-secondary" style="padding:4px 10px;font-size:12px;">⬇ Baixar</a>
												<button type="button" class="gamb-btn-secondary gamb-delete-backup-btn" data-file="<?php echo esc_attr( $bk["filename"] ); ?>" style="padding:4px 10px;font-size:12px;color:#dc2626 !important;">Excluir</button>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="gamb-card">
						<div class="gamb-card-header">
							<h3>Diagnóstico de Saúde, Permissões e Blindagem</h3>
							<span class="gamb-badge-pillar gamb-badge-guard">2. GUARD — Saúde</span>
						</div>

						<table class="gamb-table">
							<thead>
								<tr>
									<th>Categoria</th>
									<th>Verificação</th>
									<th>Status</th>
									<th>Detalhes</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $diagnostics as $d ) : ?>
									<tr>
										<td><strong><?php echo esc_html( $d["category"] ); ?></strong></td>
										<td><?php echo esc_html( $d["label"] ); ?></td>
										<td>
											<?php if ( $d["ok"] ) : ?>
												<span style="color:#059669;font-weight:700;">✓ Aprovado</span>
											<?php else : ?>
												<span style="color:#d97706;font-weight:700;">⚠ Atenção</span>
											<?php endif; ?>
										</td>
										<td style="color:#64748b;font-size:12px;"><?php echo esc_html( $d["detail"] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>

			</main>
		</div>
		<div id="gamb-toast" class="gamb-toast"></div>
		<?php
	}
}
