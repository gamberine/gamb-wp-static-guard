<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Monitor 3.3.2 integrado, com IPs exatos, proxies explícitos e respostas sem cache. */
class Version_Monitor {
    public static function normalize($ip): string {
        if (!is_string($ip) || !filter_var(trim($ip),FILTER_VALIDATE_IP)) { return ''; }
        $binary=inet_pton(trim($ip));
        if (strlen($binary)===16 && substr($binary,0,12)===str_repeat("\0",10)."\xff\xff") { $binary=substr($binary,12); }
        return bin2hex($binary);
    }
    public static function validate_list($value) {
        if (is_string($value)) { $value=preg_split('/[\s,;]+/',trim($value),-1,PREG_SPLIT_NO_EMPTY); }
        if (!is_array($value) || count($value)>200) { return new \WP_Error('invalid_monitor_ips','Informe até 200 endereços IP exatos, sem CIDR ou nomes de host.',array('status'=>400)); }
        $result=array(); foreach ($value as $ip) {
            $normalized=self::normalize($ip);
            if ($normalized==='') { return new \WP_Error('invalid_monitor_ips','A lista contém um IP inválido. A configuração anterior foi preservada.',array('status'=>400)); }
            $result[$normalized]=inet_ntop(hex2bin($normalized));
        }
        return array_values($result);
    }
    public static function resolve($peer,$header,array $proxies): string {
        $ip=self::normalize($peer); $trusted=array_map(array(__CLASS__,'normalize'),$proxies);
        if (in_array('', $trusted,true)) { return ''; }
        if ($ip!=='' && in_array($ip,$trusted,true)) {
            if (!is_string($header) || trim($header)==='' || strlen($header)>2048) { return ''; }
            $chain=array_map(array(__CLASS__,'normalize'),explode(',',$header));
            if (count($chain)>20 || in_array('',$chain,true)) { return ''; }
            foreach (array_reverse($chain) as $hop) { if (!in_array($ip,$trusted,true)) { break; } $ip=$hop; }
        }
        return $ip!=='' && !in_array($ip,$trusted,true) ? $ip : '';
    }
    public static function allowed($peer,$header,array $allowed,array $proxies): bool {
        $normalized=array_map(array(__CLASS__,'normalize'),$allowed);
        $ip=self::resolve($peer,$header,$proxies);
        return $ip!=='' && !in_array('',$normalized,true) && in_array($ip,$normalized,true);
    }
    public static function permission() {
        $options=Options::instance();
        if (!$options->get('version_monitor_enabled',false)) { return new \WP_Error('rest_no_route','Monitor desabilitado.',array('status'=>404)); }
        if (self::allowed($_SERVER['REMOTE_ADDR'] ?? '',$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',(array)$options->get('version_monitor_allowed_ips',array()),(array)$options->get('version_monitor_trusted_proxies',array()))) { return true; }
        return new \WP_Error('rest_forbidden_ip','Acesso não autorizado para esta origem.',array('status'=>403));
    }
    public static function response() { $access=self::permission(); return $access===true ? array('wordpress_version'=>get_bloginfo('version')) : $access; }
    private static function monitored(string $route): bool {
        if (preg_match('#^/gamb-wp-static-guard/v1/wordpress-version(?:/|$)#i',$route)) { return true; }
        return Options::instance()->get('version_monitor_enabled',false) && (bool)preg_match('#^/(?:monitor-versao-wp|meu-sistema/v1/versao)(?:/|$)#i',$route);
    }
    public static function check($request) { return preg_match('#^/meu-sistema/v1/versao(?:/|$)#i',$request->get_route()) ? new \WP_Error('rest_no_route','Rota removida.',array('status'=>404)) : self::permission(); }
    public static function routes(): array {
        $handler=array('methods'=>'GET','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'response'));
        return array('/monitor-versao-wp(?:/check)?/?'=>array($handler,'namespace'=>'monitor-versao-wp'));
    }
    public static function register(): void {
        add_action('rest_api_init',static function() {
            register_rest_route('gamb-wp-static-guard/v1','/wordpress-version',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'response')));
            if (Options::instance()->get('version_monitor_enabled',false)) { foreach (self::routes() as $route=>$handlers) { rest_get_server()->register_route($handlers['namespace'],$route,$handlers,true); } }
        },PHP_INT_MAX);
        add_filter('rest_endpoints',static function($endpoints) {
            if (!Options::instance()->get('version_monitor_enabled',false)) { return $endpoints; }
            foreach (array_keys($endpoints) as $route) { if (preg_match('#^/(?:monitor-versao-wp|meu-sistema/v1/versao)(?:/|\(|\?|\$|$)#i',$route)) { unset($endpoints[$route]); } }
            return self::routes()+$endpoints;
        },PHP_INT_MAX);
        add_filter('rest_pre_dispatch',static function($result,$server,$request) {
            if (self::monitored($request->get_route())) { $access=self::check($request); if ($access!==true) { return $access; } }
            return $result;
        },PHP_INT_MAX,3);
        $protect=static function($response,$server,$request) {
            if (self::monitored($request->get_route())) {
                $access=self::check($request); if ($access!==true) { $response=$access; }
                $response=is_wp_error($response) ? rest_convert_error_to_response($response) : rest_ensure_response($response);
                $response->header('Cache-Control','private, no-store, no-cache, must-revalidate, max-age=0');
                $response->header('X-Robots-Tag','noindex, nofollow');
                $response->header('X-Gamb-Monitor-Version','3.3.2');
            }
            return $response;
        };
        add_filter('rest_request_after_callbacks',$protect,PHP_INT_MAX,3);
        add_filter('rest_post_dispatch',$protect,PHP_INT_MAX,3);
        add_filter('plugin_row_meta',static function($meta,$file) {
            if ($file==='gamb-wp-static-guard/gamb-wp-static-guard.php') { $meta[]='<a href="'.esc_url(rest_url('gamb-wp-static-guard/v1/wordpress-version')).'" target="_blank" rel="noopener noreferrer">Monitor da versão WordPress</a>'; }
            return $meta;
        },10,2);
    }
    public static function status(): array {
        $options=Options::instance();
        return array('enabled'=>(bool)$options->get('version_monitor_enabled',false),'url'=>rest_url('gamb-wp-static-guard/v1/wordpress-version'),'legacy_url'=>rest_url('monitor-versao-wp'),'check_url'=>rest_url('monitor-versao-wp/check'),'peer'=>sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),'allowed'=>self::permission()===true,'wordpress_version'=>get_bloginfo('version'));
    }
}
