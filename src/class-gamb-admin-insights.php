<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Evidências administrativas; o manual público nunca recebe dados deste relatório. */
final class Admin_Insights {
    public const MANUAL_PATH = 'ajuda/publicacao-estatica/';
    public static function register(): void {
        add_action('admin_post_gamb_guard_report',array(__CLASS__,'download'));
        add_action('template_redirect',array(__CLASS__,'serve_manual'),-10000);
    }
    public static function manual_url(): string { return home_url('/'.self::MANUAL_PATH); }
    public static function manual_html(): string { return (string)file_get_contents(GAMB_STATIC_WP_PATH.'assets/docs/manual.html'); }
    public static function serve_manual(): void {
        if (is_admin() || !isset($_SERVER['REQUEST_URI'])) { return; }
        $wanted=(string)wp_parse_url(self::manual_url(),PHP_URL_PATH);
        if (rtrim((string)wp_parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/')!==rtrim($wanted,'/')) { return; }
        while(ob_get_level()>0){if(!@ob_end_clean())break;}
        $html=self::manual_html();preg_match_all('~<script\b[^>]*>(.*?)</script>~is',$html,$scripts);
        $hashes=array_map(static fn($s)=>"'sha256-".base64_encode(hash('sha256',$s,true))."'",$scripts[1]);
        status_header(200);header('Content-Type: text/html; charset=UTF-8');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        if (isset($_SERVER['HTTP_X_GAMB_STATIC_EXPORT']) && hash_equals(Frontend_Policy::export_context_token(),(string)$_SERVER['HTTP_X_GAMB_STATIC_EXPORT'])) { header('X-Gamb-Static-Context: front=0;error=0'); }
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src ".($hashes?implode(' ',$hashes):"'none'")."; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        echo $html;exit;
    }
    public static function help(): array {
        $rows=array(
            'public_url'=>array('Endereço completo da publicação, separado da pasta física. Não é alterado ao acessar o admin por um IP autorizado.','No Laragon: http://localhost/gaepe-minas/. Use INICIAR-COMPARTILHAMENTO para registrar o IP da rede sem trocar esse destino.'),
            'public_site_mode'=>array('Escolhe o que abre na URL principal do projeto. O modo estático lê a entrega local; admin, API e captura assinada continuam no WordPress. Não altera Apache, DNS ou outros projetos.','No Laragon, use o endereço local do projeto, publique o site completo e escolha Publicação estática na URL principal.'),
            'delivery_method'=>array('Escolhe como entregar o resultado. A pasta local recebe os arquivos; ZIP gera um pacote para transporte.','Use Diretório local no servidor que já hospeda o preview.'),
            'local_dir'=>array('Pasta física exclusiva no servidor. A verificação é somente leitura; Criar pasta exige ação explícita. Não define a URL pública.','Use a sugestão de pasta e clique em Verificar pasta antes de salvar.'),
            'destination_url_type'=>array('Relativo ao domínio usa caminhos públicos; Absoluto inclui protocolo e domínio; Relativo ao arquivo usa caminhos entre arquivos.','Para um site servido na raiz, use Relativo ao domínio e deixe o caminho público vazio.'),
            'relative_path'=>array('Subpasta na URL, nunca um caminho do disco. Evita que links apontem para o lugar errado.','Para um endereço terminado em /preview/, informe preview. Para a raiz do domínio, deixe vazio.'),
            'destination_host'=>array('Domínio público do site estático, sem protocolo. Também é utilizado na canonical. Pode incluir o caminho público quando necessário.','site.exemplo.org ou site.exemplo.org/portal'),
            'destination_scheme'=>array('Protocolo efetivamente disponível no endereço público. HTTPS exige certificado válido no host.','Use HTTP em um preview local sem TLS; HTTPS no domínio que já oferece certificado.'),
            'destination_environment'=>array('Preview e homologação aplicam noindex, nofollow ao HTML e às regras do servidor. Produção preserva a política da origem.','Selecione Preview para uma cópia de avaliação que não deve ser indexada.'),
            'post_types'=>array('Define os tipos públicos incluídos na conversão. Conteúdos privados, rascunhos e itens protegidos por senha continuam excluídos.','Marque Páginas e os tipos públicos utilizados pelo portal.'),
            'generate_type'=>array('Site Completo gera páginas e recursos. Atualizar site reconstrói a publicação. Somente tema não gera páginas HTML.','Use Site Completo na primeira publicação e para conferir o site inteiro.'),
            'use_search'=>array('Gera um índice de pesquisa que funciona no navegador. Exige JavaScript e um campo de pesquisa compatível no tema.','Ative quando o portal tiver pesquisa de notícias e páginas.'),
            'fallback_404'=>array('Fornece um modelo de erro de apoio em temas clássicos sem um modelo próprio compatível.','Ative se a página inexistente estiver exibindo a Home por engano.'),
            'ensure_home_canonical'=>array('Completa a canonical ausente na captura da Home. Na exportação, as canonicals são normalizadas para o destino público.','Confira a canonical final no relatório e no HTML entregue.'),
            'require_local_dependencies'=>array('Bloqueia a entrega se a inspeção encontrar dependências externas ou atingir seu limite. Links de navegação são classificados separadamente.','Ative quando a publicação precisar renderizar sem CSS, scripts ou fontes de terceiros.'),
            'clear_directory_before_export'=>array('Na entrega completa, remove somente arquivos obsoletos registrados no manifesto do plugin. Outros arquivos não são apagados por essa manutenção.','Ative para retirar páginas que deixaram de fazer parte da publicação.'),
            'single_enabled'=>array('Disponibiliza o comando de publicação no editor. A execução reconstrói o site para manter listagens e pesquisa consistentes.','Use após atualizar uma notícia publicada.'),
            'auto_export'=>array('Agenda uma publicação após alterações em conteúdo publicado. Depende do agendador do WordPress.','Ative para automatizar a atualização após salvar uma página pública.'),
            'auto_export_delay'=>array('Espera mínima, em segundos, antes da publicação agendada. Não é uma garantia de horário exato.','60 aguarda pelo menos um minuto; 0 permite agendar sem espera adicional.'),
            'publication_email'=>array('Destinatário da notificação de publicação. Deixe vazio para usar o e-mail administrativo do WordPress.','contato@exemplo.org'),
            'publication_email_enabled'=>array('Envia um e-mail depois de uma publicação concluída.','Marque para receber o aviso no endereço informado.'),
            'webhook_url'=>array('Endereço opcional que recebe notificação após a publicação. Evite credenciais na URL e use apenas serviços autorizados.','Deixe vazio quando não houver integração de notificação.'),
            'refresh_local_integrity'=>array('Recalcula SHA-384 sobre os bytes finais dos estilos e scripts locais. Sem recálculo, hashes antigos incompatíveis são descartados.','Mantenha ativo para conferir os arquivos exatamente como exportados.'),
            'disable_local_integrity'=>array('Desativa explicitamente SRI local neste destino. Recursos externos preservam sua integridade. Use somente quando o CDN/proxy alterar bytes.','Deixe desmarcado em um servidor que entrega os arquivos sem transformação.'),
            'use_minify'=>array('Aplica a minificação adicional configurada para a exportação. Confira o funcionamento dos scripts depois de ativar.','Deixe desativado se as bibliotecas do tema já forem minificadas.'),
            'guard_active_on_live_site'=>array('Aplica o tratamento também às páginas públicas da origem WordPress, além da exportação.','Valide a navegação e os formulários da origem após alterar.'),
            'guard_universal_token_cloaking'=>array('Converte recursos públicos permitidos para URLs opacas. Não substitui autenticação ou autorização.','Mantenha ativo para os recursos tratados pelo plugin.'),
            'guard_strict_static_html'=>array('Interrompe a entrega quando o HTML ainda possui referências internas não resolvidas.','Corrija os itens indicados na auditoria antes de tentar publicar novamente.'),
            'guard_clean_body_classes'=>array('Remove classes de identificação do corpo. Algumas classes podem ser necessárias ao CSS do tema.','Ative somente após comparar visualmente as páginas.'),
            'guard_remove_block_styles'=>array('Remove estilos nativos de blocos quando o tema não depende deles.','Preserve os estilos utilizados por colunas, botões e componentes do editor.'),
            'hide_comments'=>array('Remove comentários HTML do resultado público. Avisos legais das bibliotecas são preservados pelo tratamento de recursos.','Ative para reduzir anotações internas no documento final.'),
            'guard_security_headers'=>array('Aplica os headers suportados pelo plugin. O servidor de publicação precisa aceitar as regras geradas.','Confira os headers HTTP no endereço público após a publicação.'),
            'guard_restrict_rest_api'=>array('Restringe a consulta anônima a recursos administrativos da API. Integrações autorizadas devem ser testadas.','Confira serviços do portal que dependem de chamadas autenticadas.'),
            'version_monitor_enabled'=>array('Publica a consulta de versão somente conforme a lista de IPs autorizados.','Habilite apenas quando existir um serviço de monitoramento autorizado.'),
            'version_monitor_allowed_ips'=>array('Um endereço IPv4 ou IPv6 exato por linha. Lista vazia bloqueia o acesso ao monitor; não aceita redes ou curingas.','192.0.2.10 é um exemplo documental; substitua pelo IP realmente autorizado.'),
            'version_monitor_trusted_proxies'=>array('Proxies que podem informar a origem por cabeçalho encaminhado. A confiança só vale para os IPs explicitamente cadastrados.','Deixe vazio em conexões diretas, sem proxy reverso.'),
            'github_update_repository'=>array('Repositório consultado no GitHub Releases, no formato proprietário/repositório.','gamberine/gamb-wp-static-guard'),
            'github_update_token'=>array('Credencial opcional, armazenada criptografada. Campo vazio preserva o valor existente. Não compartilhe o token em relatórios.','Use um token dedicado de leitura quando houver limite anônimo ou repositório privado.'),
            'github_clear_token'=>array('Remove a credencial salva quando as configurações forem confirmadas. Uma constante de configuração tem precedência.','Marque somente quando desejar revogar o uso da credencial armazenada.'),
            'sanitizer_style_whitelist'=>array('IDs de estilos nativos que precisam permanecer para o tema funcionar. Use nomes separados por espaços.','Copie o ID do estilo necessário e valide a página após salvar.'),
            'audit_unresolved_shortcodes'=>array('Nomes de shortcodes que devem impedir a publicação caso apareçam sem renderização. Informe sem colchetes.','galeria formulario — são exemplos de nomes a adaptar ao projeto.'),
        );
        return array_map(static fn($row)=>array('description'=>$row[0],'example'=>$row[1]),$rows);
    }
    public static function recommendations(string $id): string {
        $tips=array('zip'=>'A exportação possui alternativa ZIP do WordPress. Para o backup completo, habilite a extensão ZIP no PHP do ambiente ou utilize o backup de banco.','html_parser'=>'Habilite DOM e mbstring no PHP e refaça o diagnóstico.','crypto'=>'Confira OpenSSL e suporte a HMAC SHA-256 antes de publicar.','canonical'=>'Confira o domínio, protocolo e caminho públicos e reexporte para normalizar a canonical.','error_template'=>'Teste uma rota inexistente. O modelo de apoio é usado em temas clássicos compatíveis sem modelo 404.','cron'=>'Configure o agendador do ambiente ou faça a publicação manual.','dependencies'=>'Abra Dependências externas; identifique o serviço, licença e necessidade antes de internalizar.','home'=>'Confira a URL da origem e os permalinks; a Home deve responder no contexto esperado.','error'=>'Teste a página inexistente e o template 404 da origem.');
        return $tips[$id]??'Revise a mensagem, corrija a origem quando necessário e execute novamente o diagnóstico.';
    }
    public static function publication(int $page = 1, bool $full = false, bool $audit_read_only = false): array {
        $opts=Options::instance();try{$public_url=Static_Engine::get_instance()->public_base_url();}catch(\Throwable $error){$public_url='';} if(class_exists(Origin_Context::class))$public_url=Origin_Context::map($public_url); $info=array('public_url'=>$public_url,'environment'=>$opts->get('destination_environment','production'),'method'=>$opts->get('delivery_method'),'directory'=>$opts->get('local_dir',''),'processed_units'=>(int)$opts->get('last_export_files_count',0),'published_at'=>$opts->get('last_export_time',''),'files'=>array(),'count'=>0,'page'=>max(1,$page),'per_page'=>100,'inventory_available'=>false,'message'=>'O inventário será disponibilizado após uma entrega local com manifesto.');
        if ($info['method']!=='local') { $info['message']='Entrega em ZIP: consulte o pacote gerado. A listagem local não certifica o conteúdo do pacote.'; return $info; }
        $root=Publication_Destination::validate($info['directory'],$audit_read_only); if (is_wp_error($root)) { $info['message']=$root->get_error_message(); return $info; }
        $manifest=trailingslashit($root).'.gamb-static-files.json';
        if (!is_file($manifest)||is_link($manifest)||filesize($manifest)>2097152) { return $info; }
        $paths=json_decode((string)file_get_contents($manifest),true);if (!is_array($paths)||count($paths)>20000) { return $info; }
        $info['inventory_available']=true; $info['message']='Inventário físico do manifesto da entrega local. As unidades processadas são contadas durante a execução e não equivalem ao número de arquivos físicos; tokens e cópias com extensão podem representar o mesmo recurso.';
        $paths=array_values(array_filter($paths,static fn($p)=>is_string($p)&&strlen($p)<1024&&!preg_match('~[\x00-\x1f\\\\]|^(?:/|[A-Za-z]:)|(?:^|/)\.{1,2}(?:/|$)~',$p)));sort($paths);
        $info['count']=count($paths);$selected=$full?$paths:array_slice($paths,($info['page']-1)*100,100);
        foreach ($selected as $relative) {
            $candidate=trailingslashit($root).$relative;$disk=realpath($candidate);
            $exists=$disk&&is_file($disk)&&!is_link($candidate)&&str_starts_with(strtolower(wp_normalize_path($disk)),strtolower(trailingslashit(wp_normalize_path($root))));
            $public=$exists&&(str_ends_with($relative,'.html')||Gamb_Security_Suite::is_public_asset($relative)||preg_match('~^v/[A-Za-z0-9_-]+$~D',$relative));
            $info['files'][]=array('path'=>$relative,'size'=>$exists?filesize($disk):null,'exists'=>(bool)$exists,'url'=>$public&&$public_url?$public_url.implode('/',array_map('rawurlencode',explode('/',$relative))):'');
        }
        return $info;
    }
    public static function snapshot(int $page = 1): array {
        $installation=Installation_Checks::latest();$resources=get_option(Installation_Checks::EXPORT_OPTION,array());
        $resources=$resources?:($installation['resources']??array());$alerts=array();
        foreach ($installation['checks']??array() as $check) { if (($check['status']??'')!=='ok') { $check['how_to_fix']=self::recommendations((string)($check['id']??''));$alerts[]=$check; } }
        if (empty($installation['checked_at'])) { $alerts[]=array('id'=>'missing_diagnostics','status'=>'warning','message'=>'O diagnóstico da instalação ainda não foi executado.','how_to_fix'=>'Execute Verificar instalação nesta área.'); }
        $publication=get_option(Gamb_Static_Auditor::PUBLICATION_OPTION,array());$origin=get_option(Gamb_Static_Auditor::ORIGIN_OPTION,array());$legacy=get_option(Gamb_Static_Auditor::REPORT_OPTION,array());
        return array('presentation'=>Publication_View::status(),'installation'=>$installation,'alerts'=>$alerts,'dependencies'=>$resources,'publication_audit'=>$publication,'origin_audit'=>$origin,'previous_audit'=>(!$publication&&!$origin)?$legacy:array(),'publication'=>self::publication($page),'downloads'=>array_map(static fn($type)=>html_entity_decode(wp_nonce_url(admin_url('admin-post.php?action=gamb_guard_report&type='.$type),'gamb_guard_report_'.$type),ENT_QUOTES,'UTF-8'),array_combine(array('summary','publication','origin','files','defensive'),array('summary','publication','origin','files','defensive'))));
    }
    public static function download(): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso não autorizado.','',array('response'=>403)); }
        $type=isset($_GET['type'])?sanitize_key(wp_unslash($_GET['type'])):'';
        if (!in_array($type,array('summary','publication','origin','files','defensive'),true)) { wp_die('Relatório inválido.','',array('response'=>400)); }
        check_admin_referer('gamb_guard_report_'.$type);
        if ($type==='files') { $data=self::publication(1,true); }
        elseif ($type==='summary') { $data=self::snapshot();unset($data['downloads']);$data['publication']=self::publication(1,true); }
        elseif ($type==='defensive') { $data=Destination_Audit::latest(); }
        else { $data=get_option($type==='origin'?Gamb_Static_Auditor::ORIGIN_OPTION:Gamb_Static_Auditor::PUBLICATION_OPTION,array()); }
        while(ob_get_level()>0){if(!@ob_end_clean())break;}
        nocache_headers();header('Cache-Control: private, no-store');header('Content-Type: application/json; charset=UTF-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="gamb-relatorio-'.$type.'.json"');
        echo wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
}
