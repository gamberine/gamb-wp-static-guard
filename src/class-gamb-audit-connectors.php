<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

final class Audit_Connectors {
    public const OPTION='gamb_audit_tenable';
    private static function credential(string $name): string {
        $value=defined($name)?constant($name):getenv($name);
        return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{8,256}$/D',$value)?$value:'';
    }
    public static function status(): array {
        $saved=(array)get_option(self::OPTION,array());
        return array('tenable'=>array('enabled'=>!empty($saved['enabled']),'scan_id'=>$saved['scan_id']??'','target'=>$saved['target']??'','environment'=>$saved['environment']??'','readonly_confirmed'=>!empty($saved['readonly_confirmed']),'credentials_configured'=>self::credential('GAMB_TENABLE_ACCESS_KEY')!==''&&self::credential('GAMB_TENABLE_SECRET_KEY')!==''),'cymulate'=>array('enabled'=>false,'reason'=>'Pendente: endpoint de leitura e permissões da conta não confirmados. Nenhuma consulta será feita.'));
    }
    public static function save($request) {
        if(!current_user_can('manage_options'))return new \WP_Error('forbidden','Acesso não autorizado.',array('status'=>403));
        $enabled=filter_var($request->get_param('enabled'),FILTER_VALIDATE_BOOLEAN);$id=$request->get_param('scan_id');$confirmed=filter_var($request->get_param('readonly_confirmed'),FILTER_VALIDATE_BOOLEAN);
        if(!is_string($id)||($id!==''&&!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)))return new \WP_Error('invalid_scan','Informe o UUID de uma avaliação WAS existente.',array('status'=>400));
        if($enabled&&(!$confirmed||$id===''))return new \WP_Error('readonly_required','Confirme a permissão de leitura e informe a avaliação existente.',array('status'=>400));
        $context=Admin_Insights::publication();$target=Destination_Audit::base($context['public_url']);
        if($enabled&&$target==='')return new \WP_Error('invalid_target','Configure a URL pública do destino antes de associar o relatório.',array('status'=>400));
        update_option(self::OPTION,array('enabled'=>$enabled,'scan_id'=>strtolower($id),'readonly_confirmed'=>$confirmed,'target'=>$target,'environment'=>$context['environment']),false);
        return rest_ensure_response(self::status());
    }
    private static function unavailable(array $result,string $reason): array { $result['state']='unverified';$result['reason']=$reason;return $result; }
    public static function consult(array $context,?callable $http=null): array {
        $config=(array)get_option(self::OPTION,array());$base=Destination_Audit::base($context['public_url']??'');$env=$context['environment']??'';
        $r=array('provider'=>'Tenable WAS','state'=>'unverified','target'=>$base,'environment'=>$env,'report_at'=>null,'queried_at'=>gmdate('c'),'scan_id'=>$config['scan_id']??'','findings'=>array(),'reason'=>'Conector desativado.');
        $cymulate=array('provider'=>'Cymulate','state'=>'unverified','target'=>$base,'environment'=>$env,'report_at'=>null,'queried_at'=>null,'reason'=>self::status()['cymulate']['reason']);
        $finish=static fn($tenable)=>array($tenable,$cymulate);
        if(empty($config['enabled']))return $finish($r);
        if(empty($config['readonly_confirmed']))return $finish(self::unavailable($r,'Permissão de leitura não confirmada.'));
        if($base===''||($config['target']??'')!==$base||($config['environment']??'')!==$env)return $finish(self::unavailable($r,'Associação desatualizada: destino ou ambiente mudou. Salve novamente a associação.'));
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$r['scan_id']))return $finish(self::unavailable($r,'Identificador de avaliação inválido.'));
        $access=self::credential('GAMB_TENABLE_ACCESS_KEY');$secret=self::credential('GAMB_TENABLE_SECRET_KEY');
        if($access===''||$secret==='')return $finish(self::unavailable($r,'Credenciais de leitura ausentes no servidor (GAMB_TENABLE_ACCESS_KEY e GAMB_TENABLE_SECRET_KEY).'));
        $http=$http??static fn($url,$args)=>wp_remote_get($url,$args);
        $get=static function(string $suffix)use($http,$r,$access,$secret){
            try{$response=$http('https://cloud.tenable.com/was/v2/scans/'.$r['scan_id'].$suffix,array('timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>2097152,'cookies'=>array(),'headers'=>array('Accept'=>'application/json','Content-Type'=>'application/json','X-ApiKeys'=>'accessKey='.$access.'; secretKey='.$secret.';')));}
            catch(\Throwable $e){return array('error'=>'Integração inacessível.');}
            if(is_wp_error($response))return array('error'=>'Integração inacessível: DNS, TLS, conexão ou timeout.');
            $code=wp_remote_retrieve_response_code($response);
            if($code!==200){$messages=array(401=>'Credencial inválida.',403=>'Permissão de leitura ou Can View ausente.',404=>'Avaliação ou relatório existente indisponível; não será solicitada geração.',429=>'Limite de consultas atingido.');return array('error'=>$messages[$code]??'HTTP '.$code.'; redirecionamentos não seguidos.');}
            $data=json_decode(wp_remote_retrieve_body($response),true);return is_array($data)?array('data'=>$data):array('error'=>'Relatório JSON ausente, inválido ou acima do limite de 2 MB.');
        };
        $details=$get('');if(isset($details['error']))return $finish(self::unavailable($r,$details['error']));$scan=$details['data'];
        if(!is_string($scan['target']??null)||Destination_Audit::base($scan['target'])!==$base || ($scan['scan_id']??'')!==$r['scan_id'])return $finish(self::unavailable($r,'O alvo/ID retornado pelo provedor não corresponde à avaliação associada ao destino.'));
        $r['target']=$base;$r['report_at']=self::date($scan['finalized_at']??$scan['metadata']['finalized_at']??null);
        if(($scan['status']??'')!=='completed')return $finish(self::unavailable($r,'A avaliação existente ainda não está concluída; nenhuma ação foi solicitada.'));
        $report=$get('/report');if(isset($report['error']))return $finish(self::unavailable($r,$report['error']));$data=$report['data'];
        $r['report_at']=$r['report_at']??self::date($data['scan']['finalized_at']??$data['scan']['metadata']['finalized_at']??null);
        if(!$r['report_at'])return $finish(self::unavailable($r,'O provedor não informou data válida de conclusão da avaliação.'));
        // Consume only structured counts; raw requests, responses and credentials in a report are never retained.
        $findings=$data['vulnerabilities']??null;
        if(!is_array($findings)||array_values($findings)!==$findings||array_filter($findings,static fn($f)=>!is_array($f)))return $finish(self::unavailable($r,'Relatório existente sem lista de achados reconhecida; nenhum resumo foi presumido.'));
        if(isset($data['scan']['scan_id'])&&$data['scan']['scan_id']!==$r['scan_id'])return $finish(self::unavailable($r,'O relatório retornado pertence a outra avaliação.'));
        $counts=array_fill_keys(array('critical','high','medium','low','info','unknown'),0);
        foreach($findings as $f){$severity=$f['severity']??$f['plugin']['risk_factor']??'unknown';if(!is_scalar($severity))$severity='unknown';if(is_int($severity)||ctype_digit((string)$severity))$severity=array(0=>'info',1=>'low',2=>'medium',3=>'high',4=>'critical')[(int)$severity]??'unknown';$key=strtolower((string)$severity);if(!isset($counts[$key]))$key='unknown';$counts[$key]++;}
        $r['findings']=$counts;$r['state']=array_sum($counts)>0?'attention':'approved';$r['reason']='Relatório existente consultado em leitura. Ambiente associado localmente; alvo confirmado pelo provedor. Não certifica a exportação atual.';
        $r['report_generated_at']=self::date($data['report']['created_at']??null);
        return $finish($r);
    }
    private static function date($value): ?string { return is_string($value)&&strlen($value)<60&&preg_match('/^\d{4}-\d\d-\d\dT/',$value)&&strtotime($value)!==false?substr($value,0,40):null; }
}
