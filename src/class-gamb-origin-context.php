<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Origens explícitas da mesma instalação. Nunca confia no Host/Forwarded para cadastrá-las. */
final class Origin_Context {
    private static $raw = array();
    private static $state = null;
    public static function base(string $url): string { return rtrim($url,'/').'/'; }
    private static function stored(string $key): string {
        if(isset(self::$raw[$key]))return self::$raw[$key];
        global $wpdb;
        $value=isset($wpdb)&&isset($wpdb->options)?$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)):get_option($key,'');
        return self::$raw[$key]=is_string($value)?$value:'';
    }
    public static function state(bool $refresh=false): array {
        if(!$refresh&&self::$state!==null)return self::$state;
        self::$state=array();
        $path=ABSPATH.'.gamb-runtime/compartilhamento/session.json';
        if(!is_file($path)||is_link($path)||filesize($path)>16384)return array();
        $state=json_decode((string)preg_replace('/\A\xEF\xBB\xBF/','',(string)file_get_contents($path)),true);
        return self::$state=is_array($state)&&($state['status']??'')==='running'?$state:array();
    }
    public static function canonical(string $key='home'): string {
        $raw=self::stored($key);$constant=$key==='siteurl'?'WP_SITEURL':'WP_HOME';
        if(defined($constant)&&is_string(constant($constant))){
            $configured=constant($constant);$state=self::state();
            // Compatibilidade com o antigo bloco Laragon no wp-config, que varia por requisição.
            if(!empty($state['address'])&&wp_parse_url($configured,PHP_URL_HOST)===$state['address']&&$raw!=='')return self::base($raw);
            return self::base($configured);
        }
        return self::base($raw!==''?$raw:home_url('/'));
    }
    public static function private_host(string $host): bool {
        return $host==='localhost'||$host==='::1'||(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&preg_match('/^(?:127\.|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/D',$host)===1);
    }
    public static function parent_domain(string $source,string $target): bool {
        $a=strtolower((string)wp_parse_url($source,PHP_URL_HOST));$b=strtolower((string)wp_parse_url($target,PHP_URL_HOST));
        return $a!==$b&&$a!==''&&$b!==''&&substr($a,-strlen('.'.$b))==='.'. $b;
    }
    public static function valid_alias(string $url,string $canonical): bool {
        $u=wp_parse_url($url);$c=wp_parse_url($canonical);
        if(!is_array($u)||empty($u['host'])||isset($u['pass'])||isset($u['user'])||isset($u['query'])||isset($u['fragment'])||preg_match('~[\x00-\x20\\\\<>]~',$url))return false;
        if(!in_array($u['scheme']??'',array('http','https'),true)||!preg_match('/^[a-z0-9.-]+$/i',$u['host']))return false;
        if(($u['scheme']??'')==='http'&&!self::private_host(strtolower($u['host'])))return false;
        if(self::base($u['path']??'/')!==self::base($c['path']??'/')||self::parent_domain($canonical,$url))return false;
        return !preg_match('~%(?:2f|5c|2e)|(?:^|/)\.{1,2}(?:/|$)~i',$u['path']??'/');
    }
    public static function aliases(): array {
        $state=self::state();$values=$state['origins']??array();$canonical=self::canonical();
        if(!empty($state['address'])&&filter_var($state['address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&self::private_host($state['address']))$values[]='http://'.$state['address'].(wp_parse_url($canonical,PHP_URL_PATH)?:'/');
        $result=array();foreach((array)$values as$url)if(is_string($url)&&self::valid_alias($url,$canonical))$result[]=self::base($url);
        return array_values(array_unique($result));
    }
    /** Separa a URL canônica persistida da origem HTTP autorizada desta requisição. */
    public static function request_home(?array $server=null): ?string {
        $server=$server??$_SERVER;$host=strtolower((string)($server['HTTP_HOST']??''));
        if($host===''&&PHP_SAPI==='cli')return self::canonical();
        if(!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/D',$host))return null;
        foreach(array_merge(array(self::canonical()),self::aliases())as$url){
            $u=wp_parse_url($url);$authority=strtolower($u['host']).(isset($u['port'])?':'.$u['port']:'');
            $default=($u['scheme']==='http'?':80':':443');if($host!==$authority&&$host!==$authority.$default)continue;
            if(filter_var($u['host'],FILTER_VALIDATE_IP)&&self::private_host($u['host'])&&!self::private_host((string)($server['REMOTE_ADDR']??'')))continue;
            if($u['scheme']==='https'&&!self::https($server))return null;
            return self::base($url);
        }
        return null;
    }
    private static function https(array $server): bool {
        if(!empty($server['HTTPS'])&&strtolower((string)$server['HTTPS'])!=='off'||(string)($server['SERVER_PORT']??'')==='443')return true;
        $state=self::state();$peer=(string)($server['REMOTE_ADDR']??'');
        return in_array($peer,(array)($state['trusted_proxies']??array()),true)&&($server['HTTP_X_FORWARDED_PROTO']??'')==='https';
    }
    public static function map(string $text): string {
        $request=self::request_home();if(!$request)return $text;$pairs=array();
        foreach(array('home','siteurl')as$key){$source=self::canonical($key);$u=wp_parse_url($request);$target=$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'').(wp_parse_url($source,PHP_URL_PATH)?:'/');if($source===$target)continue;
            $pairs[$source]=$target;$pairs[str_replace('/','\\/',$source)]=str_replace('/','\\/',$target);
        }
        return $pairs?strtr($text,$pairs):$text;
    }
    public static function register(): void {
        $request=self::request_home();if($request&&wp_parse_url($request,PHP_URL_SCHEME)==='https'&&self::https($_SERVER))$_SERVER['HTTPS']='on';
        foreach(array('home','siteurl')as$key)add_filter('pre_option_'.$key,static function($pre)use($key){$request=self::request_home();if(!$request)return $pre;$u=wp_parse_url($request);return rtrim($u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'').(wp_parse_url(self::canonical($key),PHP_URL_PATH)?:'/'),'/');},1);
        foreach(array('content_url','plugins_url','includes_url','rest_url','theme_file_uri','stylesheet_uri','script_loader_src','style_loader_src')as$hook)add_filter($hook,array(__CLASS__,'map'),9999);
        add_filter('upload_dir',static function($value){foreach(array('url','baseurl')as$key)if(isset($value[$key]))$value[$key]=self::map($value[$key]);return $value;},9999);
        add_action('template_redirect',static function(){if(!Frontend_Policy::is_export_request()&&self::request_home()!==self::canonical()&&self::request_home())ob_start(array(__CLASS__,'map'));},-9999);
    }
    public static function destination_error(array $settings): ?string {
        try{$target=Static_Engine::resolve_public_base($settings);}catch(\RuntimeException $error){return $error->getMessage();}
        return self::parent_domain(self::canonical(),$target)?'A instalação está em um subdomínio. Não é permitido publicar no domínio principal ou em um domínio ancestral.':null;
    }
}
