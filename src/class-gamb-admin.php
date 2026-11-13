<?php
namespace GambWPStaticGuard;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Administração restrita a usuários com permissão de gerenciar configurações. */
class Admin_Controller {
    const MENU = 'gamb-wp-static-guard';
    const API = 'gamb-wp-static-guard/v1';
    private static $instance;
    public static function get_instance() { if ( ! self::$instance ) { self::$instance = new self(); } return self::$instance; }
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_node' ), 100 );
        add_action( 'add_meta_boxes', array( $this, 'register_single_push_meta_box' ) );
        add_filter( 'plugin_action_links_gamb-wp-static-guard/gamb-wp-static-guard.php', array( $this, 'add_plugin_action_links' ) );
    }
    public function add_plugin_action_links( $links ) { array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">Configurações</a>' ); return $links; }
    public function register_admin_menu() { add_menu_page( 'Gamb WP Static Guard', 'Gamb Static Guard', 'manage_options', self::MENU, array( $this, 'render_admin_page' ), GAMB_STATIC_WP_URL . '/assets/gamb-wp-static-guard-icon.svg', 76 ); }
    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( (string) $hook, self::MENU ) ) { return; }
        wp_enqueue_style( 'gamb-guard-admin', GAMB_STATIC_WP_URL . '/assets/admin-ui.css', array(), GAMB_STATIC_WP_VERSION );
        wp_enqueue_script( 'gamb-guard-admin', GAMB_STATIC_WP_URL . '/assets/admin-ui.js', array(), GAMB_STATIC_WP_VERSION, true );
        wp_localize_script( 'gamb-guard-admin', 'gambGuardData', array( 'restUrl' => rest_url( self::API . '/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
    }
    public function register_admin_bar_node( $bar ) { if ( is_admin() && current_user_can( 'manage_options' ) ) { $bar->add_node( array( 'id' => self::MENU, 'title' => 'Gamb Static Guard', 'href' => admin_url( 'admin.php?page=' . self::MENU ) ) ); } }
    public function register_single_push_meta_box() {
        if ( ! current_user_can( 'manage_options' ) || ! Options::instance()->get( 'single_enabled', true ) ) { return; }
        foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
            if ( 'attachment' !== $type ) { add_meta_box( 'gamb-single-push', 'Publicação estática', array( $this, 'render_single_push_box' ), $type, 'side' ); }
        }
    }
    public function render_single_push_box( $post ) {
        if ( 'publish' !== $post->post_status || $post->post_password ) { echo '<p>Publique o conteúdo sem senha para disponibilizar a exportação.</p>'; return; }
        echo '<p>O botão Atualizar agenda a publicação quando a atualização automática está habilitada. A publicação manual reconstrói o site para sincronizar listagens e pesquisa.</p>';
        wp_enqueue_script( 'gamb-guard-single', GAMB_STATIC_WP_URL . '/assets/admin-ui.js', array(), GAMB_STATIC_WP_VERSION, true );
        wp_localize_script( 'gamb-guard-single', 'gambGuardData', array( 'restUrl' => rest_url( self::API . '/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
        echo '<button type="button" class="button" data-gamb-single="' . (int) $post->ID . '">Publicar e atualizar listagens</button><p role="status" aria-live="polite" data-gamb-single-message></p>';
    }
    public function register_rest_routes() {
        $permission = static function() { return current_user_can( 'manage_options' ); };
        $routes = array(
            '/settings' => array( array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get_settings' ) ), array( 'methods' => 'POST', 'callback' => array( $this, 'rest_save_settings' ) ) ),
            '/start-export' => array( 'methods' => 'POST', 'callback' => array( $this, 'rest_start_export' ) ),
            '/diagnostics' => array( array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get_diagnostics' ) ), array( 'methods' => 'POST', 'callback' => array( $this, 'rest_run_diagnostics' ) ) ),
            '/apply-single' => array( 'methods' => 'POST', 'callback' => array( $this, 'rest_apply_single' ) ),
            '/backups' => array( 'methods' => 'GET', 'callback' => static function() { return rest_ensure_response( Backup_Service::list_backups() ); } ),
            '/backup' => array( 'methods' => 'POST', 'callback' => array( $this, 'rest_create_backup' ) ),
            '/backup/delete' => array( 'methods' => 'POST', 'callback' => array( $this, 'rest_delete_backup' ) ),
        );
        foreach ( $routes as $route => $definition ) {
            if ( isset( $definition['methods'] ) ) { $definition['permission_callback'] = $permission; }
            else { foreach ( $definition as &$endpoint ) { $endpoint['permission_callback'] = $permission; } unset( $endpoint ); }
            register_rest_route( self::API, $route, $definition );
        }
    }
    public function rest_get_settings() { return rest_ensure_response( array_intersect_key( Options::instance()->get_all(), Options::get_default_options() ) ); }
    public function rest_get_diagnostics() { return rest_ensure_response( array( 'installation' => Installation_Checks::latest(), 'publication' => get_option( Installation_Checks::EXPORT_OPTION, array() ) ) ); }
    public function rest_run_diagnostics() { Installation_Checks::run(); return $this->rest_get_diagnostics(); }
    public function rest_save_settings( $request ) {
        $options = Options::instance(); $defaults = Options::get_default_options();
        $params = $request->get_params();
        if ( isset( $params['post_types'] ) && empty( $params['post_types'] ) ) { return new \WP_Error( 'missing_types', 'Selecione ao menos um tipo de conteúdo.', array( 'status' => 400 ) ); }
        if ( isset( $params['destination_scheme'] ) && ! in_array( $params['destination_scheme'], array( 'https://', 'http://' ), true ) ) { return new \WP_Error( 'invalid_scheme', 'Protocolo de publicação inválido.', array( 'status' => 400 ) ); }
        foreach ( array( 'relative_path', 'destination_host' ) as $path_key ) {
            if ( isset( $params[$path_key] ) && ( ! is_string( $params[$path_key] ) || preg_match( '~[\x00-\x20<>"\x27`?#]~', $params[$path_key] ) ) ) { return new \WP_Error( 'invalid_url_path', 'Informe um domínio ou caminho público sem espaços ou caracteres de marcação.', array( 'status' => 400 ) ); }
        }
        if ( isset( $params['delivery_method'] ) && ! in_array( $params['delivery_method'], array( 'local', 'zip' ), true ) ) { return new \WP_Error( 'invalid_delivery', 'Selecione entrega local ou arquivo ZIP.', array( 'status' => 400 ) ); }
        foreach ( $params as $key => $value ) {
            if ( ! array_key_exists( $key, $defaults ) || strpos( $key, 'last_' ) === 0 || in_array( $key, array( 'archive_status_messages', 'version', 'first_full_export_completed' ), true ) ) { continue; }
            if ( is_bool( $defaults[$key] ) ) { $value = filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
            elseif ( is_int( $defaults[$key] ) ) { $value = max( 0, (int) $value ); }
            elseif ( is_array( $defaults[$key] ) ) { $value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array(); }
            elseif ( is_scalar( $value ) ) { $value = sanitize_textarea_field( (string) $value ); }
            else { continue; }
            $options->set( $key, $value );
        }
        $options->save(); Gamb_Security_Suite::purge_page_caches();
        return rest_ensure_response( array( 'success' => true ) );
    }
    public function rest_start_export( $request ) { $result = Static_Engine::get_instance()->run_export( sanitize_key( $request->get_param( 'type' ) ?: '' ) ); return new \WP_REST_Response( $result, empty( $result['success'] ) ? 422 : 200 ); }
    public function rest_apply_single( $request ) { $id = absint( $request->get_param( 'post_id' ) ); if ( ! current_user_can( 'edit_post', $id ) ) { return new \WP_Error( 'forbidden', 'Acesso não autorizado.', array( 'status' => 403 ) ); } return rest_ensure_response( Static_Engine::get_instance()->run_export( 'single', $id ) ); }
    public function rest_create_backup( $request ) { $type = $request->get_param( 'type' ) === 'full' ? 'full' : 'db'; if ( 'full' === $type && ! class_exists( '\ZipArchive' ) ) { return new \WP_Error( 'zip_required', 'O pacote de banco, tema e extensões requer a extensão ZIP. O backup de banco permanece disponível.', array( 'status' => 422 ) ); } return rest_ensure_response( Backup_Service::create_backup( $type ) ); }
    public function rest_delete_backup( $request ) { return rest_ensure_response( array( 'success' => Backup_Service::delete_backup( sanitize_file_name( $request->get_param( 'filename' ) ?: '' ) ) ) ); }

    private function checkbox( $key, $label, $options ) { echo '<label class="gamb-setting"><input type="checkbox" data-opt="' . esc_attr( $key ) . '" ' . checked( $options[$key], true, false ) . '> <span>' . esc_html( $label ) . '</span></label>'; }
    private function input( $key, $label, $options, $type = 'text' ) { echo '<label class="gamb-field" for="gamb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input class="regular-text" id="gamb-' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" data-opt="' . esc_attr( $key ) . '" value="' . esc_attr( $options[$key] ) . '">'; }
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $options = Options::instance()->get_all();
        $diagnostics = Installation_Checks::latest( true );
        $publication_resources = get_option( Installation_Checks::EXPORT_OPTION, array() );
        $resources = ! empty( $publication_resources ) ? $publication_resources : ( $diagnostics['resources'] ?? array() );
        ?>
        <div class="wrap gamb-admin" id="gamb-guard-admin-app">
            <header class="gamb-admin-header"><img src="<?php echo esc_url( GAMB_STATIC_WP_URL . '/assets/gamb-wp-static-guard-logo.svg' ); ?>" alt="Gamb WP Static Guard" width="240"><p>Versão <?php echo esc_html( GAMB_STATIC_WP_VERSION ); ?> - Administração de publicação estática e controles de exposição.</p></header>
            <div id="gamb-toast" role="status" aria-live="polite" hidden></div>
            <div class="gamb-layout">
            <aside class="gamb-sidebar" aria-label="Publicação e segurança">
                <details class="gamb-sidebox" name="gamb-sections" open><summary>1. STATIC/Conversão - HTML/ZIP</summary><nav class="gamb-admin-nav" aria-label="Conversão estática"><button type="button" class="button" data-tab="publication" aria-controls="panel-publication" aria-pressed="true">Publicação e registro</button><button type="button" class="button" data-tab="diagnostics" aria-controls="panel-diagnostics" aria-pressed="false">Diagnóstico da instalação</button><button type="button" class="button" data-tab="settings" aria-controls="panel-settings" aria-pressed="false">Configuração da conversão</button></nav></details>
                <details class="gamb-sidebox" name="gamb-sections"><summary>2. GUARD/Segurança - Blindado</summary><nav class="gamb-admin-nav" aria-label="Controles de segurança"><button type="button" class="button" data-tab="guard" aria-controls="panel-guard" aria-pressed="false">Controles de exposição</button><button type="button" class="button" data-tab="backup" aria-controls="panel-backup" aria-pressed="false">Gerenciador de backups</button></nav></details>
                <section class="gamb-sidebox gamb-scope-box" aria-labelledby="gamb-scope-heading">
                    <h2 id="gamb-scope-heading">Selecione o Escopo de Conversão</h2>
                    <label class="screen-reader-text" for="gamb-quick-scope">Escopo de conversão</label>
                    <select id="gamb-quick-scope" aria-describedby="gamb-scope-help">
                        <option value="theme" <?php disabled( ! $options['first_full_export_completed'] ); ?> <?php selected( $options['first_full_export_completed'] && 'theme' === $options['generate_type'] ); ?>>Somente arquivos do tema (sem páginas/html)</option>
                        <option value="update" <?php disabled( ! $options['first_full_export_completed'] ); ?> <?php selected( $options['first_full_export_completed'] && 'update' === $options['generate_type'] ); ?>>Atualizar site (reconstrução completa)</option>
                        <option value="export" <?php selected( ! $options['first_full_export_completed'] || ! in_array( $options['generate_type'], array( 'theme', 'update' ), true ) ); ?>>Site Completo (Full Site)</option>
                    </select>
                    <p id="gamb-scope-help">Somente os arquivos do tema não geram páginas HTML e não são suficientes para publicar um site navegável. Atualizar site reconstrói a publicação completa.</p>
                    <p data-first-full-notice <?php if ( $options['first_full_export_completed'] ) : ?>hidden<?php endif; ?>><strong>A primeira publicação deve ser Site Completo. As outras opções serão liberadas após a conclusão.</strong></p>
                    <button type="button" class="button button-primary gamb-run-export-btn">Converter &amp; Publicar Agora</button>
                </section>
            </aside>
            <div class="gamb-content">
            <section class="gamb-panel" id="panel-publication" aria-labelledby="gamb-publication-heading">
                <h1 id="gamb-publication-heading">Publicação estática</h1>
                <p>A publicação consulta páginas públicas e grava os arquivos no destino configurado. Conteúdos privados, rascunhos e páginas com senha não integram a exportação.</p>
                <dl class="gamb-stats"><div><dt>Última publicação</dt><dd id="stat-last-time"><?php echo esc_html( $options['last_export_time'] ?: 'Não registrada' ); ?></dd></div><div><dt>Escopo</dt><dd id="stat-last-scope"><?php echo esc_html( $options['last_export_scope'] ?: 'Não registrado' ); ?></dd></div><div><dt>Arquivos processados</dt><dd id="stat-last-count"><?php echo (int) $options['last_export_files_count']; ?></dd></div></dl>
                <div id="gamb-latest-zip-wrap"></div><h2>Registro de atividades</h2><div id="gamb-activity-log-box"><?php foreach ( (array) $options['archive_status_messages'] as $entry ) : ?><p><?php echo esc_html( ( $entry['datetime'] ?? '' ) . ' - ' . ( $entry['message'] ?? '' ) ); ?></p><?php endforeach; ?></div>
            </section>
            <section class="gamb-panel" id="panel-diagnostics" hidden aria-labelledby="gamb-diagnostics-heading">
                <h2 id="gamb-diagnostics-heading">Diagnóstico da instalação</h2>
                <p>A primeira consulta é executada ao abrir este painel após instalar ou atualizar o plugin. As publicações completas inspecionam as páginas e folhas de estilo locais processadas.</p>
                <p>Verificação da origem: <span data-diagnostics-time><?php echo esc_html( $diagnostics['checked_at'] ?? 'Não realizada' ); ?></span></p>
                <button type="button" class="button" data-run-diagnostics>Verificar novamente</button>
                <ul data-installation-checks><?php foreach ( (array) ( $diagnostics['checks'] ?? array() ) as $check ) : ?><li><strong><?php echo esc_html( array( 'ok' => 'Verificado', 'warning' => 'Atenção', 'error' => 'Impedimento' )[$check['status']] ?? 'Informação' ); ?>:</strong> <?php echo esc_html( $check['message'] ); ?></li><?php endforeach; ?></ul>
                <h3>Dependências externas</h3>
                <p>Recursos de renderização e serviços externos exigem avaliação de versão, compatibilidade e licença antes da internalização. Links de navegação não são copiados. Nenhum arquivo do tema é alterado por este diagnóstico.</p>
                <p data-dependency-summary><?php echo esc_html( count( $resources['dependencies'] ?? array() ) . ' dependência(s); ' . ( $resources['external_navigation_count'] ?? 0 ) . ' link(s) externo(s) de navegação. ' . ( ! empty( $resources['limited'] ) ? 'Limite de inspeção atingido. ' : '' ) . ( $resources['scope'] ?? '' ) ); ?></p>
                <ul data-dependency-list><?php foreach ( (array) ( $resources['dependencies'] ?? array() ) as $dependency ) : ?><li><?php echo esc_html( $dependency['kind'] . ': ' . $dependency['url'] ); ?></li><?php endforeach; ?></ul>
            </section>
            <section class="gamb-panel" id="panel-settings" hidden><h2>Configuração da publicação</h2>
                <label class="gamb-field" for="gamb-delivery">Forma de entrega</label><select id="gamb-delivery" data-opt="delivery_method"><option value="local" <?php selected( $options['delivery_method'], 'local' ); ?>>Diretório local</option><option value="zip" <?php selected( $options['delivery_method'], 'zip' ); ?>>Arquivo ZIP</option></select>
                <?php $this->input( 'local_dir', 'Diretório local dedicado à publicação', $options ); ?>
                <label class="gamb-field" for="gamb-url-type">Formato dos endereços</label><select id="gamb-url-type" data-opt="destination_url_type"><option value="relative" <?php selected( $options['destination_url_type'], 'relative' ); ?>>Relativo ao domínio</option><option value="absolute" <?php selected( $options['destination_url_type'], 'absolute' ); ?>>Absoluto</option><option value="offline" <?php selected( $options['destination_url_type'], 'offline' ); ?>>Relativo ao arquivo</option></select>
                <?php $this->input( 'relative_path', 'Caminho público da publicação, vazio para a raiz do domínio', $options ); $this->input( 'destination_host', 'Domínio de destino, quando absoluto', $options ); ?>
                <fieldset><legend>Tipos de conteúdo</legend><?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) : if ( 'attachment' === $post_type->name ) { continue; } ?><label class="gamb-setting"><input class="gamb-pt-checkbox" type="checkbox" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( ( empty( $options['post_types'] ) || in_array( $post_type->name, (array) $options['post_types'], true ) ) ); ?>> <?php echo esc_html( $post_type->label ); ?></label><?php endforeach; ?></fieldset>
                <?php foreach ( array( 'single_enabled' => 'Habilitar publicação a partir do editor', 'auto_export' => 'Agendar publicação ao salvar conteúdo publicado', 'use_search' => 'Gerar índice de pesquisa local', 'fallback_404' => 'Usar página de erro de apoio em temas clássicos sem modelo 404', 'ensure_home_canonical' => 'Acrescentar canonical à Home exportada quando ausente', 'refresh_local_integrity' => 'Calcular SRI sobre os estilos e scripts reescritos pelo plugin', 'require_local_dependencies' => 'Interromper publicação com dependências externas identificadas ou inspeção incompleta', 'use_minify' => 'Aplicar minificação adicional', 'clear_directory_before_export' => 'Remover arquivos gerados obsoletos após publicação completa' ) as $key => $label ) { $this->checkbox( $key, $label, $options ); } ?>
                <?php $this->input( 'auto_export_delay', 'Espera mínima para agendamento, em segundos', $options, 'number' ); $this->input( 'webhook_url', 'Endereço opcional para notificar a publicação concluída', $options, 'url' ); ?>
                <p>A atualização automática depende da execução do agendador do WordPress. Pesquisa local requer um campo de pesquisa no tema e JavaScript habilitado no navegador.</p>
                <button type="button" class="button button-primary gamb-save-settings-btn">Salvar configurações</button>
            </section>
            <section class="gamb-panel" id="panel-guard" hidden><h2>Controles de exposição</h2><p>Os recursos publicados continuam públicos. Endereços opacos reduzem a identificação de caminhos; não substituem autenticação, autorização ou análise de segurança.</p>
                <?php foreach ( array( 'guard_active_on_live_site' => 'Aplicar tratamento também nas páginas públicas da origem', 'guard_universal_token_cloaking' => 'Reescrever recursos para endereços opacos', 'guard_strip_asset_versions' => 'Remover parâmetros de versão dos recursos públicos', 'guard_disable_speculation' => 'Desabilitar regras de pré-carregamento especulativo', 'guard_strict_static_html' => 'Interromper entrega com referências internas não resolvidas', 'guard_clean_body_classes' => 'Remover classes nativas do corpo, após validar a dependência do tema', 'guard_remove_block_styles' => 'Remover estilos nativos de blocos, somente se o tema não os utilizar', 'guard_disable_texturize' => 'Desabilitar substituições tipográficas automáticas', 'hide_comments' => 'Remover comentários HTML na saída pública', 'guard_security_headers' => 'Aplicar cabeçalhos de segurança', 'guard_restrict_rest_api' => 'Restringir consulta anônima aos recursos administrativos da API' ) as $key => $label ) { $this->checkbox( $key, $label, $options ); } ?>
                <button type="button" class="button button-primary gamb-save-settings-btn">Salvar configurações</button>
            </section>
            <section class="gamb-panel" id="panel-backup" hidden><h2>Gerenciador de backups</h2><p>São mantidas somente as seis cópias de segurança mais recentes, considerando todos os tipos. A retenção é aplicada após a conclusão de uma nova cópia.</p><p>O pacote de banco, tema e extensões não inclui uploads nem a instalação completa. A restauração é manual.</p><button type="button" class="button gamb-create-backup-btn" data-backup-type="db">Gerar cópia do banco</button> <button type="button" class="button gamb-create-backup-btn" data-backup-type="full">Gerar banco, tema e extensões</button><div class="gamb-backup-list" data-backup-list></div></section>
            </div></div>
        </div>
        <?php
    }
}
