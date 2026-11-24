<?php
use GambWPStaticGuard\Destination_Audit as Defensive;
use GambWPStaticGuard\Audit_Connectors as Connectors;
require dirname(__DIR__).'/src/class-gamb-destination-audit.php';
require dirname(__DIR__).'/src/class-gamb-audit-connectors.php';

(function()use($options){
    $settings=$options->get_all();$saved=$GLOBALS['fixture_options'];$root=wp_normalize_path(sys_get_temp_dir()).'/gamb-defensive-'.bin2hex(random_bytes(6));
    mkdir($root);mkdir($root.'/interno');mkdir($root.'/v');$base='https://preview.example.test/site/';
    $css='body{color:#123456}';$hash='sha384-'.base64_encode(hash('sha384',$css,true));
    $html=static fn($path,$robots,$sri)=>'<!doctype html><html><head><title>Teste</title><link rel="canonical" href="'.$base.$path.'"><meta name="robots" content="'.$robots.'"><link rel="stylesheet" href="/site/v/style" integrity="'.$sri.'"><link rel="stylesheet" href="https://external.invalid/x.css"></head><body>Teste</body></html>';
    $paths=array('index.html','interno/index.html','v/style','v/style.css','v/pdf','v/pdf.pdf','v/docx','v/docx.docx');
    foreach(array('v/style'=>$css,'v/style.css'=>$css,'v/pdf'=>'pdf','v/pdf.pdf'=>'pdf','v/docx'=>'docx','v/docx.docx'=>'docx')as$file=>$content)file_put_contents($root.'/'.$file,$content);
    $write=static function($robots,$sri)use($root,$html){file_put_contents($root.'/index.html',$html('',$robots,$sri));file_put_contents($root.'/interno/index.html',$html('interno/',$robots,$sri));};
    $write('noindex, nofollow',$hash);file_put_contents($root.'/.gamb-static-files.json',json_encode($paths));
    $context=array('public_url'=>$base,'environment'=>'preview','method'=>'local','directory'=>$root,'inventory_available'=>true,'published_at'=>'24/11/2026 10:00:00','processed_units'=>8,'files'=>array_map(static fn($p)=>array('path'=>$p),$paths));
    $calls=array();$makeHttp=static function($robots='noindex, nofollow',$code=200)use(&$calls,$base){return static function($url,$args)use(&$calls,$base,$robots,$code){$calls[]=array($url,$args);expect(str_starts_with($url,$base),'Consultar somente o destino salvo.');expect($args['redirection']===0&&$args['timeout']===6&&empty($args['cookies'])&&!isset($args['headers']['Authorization']),'Sem redirects, sessão ou credenciais no destino.');$headers=array('x-robots-tag'=>$robots,'x-content-type-options'=>'nosniff','content-type'=>str_ends_with($url,'v/pdf')?'application/pdf':(str_ends_with($url,'v/docx')?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'text/html'),'server'=>'Apache/2.4.0 PHP/8.3.0','via'=>'example-proxy');return fixture_response('not consumed',$code,$headers);};};
    $find=static fn($r,$id)=>array_values(array_filter($r['checks'],static fn($c)=>$c['id']===$id));
    try{
        $options->set('refresh_local_integrity',true)->set('disable_local_integrity',false);
        $result=(new Defensive($makeHttp()))->inspect($context);
        expect(count($calls)===4,'Amostra de duas páginas e dois anexos.');
        expect($result['summary']['failed']===0&&count($find($result,'canonical'))===2,'Preview: canonical e políticas válidas.');
        expect($find($result,'sri')[0]['state']==='approved','SRI corresponde aos bytes exportados.');
        expect($find($result,'mime-docx')[0]['state']==='approved','MIME e nosniff do DOCX.');
        expect($find($result,'version')[0]['state']==='attention','Divulgação de versão sinalizada.');
        expect($find($result,'waf')[0]['state']==='unverified'&&str_contains($find($result,'waf')[0]['observed'],'WAF não confirmado'),'Proxy é indício, sem alegação de WAF.');
        $write('max-image-preview:large',$hash);$context['environment']='production';$calls=array();
        $result=(new Defensive($makeHttp('')))->inspect($context);expect($find($result,'robots-meta')[0]['state']==='approved'&&$find($result,'robots-header')[0]['state']==='approved','Produção indexável preservada.');
        $write('noindex, nofollow',$hash);$result=(new Defensive($makeHttp()))->inspect($context);expect($find($result,'robots-meta')[0]['state']==='failed'&&$find($result,'robots-header')[0]['state']==='failed','Produção com noindex inesperado sinalizada.');
        $context['environment']='homologation';$result=(new Defensive(static fn()=>new WP_Error('unreachable','raw private error')))->inspect($context);expect($find($result,'canonical')[0]['state']==='approved'&&$find($result,'robots-header')[0]['state']==='unverified','Destino indisponível preserva análise local.');expect(!str_contains(json_encode($result),'raw private error'),'Não persistir erros brutos.');
        $result=(new Defensive($makeHttp('noindex, nofollow',302)))->inspect($context);expect($find($result,'http')[0]['state']==='failed'&&$find($result,'robots-header')[0]['state']==='unverified','Redirecionamento não certifica headers do documento esperado.');
        file_put_contents($root.'/v/style','changed');$result=(new Defensive($makeHttp()))->inspect($context);expect($find($result,'sri')[0]['state']==='failed','Detectar SRI divergente.');
        $options->set('disable_local_integrity',true);$write('noindex, nofollow','');$result=(new Defensive($makeHttp()))->inspect($context);expect($find($result,'sri')[0]['state']==='attention','Desativação explícita respeitada e visível.');
        $options->set('disable_local_integrity',false)->set('refresh_local_integrity',false);$result=(new Defensive($makeHttp()))->inspect($context);expect($find($result,'sri')[0]['state']==='attention','Ausência de hash com recálculo desligado não é declarada aprovada.');
        file_put_contents($root.'/backup.sql','SECRET DATABASE CONTENT');file_put_contents($root.'/debug.log','SECRET LOG CONTENT');file_put_contents($root.'/.env','SECRET CONFIG CONTENT');file_put_contents($root.'/internal.js','fetch("http://localhost/wp-json/test")');
        $result=(new Defensive($makeHttp()))->inspect($context);expect(count($find($result,'package-exposure'))===3&&count($find($result,'package-reference'))===2,'EXPOSURES='.count($find($result,'package-exposure')).' REFS='.count($find($result,'package-reference')).' '.'Classificar nomes suspeitos e referências internas.');expect(!str_contains(json_encode($result),'SECRET'),'Não apresentar conteúdo de backups, logs ou configurações.');
        expect(Defensive::base('https://user:secret@target.test/')===''&&Defensive::base('file:///etc/')===''&&Defensive::base('https://target.test/../private')==='','Rejeitar destinos ambíguos.');
        $invalid=$context;$invalid['public_url']='https://target.test/?secret=x';$calls=array();$result=(new Defensive($makeHttp()))->inspect($invalid);expect(!$calls&&$find($result,'context')[0]['state']==='unverified','Contexto inválido não gera consultas.');
        $GLOBALS['fixture_options'][Connectors::OPTION]=array('enabled'=>true,'readonly_confirmed'=>true,'scan_id'=>'11111111-1111-1111-1111-111111111111','target'=>$base,'environment'=>'homologation');
        $externalCalls=array();$never=static function()use(&$externalCalls){$externalCalls[]=true;throw new RuntimeException('not expected');};
        $integrations=Connectors::consult($context,$never);expect(!$externalCalls&&str_contains($integrations[0]['reason'],'Credenciais'),'Sem credencial não consultar provedor.');
        putenv('GAMB_TENABLE_ACCESS_KEY=fixtureAccessKey');putenv('GAMB_TENABLE_SECRET_KEY=fixtureSecretKey');
        $transport=static function($url,$args)use(&$externalCalls,$base){$externalCalls[]=$url;expect(str_starts_with($url,'https://cloud.tenable.com/was/v2/scans/11111111-1111-1111-1111-111111111111')&&$args['redirection']===0,'Tenable limitado a GET de avaliação existente no host fixo.');return fixture_response(json_encode(str_ends_with($url,'/report')?array('report'=>array('created_at'=>'2026-11-23T12:05:00Z'),'vulnerabilities'=>array(array('severity'=>3,'details'=>'secret raw payload'))):array('scan_id'=>'11111111-1111-1111-1111-111111111111','target'=>$base,'status'=>'completed','finalized_at'=>'2026-11-23T12:00:00Z')));};
        $integrations=Connectors::consult($context,$transport);expect(count($externalCalls)===2&&$integrations[0]['findings']['high']===1,'Consultar detalhes e relatório existentes.');expect($integrations[0]['report_at']==='2026-11-23T12:00:00Z'&&$integrations[0]['queried_at']!==$integrations[0]['report_at'],'Distinguir data externa e consulta.');expect(!str_contains(json_encode($integrations),'secret raw payload')&&!str_contains(json_encode(Connectors::status()),'fixtureSecretKey'),'Não expor payloads nem chaves.');
        expect($integrations[1]['state']==='unverified'&&$integrations[1]['queried_at']===null,'Cymulate pendente não interfere.');
        foreach(array(401,403,404,429,302)as$code){$r=Connectors::consult($context,static fn()=>fixture_response('private error',$code));expect($r[0]['state']==='unverified'&&!str_contains(json_encode($r),'private error'),'Falha de provedor sem conteúdo bruto.');}
        $changed=$context;$changed['environment']='production';$externalCalls=array();$r=Connectors::consult($changed,$never);expect(!$externalCalls&&str_contains($r[0]['reason'],'Associação'),'Não associar relatório a outro ambiente.');
        $r=Connectors::consult($context,static fn()=>fixture_response(json_encode(array('target'=>'https://wrong.test/','scan_id'=>'11111111-1111-1111-1111-111111111111'))));expect($r[0]['state']==='unverified','Rejeitar alvo divergente.');
    }finally{
        putenv('GAMB_TENABLE_ACCESS_KEY');putenv('GAMB_TENABLE_SECRET_KEY');$GLOBALS['fixture_options']=$saved;foreach($settings as$key=>$value)$options->set($key,$value);
        foreach(array_merge($paths,array('.gamb-static-files.json','backup.sql','debug.log','.env','internal.js')) as$file){if(is_file($root.'/'.$file))unlink($root.'/'.$file);}rmdir($root.'/interno');rmdir($root.'/v');rmdir($root);
    }
})();
