<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** WordPress original privado: switch opt-in + administrador + nonce da sessão. */
final class Original_Access {
    public const OPTION='gamb_static_guard_original_access';
    private static $current=false;
    public static function current(): bool { return self::$current; }
    private static function state(): array { $value=get_option(self::OPTION,array());return is_array($value)?$value:array(); }
    public static function enabled(): bool { $value=self::state();return ($value['enabled']??false)===true&&(int)($value['expires_at']??0)>time(); }
    public static function allowed(bool $enabled,bool $admin,bool $nonce,bool $origin,string $method): bool { return $enabled&&$admin&&$nonce&&$origin&&in_array($method,array('GET','HEAD'),true); }
    public static function register(): void {
        add_action('init',array(__CLASS__,'detect'),-20000);
        add_action('admin_bar_menu',array(__CLASS__,'toolbar'),90);
        add_action('admin_notices',array(__CLASS__,'notice'));
        add_filter('admin_body_class',static function($classes){return $classes.(self::enabled()?' gamb-original-enabled':'');});
        add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));
        add_action('wp_enqueue_scripts',array(__CLASS__,'assets'));
        add_action('admin_footer',array(__CLASS__,'dialog'));
        add_action('wp_footer',array(__CLASS__,'dialog'));
        add_action('wp_body_open',array(__CLASS__,'front_notice'));
        add_filter('show_admin_bar',static function($show){return self::$current?true:$show;},PHP_INT_MAX);
        add_filter('wp_robots',static function($robots){return self::$current?array('noindex'=>true,'nofollow'=>true,'noarchive'=>true):$robots;},PHP_INT_MAX);
        add_filter('wp_headers',static function($headers){if(self::$current){$headers['Cache-Control']='private, no-store, no-cache, must-revalidate';$headers['X-Robots-Tag']='noindex, nofollow, noarchive';$headers['Referrer-Policy']='no-referrer';}return $headers;},PHP_INT_MAX);
        add_filter('preview_post_link',static function($url){return self::enabled()&&current_user_can('manage_options')?self::url($url):$url;});
        foreach(array('style_loader_src','script_loader_src')as$hook)add_filter($hook,static function($url){return self::$current&&strpos($url,home_url('/'))===0?add_query_arg('gamb_wp_revision',(string)time(),$url):$url;},PHP_INT_MAX);
        add_action('template_redirect',static function(){if(self::$current)ob_start(array(__CLASS__,'navigation'));},-9998);
    }
    public static function detect(): void {
        if(!isset($_GET['gamb_wp_original'])||Frontend_Policy::is_export_request())return;
        $nonce=$_GET['_gamb_wp_nonce']??'';
        if(!self::allowed(self::enabled(),current_user_can('manage_options'),is_string($nonce)&&(bool)wp_verify_nonce($nonce,'gamb-original-access'),Origin_Context::request_home()!==null,$_SERVER['REQUEST_METHOD']??'GET')){
            status_header(404);nocache_headers();header('Content-Type: text/plain; charset=UTF-8');exit('Endereço indisponível.');
        }
        $path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$base=wp_parse_url(home_url('/'),PHP_URL_PATH);
        $relative=substr((string)$path,strlen((string)$base));
        if(preg_match('~^(?:wp-admin|wp-login|wp-json|wp-cron|xmlrpc|wp-config|\.)(?:[./]|$)~i',$relative)){status_header(404);exit;}
        self::$current=true;
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        nocache_headers();header('Cache-Control: private, no-store, no-cache, must-revalidate');header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');header('X-Gamb-View: wordpress-original');
    }
    public static function status(): array {
        $enabled=self::enabled();
        return array('enabled'=>$enabled,'expires_at'=>$enabled?(int)(self::state()['expires_at']??0):0,'url'=>$enabled&&current_user_can('manage_options')?self::url():'','message'=>$enabled?'Acesso WP original ativado por até uma hora: somente administradores autenticados, com link da própria sessão.':'URL de acesso WP desativada. A URL principal continua estática.');
    }
    public static function url(string $url=''): string { return add_query_arg(array('gamb_wp_original'=>'1','_gamb_wp_nonce'=>wp_create_nonce('gamb-original-access')),$url?:home_url('/')); }
    public static function toggle($request) {
        if(!current_user_can('manage_options'))return new \WP_Error('forbidden','Permissão administrativa necessária.',array('status'=>403));
        $enabled=$request->get_param('enabled');
        if(!is_bool($enabled))return new \WP_Error('invalid_access','Informe um estado booleano.',array('status'=>400));
        if($enabled&&$request->get_param('confirmed')!==true)return new \WP_Error('confirmation_required','Confirme o aviso de acesso ao WordPress original.',array('status'=>400));
        if(!Origin_Context::request_home())return new \WP_Error('unsafe_origin','Use um endereço autorizado desta instalação.',array('status'=>403));
        // Estado independente: um worker de exportação com opções antigas não pode desfazer a revogação.
        update_option(self::OPTION,array('enabled'=>$enabled,'expires_at'=>$enabled?time()+3600:0),false);Gamb_Security_Suite::purge_page_caches();
        return rest_ensure_response(self::status());
    }
    public static function toolbar($bar): void {
        if(!current_user_can('manage_options')||Frontend_Policy::is_export_request())return;
        $enabled=self::enabled();
        $bar->add_node(array('id'=>'gamb-original-access','title'=>'<button type="button" class="gamb-original-switch" role="switch" aria-checked="'.($enabled?'true':'false').'" data-gamb-original-toggle>Ativar URL acesso WP <span data-gamb-original-state>'.($enabled?'Ativado':'Desativado').'</span></button>'));
        $bar->add_node(array('id'=>'gamb-original-open','parent'=>'gamb-original-access','title'=>'Abrir WordPress original (privado)','href'=>$enabled?self::url():'#','meta'=>array('class'=>$enabled?'':'gamb-original-link-disabled','target'=>'_blank','rel'=>'noopener noreferrer')));
    }
    public static function notice(): void {
        if(current_user_can('manage_options')&&self::enabled())echo '<div class="notice notice-warning gamb-original-notice"><strong>Acesso WP original ATIVADO por até uma hora.</strong> O acesso é privado e exige sua sessão administrativa. Desative após validar os arquivos. <button type="button" class="button" data-gamb-original-toggle>Desativar acesso WP</button></div>';
    }
    public static function front_notice(): void { if(self::$current)echo '<aside class="gamb-original-front" role="status"><strong>WORDPRESS ORIGINAL — ACESSO PRIVADO.</strong> Esta visualização não é a publicação estática. <a data-gamb-static-link href="'.esc_url(home_url('/')).'">Voltar ao estático</a></aside>'; }
    public static function assets(): void {
        if(!current_user_can('manage_options')||(!is_admin()&&!self::$current)||Frontend_Policy::is_export_request())return;
        wp_enqueue_style('gamb-original-access',GAMB_STATIC_WP_URL.'/assets/original-access.css',array(),GAMB_STATIC_WP_VERSION);
        wp_enqueue_script('gamb-original-access',GAMB_STATIC_WP_URL.'/assets/original-access.js',array(),GAMB_STATIC_WP_VERSION,true);
        wp_localize_script('gamb-original-access','gambOriginalAccess',array('endpoint'=>rest_url('gamb-wp-static-guard/v1/original-access'),'nonce'=>wp_create_nonce('wp_rest'),'status'=>self::status(),'isOriginal'=>self::$current,'staticUrl'=>home_url('/')));
    }
    public static function dialog(): void {
        if(!current_user_can('manage_options')||(!is_admin()&&!self::$current)||Frontend_Policy::is_export_request())return;
        echo '<dialog id="gamb-original-confirm" class="gamb-original-dialog" aria-labelledby="gamb-original-title"><h2 id="gamb-original-title">Ativar URL acesso WP?</h2><p>Você abrirá o WordPress original para validar alterações em arquivos. Somente administradores autenticados poderão usar o link, que é vinculado à sessão. Não compartilhe esse endereço.</p><p>A URL principal continuará estática. O recurso expira em uma hora. Um alerta ficará fixo no admin enquanto estiver ativo. Desative ao terminar.</p><p data-gamb-original-error role="alert"></p><div><button type="button" class="button" data-gamb-original-cancel>Cancelar</button> <button type="button" class="button button-primary" data-gamb-original-confirm>Confirmar ativação</button></div></dialog>';
    }
    public static function navigation(string $html): string {
        if(!self::$current||strpos($html,'<')===false)return $html;
        $document=Gamb_DOM_Parser::parse($html);$home=wp_parse_url(home_url('/'));
        foreach($document->getElementsByTagName('a')as$link){$href=$link->getAttribute('href');if($link->hasAttribute('data-gamb-static-link')||$href===''||$href[0]==='#'||preg_match('~^(?:mailto:|tel:|javascript:)~i',$href))continue;
            $u=wp_parse_url($href);if(isset($u['host'])&&($u['host']!==$home['host']||($u['port']??null)!==($home['port']??null)))continue;
            $path=$u['path']??'';if(rtrim($path,'/')===rtrim($home['path'],'/'))$path=$home['path'];if(strpos($path,$home['path'])!==0)continue;$relative=substr($path,strlen($home['path']));
            if(preg_match('~^(?:wp-|v/|static/)|\.[a-z0-9]{1,8}$~i',$relative))continue;
            $link->setAttribute('href',self::url($href));
        }
        foreach($document->getElementsByTagName('form')as$form){
            if(strtoupper($form->getAttribute('method')?:'GET')!=='GET')continue;
            $action=$form->getAttribute('action');$u=wp_parse_url($action?:home_url('/'));
            if(isset($u['host'])&&($u['host']!==$home['host']||($u['port']??null)!==($home['port']??null)))continue;
            $path=$u['path']??$home['path'];if(strpos($path,$home['path'])!==0||preg_match('~(?:wp-admin|wp-login|wp-json)~',$path))continue;
            foreach(array('gamb_wp_original'=>'1','_gamb_wp_nonce'=>wp_create_nonce('gamb-original-access'))as$name=>$value){$input=$document->createElement('input');$input->setAttribute('type','hidden');$input->setAttribute('name',$name);$input->setAttribute('value',$value);$form->appendChild($input);}
        }
        return Gamb_DOM_Parser::html($document);
    }
}
