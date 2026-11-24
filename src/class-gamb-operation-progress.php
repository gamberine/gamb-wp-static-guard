<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Estado restrito e persistente; percentuais representam etapas e unidades concluídas. */
class Operation_Progress {
    const OPTION = 'gamb_static_guard_operation';
    const HISTORY = 'gamb_static_guard_operation_history';
    const CONTROL_OPTION = 'gamb_static_guard_operation_control';
    private static $current = null;
    private static $last_write = 0.0;
    private static $last_control_read = 0.0;
    const LABELS = array('export'=>'Publicação estática', 'single'=>'Publicação do conteúdo', 'settings'=>'Aplicação das configurações', 'diagnostics'=>'Diagnóstico da instalação', 'updates'=>'Consulta de atualizações', 'audit'=>'Auditoria da origem', 'publication-audit'=>'Auditoria da publicação', 'destination-audit'=>'Auditoria defensiva', 'audit-connectors'=>'Conectores de auditoria', 'destination'=>'Verificação do destino', 'backup'=>'Cópia de segurança', 'backup-delete'=>'Exclusão da cópia de segurança');

    private static function lock() {
        $handle = @fopen(get_temp_dir() . 'gamb-operation-' . md5(ABSPATH) . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { if ($handle) { fclose($handle); } return false; }
        return $handle;
    }
    private static function busy() { return new \WP_Error('operation_busy', 'Há uma operação em andamento. Aguarde sua conclusão.', array('status'=>409)); }
    private static function fresh_option(string $key): array {
        // Long-running workers must see commands written by another HTTP request.
        if (function_exists('wp_cache_delete')) { wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options'); }
        $value=get_option($key,array()); return is_array($value) ? $value : array();
    }
    private static function read(): array { return self::fresh_option(self::OPTION); }
    private static function fresh(array $state): bool { return in_array($state['status'] ?? '', array('queued','running','paused'), true) && time() - (int)($state['updated'] ?? 0) < 90; }
    private static function controllable(array $state): bool {
        return in_array($state['kind'] ?? '',array('export','single'),true) && in_array($state['status'] ?? '',array('queued','running','paused'),true) && ((int)($state['owner'] ?? -1)===get_current_user_id() || (int)($state['owner'] ?? -1)===0);
    }
    public static function control($request) {
        if (!current_user_can('manage_options')) { return new \WP_Error('forbidden','Acesso não autorizado.',array('status'=>403)); }
        $action=(string)$request->get_param('action'); $id=(string)$request->get_param('id');
        if (!in_array($action,array('pause','resume'),true) || ''===$id) { return new \WP_Error('invalid_control','Informe a operação e o comando de pausa ou continuação.',array('status'=>400)); }
        $lock=@fopen(get_temp_dir().'gamb-control-'.md5(ABSPATH).'.lock','c');
        if (!$lock) { return self::busy(); }
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return self::busy(); }
        try {
            $state=self::read();
            if (($state['id'] ?? '')!==$id || !self::controllable($state) || !self::fresh($state)) { return new \WP_Error('operation_not_controllable','Esta publicação não está disponível para pausa ou continuação.',array('status'=>409)); }
            update_option(self::CONTROL_OPTION,array('id'=>$id,'action'=>$action),false);
            return self::snapshot($id);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** Cooperative pause: retain the worker and its locks at a safe unit boundary. */
    public static function checkpoint(): void {
        if (!self::$current || !in_array(self::$current['kind'],array('export','single'),true) || microtime(true)-self::$last_control_read<0.2) { return; }
        self::$last_control_read=microtime(true); $control=self::fresh_option(self::CONTROL_OPTION);
        if (($control['id'] ?? '')!==self::$current['id'] || ($control['action'] ?? '')!=='pause') { return; }
        $message=self::$current['message'];
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        self::$current['status']='paused'; self::$current['message']='Sincronização parada. Clique em Continuar para retomar deste ponto.';
        do {
            self::$current['updated']=time(); update_option(self::OPTION,self::$current,false);
            usleep(250000); $control=self::fresh_option(self::CONTROL_OPTION);
        } while (($control['id'] ?? '')===self::$current['id'] && ($control['action'] ?? '')==='pause');
        self::$current['status']='running'; self::$current['message']=$message; self::$current['updated']=time();
        update_option(self::OPTION,self::$current,false); self::$last_control_read=0.0;
    }
    private static function build(string $kind): array {
        return array('id'=>wp_generate_uuid4(), 'kind'=>$kind, 'owner'=>get_current_user_id(), 'status'=>'queued', 'percent'=>0, 'label'=>self::LABELS[$kind], 'message'=>'Aguardando início da execução.', 'phase'=>0, 'phases'=>0, 'done'=>0, 'total'=>0, 'started'=>time(), 'updated'=>time());
    }
    public static function create(string $kind) {
        if (!isset(self::LABELS[$kind])) { return new \WP_Error('invalid_operation', 'Operação inválida.', array('status'=>400)); }
        $lock = self::lock(); if (!$lock) { return self::busy(); }
        try {
            if (self::fresh(self::read())) { return self::busy(); }
            $state = self::build($kind); update_option(self::OPTION, $state, false);
            unset($state['owner']); return $state;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function snapshot(string $id = '') {
        $state = self::read();
        if ($id !== '' && ($state['id'] ?? '') !== $id) {
            $history=get_option(self::HISTORY,array());
            if (isset($history[$id])) { $previous=$history[$id]; unset($previous['owner']); return $previous; }
            return new \WP_Error('operation_not_found', 'O registro desta operação não está mais disponível.', array('status'=>410));
        }
        if ($state && in_array($state['status'] ?? '', array('queued','running','paused'), true) && !self::fresh($state)) {
            $lock = self::lock();
            if ($lock) {
                try {
                    $state = self::read();
                    if (in_array($state['status'] ?? '', array('queued','running','paused'), true) && !self::fresh($state)) {
                        $state['status']='error'; $state['message']='Execução interrompida. Verifique o registro antes de tentar novamente.'; $state['updated']=time(); update_option(self::OPTION, $state, false);
                    }
                } finally { flock($lock, LOCK_UN); fclose($lock); }
            }
        }
        $state['controllable']=self::controllable($state);
        $control=self::fresh_option(self::CONTROL_OPTION);
        if ($state['controllable'] && ($control['id'] ?? '')===($state['id'] ?? '')) {
            if (($control['action'] ?? '')==='pause' && ($state['status'] ?? '')!=='paused') { $state['status']='pausing'; $state['message']='Parando a sincronização no próximo ponto seguro…'; }
            elseif (($control['action'] ?? '')==='resume' && ($state['status'] ?? '')==='paused') { $state['status']='resuming'; $state['message']='Retomando a sincronização…'; }
        }
        unset($state['owner']); return $state;
    }
    public static function active(): bool { return self::$current !== null; }
    public static function phase(string $kind, int $phase, int $phases, int $done, int $total, string $message): void {
        if (!self::$current || !(self::$current['kind'] === $kind || ($kind === 'export' && self::$current['kind'] === 'single'))) { return; }
        self::checkpoint();
        $phase=max(1,min($phases,$phase)); $total=max(1,$total); $done=max(0,min($total,$done));
        $percent=min(99,(int)floor(100 * (($phase-1) + $done/$total) / max(1,$phases)));
        $changed = self::$current['percent'] !== $percent || self::$current['message'] !== $message;
        self::$current = array_merge(self::$current, array('status'=>'running','percent'=>$percent,'phase'=>$phase,'phases'=>$phases,'done'=>$done,'total'=>$total,'message'=>$message,'updated'=>time()));
        if ($changed || microtime(true)-self::$last_write >= 1) { update_option(self::OPTION,self::$current,false); self::$last_write=microtime(true); }
    }
    private static function finish(string $status, string $message): void {
        if (!self::$current) { return; }
        self::$current['status']=$status; self::$current['message']=$message; self::$current['updated']=time();
        if ($status === 'success' || $status === 'warning') { self::$current['percent']=100; self::$current['phase']=self::$current['phases']; self::$current['done']=self::$current['total']; }
        $history=get_option(self::HISTORY,array()); $history=is_array($history) ? $history : array();
        $history[self::$current['id']]=self::$current;
        update_option(self::HISTORY,array_slice($history,-10,null,true),false);
        update_option(self::OPTION,self::$current,false); self::$current=null;
    }
    public static function execute($request, string $kind, callable $callback) {
        if (self::active()) { return $callback(); }
        $lock=self::lock(); if (!$lock) { return self::busy(); }
        try {
            $id = $request ? (string)$request->get_param('_gamb_operation') : '';
            $state=self::read();
            if ($id !== '') {
                if (($state['id'] ?? '') !== $id || ($state['status'] ?? '') !== 'queued' || ($state['kind'] ?? '') !== $kind || (int)($state['owner'] ?? -1) !== get_current_user_id()) { return new \WP_Error('operation_mismatch','A operação não corresponde ao pedido ou já foi executada.',array('status'=>409)); }
            } else {
                if (self::fresh($state)) { return self::busy(); }
                $state=self::build($kind);
            }
            $previous_abort=ignore_user_abort(true);
            self::$current=$state; self::$current['status']='running'; self::$current['message']='Execução iniciada.'; self::$current['updated']=time();
            update_option(self::OPTION,self::$current,false);
            register_shutdown_function(static function() { if (self::active()) { self::finish('error','Execução interrompida. Consulte o registro de atividades.'); } });
            self::$last_control_read=0.0; self::checkpoint();
            $result=$callback(); $data=$result instanceof \WP_REST_Response ? $result->get_data() : $result;
            if (is_wp_error($result) || ($result instanceof \WP_REST_Response && $result->get_status() >= 400) || (is_array($data) && isset($data['success']) && !$data['success'])) {
                self::finish('error', is_wp_error($result) ? $result->get_error_message() : ($data['message'] ?? 'A operação não foi concluída.'));
            } elseif (in_array($kind,array('audit','publication-audit'),true) && empty($data['audit']['success'])) {
                self::finish('error','Auditoria concluída com impedimentos. Consulte os resultados.');
            } elseif ($kind==='destination-audit') {
                $summary=$data['report']['summary']??array();
                $warning=($summary['attention']??0)+($summary['failed']??0)+($summary['unverified']??0)>0;
                self::finish($warning?'warning':'success','Auditoria defensiva concluída. Consulte os estados e a cobertura na aba Auditoria.');
            } else {
                $message=self::LABELS[$kind] . ': execução concluída.';
                if (isset($data['files_count'])) { $message.=' ' . (int)$data['files_count'] . ' unidades processadas.'; }
                if (isset($data['filename'])) { $message.=' Arquivo: ' . sanitize_file_name($data['filename']) . '.'; }
                $warning=($kind==='updates' && empty($data['latest'])) || !empty($data['dependency_warnings']) || ($kind === 'diagnostics' && count(array_filter($data['installation']['checks'] ?? array(), static function($c) { return ($c['status'] ?? '') !== 'ok'; })));
                if ($warning) { $message.=' Há alertas; consulte os resultados.'; }
                self::finish($warning ? 'warning' : 'success',$message);
            }
            return $result;
        } catch (\Throwable $error) {
            self::finish('error','Falha na execução. O processo não foi concluído. Consulte o registro.');
            return new \WP_Error('operation_failed','Falha na execução. Consulte o registro.',array('status'=>500));
        } finally { self::$current=null; if (isset($previous_abort)) { ignore_user_abort((bool)$previous_abort); } flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function background_export(callable $callback): array {
        $result=self::execute(null,'export',$callback);
        return is_wp_error($result) ? array('success'=>false,'message'=>$result->get_error_message()) : $result;
    }
}
