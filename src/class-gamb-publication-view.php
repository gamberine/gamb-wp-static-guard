<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Optional presentation at the source URL, without Apache, hosts or DNS changes. */
final class Publication_View {
    public const RECEIPT='.gamb-static-publication.json';
    public static function register(): void { add_action('init',array(__CLASS__,'serve'),-10000); }
    public static function settings_error(array $settings): ?string {
        if(($settings['public_site_mode']??'wordpress')!=='static')return null;
        if(($settings['delivery_method']??'')!=='local')return 'A apresentação estática na raiz requer entrega local.';
        foreach(array('destination_scheme','destination_host','relative_path')as$key){if(!is_string($settings[$key]??''))return 'Valor de destino invalido.';}
        $home=Destination_Audit::base(home_url('/'));
        try { $base = Static_Engine::resolve_public_base($settings); }
        catch (\RuntimeException $error) { return $error->getMessage(); }
        if(Destination_Audit::base($base)!==$home)return 'Para apresentar o estático na raiz, use a URL principal do projeto como destino. Clique em Usar endereço local do projeto.';
        if(($settings['destination_url_type']??'')==='offline')return 'Use endereços relativos ao domínio ou absolutos para apresentar o estático na raiz.';
        return null;
    }
    public static function status(): array {
        $o=Options::instance()->get_all();$mode=$o['public_site_mode']??'wordpress';
        $result=array('mode'=>$mode,'ready'=>false,'url'=>home_url('/'),'message'=>'A URL principal apresenta o WordPress.');
        if($mode!=='static')return $result;
        $error=self::settings_error($o);$root=Publication_Destination::validate($o['local_dir']??'');
        if($error||is_wp_error($root)){$result['message']=$error?:$root->get_error_message();return $result;}
        $receipt=$root.'/'.self::RECEIPT;$manifest=$root.'/.gamb-static-files.json';
        if(!is_file($receipt)||is_link($receipt)||filesize($receipt)>16384||!is_file($manifest)||is_link($manifest)||filesize($manifest)>2097152){$result['message']='Modo estático selecionado. Execute uma publicação completa para ativar a entrega na raiz.';return $result;}
        $meta=json_decode((string)file_get_contents($receipt),true);
        if(!is_array($meta)||($meta['public_url']??'')!==Destination_Audit::base(home_url('/'))||($meta['environment']??'')!==($o['destination_environment']??'production')){$result['message']='A entrega existente pertence a outro destino/ambiente. Reexporte antes de apresentar o estático.';return $result;}
        $files=json_decode((string)file_get_contents($manifest),true);
        if(!is_array($files)||!array_is_list($files)||count($files)>20000||!in_array('index.html',$files,true)||!self::resolve($root,'index.html')){$result['message']='Home ou manifesto da entrega indisponível.';return $result;}
        $result['ready']=true;$result['message']='A URL principal apresenta os arquivos estáticos da última entrega. WordPress permanece disponível no admin e na captura assinada.';return $result;
    }
    public static function resolve(string $root,string $relative): ?string {
        if($relative===''||strlen($relative)>2048||preg_match('~[\x00-\x20\\\\]|^/|(?:^|/)\.{1,2}(?:/|$)~',$relative))return null;
        $candidate=$root.'/'.$relative;$real=realpath($candidate);
        if(!$real||!is_file($real)||!str_starts_with(strtolower(wp_normalize_path($real)),strtolower(trailingslashit(wp_normalize_path($root)))))return null;
        for($part=$candidate;strlen($part)>strlen($root);$part=dirname($part)){if(is_link($part))return null;}
        return $real;
    }
    public static function candidate(string $uri,string $homePath,array $manifest): ?array {
        $path=wp_parse_url($uri,PHP_URL_PATH);
        $prefix=trailingslashit('/'.trim($homePath,'/'));
        if(!is_string($path)||!str_starts_with($path,$prefix))return null;
        $relative=substr($path,strlen($prefix));
        if(preg_match('~%(?:2f|5c|00)|\\\\|[\x00-\x1f]~i',$relative))return array('status'=>404,'path'=>'404.html','mime'=>'text/html');
        $relative=rawurldecode($relative);
        if(preg_match('~^(?:wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php)(?:/|$)~i',$relative))return null;
        if(preg_match('~(?:^|/)\.|(?:^|/)\.\.(?:/|$)|[\x00-\x20\\\\]~',$relative))return array('status'=>404,'path'=>'404.html','mime'=>'text/html');
        $relative=(string)preg_replace('~^index\.php(?:/|$)~','',$relative);
        if(preg_match('~(?:^|/)(?:gamb-(?:backups|static-guard-(?:backups|archive))|backups?(?:[-_][^/]*)?)(?:/|$)~i',$relative))return array('status'=>404,'path'=>'404.html','mime'=>'text/html');
        $lookup=array_fill_keys(array_values(array_filter($manifest,'is_string')),true);
        $file=$relative===''?'index.html':(str_ends_with($relative,'/')?$relative.'index.html':$relative);
        if(!isset($lookup[$file])&&isset($lookup[$file.'/index.html']))$file.='/index.html';
        if(isset($lookup[$file])){
            if(str_ends_with($file,'.html'))return array('status'=>$file==='404.html'?404:200,'path'=>$file,'mime'=>'text/html');
            $map=Gamb_Security_Suite::asset_mime_types();$ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
            if(isset($map[$ext])&&Gamb_Security_Suite::is_public_asset($file))return array('status'=>200,'path'=>$file,'mime'=>$map[$ext]);
            if(preg_match('~^v/[A-Za-z0-9_-]+$~D',$file)){foreach($map as$type=>$mime){if(isset($lookup[$file.'.'.$type]))return array('status'=>200,'path'=>$file,'mime'=>$mime);}}
        }
        return array('status'=>404,'path'=>'404.html','mime'=>'text/html');
    }
    public static function serve(): void {
        if(Options::instance()->get('public_site_mode','wordpress')!=='static'||is_admin()||!empty($_GET['rest_route'])||Frontend_Policy::is_export_request()||(defined('DOING_CRON')&&DOING_CRON)||(defined('WP_CLI')&&WP_CLI))return;
        if(isset($_GET['preview'])&&current_user_can('edit_posts'))return;
        $uri=$_SERVER['REQUEST_URI']??'';$homePath=(string)wp_parse_url(home_url('/'),PHP_URL_PATH);
        $relative=substr((string)wp_parse_url($uri,PHP_URL_PATH),strlen(trailingslashit($homePath)));
        if(preg_match('~^(?:wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php)(?:/|$)~i',$relative))return;
        $status=self::status();
        if(!$status['ready']){self::headers(503,'text/plain');echo 'Publicação estática aguardando entrega válida. O painel administrativo permanece disponível.';exit;}
        $root=Publication_Destination::validate(Options::instance()->get('local_dir',''));
        $manifest=json_decode((string)file_get_contents($root.'/.gamb-static-files.json'),true);
        $candidate=self::candidate($uri,$homePath,$manifest);if($candidate===null)return;
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',array('GET','HEAD'),true)){self::headers(405,'text/plain');header('Allow: GET, HEAD');exit;}
        $file=in_array($candidate['path'],$manifest,true)?self::resolve($root,$candidate['path']):null;
        self::headers($file?$candidate['status']:404,$file?$candidate['mime']:'text/plain');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')exit;
        if($file){header('Content-Length: '.filesize($file));readfile($file);}else{echo 'Página não encontrada.';}exit;
    }
    private static function headers(int $status,string $mime): void {
        while(ob_get_level()>0){if(!@ob_end_clean())break;}
        status_header($status);header('Content-Type: '.$mime.(str_starts_with($mime,'text/')?'; charset=UTF-8':''));header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=0, must-revalidate');header_remove('X-Powered-By');header_remove('Link');
        if(Options::instance()->get('guard_security_headers',true)){
            $rules=explode("\n\n",Gamb_Security_Suite::get_headers_file_content())[0];
            foreach(explode("\n",$rules)as$rule){if(!preg_match('/^  ([A-Za-z-]+): (.*)$/D',$rule,$m))continue;if($m[1]==='Strict-Transport-Security'&&!is_ssl())continue;$value=$m[2];if(!is_ssl())$value=str_replace('upgrade-insecure-requests; ','',$value);header($m[1].': '.$value);}
        }
        if(Installation_Checks::preview_destination())header('X-Robots-Tag: noindex, nofollow');
    }
}
