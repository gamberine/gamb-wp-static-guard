<?php
declare(strict_types=1);
/** Estados administrativos não podem declarar aprovação sem evidência atual. */
require dirname(__DIR__) . '/src/class-gamb-admin.php';
use GambWPStaticGuard\Admin_Controller as Admin;
use GambWPStaticGuard\Installation_Checks as Installation;
use GambWPStaticGuard\Gamb_Static_Auditor as Audit;

$saved_options = $GLOBALS['fixture_options'];
$good = array('version'=>GAMB_STATIC_WP_VERSION, 'checked_at'=>'2026-11-23', 'checks'=>array(
    array('id'=>'html_parser','status'=>'ok'), array('id'=>'crypto','status'=>'ok'),
    array('id'=>'dependencies','status'=>'ok'), array('id'=>'zip','status'=>'ok'),
));
$approved = array('version'=>GAMB_STATIC_WP_VERSION,'checked_at'=>'2026-11-23','success'=>true,'documents'=>22,'findings'=>array());
$cases = array(
    array('Sem diagnóstico',array(),array(),false,'neutral','neutral'),
    array('Diagnóstico e auditoria aprovados',$good,$approved,false,'success','success'),
    array('Sem auditoria',$good,array(),false,'success','neutral'),
    array('Revalidação pendente',$good,$approved,true,'success','warning'),
);
$report=$good; $report['checks'][3]['status']='warning'; $cases[]=array('ZIP não altera aprovação do GUARD',$report,$approved,false,'warning','success');
$report=$good; $report['checks'][0]['status']='error'; $cases[]=array('Parser impede conversão e auditoria',$report,$approved,false,'error','error');
$report=$good; $report['checks'][1]['status']='error'; $cases[]=array('Criptografia indisponível',$report,$approved,false,'error','error');
$report=$good; $report['checks'][2]['status']='warning'; $cases[]=array('Dependências geram atenção',$report,$approved,false,'warning','warning');
$report=$good; $report['version']='0.0.0'; $cases[]=array('Diagnóstico de outra versão',$report,$approved,false,'warning','warning');
$audit=$approved; $audit['success']=false; $cases[]=array('Auditoria reprovada',$good,$audit,false,'success','error');
$audit=$approved; $audit['findings']=array(array('severity'=>'CRITICAL')); $cases[]=array('Achado crítico prevalece sobre sucesso',$good,$audit,false,'success','error');
$audit=$approved; $audit['findings']=array(array('severity'=>'WARNING')); $cases[]=array('Achado de atenção',$good,$audit,false,'success','warning');
$audit=$approved; $audit['version']='0.0.0'; $cases[]=array('Auditoria de outra versão',$good,$audit,false,'success','warning');
$audit=$approved; $audit['documents']=0; $cases[]=array('Não aprovar zero documentos',$good,$audit,false,'success','neutral');
try {
    foreach ($cases as [$name,$installation,$audit,$pending,$static_tone,$guard_tone]) {
        $GLOBALS['fixture_options'][Installation::REPORT_OPTION]=$installation;
        $GLOBALS['fixture_options'][Audit::REPORT_OPTION]=$audit;
        $GLOBALS['fixture_options'][Audit::PENDING_OPTION]=$pending;
        $before=$GLOBALS['fixture_options'];
        $badges=Admin::section_badges();
        expect($badges['static']['tone']===$static_tone && $badges['guard']['tone']===$guard_tone,$name);
        expect($GLOBALS['fixture_options']===$before,'Ler badges sem alterar opções ou executar diagnóstico.');
    }
} finally { $GLOBALS['fixture_options']=$saved_options; }

defined('GAMB_STATIC_WP_URL') || define('GAMB_STATIC_WP_URL','https://example.test/plugin');
function wp_enqueue_style($handle,$url,$dependencies=array(),$version=null) { $GLOBALS['fixture_styles'][$handle]=compact('url','dependencies','version'); }
function wp_enqueue_script($handle,$url,$dependencies=array(),$version=null,$footer=false) { $GLOBALS['fixture_scripts'][$handle]=compact('url','dependencies','version','footer'); }
function wp_localize_script($handle,$name,$data) { $GLOBALS['fixture_localized'][$handle]=$data; }
function rest_url($route='') { return 'https://example.test/wp-json/' . $route; }
$admin=(new ReflectionClass(Admin::class))->newInstanceWithoutConstructor();
foreach (array('index.php','plugins.php','edit.php','toplevel_page_gamb-wp-static-guard') as $hook) {
    $GLOBALS['fixture_styles']=array(); $GLOBALS['fixture_scripts']=array();
    $admin->enqueue_admin_assets($hook);
    $panel=str_contains($hook,Admin::MENU);
    expect(isset($GLOBALS['fixture_styles']['gamb-guard-menu']),'Carregar menu em todas as telas administrativas.');
    expect(isset($GLOBALS['fixture_styles']['gamb-guard-admin'])===$panel && isset($GLOBALS['fixture_scripts']['gamb-guard-admin'])===$panel,'Restringir CSS e JS do painel ao próprio contexto.');
}
