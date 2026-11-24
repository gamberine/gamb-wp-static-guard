<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** A bounded, read-only assessment. It never invokes export or scan launch APIs. */
final class Destination_Audit {
    public const REPORT_OPTION='gamb_destination_audit';
    public const LIMIT=20000;
    private array $checks=array();
    private array $responses=array();
    private $http;
    public function __construct(?callable $http=null) { $this->http=$http??static fn($url,$args)=>wp_remote_get($url,$args); }
    public static function latest(): array { return (array)get_option(self::REPORT_OPTION,array()); }
    public static function run(): array {
        $context=Admin_Insights::publication(1,true,true);
        $record=(array)get_option(Publication_Status::OPTION,array());
        $context['record_matches_destination']=isset($record['destination'])&&hash_equals($record['destination'],Publication_Status::destination());
        Operation_Progress::phase('destination-audit',1,3,0,1,'Conferindo arquivos e resposta do destino salvo.');
        $result=(new self())->inspect($context);
        Operation_Progress::phase('destination-audit',2,3,0,1,'Consultando apenas relatórios externos existentes.');
        try { $result['integrations']=Audit_Connectors::consult($context); }
        catch(\Throwable $error) { $result['integrations']=array(array('provider'=>'Integrações','state'=>'unverified','target'=>$context['public_url'],'environment'=>$context['environment'],'report_at'=>null,'queried_at'=>gmdate('c'),'reason'=>'Não foi possível interpretar a integração. Resultado local preservado.')); }
        Operation_Progress::phase('destination-audit',3,3,1,1,'Consolidando evidências e cobertura.');
        update_option(self::REPORT_OPTION,$result,false);
        return $result;
    }
    private function add(string $group,string $id,string $state,string $expected,string $observed,string $action,string $path='',string $reference=''): void {
        $this->checks[]=compact('group','id','state','expected','observed','action','path','reference');
    }
    public static function base(string $url): string {
        $p=wp_parse_url($url);
        if (!$p || !in_array($p['scheme']??'',array('http','https'),true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[\x00-\x20\\\\]/',$url)) { return ''; }
        $path=$p['path']??'/';
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)|%2e|%2f|%5c~i',$path)) { return ''; }
        return strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port'])?':'.$p['port']:'').'/'.trim($path,'/').(trim($path,'/')!==''?'/':'');
    }
    private static function relative(string $path): bool {
        return $path!=='' && strlen($path)<1024 && !preg_match('~[\x00-\x20\\\\:?#%]|^(?:/)|(?:^|/)\.{1,2}(?:/|$)~',$path);
    }
    private static function disk(string $root,string $path): ?string {
        if(!self::relative($path)){return null;}
        $candidate=$root.'/'.$path;$real=realpath($candidate);
        if(!$real || !is_file($real) || !str_starts_with(strtolower(wp_normalize_path($real)),strtolower(trailingslashit(wp_normalize_path($root))))){return null;}
        for($part=$candidate;strlen($part)>strlen($root);$part=dirname($part)){if(is_link($part))return null;}
        return $real;
    }
    private static function url(string $base,string $path): string {
        return $base.implode('/',array_map('rawurlencode',explode('/',$path)));
    }
    private static function public_path(string $path): string { return str_ends_with($path,'index.html')?substr($path,0,-10):$path; }
    private function request(string $url,string $base): array {
        // No cookies, Authorization, arbitrary input, redirect following or private network expansion.
        if(!str_starts_with($url,$base)){return array('verified'=>false,'reason'=>'URL fora do destino salvo.');}
        try{$r=($this->http)($url,array('timeout'=>6,'redirection'=>0,'limit_response_size'=>1024,'sslverify'=>true,'cookies'=>array(),'headers'=>array('Accept'=>'*/*'),'user-agent'=>'Gamb-Defensive-Audit/1.0'));}
        catch(\Throwable $error){return array('verified'=>false,'reason'=>'Destino inacessível.');}
        if(is_wp_error($r)){return array('verified'=>false,'reason'=>'Destino inacessível: falha de conexão, DNS, TLS ou timeout.');}
        $status=(int)wp_remote_retrieve_response_code($r);$headers=array();
        foreach(array('content-type','x-content-type-options','x-robots-tag','strict-transport-security','content-security-policy','referrer-policy','x-frame-options','cross-origin-opener-policy','server','x-powered-by','via','cf-ray','x-sucuri-id','x-cdn','x-cache') as $name){
            $v=wp_remote_retrieve_header($r,$name);if(is_array($v))$v=implode(', ',$v);
            if($v!==''){$headers[$name]=substr((string)preg_replace('/[\x00-\x1f]/',' ',(string)$v),0,600);}
        }
        return array('verified'=>true,'status'=>$status,'headers'=>$headers);
    }
    public function inspect(array $context): array {
        $this->checks=array();$this->responses=array();$base=self::base((string)($context['public_url']??''));$env=$context['environment']??'production';
        $root=Publication_Destination::validate($context['directory']??'',true);
        $paths=array_values(array_filter(array_column($context['files']??array(),'path'),static fn($p)=>is_string($p)&&self::relative($p)));
        $manifest=array_fill_keys($paths,true);$pages=array();$docs=array();
        $valid=($context['method']??'')==='local'&&!is_wp_error($root)&&!empty($context['inventory_available'])&&$base!=='';
        $this->add('export','context',$valid?'approved':'unverified','Entrega local com pasta e manifesto válidos, URL pública e ambiente salvos.',$valid?'Manifesto da última entrega local disponível.':'Destino ou manifesto indisponível; o ZIP remoto não é inspecionado automaticamente.','Confira o destino e a última entrega local.');
        if($valid){
            if(isset($context['record_matches_destination'])&&!$context['record_matches_destination'])$this->add('publication','export-association','attention','Registro de entrega associado ao destino salvo.','O destino mudou ou o registro é anterior ao controle de associação. O manifesto identifica os arquivos efetivamente lidos.','Confira a entrega correspondente a esta pasta; datas da última execução podem pertencer a outro destino.');
            $pages=array_values(array_filter($paths,static fn($p)=>str_ends_with($p,'.html')&&!in_array($p,array('index.html','404.html',Admin_Insights::MANUAL_PATH.'index.html'),true)));
            sort($pages);$pages=array_merge(isset($manifest['index.html'])?array('index.html'):array(),array_slice($pages,0,1));
            if(!isset($manifest['index.html'])){$this->add('publication','home','failed','Home no manifesto.','index.html não registrado.','Confira a última exportação.');}
            if(count($pages)<2){$this->add('publication','internal','unverified','Uma página interna no manifesto.','Nenhuma página interna disponível.','Inclua uma página pública na próxima exportação.');}
            foreach($pages as $path){$disk=self::disk($root,$path);if(!$disk||filesize($disk)>5242880){$this->add('publication','html','unverified','HTML legível, até 5 MB.','Arquivo ausente, link ou acima do limite.','Confira a entrega.',$path);continue;}
                $html=(string)file_get_contents($disk);$doc=Gamb_DOM_Parser::parse($html);$docs[$path]=$doc;$canonical=array();$robots=array();
                foreach($doc->getElementsByTagName('link') as $el){if(in_array('canonical',preg_split('/\s+/',strtolower($el->getAttribute('rel'))),true))$canonical[]=$el->getAttribute('href');}
                foreach($doc->getElementsByTagName('meta') as $el){if(strtolower($el->getAttribute('name'))==='robots')$robots[]=$el->getAttribute('content');}
                $expected=$base.self::public_path($path);$ok=count($canonical)===1&&$canonical[0]===$expected;
                $this->add('publication','canonical',$ok?'approved':'failed','Uma canonical absoluta: '.$expected,'Quantidade: '.count($canonical).'; valores: '.implode(' | ',array_map(static fn($v)=>self::safe_reference($v),$canonical)),'Corrija host/caminho do destino e regenere após corrigir a origem.',$path);
                $tokens=self::tokens(implode(',',$robots));$preview=in_array($env,array('preview','homologation'),true);$ok=$preview?count($robots)===1&&self::preview_policy(implode(',',$robots)):!in_array('noindex',$tokens,true);
                $this->add('publication','robots-meta',$ok?'approved':'failed',$preview?'Uma meta robots com noindex, nofollow.':'Ausência de noindex inesperado em produção.','Quantidade: '.count($robots).'; diretivas: '.implode(', ',$tokens),'Confira ambiente e indexação da origem; regenere somente após a correção.',$path);
                $this->sri($doc,$path,$root,$base,$manifest);
            }
            $this->package($root,$base,$env);
            foreach(array('pdf','docx') as $ext){$found=null;foreach($paths as $p){if(preg_match('~^v/([A-Za-z0-9_-]+)\.'.$ext.'$~D',$p,$m)&&isset($manifest['v/'.$m[1]])){$found='v/'.$m[1];break;}}
                if($found){$this->responses[$found]=$this->request(self::url($base,$found),$base);$this->attachment($found,$ext,$this->responses[$found]);}
                else{$this->add('server','mime-'.$ext,'unverified','Um '.$ext.' já publicado por /v/<token>.','Nenhum exemplar identificado no manifesto; não foi criado arquivo de teste.','Publique um anexo desse tipo quando necessário.');}}
            foreach($pages as $path){$url=$base.self::public_path($path);$r=$this->request($url,$base);$this->responses[$path]=$r;$this->server($path,$r,$base,$env);}
        }else{
            foreach(array('canonical','robots-meta','sri','package') as $id)$this->add($id==='package'?'export':'publication',$id,'unverified','Exportação local legível.','Sem evidência local suficiente.','Confira pasta, manifesto e URL pública.');
            $this->add('server','http','unverified','Amostra HTTP do destino salvo.','Não consultada: contexto inválido.','Confira o destino salvo.');
        }
        $summary=array_fill_keys(array('approved','attention','failed','unverified'),0);foreach($this->checks as $check)$summary[$check['state']]++;
        return array('checked_at'=>gmdate('c'),'version'=>GAMB_STATIC_WP_VERSION,'destination'=>array('url'=>$base,'environment'=>$env,'directory'=>$context['directory']??''),'export'=>array('published_at'=>$context['published_at']??'','processed_units'=>$context['processed_units']??0,'manifest_files'=>count($paths),'manifest_sha256'=>$valid?hash_file('sha256',$root.'/.gamb-static-files.json'):null),'summary'=>$summary,'checks'=>$this->checks,'http'=>$this->responses,'reference'=>'OWASP ASVS 5.0.0, cobertura parcial defensiva; canonical e robots são critérios de publicação, não requisitos ASVS.','limitations'=>array('Somente leitura; nenhuma reexportação, correção automática ou varredura externa iniciada.','No máximo quatro URLs da publicação, sem redirecionamentos, cookies ou autenticação; timeout de 6 segundos por URL.','SRI: CSS local referenciado nas duas páginas amostradas; leitura de textos limitada a 5 MB por arquivo e 32 MB no pacote.','Arquivo suspeito é classificado pelo nome e caminho relativo; backups, logs e configurações não são abertos.','Cabeçalhos não comprovam WAF. Ausência de achados não equivale a certificação ASVS.'));
    }
    public static function tokens(string $value): array { return array_values(array_filter(preg_split('/[\s,]+/',strtolower($value))?:array())); }
    private static function preview_policy(string $value): bool { $tokens=self::tokens($value);return !str_contains($value,':')&&in_array('noindex',$tokens,true)&&in_array('nofollow',$tokens,true)&&!in_array('index',$tokens,true)&&!in_array('follow',$tokens,true); }
    private static function safe_reference(string $value): string { return substr(Installation_Checks::public_reference($value),0,400)?:'(URL relativa ou inválida; valor omitido)'; }
    private function sri(object $doc,string $page,string $root,string $base,array $manifest): void {
        $opts=Options::instance();$disabled=(bool)$opts->get('disable_local_integrity',false);$refresh=(bool)$opts->get('refresh_local_integrity',true);$count=0;
        foreach($doc->getElementsByTagName('link') as $el){if(!in_array('stylesheet',self::tokens($el->getAttribute('rel')),true))continue;if($count>=50){$this->add('export','sri-limit','unverified','Até 50 declarações locais por página.','Limite atingido.','Confira as declarações restantes manualmente.',$page);break;}$href=$el->getAttribute('href');$p=wp_parse_url($href);$rel='';
            if(isset($p['host'])){if(!str_starts_with($href,$base))continue;$rel=substr($href,strlen($base));}
            elseif(str_starts_with($href,'//'))continue;
            elseif(str_starts_with($href,'/')){$prefix=(string)wp_parse_url($base,PHP_URL_PATH);if(!str_starts_with($href,$prefix))continue;$rel=substr($href,strlen($prefix));}
            else{$rel=($page==='index.html'?'':dirname($page).'/').$href;$segments=array();$escaped=false;foreach(explode('/',$rel)as$part){if($part==='..'){if(!$segments)$escaped=true;array_pop($segments);}elseif($part!=='.')$segments[]=$part;}if($escaped){$count++;$this->add('export','sri','failed','CSS dentro da raiz exportada.','Referência relativa sai da publicação; não foi lida.','Corrija o caminho do stylesheet.',$page);continue;}$rel=implode('/',$segments);}
            $rel=(string)wp_parse_url($rel,PHP_URL_PATH);if(!self::relative($rel))continue;$count++;$integrity=trim($el->getAttribute('integrity'));
            if($disabled){$this->add('export','sri',$integrity===''?'attention':'failed','SRI local explicitamente desativado neste destino.',$integrity===''?'Sem integrity, conforme configuração.':'Ainda há integrity declarado.','Documente a transformação por CDN/proxy; prefira verificação ativa.',$rel,'ASVS 5.0.0 V3.6.1 (aplicado por analogia ao recurso local)');continue;}
            $disk=isset($manifest[$rel])?self::disk($root,$rel):null;
            if(!$disk||filesize($disk)>10485760){$this->add('export','sri','unverified','CSS local no manifesto, legível, até 10 MB.','Arquivo ausente ou acima do limite.','Confira os arquivos entregues.',$rel);continue;}
            if($integrity===''){$this->add('export','sri',$refresh?'failed':'attention',$refresh?'SHA-384 válido sobre os bytes finais.':'Recálculo desligado: hash incompatível pode ser removido.','Sem integrity declarado.','Ative o recálculo para verificar os bytes finais.',$rel);continue;}
            $hashes=preg_split('/\s+/',$integrity)?:array();$best=0;$valid=false;$supported=false;
            foreach($hashes as $h){if(!preg_match('/^(sha256|sha384|sha512)-([A-Za-z0-9+\/=]+)(?:\?.*)?$/D',$h,$m))continue;$rank=(int)substr($m[1],3);if($rank>$best){$best=$rank;$valid=false;}$supported=true;if($rank===$best&&hash_equals(base64_encode(hash_file($m[1],$disk,true)),$m[2]))$valid=true;}
            $ok=$supported&&$valid&&(!$refresh||in_array('sha384-'.base64_encode(hash_file('sha384',$disk,true)),$hashes,true));
            $this->add('export','sri',$ok?'approved':'failed',$refresh?'SHA-384 válido sobre os bytes finais.':'Hashes declarados devem corresponder aos bytes finais.',$ok?'Hash confere com o arquivo exportado.':'Hash inválido, incompatível ou algoritmo esperado ausente.','Recalcule a integridade na próxima exportação após corrigir os assets.',$rel,'ASVS 5.0.0 V3.6.1 (analogia local)');
        }
        if(!$count)$this->add('export','sri','unverified','CSS local nas páginas amostradas.','Nenhuma declaração local identificada.','Confira os estilos no navegador.',$page);
    }
    private function package(string $root,string $base,string $env): void {
        $seen=0;$bytes=0;$findings=0;$critical=0;$limited=false;
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $file){if(++$seen>self::LIMIT){$limited=true;break;}$relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));$type='';
            if($file->isLink()){$type='link simbólico';}
            elseif(preg_match('~(?:^|/)(?:\.git|\.svn|\.hg)(?:/|$)|(?:^|/)(?:\.env(?:\..*)?|\.htpasswd|id_rsa|wp-config\.php|composer\.(?:json|lock)|package(?:-lock)?\.json|.*\.(?:ini|conf|config|ya?ml|pem|key|p12|pfx))$~i',$relative)){$type='configuração/metadados';}
            elseif(preg_match('~(?:backup|gamb-backups|\.sql(?:\.|$)|\.(?:bak|zip|tar|gz|7z)$)~i',$relative)){$type='possível backup/arquivo compactado';}
            elseif(preg_match('~(?:^|/)(?:logs?|debug\.log)(?:/|$)|\.log$~i',$relative)){$type='log';}
            elseif(preg_match('~(?:^|/)(?:wp-content|wp-includes|wp-json|wp-admin)(?:/|$)~i',$relative)){$type='caminho interno WordPress';}
            if($type!==''){$findings++;$critical++;$this->add('export','package-exposure','failed','Pacote sem arquivos internos ou administrativos.','Tipo: '.$type.'; conteúdo não aberto.','Remova o arquivo da publicação após revisão.',$relative,'ASVS 5.0.0 V13.4.1, V13.4.7');continue;}
            if(!$file->isFile()||!in_array(strtolower($file->getExtension()),array('html','css','js'),true))continue;
            if($file->getSize()>5242880||$bytes+$file->getSize()>33554432){$limited=true;continue;}$disk=self::disk($root,$relative);if(!$disk)continue;$bytes+=$file->getSize();$text=(string)file_get_contents($disk);
            foreach(array('referência localhost'=>'~(?:localhost|127\.0\.0\.1|\[::1\])~i','referência interna WordPress'=>'~/(?:wp-content|wp-includes|wp-json|wp-admin)(?:/|[?"\x27\s<])~i')as$kind=>$pattern){if(preg_match($pattern,$text)){$findings++;$intentional=$kind==='referência localhost'&&in_array(wp_parse_url($base,PHP_URL_HOST),array('localhost','127.0.0.1','[::1]'),true)&&$env!=='production';if(!$intentional)$critical++;$this->add('export','package-reference',$intentional?'attention':'failed','Referências compatíveis com o ambiente e destino.','Tipo: '.$kind.($intentional?' (destino local configurado)':'').'; trecho omitido.',$intentional?'Uso local identificado. Configure o domínio real e reexporte ao publicar em produção.':'Revise a origem e a reescrita antes de publicar novamente.',$relative,'ASVS 5.0.0 V13.4.7');}}
        }
        $this->add('export','package-summary',$limited?'unverified':($critical?'failed':($findings?'attention':'approved')),'Nomes de arquivos e textos públicos dentro dos limites.',$seen.' entradas examinadas; '.$findings.' achados; '.($limited?'limite atingido, cobertura incompleta.':'inspeção concluída.'),'Revise os achados e respeite a cobertura declarada.');
    }
    private function attachment(string $path,string $ext,array $r): void {
        $expected=$ext==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.wordprocessingml.document';$h=$r['headers']??array();$ok=($r['status']??0)===200&&strtolower(trim(explode(';',$h['content-type']??'')[0]))===$expected&&strtolower($h['x-content-type-options']??'')==='nosniff';
        $this->add('server','mime-'.$ext,empty($r['verified'])?'unverified':($ok?'approved':'failed'),'HTTP 200; '.$expected.'; X-Content-Type-Options: nosniff.',empty($r['verified'])?$r['reason']:'HTTP '.$r['status'].'; Content-Type: '.($h['content-type']??'ausente').'; nosniff: '.($h['x-content-type-options']??'ausente'),'Confira o mapa MIME e as regras do servidor de publicação.',$path,'ASVS 5.0.0 V3.4.4');
    }
    private function server(string $path,array $r,string $base,string $env): void {
        if(empty($r['verified'])){foreach(array('http','robots-header','headers','version','waf') as $id)$this->add($id==='robots-header'?'publication':'server',$id,'unverified','Resposta HTTP do destino salvo.',$r['reason'],'Confira DNS, TLS, conectividade e configuração do host.',$path);return;}
        $h=$r['headers'];$ok=$r['status']===200;
        $this->add('server','http',$ok?'approved':'failed','HTTP 200; redirecionamentos não seguidos.','HTTP '.$r['status'],'Confira o host e o caminho público do destino.',$path);
        $tokens=self::tokens($h['x-robots-tag']??'');$preview=in_array($env,array('preview','homologation'),true);$robots=$preview?self::preview_policy($h['x-robots-tag']??''):!in_array('noindex',$tokens,true);
        $this->add('publication','robots-header',!$ok?'unverified':($robots?'approved':'failed'),$preview?'X-Robots-Tag: noindex, nofollow.':'Ausência de noindex inesperado.','HTTP '.$r['status'].'; diretivas: '.implode(', ',$tokens),'Confira ambiente e regras de headers aceitas pelo host.',$path);
        foreach(array('x-content-type-options'=>'nosniff','content-security-policy'=>'object-src/base-uri e política de recursos confiáveis','referrer-policy'=>'política explícita contra vazamento','strict-transport-security'=>'max-age de pelo menos 31536000 em HTTPS','cross-origin-opener-policy'=>'same-origin ou same-origin-allow-popups')as$name=>$expected){$v=$h[$name]??'';$valid=$v!=='';
            if($name==='x-content-type-options')$valid=strtolower($v)==='nosniff';
            if($name==='content-security-policy'){$resource=preg_match('~(?:^|;)\s*(?:script-src|default-src)\s+([^;]+)~i',$v,$policy);$trusted=$resource&&!preg_match('~\*|(?:^|\s)(?:https?:|data:)(?:\s|$)|\x27unsafe-eval\x27~i',$policy[1]);if($trusted&&str_contains($policy[1],"'unsafe-inline'")&&!preg_match('~\x27(?:nonce-|sha(?:256|384|512)-)~i',$policy[1]))$trusted=false;$valid=$trusted&&preg_match("/(?:^|;)\s*object-src\s+'none'\s*(?:;|$)/i",$v)&&preg_match("/(?:^|;)\s*base-uri\s+'none'\s*(?:;|$)/i",$v);}
            if($name==='referrer-policy'){$policies=explode(',',strtolower($v));$valid=in_array(trim((string)end($policies)),array('no-referrer','same-origin','strict-origin','strict-origin-when-cross-origin','origin','origin-when-cross-origin'),true);}
            if($name==='strict-transport-security')$valid=str_starts_with($base,'https://')&&preg_match('/max-age\s*=\s*(\d+)/i',$v,$m)&&(int)$m[1]>=31536000;
            if($name==='cross-origin-opener-policy')$valid=in_array(strtolower($v),array('same-origin','same-origin-allow-popups'),true);
            $ref=array('x-content-type-options'=>'V3.4.4','content-security-policy'=>'V3.4.3','referrer-policy'=>'V3.4.5','strict-transport-security'=>'V3.4.1','cross-origin-opener-policy'=>'V3.4.8')[$name];
            $this->add('server',$name,!$ok?'unverified':($valid?'approved':'attention'),$name.': '.$expected,$v?:'Ausente.','Revise a política do servidor; presença isolada não certifica o requisito completo.',$path,'ASVS 5.0.0 '.$ref);
        }
        $frame=preg_match('~(?:^|;)\s*frame-ancestors\s+~i',$h['content-security-policy']??'');$this->add('server','framing',!$ok?'unverified':($frame?'approved':'attention'),'CSP com frame-ancestors.',$frame?'Diretiva observada.':'Diretiva ausente; X-Frame-Options: '.($h['x-frame-options']??'ausente'),'Defina os ancestrais permitidos conforme o site.',$path,'ASVS 5.0.0 V3.4.6');
        $version=implode('; ',array_filter(array($h['server']??'',$h['x-powered-by']??'')));$exposed=preg_match('~(?:/|\bversion\s*)\d|\b(?:PHP|ASP\.NET)\b~i',$version);
        $this->add('server','version',!$ok?'unverified':($exposed?'attention':'approved'),'Sem divulgação detalhada de versões.',$version?:'Nenhum header de versão observado.','Ajuste a identificação no servidor/proxy; não há alteração automática.',$path,'ASVS 5.0.0 V13.4.6');
        $signals=array_intersect_key($h,array_flip(array('via','cf-ray','x-sucuri-id','x-cdn','x-cache')));if(preg_match('/cloudflare|akamai|sucuri|cloudfront/i',$h['server']??''))$signals['server']=$h['server'];
        $this->add('server','waf','unverified','Confirmação administrativa do provedor.',$signals?'Indício observado: '.implode(', ',array_keys($signals)).'. WAF não confirmado.':'WAF não confirmado; nenhum indício conclusivo.','Confirme a proteção na conta do provedor; headers não comprovam WAF.',$path);
    }
}
