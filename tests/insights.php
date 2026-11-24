<?php
/** Regressões de limites da pasta e evidências administrativas, sem instalação real. */
use GambWPStaticGuard\Publication_Destination as Destination;
use GambWPStaticGuard\Admin_Insights as Insights;
use GambWPStaticGuard\Gamb_Static_Auditor as Evidence;

$baseline=$GLOBALS['fixture_options'];$config=$options->get_all();
$sandbox=wp_normalize_path(sys_get_temp_dir()).'/gamb-insights-'.bin2hex(random_bytes(6));
mkdir($sandbox);mkdir($sandbox.'/application');file_put_contents($sandbox.'/application/.env','fixture');
try {
    foreach(array(ABSPATH,dirname(rtrim(ABSPATH,'/')),WP_CONTENT_DIR,ABSPATH.'wp-admin/new',ABSPATH.'wp-includes/new',$sandbox.'/application',$sandbox.'/../escape',$sandbox.'/CON',$sandbox.'/file:stream','//network/share',"/bad\x00path")as$blocked){expect(is_wp_error(Destination::inspect($blocked,true)),'Rejeitar destino protegido ou ambíguo: '.str_replace("\0",'',$blocked));}
    $check=Destination::inspect($sandbox.'/publication');expect(!is_wp_error($check)&&$check['can_create']&&!is_dir($sandbox.'/publication'),'Verificação não cria a pasta.');
    $created=Destination::inspect($sandbox.'/publication',true);expect(!is_wp_error($created)&&$created['created']&&is_dir($sandbox.'/publication'),'Criar somente após ação explícita.');
    expect(!is_wp_error(Destination::validate($sandbox.'/publication')),'A entrega usa a mesma validação da criação.');
    $folder=$sandbox.'/publication';$html='<!doctype html><html><head><title>Publicação</title></head><body>Conteúdo</body></html>';
    file_put_contents($folder.'/index.html',$html);mkdir($folder.'/pagina');file_put_contents($folder.'/pagina/index.html',$html);
    Evidence::begin('origin',2);Evidence::assert_safe($html,'https://example.test/site/',true,true);$origin=Evidence::save(true);
    $report=Evidence::audit_directory($folder,'delivered');
    expect($report['success']&&$report['documents']===2&&$report['expected_documents']===2&&$report['context']==='delivered','Cobertura explícita do destino.');
    expect(count($report['checks'])>=11&&count($report['records'])===2,'Persistir controles e documentos, inclusive aprovações.');
    expect(in_array('pagina/index.html',array_column($report['records'],'source'),true),'Preservar o caminho relativo dos documentos.');
    expect(get_option(Evidence::ORIGIN_OPTION)===$origin,'Auditoria da publicação não substitui a origem.');
    file_put_contents($folder.'/pagina/index.html','<html>Fatal error: fixture</html>');$failed=false;
    try{Evidence::audit_directory($folder,'delivered');}catch(RuntimeException $e){$failed=true;}
    $report=get_option(Evidence::PUBLICATION_OPTION);expect($failed&&!$report['success']&&!empty($report['findings'][0]['how_to_fix']),'Falha detalhada inclui providência.');
    expect(!str_contains(json_encode($report),'Fatal error: fixture'),'Não persistir fragmentos de conteúdo potencialmente sensível.');
    file_put_contents($folder.'/.gamb-static-files.json',json_encode(array('index.html','pagina/index.html','../outside.txt','/outside.txt','.htaccess')));
    $options->set('delivery_method','local')->set('local_dir',$folder)->set('last_export_files_count',157);
    $inventory=Insights::publication(1,true);expect($inventory['count']===3&&count($inventory['files'])===3&&$inventory['processed_units']===157,'Distinguir unidades e inventário e rejeitar travessia no manifesto.');
    expect($inventory['files'][0]['url']===''&&!$inventory['files'][0]['exists'],'Não gerar link público para arquivo de configuração.');
    $options->set('delivery_method','zip');expect(!Insights::publication()['inventory_available'],'Não atribuir inventário local ao pacote ZIP.');
    $controller=file_get_contents(dirname(__DIR__).'/src/class-gamb-admin.php');preg_match_all('/data-opt="([a-z_]+)"/',$controller,$fields);$help=Insights::help();foreach($fields[1]as$key){expect(isset($help[$key]),'Ajuda disponível para campo '.$key);}
    $manual=file_get_contents(dirname(__DIR__).'/assets/docs/manual.html');expect(!Evidence::inspect($manual,false,true),'Manual público passa pelos controles do HTML final.');
    expect(!preg_match('~(?:localhost|[A-Z]:[\\\\/]|_wpnonce|data-opt=|restUrl)~i',$manual),'Manual sem dados operacionais ou acesso às configurações.');
}finally{
    $GLOBALS['fixture_options']=$baseline;foreach($config as$key=>$value){$options->set($key,$value);}
    foreach(array('/publication/pagina/index.html','/publication/index.html','/publication/.gamb-static-files.json','/application/.env')as$file){if(is_file($sandbox.$file))unlink($sandbox.$file);}
    foreach(array('/publication/pagina','/publication','/application','')as$dir){if(is_dir($sandbox.$dir))rmdir($sandbox.$dir);}
}
