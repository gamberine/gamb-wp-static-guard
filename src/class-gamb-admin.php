<?php

declare(strict_types=1);

namespace GambWPStaticGuard;

if (! defined('ABSPATH')) {
    exit;
}

/** Administração restrita a usuários com permissão de gerenciar configurações. */
class Admin_Controller
{
    const MENU = 'gamb-wp-static-guard';
    const API = 'gamb-wp-static-guard/v1';
    private static $instance;
    public static function get_instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_bar_menu', array($this, 'register_admin_bar_node'), 100);
        add_action('add_meta_boxes', array($this, 'register_single_push_meta_box'));
        add_filter('plugin_action_links_gamb-wp-static-guard/gamb-wp-static-guard.php', array($this, 'add_plugin_action_links'));
    }
    public function add_plugin_action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::MENU)) . '">Configurações</a>');
        return $links;
    }
    public function register_admin_menu()
    {
        add_menu_page('Gamb WP Static Guard', 'Gamb Static Guard', 'manage_options', self::MENU, array($this, 'render_admin_page'), GAMB_STATIC_WP_URL . '/assets/gamb-wp-static-guard-icon.svg', 76);
    }
    public function enqueue_admin_assets($hook)
    {
        wp_enqueue_style('gamb-guard-menu', GAMB_STATIC_WP_URL . '/assets/admin-menu.css', array(), GAMB_STATIC_WP_VERSION);
        if (false === strpos((string) $hook, self::MENU)) {
            return;
        }
        wp_enqueue_style('gamb-guard-admin', GAMB_STATIC_WP_URL . '/assets/admin-ui.css', array('gamb-guard-menu'), $this->asset_version('admin-ui.css'));
        wp_enqueue_script('gamb-destination-audit', GAMB_STATIC_WP_URL . '/assets/destination-audit.js', array(), $this->asset_version('destination-audit.js'), true);
        wp_enqueue_script('gamb-guard-insights', GAMB_STATIC_WP_URL . '/assets/admin-insights.js', array(), $this->asset_version('admin-insights.js'), true);
        wp_enqueue_script('gamb-guard-admin', GAMB_STATIC_WP_URL . '/assets/admin-ui.js', array('gamb-guard-insights','gamb-destination-audit'), $this->asset_version('admin-ui.js'), true);
        wp_localize_script('gamb-guard-admin', 'gambGuardData', array('restUrl' => rest_url(self::API . '/'), 'nonce' => wp_create_nonce('wp_rest'),'timezone'=>function_exists('wp_timezone_string')?wp_timezone_string():'UTC','fieldHelp'=>Admin_Insights::help(),'suggestedDirectory'=>wp_normalize_path(ABSPATH.'link-publico')));
    }
    public function register_admin_bar_node($bar)
    {
        if (is_admin() && current_user_can('manage_options')) {
            $bar->add_node(array('id' => self::MENU, 'title' => 'Gamb Static Guard', 'href' => admin_url('admin.php?page=' . self::MENU)));
        }
    }
    private function asset_version($file)
    {
        $time = @filemtime(dirname(__DIR__) . '/assets/' . $file);
        return GAMB_STATIC_WP_VERSION . ($time ? '.' . $time : '');
    }
    public function register_single_push_meta_box()
    {
        if (! current_user_can('manage_options') || ! Options::instance()->get('single_enabled', true)) {
            return;
        }
        foreach (get_post_types(array('public' => true), 'names') as $type) {
            if ('attachment' !== $type) {
                add_meta_box('gamb-single-push', 'Publicação estática', array($this, 'render_single_push_box'), $type, 'side');
            }
        }
    }
    public function render_single_push_box($post)
    {
        if ('publish' !== $post->post_status || $post->post_password) {
            echo '<p>Publique o conteúdo sem senha para disponibilizar a exportação.</p>';
            return;
        }
        echo '<p>O botão Atualizar agenda a publicação quando a atualização automática está habilitada. A publicação manual reconstrói o site para sincronizar listagens e pesquisa.</p>';
        wp_enqueue_script('gamb-guard-single', GAMB_STATIC_WP_URL . '/assets/admin-ui.js', array(), $this->asset_version('admin-ui.js'), true);
        wp_localize_script('gamb-guard-single', 'gambGuardData', array('restUrl' => rest_url(self::API . '/'), 'nonce' => wp_create_nonce('wp_rest')));
        echo '<button type="button" class="button" data-gamb-single="' . (int) $post->ID . '">Publicar e atualizar listagens</button><p role="status" aria-live="polite" data-gamb-single-message></p>';
        wp_enqueue_style('gamb-guard-single', GAMB_STATIC_WP_URL . '/assets/admin-ui.css', array(), $this->asset_version('admin-ui.css'));
    }
    public function register_rest_routes()
    {
        $permission = static function () {
            return current_user_can('manage_options');
        };
        $routes = array(
            '/operations' => array('methods' => 'POST', 'callback' => static function ($request) {
                return Operation_Progress::create(sanitize_key($request->get_param('kind') ?: ''));
            }),
            '/operation-status' => array('methods' => 'GET', 'callback' => static function ($request) {
                return Operation_Progress::snapshot((string)($request->get_param('id') ?: ''));
            }),
            '/operation-control' => array('methods' => 'POST', 'callback' => array(Operation_Progress::class, 'control')),
            '/publication-status' => array('methods' => 'GET', 'callback' => static function () {
                return array_merge(Publication_Status::snapshot(), array('sections' => self::section_badges()));
            }),
            '/version-monitor' => array('methods' => 'GET', 'callback' => static function () {
                return Version_Monitor::status();
            }),
            '/settings' => array(array('methods' => 'GET', 'callback' => array($this, 'rest_get_settings')), array('methods' => 'POST', 'callback' => array($this, 'rest_save_settings'))),
            '/original-access' => array(
                array('methods'=>'GET','callback'=>static function(){return rest_ensure_response(Original_Access::status());}),
                array('methods'=>'POST','callback'=>static function($request){if(!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest'))return new \WP_Error('invalid_nonce','Recarregue o painel.',array('status'=>403));return Original_Access::toggle($request);}),
            ),
            '/start-export' => array('methods' => 'POST', 'callback' => array($this, 'rest_start_export')),
            '/diagnostics' => array(array('methods' => 'GET', 'callback' => array($this, 'rest_get_diagnostics')), array('methods' => 'POST', 'callback' => array($this, 'rest_run_diagnostics'))),
            '/security-status' => array('methods' => 'GET', 'callback' => array($this, 'rest_security_status')),
            '/check-updates' => array('methods' => 'POST', 'callback' => array($this, 'rest_check_updates')),
            '/audit' => array('methods' => 'POST', 'callback' => array($this, 'rest_audit')),
            '/insights' => array('methods'=>'GET','callback'=>static function($request){return rest_ensure_response(Admin_Insights::snapshot(max(1,absint($request->get_param('page')))));}),
            '/destination-audit' => array(
                array('methods'=>'GET','callback'=>static fn()=>rest_ensure_response(array('report'=>Destination_Audit::latest(),'connectors'=>Audit_Connectors::status()))),
                array('methods'=>'POST','callback'=>static function($request){
                    if(!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest'))return new \WP_Error('invalid_nonce','Recarregue o painel para validar a requisição.',array('status'=>403));
                    return rest_ensure_response(array('report'=>Destination_Audit::run(),'connectors'=>Audit_Connectors::status()));
                }),
            ),
            '/audit-connectors' => array('methods'=>'POST','callback'=>static function($request){
                if(!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest'))return new \WP_Error('invalid_nonce','Recarregue o painel para validar a requisição.',array('status'=>403));
                return Audit_Connectors::save($request);
            }),
            '/destination' => array('methods'=>'POST','callback'=>static function($request){$result=Publication_Destination::inspect($request->get_param('path'),filter_var($request->get_param('create'),FILTER_VALIDATE_BOOLEAN));if(is_wp_error($result))return new \WP_Error($result->get_error_code(),$result->get_error_message(),array('status'=>400));return rest_ensure_response($result);}),
            '/publication-audit' => array('methods'=>'POST','callback'=>static function(){
                if (Options::instance()->get('delivery_method')!=='local') { return new \WP_Error('local_delivery_required','A auditoria do destino requer uma entrega local. Para ZIP, confira o pacote e o servidor de destino.',array('status'=>400)); }
                $directory=Publication_Destination::validate(Options::instance()->get('local_dir',''));if(is_wp_error($directory)){return $directory;}
                try {$report=Gamb_Static_Auditor::audit_directory($directory,'delivered');}catch(\Throwable $error){Gamb_Static_Auditor::processing_failure();$report=get_option(Gamb_Static_Auditor::PUBLICATION_OPTION,array());}
                return rest_ensure_response(array('audit'=>$report));
            }),
            '/apply-single' => array('methods' => 'POST', 'callback' => array($this, 'rest_apply_single')),
            '/backups' => array('methods' => 'GET', 'callback' => static function () {
                return rest_ensure_response(Backup_Service::list_backups());
            }),
            '/backup' => array('methods' => 'POST', 'callback' => array($this, 'rest_create_backup')),
            '/backup/delete' => array('methods' => 'POST', 'callback' => array($this, 'rest_delete_backup')),
        );
        foreach ($routes as $route => $definition) {
            if (isset($definition['methods'])) {
                $definition['permission_callback'] = $permission;
            } else {
                foreach ($definition as &$endpoint) {
                    $endpoint['permission_callback'] = $permission;
                }
                unset($endpoint);
            }
            $kinds = array('/settings' => 'settings', '/start-export' => 'export', '/apply-single' => 'single', '/diagnostics' => 'diagnostics', '/check-updates' => 'updates', '/audit' => 'audit', '/publication-audit'=>'publication-audit','/destination'=>'destination','/destination-audit'=>'destination-audit','/audit-connectors'=>'audit-connectors','/backup' => 'backup', '/backup/delete' => 'backup-delete');
            $wrap = static function ($endpoint) use ($route, $kinds) {
                if (isset($kinds[$route]) && $endpoint['methods'] === 'POST') {
                    $callback = $endpoint['callback'];
                    $kind = $kinds[$route];
                    $endpoint['callback'] = static function ($request) use ($callback, $kind) {
                        return Operation_Progress::execute($request, $kind, static function () use ($callback, $request) {
                            return call_user_func($callback, $request);
                        });
                    };
                }
                return $endpoint;
            };
            if (isset($definition['methods'])) {
                $definition = $wrap($definition);
            } else {
                $definition = array_map($wrap, $definition);
            }
            register_rest_route(self::API, $route, $definition);
        }
    }
    public function rest_get_settings()
    {
        return rest_ensure_response(array_intersect_key(Options::instance()->get_all(), Options::get_default_options()));
    }
    public function rest_get_diagnostics()
    {
        return rest_ensure_response(array('installation' => Installation_Checks::latest(), 'publication' => get_option(Installation_Checks::EXPORT_OPTION, array())));
    }
    public function rest_run_diagnostics()
    {
        Installation_Checks::run();
        return $this->rest_get_diagnostics();
    }
    public function rest_security_status()
    {
        $updater = Gamb_GitHub_Updater::instance();
        $release = $updater->latest();
        return rest_ensure_response(array('installed' => GAMB_STATIC_WP_VERSION, 'latest' => $release['version'] ?? null, 'update_available' => $release && version_compare($release['version'], GAMB_STATIC_WP_VERSION, '>'), 'token_configured' => '' !== $updater->token(), 'audit' => get_option(Gamb_Static_Auditor::REPORT_OPTION, array()), 'audit_pending' => (bool) get_option(Gamb_Static_Auditor::PENDING_OPTION, false)));
    }
    public function rest_check_updates()
    {
        if (! current_user_can('update_plugins')) {
            return new \WP_Error('forbidden', 'A verificação requer permissão para atualizar plugins.', array('status' => 403));
        }
        $updater = Gamb_GitHub_Updater::instance();
        try {
            $updater->repository();
        } catch (\RuntimeException $error) {
            return new \WP_Error('invalid_repository', 'Configure o repositório GitHub no formato owner/repo.', array('status' => 400));
        }
        $updater->clear_cache();
        $release = $updater->latest(true);
        if (! $release) {
            return new \WP_Error('github_unavailable', 'Não foi possível consultar uma release estável. Verifique repositório, token, conectividade e limite da API.', array('status' => 422));
        }
        wp_update_plugins();
        return $this->rest_security_status();
    }
    public function rest_audit()
    {
        Gamb_Static_Auditor::revalidate();
        return $this->rest_security_status();
    }
    public function rest_save_settings($request)
    {
        $options = Options::instance();
        $defaults = Options::get_default_options();
        $params = $request->get_params();
        if(isset($params['original_access_enabled'])||isset($params['original_access_expires_at']))return new \WP_Error('dedicated_control','Use o switch Ativar URL acesso WP e confirme o aviso.',array('status'=>400));
        if(array_key_exists('public_url',$params)){
            $url=$params['public_url'];$parsed=is_string($url)?wp_parse_url($url):false;
            if(!$parsed||empty($parsed['host'])||!in_array($parsed['scheme']??'',array('http','https'),true)||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['query'])||isset($parsed['fragment'])||preg_match('~[\x00-\x20<>"\x27`\\\\]|%(?:2f|5c|2e)~i',$url))return new \WP_Error('invalid_public_url','Informe a URL completa, sem credenciais, parâmetros ou fragmento.',array('status'=>400));
            $params['destination_scheme']=$parsed['scheme'].'://';$params['destination_host']=strtolower($parsed['host']).(isset($parsed['port'])?':'.$parsed['port']:'');$params['relative_path']=trim($parsed['path']??'','/');unset($params['public_url']);
        }
        if(isset($params['public_site_mode'])&&!in_array($params['public_site_mode'],array('wordpress','static'),true))return new \WP_Error('invalid_presentation','Selecione WordPress ou publicação estática.',array('status'=>400));
        foreach (array('version_monitor_allowed_ips', 'version_monitor_trusted_proxies') as $key) {
            if (array_key_exists($key, $params)) {
                $parsed = Version_Monitor::validate_list($params[$key]);
                if (is_wp_error($parsed)) {
                    return $parsed;
                }
                $params[$key] = $parsed;
            }
        }
        Operation_Progress::phase('settings', 1, 3, 1, 1, 'Configurações recebidas; validando os campos.');
        if (isset($params['destination_environment']) && !in_array($params['destination_environment'], array('production', 'preview', 'homologation'), true)) {
            return new \WP_Error('invalid_environment', 'Selecione produção, preview ou homologação.', array('status' => 400));
        }
        if (isset($params['github_update_repository']) && (! is_string($params['github_update_repository']) || ! Gamb_GitHub_Updater::valid_repository($params['github_update_repository']))) {
            return new \WP_Error('invalid_repository', 'Informe owner/repo para o GitHub.', array('status' => 400));
        }
        if (isset($params['github_update_repository']) && $params['github_update_repository'] !== $options->get('github_update_repository') && ! current_user_can('update_plugins')) {
            return new \WP_Error('forbidden', 'A configuração requer permissão para atualizar plugins.', array('status' => 403));
        }
        $new_token = $params['github_update_token'] ?? '';
        $clear_token = ! empty($params['github_clear_token']);
        if (! is_string($new_token)) {
            return new \WP_Error('invalid_token', 'Token GitHub inválido.', array('status' => 400));
        }
        if ('' !== $new_token || $clear_token) {
            if (! current_user_can('update_plugins') || defined('GAMB_GITHUB_TOKEN')) {
                return new \WP_Error('token_permission', 'O token exige permissão de atualização e não pode substituir uma constante de configuração.', array('status' => 403));
            }
        }
        if (isset($params['post_types']) && empty($params['post_types'])) {
            return new \WP_Error('missing_types', 'Selecione ao menos um tipo de conteúdo.', array('status' => 400));
        }
        if (isset($params['destination_scheme']) && ! in_array($params['destination_scheme'], array('https://', 'http://'), true)) {
            return new \WP_Error('invalid_scheme', 'Protocolo de publicação inválido.', array('status' => 400));
        }
        foreach (array('relative_path', 'destination_host') as $path_key) {
            if (isset($params[$path_key]) && (! is_string($params[$path_key]) || preg_match('~[\x00-\x20<>"\x27`?#]~', $params[$path_key]))) {
                return new \WP_Error('invalid_url_path', 'Informe um domínio ou caminho público sem espaços ou caracteres de marcação.', array('status' => 400));
            }
        }
        if (isset($params['delivery_method']) && ! in_array($params['delivery_method'], array('local', 'zip'), true)) {
            return new \WP_Error('invalid_delivery', 'Selecione entrega local ou arquivo ZIP.', array('status' => 400));
        }
        if (($params['delivery_method']??$options->get('delivery_method'))==='local') {
            $destination=Publication_Destination::validate($params['local_dir']??$options->get('local_dir',''));
            if(is_wp_error($destination)){return new \WP_Error('invalid_local',$destination->get_error_message(),array('status'=>400));}
            $params['local_dir']=$destination;
        }
        if(isset($params['destination_url_type'])&&!in_array($params['destination_url_type'],array('relative','absolute','offline'),true))return new \WP_Error('invalid_url_type','Formato de endereços inválido.',array('status'=>400));
        try {
            $resolved = Static_Engine::resolve_public_base(array_merge($options->get_all(), $params));
        } catch (\RuntimeException $error) {
            return new \WP_Error('invalid_public_destination', $error->getMessage(), array('status' => 400));
        }
        if (isset($params['destination_scheme'])) {
            $params['destination_scheme'] = wp_parse_url($resolved, PHP_URL_SCHEME) . '://';
        }
        $candidate=array_merge($options->get_all(),$params);
        if(class_exists(Origin_Context::class)){$originError=Origin_Context::destination_error($candidate);if($originError)return new \WP_Error('invalid_origin',$originError,array('status'=>400));}
        $presentation=Publication_View::settings_error($candidate);
        if($presentation)return new \WP_Error('invalid_presentation',$presentation,array('status'=>400));
        if ('' !== $new_token || $clear_token) {
            Gamb_GitHub_Updater::instance()->clear_cache();
            if (! Gamb_GitHub_Updater::instance()->set_token($clear_token ? '' : $new_token)) {
                return new \WP_Error('token_encryption', 'Não foi possível criptografar o token. Verifique OpenSSL e o formato informado.', array('status' => 400));
            }
        }
        Operation_Progress::phase('settings', 2, 3, 0, 1, 'Aplicando os campos validados.');
        $publication_changed = false;
        foreach ($params as $key => $value) {
            if (! array_key_exists($key, $defaults) || strpos($key, 'last_') === 0 || in_array($key, array('archive_status_messages', 'version', 'first_full_export_completed'), true)) {
                continue;
            }
            if (is_bool($defaults[$key])) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($defaults[$key])) {
                $value = max(0, (int) $value);
            } elseif (is_array($defaults[$key])) {
                $value = is_array($value) ? array_map('sanitize_text_field', $value) : array();
            } elseif (is_scalar($value)) {
                $value = sanitize_textarea_field((string) $value);
            } else {
                continue;
            }
            if ($key!=='public_site_mode' && !str_starts_with($key, 'version_monitor_') && !str_starts_with($key, 'github_') && $options->get($key) !== $value) {
                $publication_changed = true;
            }
            $options->set($key, $value);
        }
        Gamb_GitHub_Updater::instance()->clear_cache();
        Operation_Progress::phase('settings', 3, 3, 0, 1, 'Persistindo as configurações e invalidando os caches.');
        $options->save();
        if ($publication_changed) {
            Publication_Status::changed();
        }
        Gamb_Security_Suite::purge_page_caches();
        return rest_ensure_response(array('success' => true));
    }
    public function rest_start_export($request)
    {
        $result = Static_Engine::get_instance()->run_export(sanitize_key($request->get_param('type') ?: ''));
        return new \WP_REST_Response($result, empty($result['success']) ? 422 : 200);
    }
    public function rest_apply_single($request)
    {
        $id = absint($request->get_param('post_id'));
        if (! current_user_can('edit_post', $id)) {
            return new \WP_Error('forbidden', 'Acesso não autorizado.', array('status' => 403));
        }
        return rest_ensure_response(Static_Engine::get_instance()->run_export('single', $id));
    }
    public function rest_create_backup($request)
    {
        $type = $request->get_param('type') === 'full' ? 'full' : 'db';
        return rest_ensure_response(Backup_Service::create_backup($type));
    }
    public function rest_delete_backup($request)
    {
        return rest_ensure_response(array('success' => Backup_Service::delete_backup(sanitize_file_name($request->get_param('filename') ?: ''))));
    }

    private function checkbox($key, $label, $options)
    {
        echo '<label class="gamb-setting"><input type="checkbox" data-opt="' . esc_attr($key) . '" ' . checked($options[$key], true, false) . '> <span>' . esc_html($label) . '</span></label>';
    }
    private function input($key, $label, $options, $type = 'text')
    {
        echo '<label class="gamb-field" for="gamb-' . esc_attr($key) . '">' . esc_html($label) . '</label><input class="regular-text" id="gamb-' . esc_attr($key) . '" type="' . esc_attr($type) . '" data-opt="' . esc_attr($key) . '" value="' . esc_attr($options[$key]) . '">';
    }
    /** Resume evidências registradas, sem executar diagnóstico ou alterar configurações. */
    public static function section_badges(): array
    {
        $installation = (array) Installation_Checks::latest();
        $audit = (array) get_option(Gamb_Static_Auditor::PUBLICATION_OPTION, get_option(Gamb_Static_Auditor::REPORT_OPTION, array()));
        $checks = array_values(array_filter((array) ($installation['checks'] ?? array()), 'is_array'));
        $badge = static function (string $state, string $label, string $tone, string $message): array {
            return compact('state', 'label', 'tone', 'message');
        };
        $assess = static function (array $items) use ($installation, $badge): array {
            $statuses = array_column($items, 'status');
            $reasons=array_values(array_filter(array_map(static fn($check)=>($check['status']??'')!=='ok'?(string)($check['message']??''): '',$items)));
            $reason=$reasons?' '.implode(' ',array_slice($reasons,0,2)):'';
            if (in_array('error', $statuses, true)) {
                return $badge('error', 'Impedimento', 'error', 'O diagnóstico registrou impedimentos.'.$reason.' Consulte Análises e auditoria.');
            }
            if (empty($installation['checked_at']) || empty($items)) {
                return $badge('unknown', 'Não verificado', 'neutral', 'Execute o diagnóstico da instalação.');
            }
            if (($installation['version'] ?? '') !== GAMB_STATIC_WP_VERSION) {
                return $badge('stale', 'Revalidar', 'warning', 'O diagnóstico pertence a outra versão do plugin. Execute a verificação novamente.');
            }
            if (count($statuses) !== count($items) || array_diff($statuses, array('ok'))) {
                return $badge('warning', 'Atenção', 'warning', 'O diagnóstico registrou alertas.'.$reason.' Consulte Análises e auditoria.');
            }
            return $badge('ok', 'Verificado', 'success', 'Nenhum impedimento ou alerta no diagnóstico registrado.');
        };
        $static = $assess($checks);
        $guard = $assess(array_values(array_filter($checks, static function (array $check): bool {
            return in_array($check['id'] ?? '', array('html_parser', 'crypto', 'dependencies'), true);
        })));
        $audit_error = !empty($audit['checked_at']) && isset($audit['success']) && false === $audit['success'];
        $audit_warning = false;
        foreach ((array) ($audit['findings'] ?? array()) as $finding) {
            $severity = strtoupper((string) ($finding['severity'] ?? ''));
            $audit_error = $audit_error || in_array($severity, array('CRITICAL', 'ERROR', 'HIGH'), true);
            $audit_warning = $audit_warning || in_array($severity, array('WARNING', 'MEDIUM', 'LOW'), true);
        }
        $guard_target = 'diagnostics';
        if ($audit_error) {
            $guard_target = 'publication-audit';
            $guard = $badge('error', 'Impedimento', 'error', 'A auditoria registrou impedimentos. Consulte Auditoria da publicação.');
        } elseif ('error' !== $guard['tone']) {
            if (get_option(Gamb_Static_Auditor::PENDING_OPTION, false) || (!empty($audit['checked_at']) && ($audit['version'] ?? '') !== GAMB_STATIC_WP_VERSION)) {
                $guard_target = 'publication-audit';
                $guard = $badge('stale', 'Revalidar', 'warning', 'Há uma revalidação de segurança pendente. Execute a auditoria.');
            } elseif ($audit_warning) {
                $guard_target = 'publication-audit';
                $guard = $badge('warning', 'Atenção', 'warning', 'A auditoria registrou alertas. Consulte Auditoria da publicação.');
            } elseif ('success' === $guard['tone']) {
                if (empty($audit['checked_at']) || true !== ($audit['success'] ?? null) || (int) ($audit['documents'] ?? 0) < 1) {
                    $guard = $badge('unknown', 'Não auditado', 'neutral', 'Execute a auditoria sobre os documentos da publicação estática.');
                } else {
                    $guard = $badge('ok', 'Verificado', 'success', 'Diagnóstico e auditoria registrados sem impedimentos ou alertas nos documentos inspecionados.');
                }
            }
        }
        $static['target'] = 'diagnostics';
        $guard['target'] = $guard_target;
        return array('static' => $static, 'guard' => $guard);
    }

    public function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $options = Options::instance()->get_all();
        $diagnostics = Installation_Checks::latest();
        $publication = Publication_Status::snapshot();
        $sections = self::section_badges();
        try { $public_url=Static_Engine::get_instance()->public_base_url(); } catch (\Throwable $error) { $public_url=''; }
        $saved_public_url=$public_url;if(class_exists(Origin_Context::class))$public_url=Origin_Context::map($public_url);
        $publication_resources = get_option(Installation_Checks::EXPORT_OPTION, array());
        $resources = ! empty($publication_resources) ? $publication_resources : ($diagnostics['resources'] ?? array());
?>
        <div class="wrap gamb-admin" id="gamb-guard-admin-app">
            <header class="gamb-admin-header">
                <div class="gamb-admin-brand">
                    <img src="<?php echo esc_url(GAMB_STATIC_WP_URL . '/assets/gamb-wp-static-guard-logo.svg'); ?>" alt="Gamb WP Static Guard" width="420" height="100">
                </div>
                <div class="gamb-admin-header-info">
                    <p>Administração de publicação estática e controles de exposição - Versão <?php echo esc_html(GAMB_STATIC_WP_VERSION); ?> · Horários: <?php echo esc_html(function_exists('wp_timezone_string')?wp_timezone_string():'UTC'); ?></p>
                    <a class="button gamb-releases-link" href="https://github.com/gamberine/gamb-wp-static-guard/releases/latest" target="_blank" rel="noopener noreferrer">Releases no GitHub <span aria-hidden="true">↗</span></a>
                </div>
            </header>
            <div id="gamb-toast" class="gamb-process" role="status" aria-live="polite" aria-atomic="true" hidden>
                <div class="gamb-process-heading"><strong data-process-label></strong><span data-process-percent></span><nav class="gamb-quick-links" aria-label="Acessos rápidos"><a data-publication-link href="<?php echo esc_url($public_url); ?>" target="_blank" rel="noopener noreferrer" <?php if (!$public_url) echo 'hidden'; ?>>Abrir publicação ↗</a><a href="<?php echo esc_url(Admin_Insights::manual_url()); ?>" target="_blank" rel="noopener noreferrer">Manual ↗</a></nav></div>
                <div class="gamb-progress-track" role="progressbar" aria-label="Progresso das etapas" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-process-bar></span></div>
                <div class="gamb-process-detail"><div class="gamb-process-summary"><p data-process-message></p><small data-process-units></small></div><div class="gamb-process-actions" hidden><button type="button" class="button gamb-process-stop" data-process-stop disabled>Parar</button><button type="button" class="button button-primary" data-process-resume hidden>Continuar</button></div></div>
                <button type="button" class="gamb-static-problem" data-static-problem data-open-panel="analysis" hidden></button>
            </div>
            <div class="gamb-layout">
                <aside class="gamb-sidebar" aria-label="Publicação e segurança">
                    <details class="gamb-sidebox" name="gamb-sections" open>
                        <summary><span>1. STATIC/Conversão</span><button type="button" class="gamb-badge" data-section-badge="static" aria-live="polite" title="<?php echo esc_attr($sections['static']['message']); ?>" data-tone="<?php echo esc_attr($sections['static']['tone']); ?>"><?php echo esc_html($sections['static']['label']); ?></button></summary>
                        <nav class="gamb-admin-nav" aria-label="Conversão estática">
                            <button type="button" class="button" data-tab="publication" aria-controls="panel-publication" aria-pressed="true">Visão geral da publicação</button>
                            <button type="button" class="button" data-tab="activity" aria-controls="panel-activity" aria-pressed="false">Registro de atividades</button>
                            <button type="button" class="button" data-tab="settings" aria-controls="panel-settings" aria-pressed="false">Destino e endereços</button>
                            <button type="button" class="button" data-tab="conversion" aria-controls="panel-conversion" aria-pressed="false">Conteúdo e conversão</button>
                            <button type="button" class="button" data-tab="automation" aria-controls="panel-automation" aria-pressed="false">Automação e notificações</button>
                            <button type="button" class="button" data-tab="performance" aria-controls="panel-performance" aria-pressed="false">Integridade e desempenho</button>
                        </nav>
                    </details>
                    <details class="gamb-sidebox" name="gamb-sections">
                        <summary><span>2. Guard/Segurança</span><button type="button" class="gamb-badge" data-section-badge="guard" aria-live="polite" title="<?php echo esc_attr($sections['guard']['message']); ?>" data-tone="<?php echo esc_attr($sections['guard']['tone']); ?>"><?php echo esc_html($sections['guard']['label']); ?></button></summary>
                        <nav class="gamb-admin-nav" aria-label="Controles de segurança">
                            <button type="button" class="button" data-tab="guard" aria-controls="panel-guard" aria-pressed="false">Controles de exposição</button>
                            <button type="button" class="button" data-tab="updates" aria-controls="panel-updates" aria-pressed="false">Atualizações do plugin</button>
                            <button type="button" class="button" data-tab="audit" aria-controls="panel-audit" aria-pressed="false">Configuração da auditoria</button>
                            <button type="button" class="button" data-tab="monitor" aria-controls="panel-monitor" aria-pressed="false">Monitor da versão WP</button>
                            <button type="button" class="button" data-tab="monitor-query" aria-controls="panel-monitor-query" aria-pressed="false">Consultas do monitor</button>
                            <button type="button" class="button" data-tab="backup" aria-controls="panel-backup" aria-pressed="false">Gerenciador de backups</button>
                        </nav>
                    </details>
                    <details class="gamb-sidebox gamb-analysis-box" name="gamb-sections">
                        <summary><span>3. Análises e auditoria</span></summary>
                        <nav class="gamb-admin-nav" aria-label="Análises e auditoria">
                            <?php foreach (array('destination-audit'=>'Auditoria','analysis'=>'Resumo e pendências','publication-audit'=>'Auditoria da publicação','origin-audit'=>'Auditoria da origem','diagnostics'=>'Diagnóstico da instalação','dependencies'=>'Dependências externas','files'=>'Arquivos publicados') as $tab=>$label) : ?>
                            <button type="button" class="button" data-tab="<?php echo esc_attr($tab); ?>" aria-controls="panel-<?php echo esc_attr($tab); ?>" aria-pressed="false"><?php echo esc_html($label); ?></button>
                            <?php endforeach; ?>
                        </nav>
                    </details>
                    <details class="gamb-sidebox gamb-scope-box" name="gamb-sections">
                        <summary id="gamb-scope-heading"><span>4. Escopo da Conversão</span><span class="gamb-badge" data-publication-badge data-tone="<?php echo esc_attr($publication['tone']); ?>"><?php echo esc_html($publication['label']); ?></span></summary>
                        <div class="gamb-scope-content">
                            <p data-publication-message><?php echo esc_html($publication['message']); ?></p>
                            <label class="screen-reader-text" for="gamb-quick-scope">Escopo de conversão</label>
                            <select id="gamb-quick-scope" aria-describedby="gamb-scope-help">
                                <option value="theme" <?php disabled(! $options['first_full_export_completed']); ?> <?php selected($options['first_full_export_completed'] && 'theme' === $options['generate_type']); ?>>Somente arquivos do tema (sem páginas/html)</option>
                                <option value="update" <?php disabled(! $options['first_full_export_completed']); ?> <?php selected($options['first_full_export_completed'] && 'update' === $options['generate_type']); ?>>Atualizar site (reconstrução completa)</option>
                                <option value="export" <?php selected(! $options['first_full_export_completed'] || ! in_array($options['generate_type'], array('theme', 'update'), true)); ?>>Site Completo (Full Site)</option>
                            </select>
                            <p id="gamb-scope-help">Somente os arquivos do tema não geram páginas HTML e não são suficientes para publicar um site navegável. Atualizar site reconstrói a publicação completa.</p>
                            <p data-first-full-notice <?php if ($options['first_full_export_completed']) : ?>hidden<?php endif; ?>><strong>A primeira publicação deve ser Site Completo. As outras opções serão liberadas após a conclusão.</strong></p>
                            <button type="button" class="button button-primary gamb-run-export-btn">Converter &amp; Publicar Agora</button>
                        </div>
                    </details>
                </aside>
                <div class="gamb-content">
                    <div class="gamb-settings-footer" data-settings-footer hidden><button type="button" class="button button-primary gamb-save-settings-btn">Salvar configuração</button></div>
                    <section class="gamb-panel" id="panel-publication" aria-labelledby="gamb-publication-heading">
                        <h1 id="gamb-publication-heading">Publicação estática</h1>
                        <p>A publicação consulta páginas públicas e grava os arquivos no destino configurado. Conteúdos privados, rascunhos e páginas com senha não integram a exportação.</p>
                        <dl class="gamb-stats">
                            <div>
                                <dt>Última publicação</dt>
                                <dd id="stat-last-time"><?php echo esc_html($options['last_export_time'] ?: 'Não registrada'); ?></dd>
                            </div>
                            <div>
                                <dt>Escopo</dt>
                                <dd id="stat-last-scope"><?php echo esc_html($options['last_export_scope'] ?: 'Não registrado'); ?></dd>
                            </div>
                            <div>
                                <dt>Unidades processadas</dt>
                                <dd id="stat-last-count"><?php echo (int) $options['last_export_files_count']; ?></dd>
                            </div>
                        </dl>
                        <p>As unidades representam etapas de processamento. Um mesmo recurso pode gerar mais de um arquivo físico.</p>
                        <p class="gamb-destination-summary" data-publication-location></p>
                        <div class="gamb-action-row"><button type="button" class="button" data-open-panel="files">Ver arquivos e localização</button><button type="button" class="button button-primary" data-open-panel="publication-audit">Ver auditoria da publicação</button><button type="button" class="button" data-open-panel="analysis">Entender alertas</button></div>
                        <div id="gamb-latest-zip-wrap"></div>
                    </section>
                    <section class="gamb-panel" id="panel-activity" hidden>
                        <h2>Registro de atividades</h2>
                        <div id="gamb-activity-log-box"><?php foreach ((array) $options['archive_status_messages'] as $entry) : ?><p><?php echo esc_html(($entry['datetime'] ?? '') . ' - ' . ($entry['message'] ?? '')); ?></p><?php endforeach; ?></div>
                    </section>
                    <section class="gamb-panel" id="panel-diagnostics" hidden aria-labelledby="gamb-diagnostics-heading">
                        <h2 id="gamb-diagnostics-heading">Diagnóstico da instalação</h2>
                        <p>Verifica recursos do PHP, páginas da origem, modelo 404 e condições necessárias à conversão. Alertas não equivalem necessariamente a bloqueios.</p>
                        <p>Verificação da origem: <span data-diagnostics-time><?php echo esc_html($diagnostics['checked_at'] ?? 'Não realizada'); ?></span></p>
                        <button type="button" class="button" data-run-diagnostics>Verificar novamente</button>
                        <ul data-installation-checks><?php foreach ((array) ($diagnostics['checks'] ?? array()) as $check) : ?><li><strong><?php echo esc_html(array('ok' => 'Verificado', 'warning' => 'Atenção', 'error' => 'Impedimento')[$check['status']] ?? 'Informação'); ?>:</strong> <?php echo esc_html($check['message']); ?></li><?php endforeach; ?></ul>
                    </section>
                    <section class="gamb-panel" id="panel-dependencies" hidden>
                        <h2>Dependências externas</h2>
                        <p>Recursos de renderização e serviços externos exigem avaliação de versão, compatibilidade e licença antes da internalização. Links de navegação não são copiados. Nenhum arquivo do tema é alterado por este diagnóstico.</p>
                        <p data-dependency-summary><?php echo esc_html(count($resources['dependencies'] ?? array()) . ' dependência(s); ' . ($resources['external_navigation_count'] ?? 0) . ' link(s) externo(s) de navegação. ' . (! empty($resources['limited']) ? 'Limite de inspeção atingido. ' : '') . ($resources['scope'] ?? '')); ?></p>
                        <dl class="gamb-stats"><div><dt>Recursos externos encontrados</dt><dd data-dependency-count>—</dd></div><div><dt>Links de navegação</dt><dd data-navigation-count>—</dd></div><div><dt>Estado da inspeção</dt><dd data-dependency-state>—</dd></div></dl>
                        <div class="gamb-callout"><strong>Como interpretar</strong><p>Zero recursos encontrados vale apenas para o HTML e CSS inspecionados. Chamadas criadas por JavaScript, serviços, formulários e carregamentos tardios precisam ser conferidos na aba Rede do navegador. Links para outros sites são navegação e permanecem externos.</p></div>
                        <h3>Recursos que participam da renderização</h3>
                        <ul data-dependency-list><?php foreach ((array) ($resources['dependencies'] ?? array()) as $dependency) : ?><li><?php echo esc_html($dependency['kind'] . ': ' . $dependency['url']); ?></li><?php endforeach; ?></ul>
                        <details><summary>Links externos de navegação</summary><ul data-navigation-list></ul></details>
                        <h3>Como proceder</h3><ol><li>Identifique o recurso, o responsável e a necessidade para o portal.</li><li>Antes de internalizar, confira licença, versão e compatibilidade. Este diagnóstico não copia bibliotecas.</li><li>Valide o site no navegador e refaça a publicação após corrigir a origem.</li></ol>
                        <a class="button" data-report-download="summary">Baixar relatório completo (JSON)</a>
                    </section>
                    <section class="gamb-panel" id="panel-settings" data-settings-panel hidden>
                        <h2>Destino e endereços</h2>
                        <div class="gamb-callout gamb-project-mode">
                            <div class="gamb-project-row"><div><label class="gamb-field" for="gamb-public-site-mode">O que abrir na URL principal do projeto</label>
                            <select id="gamb-public-site-mode" data-opt="public_site_mode"><option value="wordpress" <?php selected($options['public_site_mode']??'wordpress','wordpress'); ?>>WordPress</option><option value="static" <?php selected($options['public_site_mode']??'wordpress','static'); ?>>Publicação estática na URL principal</option></select></div>
                            <p>Acesso nesta origem: <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(home_url('/')); ?></a></p></div>
                            <p data-public-site-status><?php echo esc_html(Publication_View::status()['message']); ?></p>
                            <small>Estático é o padrão. Para validar arquivos, use o acesso WP privado abaixo. O modo WordPress desta seleção é público e foi mantido apenas por compatibilidade.</small>
                        </div>
                        <fieldset class="gamb-origin-section"><legend>1. Endereço da publicação</legend>
                            <div class="gamb-url-editor"><div><label class="gamb-field" for="gamb-public-url">URL pública completa</label><input id="gamb-public-url" type="url" data-public-url value="<?php echo esc_attr($saved_public_url?:Origin_Context::canonical()); ?>" placeholder="https://site.exemplo.org/portal/"></div>
                            <button type="button" class="button" data-use-project-url data-project-url="<?php echo esc_url(Origin_Context::canonical()); ?>">Usar URL padrão da instalação</button></div>
                            <p class="gamb-help">Protocolo, domínio e caminho ficam neste único campo. Localhost e IP compartilham a mesma entrega; o acesso atual não altera o endereço salvo.</p>
                        </fieldset>
                        <fieldset class="gamb-origin-section"><legend>2. WordPress original — validação privada</legend>
                            <div class="gamb-original-settings"><button type="button" class="button" role="switch" aria-checked="<?php echo Original_Access::enabled()?'true':'false'; ?>" data-gamb-original-toggle>Ativar URL acesso WP — <span data-gamb-original-state><?php echo Original_Access::enabled()?'Ativado':'Desativado'; ?></span></button><a class="button" data-gamb-original-open href="<?php echo Original_Access::enabled()?esc_url(Original_Access::url()):'#'; ?>" target="_blank" rel="noopener noreferrer" <?php if(!Original_Access::enabled())echo 'hidden'; ?>>Abrir WordPress original ↗</a></div>
                            <p data-gamb-original-message><?php echo esc_html(Original_Access::status()['message']); ?></p><p class="gamb-help">Exige confirmação, permissão administrativa e link da própria sessão. Expira em uma hora. A URL principal permanece estática e o admin mostra um alerta fixo.</p>
                        </fieldset>
                        <h3>3. Entrega e ambiente</h3>
                        <p class="gamb-help gamb-destination-address"><span data-proposed-url></span> <span data-saved-address hidden>Salvo: <a data-publication-url href="<?php echo esc_url($public_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($public_url); ?></a></span></p>
                        <div class="gamb-delivery-row">
                        <div>
                        <label class="gamb-field" for="gamb-delivery">Forma de entrega</label><select id="gamb-delivery" data-opt="delivery_method">
                            <option value="local" <?php selected($options['delivery_method'], 'local'); ?>>Diretório local</option>
                            <option value="zip" <?php selected($options['delivery_method'], 'zip'); ?>>Arquivo ZIP</option>
                        </select>
                        </div>
                        <div><?php $this->input('local_dir', 'Diretório local dedicado à publicação', $options); ?><div class="gamb-action-row"><button type="button" class="button" data-destination-suggest>Usar sugestão</button><button type="button" class="button" data-destination-check>Verificar pasta</button><button type="button" class="button" data-destination-create hidden disabled>Criar pasta</button></div></div></div><p data-destination-result role="status" aria-live="polite">Verifique a pasta antes de salvar. A criação só ocorre ao clicar em Criar pasta.</p><div class="gamb-form-grid">
                        <div>
                        <label class="gamb-field" for="gamb-url-type">Formato dos endereços</label><select id="gamb-url-type" data-opt="destination_url_type">
                            <option value="relative" <?php selected($options['destination_url_type'], 'relative'); ?>>Relativo ao domínio</option>
                            <option value="absolute" <?php selected($options['destination_url_type'], 'absolute'); ?>>Absoluto</option>
                            <option value="offline" <?php selected($options['destination_url_type'], 'offline'); ?>>Relativo ao arquivo</option>
                        </select>
                        </div>
                        <input type="hidden" data-opt="relative_path" value="<?php echo esc_attr($options['relative_path']); ?>">
                        <input type="hidden" data-opt="destination_host" value="<?php echo esc_attr($options['destination_host']); ?>">
                        <input type="hidden" data-opt="destination_scheme" value="<?php echo esc_attr($options['destination_scheme']); ?>">
                        <div>
                        <label class="gamb-field" for="gamb-environment">Ambiente do destino</label><select id="gamb-environment" data-opt="destination_environment">
                            <option value="production" <?php selected($options['destination_environment'], 'production'); ?>>Produção — preservar indexação</option>
                            <option value="preview" <?php selected($options['destination_environment'], 'preview'); ?>>Preview — noindex, nofollow</option>
                            <option value="homologation" <?php selected($options['destination_environment'], 'homologation'); ?>>Homologação — noindex, nofollow</option>
                        </select>
                        </div>
                        </div>
                        <p class="gamb-help">Para localhost e compartilhamento por IP, use HTTP e endereços relativos ao domínio. Em preview/homologação, a publicação recebe noindex. Instalações em subdomínio não podem publicar no domínio principal.</p>
                    </section>
                    <section class="gamb-panel" id="panel-conversion" data-settings-panel hidden>
                        <h2>Conteúdo e conversão</h2>
                        <fieldset>
                            <legend>Tipos de conteúdo</legend><?php foreach (get_post_types(array('public' => true), 'objects') as $post_type) : if ('attachment' === $post_type->name) {
                                                                        continue;
                                                                    } ?><label class="gamb-setting"><input class="gamb-pt-checkbox" type="checkbox" value="<?php echo esc_attr($post_type->name); ?>" <?php checked((empty($options['post_types']) || in_array($post_type->name, (array) $options['post_types'], true))); ?>> <?php echo esc_html($post_type->label); ?></label><?php endforeach; ?>
                        </fieldset>
                        <?php foreach (array('use_search' => 'Gerar índice de pesquisa local', 'fallback_404' => 'Usar página de erro de apoio em temas clássicos sem modelo 404', 'ensure_home_canonical' => 'Acrescentar canonical à Home exportada quando ausente', 'require_local_dependencies' => 'Interromper publicação com dependências externas identificadas ou inspeção incompleta', 'clear_directory_before_export' => 'Remover arquivos gerados obsoletos após publicação completa') as $key => $label) {
                            $this->checkbox($key, $label, $options);
                        } ?>
                        <p class="gamb-help">A pesquisa local requer um campo de pesquisa no tema e JavaScript no navegador.</p>
                    </section>
                    <section class="gamb-panel" id="panel-automation" data-settings-panel hidden>
                        <h2>Automação e notificações</h2>
                        <?php $this->checkbox('single_enabled', 'Habilitar publicação a partir do editor', $options);
                        $this->checkbox('auto_export', 'Agendar publicação ao salvar conteúdo publicado', $options); ?>
                        <?php $this->input('auto_export_delay', 'Espera mínima para agendamento, em segundos', $options, 'number');
                        $this->input('webhook_url', 'Endereço opcional para notificar a publicação concluída', $options, 'url'); ?>
                        <p class="gamb-help">A atualização automática depende da execução do agendador do WordPress.</p>
                    </section>
                    <section class="gamb-panel" id="panel-performance" data-settings-panel hidden>
                        <h2>Integridade e desempenho</h2>
                        <?php $this->checkbox('refresh_local_integrity', 'Calcular SRI sobre os estilos e scripts reescritos pelo plugin', $options);
                        $this->checkbox('disable_local_integrity', 'Desabilitar SRI local neste destino (somente quando CDN/proxy transforma os arquivos)', $options);
                        $this->checkbox('use_minify', 'Aplicar minificação adicional', $options); ?>
                        <p class="gamb-help">SRI local permanece ativo por padrão. A desativação local não altera a integridade de recursos externos.</p>
                    </section>
                    <section class="gamb-panel" id="panel-guard" data-settings-panel hidden>
                        <h2>Controles de exposição</h2>
                        <p>Os recursos publicados continuam públicos. Endereços opacos reduzem a identificação de caminhos; não substituem autenticação, autorização ou análise de segurança.</p>
                        <?php foreach (array('guard_active_on_live_site' => 'Aplicar tratamento também nas páginas públicas da origem', 'guard_universal_token_cloaking' => 'Reescrever recursos para endereços opacos', 'guard_strict_static_html' => 'Interromper entrega com referências internas não resolvidas', 'guard_clean_body_classes' => 'Remover classes nativas do corpo, após validar a dependência do tema', 'guard_remove_block_styles' => 'Remover estilos nativos de blocos, somente se o tema não os utilizar', 'hide_comments' => 'Remover comentários HTML na saída pública', 'guard_security_headers' => 'Aplicar cabeçalhos de segurança', 'guard_restrict_rest_api' => 'Restringir consulta anônima aos recursos administrativos da API') as $key => $label) {
                            $this->checkbox($key, $label, $options);
                        } ?>
                    </section>
                    <section class="gamb-panel" id="panel-monitor" data-settings-panel hidden aria-labelledby="gamb-monitor-heading">
                        <h2 id="gamb-monitor-heading">Monitor da versão WordPress</h2>
                        <p>Disponibiliza a versão em JSON somente para IPs exatos autorizados. Uma lista vazia bloqueia todas as origens. O acesso administrativo não dispensa essa restrição.</p>
                        <?php $this->checkbox('version_monitor_enabled', 'Habilitar o monitor da versão WordPress', $options); ?>
                        <div class="gamb-form-grid">
                        <div><label class="gamb-field" for="gamb-monitor-ips">IPs autorizados — um IPv4 ou IPv6 por linha</label><textarea class="large-text code" rows="5" id="gamb-monitor-ips" data-opt="version_monitor_allowed_ips"><?php echo esc_textarea(implode("\n", (array)$options['version_monitor_allowed_ips'])); ?></textarea></div>
                        <div><label class="gamb-field" for="gamb-monitor-proxies">Proxies explicitamente confiáveis — um IP por linha</label><textarea class="large-text code" rows="5" id="gamb-monitor-proxies" data-opt="version_monitor_trusted_proxies"><?php echo esc_textarea(implode("\n", (array)$options['version_monitor_trusted_proxies'])); ?></textarea></div>
                        </div>
                        <p>Deixe proxies vazios em conexões diretas. X-Forwarded-For só é considerado quando a conexão vem de um proxy informado. Não são aceitos CIDR, curingas, nomes de host ou autorização automática de loopback.</p>
                    </section>
                    <section class="gamb-panel" id="panel-monitor-query" hidden>
                        <h3>Endereços de consulta</h3>
                        <p><a data-monitor-url href="<?php echo esc_url(rest_url('gamb-wp-static-guard/v1/wordpress-version')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(rest_url('gamb-wp-static-guard/v1/wordpress-version')); ?></a></p>
                        <p class="gamb-wrap-anywhere">Compatibilidade: <code><?php echo esc_html(rest_url('monitor-versao-wp')); ?></code> e <code><?php echo esc_html(rest_url('monitor-versao-wp/check')); ?></code>. A rota antiga <code>/meu-sistema/v1/versao</code> permanece removida.</p>
                        <p data-monitor-status></p><button type="button" class="button" data-test-monitor>Verificar acesso desta origem</button>
                        <pre data-monitor-result class="gamb-monitor-result"></pre>
                    </section>
                    <section class="gamb-panel" id="panel-backup" hidden>
                        <h2>Gerenciador de backups</h2>
                        <p>São mantidas somente as seis cópias de segurança mais recentes, considerando todos os tipos. A retenção é aplicada após a conclusão de uma nova cópia.</p>
                        <p>O pacote de banco, tema e extensões não inclui uploads nem a instalação completa. A restauração é manual.</p><button type="button" class="button gamb-create-backup-btn" data-backup-type="db">Gerar cópia do banco</button> <button type="button" class="button gamb-create-backup-btn" data-backup-type="full">Gerar banco, tema e extensões</button>
                        <div class="gamb-backup-list" data-backup-list role="region" aria-label="Cópias de segurança" tabindex="0"></div>
                    </section>
                    <section class="gamb-panel" id="panel-updates" data-settings-panel hidden aria-labelledby="gamb-updates-heading">
                        <h2 id="gamb-updates-heading">Atualizações do plugin</h2>
                        <p>As versões são consultadas no GitHub Releases. A instalação e a ativação das atualizações automáticas utilizam os controles nativos do WordPress.</p>
                        <p data-update-status role="status" aria-live="polite">Versão instalada: <?php echo esc_html(GAMB_STATIC_WP_VERSION); ?>.</p>
                        <button type="button" class="button" data-check-updates>Verificar atualizações agora</button>
                        <a class="button" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Gerenciar atualizações no WordPress</a>
                        <?php $this->input('github_update_repository', 'Repositório GitHub no formato owner/repo', $options); ?>
                        <label class="gamb-field" for="gamb-github-token">Token opcional para repositório privado</label>
                        <input class="regular-text" type="password" id="gamb-github-token" data-opt="github_update_token" value="" autocomplete="new-password" <?php disabled(defined('GAMB_GITHUB_TOKEN')); ?>>
                        <p>O campo vazio preserva o token atual. O valor salvo é criptografado; a constante GAMB_GITHUB_TOKEN tem precedência e não é exibida.</p>
                        <label class="gamb-setting"><input type="checkbox" data-opt="github_clear_token" <?php disabled(defined('GAMB_GITHUB_TOKEN')); ?>> Remover token salvo</label>
                    </section>
                    <section class="gamb-panel" id="panel-audit" data-settings-panel hidden>
                        <h2>Configuração da auditoria</h2>
                        <p>Defina as exceções necessárias ao tema e os shortcodes que precisam estar renderizados. Os resultados ficam em Análises e auditoria.</p>
                        <?php $this->input('sanitizer_style_whitelist', 'IDs de estilos nativos preservados, separados por espaços', $options);
                        $this->input('audit_unresolved_shortcodes', 'Shortcodes não resolvidos que devem bloquear a publicação, sem colchetes', $options); ?>
                        <p>O gate examina o buffer original e o HTML final. Um impedimento CRITICAL interrompe a entrega e preserva a publicação existente.</p>
                        <button type="button" class="button" data-open-panel="publication-audit">Abrir resultados da publicação</button>
                    </section>
                    <section class="gamb-panel" id="panel-destination-audit" hidden>
                        <h2>Auditoria</h2>
                        <p>Verificações defensivas de leitura da última exportação e de até quatro URLs do destino salvo. Referência: OWASP ASVS 5.0.0, com cobertura parcial. Canonical e robots são critérios de publicação. Não reexporta, não corrige o site e não inicia testes externos.</p>
                        <button type="button" class="button button-primary" data-run-destination-audit>Executar auditoria</button>
                        <a class="button" data-report-download="defensive">Baixar resultado (JSON)</a>
                        <p data-destination-audit-message role="status" aria-live="polite"></p>
                        <div data-destination-audit-report></div>
                        <details><summary>Conectores opcionais — somente relatórios existentes</summary>
                            <h3>Tenable WAS</h3><p>Associe uma avaliação existente à URL e ao ambiente atualmente salvos. Use uma conta com WAS.SCAN_WAS.READ e Can View, sem permissão de iniciar ou editar avaliações. O plugin não verifica privilégios excedentes da chave.</p>
                            <p>Chaves somente no servidor: constantes ou variáveis de ambiente <code>GAMB_TENABLE_ACCESS_KEY</code> e <code>GAMB_TENABLE_SECRET_KEY</code>. Nenhuma chave é enviada ao navegador ou registrada no relatório.</p>
                            <label class="gamb-setting"><input type="checkbox" data-tenable-enabled> Consultar Tenable durante a auditoria</label>
                            <label class="gamb-field" for="gamb-tenable-scan">UUID da avaliação existente <span title="Identificador retornado pelo Tenable. Não é uma URL nem uma credencial.">ⓘ</span></label><input class="regular-text" id="gamb-tenable-scan" data-tenable-scan autocomplete="off" maxlength="36" placeholder="00000000-0000-0000-0000-000000000000">
                            <label class="gamb-setting"><input type="checkbox" data-tenable-readonly> Confirmo que a conta foi limitada à leitura e pode visualizar esta avaliação.</label>
                            <p data-tenable-status></p><button type="button" class="button" data-save-audit-connectors>Salvar associação ao destino atual</button>
                            <h3>Cymulate</h3><p>Pendente, desativado e sem interferência na auditoria. Será usado quando o endpoint de leitura e as permissões da conta forem confirmados e o conector for habilitado. Nenhuma requisição é feita nesta versão.</p>
                        </details>
                    </section>
                    <section class="gamb-panel" id="panel-analysis" hidden>
                        <h2>Análises e auditoria</h2><p>Consulte as causas dos alertas, a cobertura de cada verificação e as providências recomendadas. Os relatórios desta área são administrativos e podem conter a estrutura da publicação.</p>
                        <div class="gamb-callout"><strong>Auditoria da publicação</strong><p data-analysis-audit>Carregando evidências registradas.</p><button type="button" class="button button-primary" data-open-panel="publication-audit">Abrir relatório detalhado</button></div>
                        <h3>Pendências e causas do status STATIC</h3><ul data-analysis-alerts></ul>
                        <div class="gamb-action-row"><button type="button" class="button" data-open-panel="diagnostics">Diagnóstico da instalação</button><button type="button" class="button" data-open-panel="dependencies">Avaliar dependências</button><a class="button" data-report-download="summary">Baixar todas as evidências (JSON)</a></div>
                    </section>
                    <section class="gamb-panel" id="panel-publication-audit" hidden>
                        <h2>Auditoria da publicação</h2><p>Durante a exportação, o gate examina o buffer original e o HTML final antes da entrega. A verificação abaixo lê os documentos HTML presentes na pasta local salva, sem alterá-los. Ela interrompe a inspeção no primeiro impedimento crítico; documentos restantes ficam sem certificação.</p>
                        <div class="gamb-action-row"><button type="button" class="button button-primary" data-run-publication-audit>Auditar arquivos publicados</button><a class="button" data-report-download="publication">Baixar auditoria (JSON)</a></div><div data-audit-report="publication" aria-live="polite"></div>
                    </section>
                    <section class="gamb-panel" id="panel-origin-audit" hidden>
                        <h2>Auditoria da origem</h2><p>Revalida a captura da Home e de uma página inexistente da origem. Não substitui a auditoria dos arquivos entregues nem verifica todas as páginas do site.</p>
                        <div class="gamb-action-row"><button type="button" class="button button-primary" data-run-audit>Revalidar origem agora</button><a class="button" data-report-download="origin">Baixar auditoria (JSON)</a></div><div data-audit-report="origin" aria-live="polite"></div>
                    </section>
                    <section class="gamb-panel" id="panel-files" hidden>
                        <h2>Arquivos publicados</h2><p data-inventory-summary>Carregando inventário da entrega local.</p><p class="gamb-destination-summary" data-inventory-location></p>
                        <div class="gamb-action-row"><label for="gamb-file-search">Filtrar esta página</label><input type="search" id="gamb-file-search" placeholder="Nome ou caminho do arquivo"><a class="button" data-report-download="files">Baixar inventário completo (JSON)</a></div>
                        <div class="gamb-table-wrap" tabindex="0" role="region" aria-label="Inventário dos arquivos"><table class="widefat striped"><thead><tr><th scope="col">Arquivo relativo à pasta</th><th scope="col">Tamanho</th><th scope="col">Disponibilidade</th></tr></thead><tbody data-inventory-files></tbody></table></div>
                        <div class="gamb-action-row"><button type="button" class="button" data-files-prev>Anterior</button><span data-files-page></span><button type="button" class="button" data-files-next>Próxima</button></div>
                    </section>
                </div>
            </div>
        </div>
<?php
    }
}
