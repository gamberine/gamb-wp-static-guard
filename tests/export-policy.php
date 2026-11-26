<?php
/** Regressões da publicação, recursos públicos e autorização de pausa. */
use GambWPStaticGuard\Gamb_Security_Suite as ExportGuard;
use GambWPStaticGuard\Installation_Checks as ExportChecks;
use GambWPStaticGuard\Operation_Progress as ExportProgress;
use GambWPStaticGuard\Static_Engine as ExportEngine;

if (!function_exists('current_user_can')) {
    function current_user_can($capability) { return $GLOBALS['fixture_manage_options'] ?? true; }
}
(static function () use ($options) {
    $saved=$GLOBALS['fixture_options']; $settings=$options->get_all();
    $user=$GLOBALS['fixture_user'] ?? 1;
    try {
        foreach (array('package.json','sub/package-lock.json','composer.json','tsconfig.app.json','vite.config.mjs','library.js.map','library.js.map.br') as $file) {
            expect(!ExportGuard::is_public_asset($file),'Impedir publicação de metadados e mapas: '.$file);
        }
        expect(ExportGuard::is_public_asset('manifest.json') && ExportGuard::is_public_asset('library.mjs'),'Preservar recursos públicos e manifestos de execução.');
        expect(ExportGuard::asset_mime_types()['docx']==='application/vnd.openxmlformats-officedocument.wordprocessingml.document','DOCX usa o mesmo MIME no handler e na publicação.');
        $guard=ExportGuard::get_instance();
        expect(ExportEngine::resolve_public_base(array('delivery_method'=>'local','local_dir'=>'/srv/preview'))==='https://example.test/site/preview/','Derivar o endereço da instalação e da pasta.');
        expect(ExportEngine::resolve_public_base(array('destination_host'=>'example.test/site','relative_path'=>'site'))==='https://example.test/site/','Preservar a raiz sem duplicar o caminho.');
        expect(ExportEngine::resolve_public_base(array('destination_host'=>'public.example.org','destination_scheme'=>'http://'))==='https://public.example.org/','Forçar HTTPS externo.');
        expect(ExportEngine::resolve_public_base(array('destination_host'=>'localhost:8080','destination_scheme'=>'http://'))==='http://localhost:8080/','Permitir HTTP local.');
        foreach (array('test','example.test/other','example.test/site/%2e%2e') as $invalid) {
            $blocked = false;
            try { ExportEngine::resolve_public_base(array('destination_host'=>$invalid)); }
            catch (\RuntimeException $error) { $blocked = true; }
            expect($blocked,'Bloquear domínio pai ou caminho fora da instalação.');
        }
        $options->set('guard_hide_theme_identity',true);
        $literal='const sample="//# sourceMappingURL=literal.map";';
        expect($guard->sanitize_js_content($literal)===$literal,'Preservar texto semelhante a comentário dentro de strings JS.');
        expect(!str_contains($guard->sanitize_js_content("const sample=1;\n//# sourceMappingURL=library.js.map"),'sourceMappingURL='),'Remover diretiva final de depuração.');
        $legal='/*! Copyright authors. MIT License. */';
        expect(str_contains($guard->sanitize_js_content($legal."\nconst sample=1;"),$legal),'Preservar avisos legais das bibliotecas.');

        if (class_exists('WP_HTML_Tag_Processor')) {
            $options->set('destination_url_type','relative')->set('relative_path','preview')->set('destination_host','preview.test')->set('destination_scheme','https://')->set('destination_environment','preview');
            $engine=ExportEngine::get_instance();
            $external='https://production.test/site/news/';
            expect($engine->apply_url_replacement_mode($external,'index.html')===$external,'Preservar navegação intencional para produção.');
            expect($engine->apply_url_replacement_mode('/preview/news/','index.html')==='/preview/news/','Não duplicar o caminho do destino.');
            $html='<html><head><link rel="canonical" href="/old"><link rel="canonical" href="/duplicate"><meta name="robots" content="index"><meta name="robots" content="all"></head><body></body></html>';
            $final=ExportChecks::finalize_document($html,'news/index.html');
            expect(substr_count($final,'rel="canonical"')===1 && str_contains($final,'href="https://preview.test/preview/news/"'),'Canonical única, absoluta e baseada no destino.');
            expect(substr_count($final,'name="robots"')===1 && str_contains($final,'content="noindex, nofollow"'),'Preview usa uma única política de robôs.');
            expect($engine->apply_url_replacement_mode($final,'news/index.html')===$final,'Reescrita posterior preserva canonical absoluta.');
            expect(!str_contains(ExportChecks::finalize_document($html,'404.html'),'canonical'),'404 continua sem canonical.');
            expect(str_contains(ExportGuard::get_htaccess_content(),'Header always set X-Robots-Tag "noindex, nofollow"'),'Preview gera header no Apache.');
            $options->set('destination_environment','production');
            expect(!str_contains(ExportGuard::get_htaccess_content(),'X-Robots-Tag'),'Produção preserva sua política de headers.');
            expect(str_contains(ExportChecks::finalize_document($html,'news/index.html'),'content="index"'),'Produção preserva a política de robôs da origem.');
            $dir=sys_get_temp_dir().'/gamb-final-sri-'.bin2hex(random_bytes(8)); mkdir($dir.'/v',0777,true);
            try {
                file_put_contents($dir.'/v/final.css','body{color:green}');
                $asset='<link rel="stylesheet" href="/preview/v/final.css" crossorigin="anonymous" integrity="old">';
                $options->set('refresh_local_integrity',true)->set('disable_local_integrity',false);
                $refreshed=ExportChecks::refresh_integrity($asset,$dir);
                $hash='sha384-'.base64_encode(hash_file('sha384',$dir.'/v/final.css',true));
                expect(str_contains($refreshed,$hash) && !str_contains($refreshed,'crossorigin'),'SHA-384 usa bytes finais sem CORS desnecessário.');
                $options->set('refresh_local_integrity',false);
                expect(!str_contains(ExportChecks::refresh_integrity($asset,$dir),'integrity='),'Descartar hash obsoleto sem recálculo.');
                expect(str_contains(ExportChecks::refresh_integrity($refreshed,$dir),$hash),'Preservar hash válido sem recálculo.');
                $options->set('disable_local_integrity',true);
                expect(!str_contains(ExportChecks::refresh_integrity($refreshed,$dir),'integrity='),'Permitir desativação explícita do SRI local.');
                $externalAsset='<script src="https://cdn.test/v/final.css" integrity="sha256-external"></script>';
                expect(ExportChecks::refresh_integrity($externalAsset,$dir)===$externalAsset,'Preservar SRI de recurso externo.');
            } finally { unlink($dir.'/v/final.css'); rmdir($dir.'/v'); rmdir($dir); }
        }

        $GLOBALS['fixture_manage_options']=true; $GLOBALS['fixture_user']=1;
        $job=ExportProgress::create('export');
        expect(!is_wp_error($job),'Reservar publicação para testar controle.');
        $request=static function($id,$action){return new class($id,$action){private $id;private $action;function __construct($id,$action){$this->id=$id;$this->action=$action;}function get_param($key){return $key==='id'?$this->id:($key==='action'?$this->action:$this->id);}};};
        $paused=ExportProgress::control($request($job['id'],'pause'));
        expect(!is_wp_error($paused)&&$paused['status']==='pausing','Solicitar pausa sem executar outro worker.');
        expect(is_wp_error(ExportProgress::create('settings')),'Pausa solicitada mantém exclusão de operações concorrentes.');
        expect(is_wp_error(ExportProgress::control($request('obsolete','resume'))),'Recusar identificador obsoleto.');
        expect(is_wp_error(ExportProgress::control($request($job['id'],'invalid'))),'Recusar comando desconhecido.');
        $GLOBALS['fixture_user']=2;
        expect(is_wp_error(ExportProgress::control($request($job['id'],'resume'))),'Recusar controle de publicação de outro usuário.');
        $GLOBALS['fixture_user']=1; $GLOBALS['fixture_manage_options']=false;
        expect(is_wp_error(ExportProgress::control($request($job['id'],'resume'))),'Exigir permissão administrativa no próprio callback.');
        $GLOBALS['fixture_manage_options']=true;
        expect(!is_wp_error(ExportProgress::control($request($job['id'],'resume'))),'Autorizar continuação da operação reservada.');
        ExportProgress::execute($request($job['id'],'resume'),'export',static function(){ExportProgress::phase('export',3,6,1,2,'Processando unidade.');return array('success'=>true);});
        expect(ExportProgress::snapshot()['status']==='success','Concluir a operação controlada.');
        expect(is_wp_error(ExportProgress::control($request($job['id'],'pause'))),'Recusar pausa após conclusão.');
    } finally {
        $GLOBALS['fixture_options']=$saved; $GLOBALS['fixture_user']=$user; unset($GLOBALS['fixture_manage_options']);
        foreach($settings as $key=>$value){$options->set($key,$value);}
    }
})();
