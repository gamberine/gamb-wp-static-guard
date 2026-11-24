<?php
require dirname(__DIR__).'/src/class-gamb-publication-view.php';
use GambWPStaticGuard\Publication_View as View;
(function()use($options){
    $settings=$options->get_all();$root=wp_normalize_path(sys_get_temp_dir()).'/gamb-view-'.bin2hex(random_bytes(6));mkdir($root);
    try{
        $valid=array('public_site_mode'=>'static','delivery_method'=>'local','destination_host'=>'example.test','destination_scheme'=>'https://','relative_path'=>'site','destination_url_type'=>'relative');
        expect(View::settings_error($valid)===null,'Apresentar o estático apenas na URL principal configurada.');
        foreach(array(array('destination_host'=>'other.test'),array('delivery_method'=>'zip'),array('destination_url_type'=>'offline'))as$change)expect(View::settings_error(array_merge($valid,$change))!==null,'Rejeitar modo estático incompatível.');
        $manifest=array('index.html','404.html','news/index.html','v/token','v/token.css','v/pdf','v/pdf.pdf','static/search.js','.gamb-static-files.json','handler.php','backups/example.html');
        $cases=array('/site/'=>array(200,'index.html','text/html'),'/site/news/'=>array(200,'news/index.html','text/html'),'/site/news'=>array(200,'news/index.html','text/html'),'/site/v/token'=>array(200,'v/token','text/css'),'/site/v/pdf'=>array(200,'v/pdf','application/pdf'),'/site/static/search.js'=>array(200,'static/search.js','application/javascript'),'/site/missing/'=>array(404,'404.html','text/html'),'/site/.gamb-static-files.json'=>array(404,'404.html','text/html'),'/site/handler.php'=>array(404,'404.html','text/html'),'/site/../secret'=>array(404,'404.html','text/html'),'/site/%2e%2e/secret'=>array(404,'404.html','text/html'),'/site/v/token%2fsecret'=>array(404,'404.html','text/html'),'/site/backups/example.html'=>array(404,'404.html','text/html'),'/site/index.php'=>array(200,'index.html','text/html'));
        foreach($cases as$uri=>$expected){$r=View::candidate($uri,'/site/',$manifest);expect(array_values($r)===$expected,'Rota estática segura: '.$uri);}
        foreach(array('/site/wp-admin/','/site/wp-json/gamb/v1/','/site/wp-login.php','/site/wp-cron.php')as$uri)expect(View::candidate($uri,'/site/',$manifest)===null,'Preservar endpoint WordPress: '.$uri);
        expect(View::candidate('/other-project/','/site/',$manifest)===null,'Não interceptar outro projeto.');
        file_put_contents($root.'/index.html','public home');file_put_contents($root.'/.gamb-static-files.json',json_encode(array('index.html')));
        foreach(array_merge($valid,array('local_dir'=>$root))as$key=>$value)$options->set($key,$value);
        expect(!View::status()['ready'],'Não ativar entrega antiga sem identificação.');
        file_put_contents($root.'/'.View::RECEIPT,json_encode(array('public_url'=>home_url('/'),'environment'=>$options->get('destination_environment'))));
        expect(View::status()['ready'],'Entrega correspondente ativa o modo estático.');
        $options->set('destination_environment','preview');expect(!View::status()['ready'],'Ambiente alterado exige nova entrega correspondente.');
        $options->set('public_site_mode','wordpress');expect(!View::status()['ready']&&View::status()['mode']==='wordpress','Voltar ao WordPress sem apagar arquivos.');
        expect(View::resolve($root,'../secret')===null&&View::resolve($root,'.env')===null,'Não resolver travessia ou arquivo ausente.');
        file_put_contents($root.'/.env','secret');expect(is_wp_error(GambWPStaticGuard\Publication_Destination::validate($root)),'Escrita continua bloqueada com marcador de aplicação.');
        expect(!is_wp_error(GambWPStaticGuard\Publication_Destination::validate($root,true)),'Auditoria pode classificar marcador sem abri-lo.');
        expect(is_wp_error(GambWPStaticGuard\Publication_Destination::inspect($root.'/new',true,true)),'Modo de leitura não cria diretórios.');
    }finally{foreach($settings as$key=>$value)$options->set($key,$value);foreach(array('index.html','.gamb-static-files.json',View::RECEIPT,'.env')as$file){if(is_file($root.'/'.$file))unlink($root.'/'.$file);}rmdir($root);}
})();
