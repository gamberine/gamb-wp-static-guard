<?php
/** Isolamento de origens e condições necessárias do acesso privado. */
require dirname(__DIR__).'/src/class-gamb-origin-context.php';
require dirname(__DIR__).'/src/class-gamb-original-access.php';
use GambWPStaticGuard\Origin_Context as Origin;
use GambWPStaticGuard\Original_Access as Original;
(function(){
    $directory=ABSPATH.'.gamb-runtime/compartilhamento';wp_mkdir_p($directory);
    $server=$_SERVER;$fixture=$GLOBALS['fixture_options'];
    try {
        file_put_contents($directory.'/session.json',json_encode(array('status'=>'running','address'=>'172.30.125.118','origins'=>array('https://shared.example.test/site/'),'trusted_proxies'=>array('127.0.0.1'))));
        Origin::state(true);
        expect(Origin::request_home(array('HTTP_HOST'=>'172.30.125.118','REMOTE_ADDR'=>'172.30.125.10'))==='http://172.30.125.118/site/','Origem LAN explicitamente autorizada preserva o caminho.');
        expect(Origin::request_home(array('HTTP_HOST'=>'172.30.125.118','REMOTE_ADDR'=>'8.8.8.8'))===null,'Não aceitar peer público em origem privada.');
        expect(Origin::request_home(array('HTTP_HOST'=>'evil.test','HTTP_X_FORWARDED_HOST'=>'example.test','HTTPS'=>'on'))===null,'Não confiar em Host ou Forwarded arbitrários.');
        expect(Origin::request_home(array('HTTP_HOST'=>'shared.example.test','REMOTE_ADDR'=>'8.8.8.8','HTTP_X_FORWARDED_PROTO'=>'https'))===null,'Forwarded de proxy desconhecido não libera HTTPS.');
        expect(Origin::request_home(array('HTTP_HOST'=>'shared.example.test','REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_PROTO'=>'https'))==='https://shared.example.test/site/','Proxy cadastrado permite origem HTTPS explícita.');
        foreach(array('http://public.test/site/','https://example.test/','https://user:secret@public.test/site/','https://public.test/site/%2e%2e/','https://public.test/site/?secret=1')as$url)expect(!Origin::valid_alias($url,Origin::canonical()),'Rejeitar origem insegura ou fora do caminho.');
        expect(Origin::parent_domain('https://preview.example.org/','https://example.org/'),'Bloquear domínio ancestral.');
        expect(!Origin::parent_domain('https://preview.example.org/','https://other.example.org/'),'Não confundir domínio irmão com ancestral.');
        expect(!Origin::private_host('10.evil.test'),'Não tratar domínio com prefixo numérico como IPv4 privado.');
        $_SERVER=array('HTTP_HOST'=>'172.30.125.118','REMOTE_ADDR'=>'172.30.125.10');
        expect(Origin::map('https://example.test/site/v/token')==='http://172.30.125.118/site/v/token','Mapear origem sem duplicar caminho.');
        expect(Origin::map('https://external.test/site/a.css')==='https://external.test/site/a.css','Preservar referências externas.');
        $before=$GLOBALS['fixture_options'];Origin::map('https://example.test/site/');expect($before===$GLOBALS['fixture_options'],'Mapeamento não altera opções persistidas.');
        expect(Original::allowed(true,true,true,true,'GET'),'Administrador com sessão e confirmação pode ler original.');
        foreach(array(array(false,true,true,true,'GET'),array(true,false,true,true,'GET'),array(true,true,false,true,'GET'),array(true,true,true,false,'GET'),array(true,true,true,true,'POST'))as$args)expect(!Original::allowed(...$args),'Não permitir original sem cada barreira de segurança.');
        $o=GambWPStaticGuard\Options::instance();$saved=$o->get_all();
        update_option(Original::OPTION,array('enabled'=>true,'expires_at'=>time()-1));expect(!Original::enabled(),'Expiração desativa acesso mesmo com flag habilitada.');
        update_option(Original::OPTION,array('enabled'=>true,'expires_at'=>time()+3600));expect(Original::enabled(),'Estado dedicado permite acesso durante o prazo.');
        update_option(Original::OPTION,array('enabled'=>false,'expires_at'=>0));
        $o->set('original_access_enabled',true)->set('original_access_expires_at',time()+3600)->save();expect(!Original::enabled(),'Worker com opções antigas não reativa acesso revogado.');
        unset($GLOBALS['fixture_options'][Original::OPTION]);
        foreach($saved as$key=>$value)$o->set($key,$value);
        expect(!GambWPStaticGuard\Options::get_default_options()['original_access_enabled'],'Acesso original desativado por padrão.');
        $guard=file_get_contents(dirname(__DIR__).'/src/class-gamb-guard-suite.php');
        preg_match_all('/header\(\s*["\x27]([^"\x27:]+):/',$guard,$headers);
        foreach($headers[1]as$name)expect((bool)preg_match('/^[A-Za-z0-9-]+$/D',$name),'Nomes de cabeçalhos HTTP são tokens ASCII válidos.');
        file_put_contents($directory.'/session.json',json_encode(array('status'=>'stopped','address'=>'172.30.125.118')));
        expect(Origin::state()!==array(),'Estado consistente durante a mesma requisição.');Origin::state(true);
        expect(Origin::request_home(array('HTTP_HOST'=>'172.30.125.118','REMOTE_ADDR'=>'172.30.125.10'))===null,'Parar compartilhamento revoga alias.');
    } finally {$_SERVER=$server;$GLOBALS['fixture_options']=$fixture;unlink($directory.'/session.json');Origin::state(true);rmdir($directory);rmdir(dirname($directory));}
})();
