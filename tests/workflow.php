<?php
/** Regressões de autorização, proxy, progresso e estado editorial. */
require dirname(__DIR__) . '/src/class-gamb-operation-progress.php';
require dirname(__DIR__) . '/src/class-gamb-publication-status.php';
require dirname(__DIR__) . '/src/class-gamb-version-monitor.php';
function get_current_user_id() { return $GLOBALS['fixture_user'] ?? 1; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function current_time($format) { return gmdate('Y-m-d H:i:s'); }
function sanitize_file_name($name) { return basename($name); }
use GambWPStaticGuard\Version_Monitor as Monitor;
use GambWPStaticGuard\Operation_Progress as Progress;
use GambWPStaticGuard\Publication_Status as Publication;
use GambWPStaticGuard\Options;
expect(Monitor::normalize('::ffff:192.0.2.10')===Monitor::normalize('192.0.2.10'),'Normalizar IPv4 mapeado em IPv6.');
expect(Monitor::normalize(' 2001:db8::1 ')===Monitor::normalize('2001:0db8:0:0:0:0:0:1'),'Normalizar IPv6 sem equivalência textual obrigatória.');
expect(Monitor::allowed('192.0.2.10','',array('192.0.2.10'),array()),'Autorizar IP exato.');
expect(!Monitor::allowed('192.0.2.11','192.0.2.10',array('192.0.2.10'),array()),'Ignorar XFF de cliente sem proxy confiável.');
expect(!Monitor::allowed('192.0.2.10','',array(),array()),'Bloquear todas as origens com lista vazia.');
expect(!Monitor::allowed('127.0.0.1','',array('192.0.2.10'),array()),'Não autorizar loopback implicitamente.');
expect(Monitor::allowed('198.51.100.2','192.0.2.10',array('192.0.2.10'),array('198.51.100.2')),'Reconhecer proxy expressamente confiável.');
expect(Monitor::allowed('198.51.100.2','192.0.2.10, 198.51.100.3',array('192.0.2.10'),array('198.51.100.2','198.51.100.3')),'Percorrer cadeia confiável da direita para a esquerda.');
expect(!Monitor::allowed('198.51.100.2','192.0.2.10, 203.0.113.5',array('192.0.2.10'),array('198.51.100.2')),'Parar no primeiro salto não confiável.');
foreach (array('', 'invalid', '192.0.2.10,', str_repeat('1',2049), implode(',',array_fill(0,21,'192.0.2.10'))) as $header) { expect(!Monitor::allowed('198.51.100.2',$header,array('192.0.2.10'),array('198.51.100.2')),'Recusar cabeçalho ausente, inválido ou excessivo.'); }
expect(!Monitor::allowed('192.0.2.10','',array('192.0.2.10','bad'),array()),'Falhar fechado com configuração autorizada inválida.');
expect(!Monitor::allowed('192.0.2.10','',array('192.0.2.10'),array('bad')),'Falhar fechado com proxy inválido.');
expect(Monitor::validate_list("192.0.2.10\n::ffff:192.0.2.10")===array('192.0.2.10'),'Remover duplicatas equivalentes de IP.');
foreach (array('192.0.2.0/24','*.example.test','192.0.2.10:80','example.test') as $value) { expect(is_wp_error(Monitor::validate_list($value)),'Recusar rede, curinga, porta e hostname.'); }
expect(is_wp_error(Monitor::validate_list(array_fill(0,201,'192.0.2.10'))),'Limitar o tamanho da configuração.');
expect(!Options::get_default_options()['version_monitor_enabled'] && Options::get_default_options()['version_monitor_allowed_ips']===array(),'Novas instalações mantêm o monitor desabilitado e sem IPs institucionais.');
$options->set('version_monitor_enabled',false);
expect(is_wp_error(Monitor::permission()) && Monitor::permission()->data['status']===404,'Desabilitar o endpoint sem retornar versão.');
$options->set('version_monitor_enabled',true)->set('version_monitor_allowed_ips',array('192.0.2.10'))->set('version_monitor_trusted_proxies',array());
$_SERVER['REMOTE_ADDR']='192.0.2.11'; $_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.10';
expect(is_wp_error(Monitor::response()),'Revalidar IP no próprio callback.');
expect(is_wp_error(Progress::create('unknown')),'Restringir tipos de operação.');
$job=Progress::create('settings');
expect($job['status']==='queued' && $job['percent']===0 && !isset($job['owner']),'Reservar operação sem divulgar o identificador do usuário.');
expect(is_wp_error(Progress::create('backup')),'Impedir operação concorrente durante reserva.');
$req=new class($job['id']) { private $id; public function __construct($id){$this->id=$id;} public function get_param($key){return $this->id;} };
$GLOBALS['fixture_user']=2;
expect(is_wp_error(Progress::execute($req,'settings',static function(){return array('success'=>true);})), 'Impedir execução de reserva por outro usuário.');
$GLOBALS['fixture_user']=1;
Progress::execute($req,'settings',static function(){Progress::phase('settings',2,4,1,2,'Aplicando unidades concluídas.');expect(Progress::snapshot()['percent']===37,'Calcular percentual a partir de etapa e unidades reais.');return array('success'=>true);});
expect(Progress::snapshot()['status']==='success' && Progress::snapshot()['percent']===100,'Concluir operação bem-sucedida em 100%.');
expect(is_wp_error(Progress::execute($req,'settings',static function(){return array('success'=>true);})), 'Impedir a repetição do mesmo pedido.');
$job=Progress::create('backup');$req=new class($job['id']){private $id;public function __construct($id){$this->id=$id;}public function get_param($key){return $this->id;}};
Progress::execute($req,'backup',static function(){Progress::phase('backup',2,4,0,1,'Iniciando pacote.');return new WP_Error('fixture','Falha controlada.');});
expect(Progress::snapshot()['status']==='error' && Progress::snapshot()['percent']===25,'Não apresentar falha como 100% concluído.');
Progress::execute(null,'audit',static function(){throw new RuntimeException('private-secret');});
expect(Progress::snapshot()['status']==='error' && !str_contains(Progress::snapshot()['message'],'private-secret'),'Não divulgar detalhes de exceções internas.');
expect(Progress::snapshot($job['id'])['status']==='error','Preservar resultado anterior para usuários concorrentes.');
update_option(Progress::OPTION,array('id'=>'stale','status'=>'running','updated'=>time()-100,'percent'=>25));
expect(Progress::snapshot()['status']==='error','Detectar execução interrompida sem lock ativo.');
$options->set('first_full_export_completed',false);delete_option(Publication::OPTION);delete_option(Publication::REVISION);
expect(Publication::snapshot()['state']==='unpublished','Identificar primeira publicação pendente.');
Publication::complete(Publication::revision());
expect(Publication::snapshot()['label']==='Publicado','Registrar a primeira entrega completa.');
Publication::changed();expect(Publication::snapshot()['state']==='outdated','Identificar alteração posterior na origem.');
$revision=Publication::revision();Publication::changed();Publication::complete($revision);
expect(Publication::snapshot()['state']==='outdated','Não declarar sincronização se a origem mudar durante a exportação.');
Publication::complete(Publication::revision());expect(Publication::snapshot()['label']==='Atualizado','Registrar reconstrução sincronizada.');
$options->set('local_dir','/changed-destination');expect(Publication::snapshot()['state']==='outdated','Não declarar sincronização após mudar o destino.');
$revision=Publication::revision();Publication::option_changed('options_consumer_banner');expect(Publication::revision()!==$revision,'Identificar edição de conteúdo nas opções ACF.');
$revision=Publication::revision();Publication::option_changed('gamb_static_guard_operation');expect(Publication::revision()===$revision,'Não invalidar a publicação por atualização do próprio progresso.');
