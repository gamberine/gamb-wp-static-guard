<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

final class Gamb_Static_Auditor {
    public const REPORT_OPTION = 'gamb_wp_static_guard_audit_report';
    public const PENDING_OPTION = 'gamb_wp_static_guard_audit_pending';
    public const PUBLICATION_OPTION = 'gamb_wp_static_guard_publication_audit';
    public const ORIGIN_OPTION = 'gamb_wp_static_guard_origin_audit';
    private static array $findings = array();
    private static int $documents = 0;
    private static array $records = array();
    private static array $controls = array();
    private static string $scope = 'processing';
    private static int $expected = 0;
    private static string $context = 'export_stage';

    public static function begin(string $scope = 'processing', int $expected = 0): void { self::$findings = array(); self::$documents = 0; self::$records=array(); self::$controls=array(); self::$scope=$scope; self::$expected=$expected; self::$context=$scope==='origin'?'origin':'export_stage'; }
    public static function catalog(): array {
        return array(
            'runtime_error'=>array('Erros de execução','Busca mensagens de erro e rastros de execução no HTML.','Corrija o erro na origem e execute novamente.'),
            'system_path'=>array('Caminhos do servidor','Detecta caminhos internos expostos no documento.','Remova saídas de depuração e mensagens com caminhos do servidor.'),
            'unprocessed_code'=>array('Código não processado','Verifica fragmentos de código e blocos não renderizados.','Revise o template e a renderização antes de exportar.'),
            'sensitive_identifier'=>array('Identificadores sensíveis','Verifica referências administrativas e identificadores de configuração.','Revise os recursos da origem; não publique credenciais ou nonces.'),
            'development_fallback'=>array('Conteúdo de desenvolvimento','Identifica marcadores e saídas de depuração.','Substitua marcadores de desenvolvimento pelo conteúdo definitivo.'),
            'unresolved_shortcode'=>array('Shortcodes pendentes','Confere os shortcodes configurados que não foram renderizados.','Ative o recurso necessário ou corrija o conteúdo na origem.'),
            'unresolved_cms_reference'=>array('Rotas internas residuais','Procura referências internas que deveriam ter sido tratadas.','Revise recursos não tokenizados e dependências do tema.'),
            'accidental_home_404'=>array('Contexto da Home','Verifica se a Home foi capturada como erro 404.','Confira a URL da origem e o template da Home.'),
            'home_document_structure'=>array('Estrutura da Home','Confere cabeçalho e título do documento.','Corrija a estrutura HTML do template.'),
            'native_class'=>array('Classes nativas','Confere classes que a sanitização deveria remover.','Revise a sanitização e valide a dependência visual do tema.'),
            'speculation_rules'=>array('Regras especulativas','Confere a remoção de regras de navegação especulativa.','Revalide a origem e os scripts inseridos por extensões.'),
            'native_style'=>array('Estilos nativos','Confere estilos nativos fora da lista de preservação.','Revise a lista de estilos preservados na configuração da auditoria.'),
            'native_css_variables'=>array('Variáveis nativas de CSS','Confere variáveis de identidade removidas pelo tratamento.','Revise os estilos incorporados pelo tema e extensões.'),
            'source_annotation'=>array('Anotações de origem','Confere comentários e referências de mapas no HTML final.','Remova anotações de desenvolvimento e reexporte.'),
            'capture_failed'=>array('Captura HTTP','Uma página não pôde ser obtida com o contexto esperado.','Verifique a origem, os permalinks e o acesso HTTP local.'),
            'revalidation_failed'=>array('Revalidação da origem','A consulta ou o processamento da origem não terminou.','Execute o diagnóstico e consulte o registro de atividades.'),
            'export_processing_failed'=>array('Processamento da exportação','O processamento foi interrompido antes de concluir os controles.','Consulte os registros, corrija o impedimento e tente novamente.'),
        );
    }
    public static function inspect(string $html, bool $home = false, bool $final = false): array {
        $normalized = str_replace('\\/', '/', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $rules = array(
            'runtime_error' => '~\b(?:Fatal error|Uncaught (?:Error|Exception|TypeError)|Parse error|Warning:|Notice:|Deprecated:|WordPress database error|Stack trace:)~i',
            'system_path' => '~(?:/var/www/|[A-Z]:\\\\|\.php\s+on\s+line\b)~i',
            'unprocessed_code' => '~<\?(?:php\b|=)|<!--\s*wp:~i',
            'sensitive_identifier' => '~\b(?:DB_PASSWORD|DB_USER|DB_HOST|DB_NAME|AUTH_KEY)\b|_wpnonce|admin-ajax\.php~i',
            'development_fallback' => '~realiza(?:ç|c)[aã]o\s+n[aã]o\s+inferida|Documentos\s+n[aã]o\s+fornecidos|\bTODO:|\bFIXME:|\b(?:var_dump|print_r)\s*\(~iu',
        );
        if ($final) { $rules['unresolved_cms_reference'] = '~/(?:wp-content|wp-includes|wp-admin|wp-json)(?:/|[?"\x27\s<]|$)|simply-static~i'; }
        if ($home) {
            $rules['accidental_home_404'] = '~is_404\s*\(|<body\b[^>]*\bclass\s*=\s*["\x27][^"\x27]*\berror404\b|<title\b[^>]*>[^<]*(?:P[aá]gina\s+n[aã]o\s+encontrada|Page\s+not\s+found)~iu';
        }
        $codes = array();
        foreach ($rules as $code => $pattern) { if (preg_match($pattern, $normalized)) { $codes[] = $code; } }
        foreach (self::shortcodes() as $shortcode) {
            if (preg_match('~\[/?' . preg_quote($shortcode, '~') . '(?:\s|/?\])~i', $normalized)) { $codes[] = 'unresolved_shortcode'; break; }
        }
        if ($final) {
            $document = Gamb_DOM_Parser::parse($html);
            if ($home) {
                $head = $document->getElementsByTagName('head')->item(0);
                if (!$head || !$head->getElementsByTagName('title')->length) { $codes[] = 'home_document_structure'; }
            }
            $allowed = Gamb_Static_Sanitizer::style_whitelist();
            foreach (Gamb_DOM_Parser::elements($document) as $element) {
                foreach (preg_split('/\s+/', trim(Gamb_DOM_Parser::attribute($element, 'class'))) ?: array() as $class) {
                    if (Gamb_Static_Sanitizer::native_class($class)) { $codes[] = 'native_class'; break; }
                }
                $tag = strtolower($element->tagName);
                if ('script' === $tag && 'speculationrules' === strtolower(Gamb_DOM_Parser::attribute($element, 'type'))) { $codes[] = 'speculation_rules'; }
                if ('style' === $tag && preg_match('/^(wp-|global-styles|classic-theme|core-block)/i', Gamb_DOM_Parser::attribute($element, 'id')) && !in_array(Gamb_DOM_Parser::attribute($element, 'id'), $allowed, true)) { $codes[] = 'native_style'; }
                if ('style' === $tag && preg_match('~:root\b[^{}]*\{[^{}]*--(?:wp--preset--|wp-admin-|wp-block-)~i', $element->textContent)) { $codes[] = 'native_css_variables'; }
            }
            if (preg_match('~<!--|/\*[#@]\s*source(?:Mapping)?URL=~i', $html)) { $codes[] = 'source_annotation'; }
        }
        return array_values(array_unique($codes));
    }

    private static function shortcodes(): array {
        $value = Options::instance()->get('audit_unresolved_shortcodes', '');
        return array_values(array_filter(preg_split('/[\s,]+/', is_string($value) ? $value : '') ?: array(), static fn(string $name): bool => (bool)preg_match('/^[a-zA-Z0-9_-]+$/D', $name)));
    }

    public static function assert_safe(string $html, string $source = '', bool $home = false, bool $final = false): void {
        $codes = self::inspect($html, $home, $final);
        $applied=array('runtime_error','system_path','unprocessed_code','sensitive_identifier','development_fallback');
        if (self::shortcodes()) { $applied[]='unresolved_shortcode'; }
        if ($home) { $applied[]='accidental_home_404'; }
        if ($final) { $applied=array_merge($applied,array('unresolved_cms_reference','native_class','speculation_rules','native_style','native_css_variables','source_annotation')); if ($home) { $applied[]='home_document_structure'; } }
        foreach ($applied as $code) { self::$controls[$code]['checked']=(self::$controls[$code]['checked']??0)+1; self::$controls[$code]['failed']=(self::$controls[$code]['failed']??0)+(in_array($code,$codes,true)?1:0); }
        if (count(self::$records)<5000) { self::$records[]=array('source'=>self::safe_source($source),'stage'=>$final?'final':'origin','success'=>!$codes,'checks'=>count($applied),'failures'=>$codes); }
        if (!$codes) { if ($final) { self::$documents++; } return; }
        foreach ($codes as $code) {
            if (count(self::$findings) >= 100) { break; }
            self::$findings[] = array('severity' => 'CRITICAL', 'code' => $code, 'source' => self::safe_source($source));
        }
        self::save(false);
        throw new \RuntimeException('CRITICAL: publicação bloqueada pelo auditor (' . implode(', ', $codes) . '). O destino foi preservado.');
    }

    private static function safe_source(string $source): string {
        if (preg_match('~^https?://~i', $source)) { return (string)(parse_url($source, PHP_URL_PATH) ?: '/'); }
        $source=str_replace('\\','/',$source);
        if (preg_match('~^(?:[A-Za-z]:/|/)~',$source) || str_contains($source,'..')) { return basename($source); }
        return substr((string)preg_replace('/[\x00-\x1f]/','',$source),0,1024);
    }
    public static function save(bool $success): array {
        $catalog=self::catalog(); $checks=array();
        foreach (self::$controls as $code=>$counts) { $info=$catalog[$code]??array($code,'Controle registrado.','Revise a origem.'); $checks[]=array('code'=>$code,'label'=>$info[0],'description'=>$info[1],'how_to_fix'=>$info[2],'checked'=>$counts['checked'],'failed'=>$counts['failed'],'status'=>$counts['failed']?'error':'ok'); }
        $findings=array_map(static function($finding)use($catalog){$info=$catalog[$finding['code']]??array($finding['code'],'Impedimento no processamento.','Consulte o registro.');return array_merge($finding,array('label'=>$info[0],'description'=>$info[1],'how_to_fix'=>$info[2]));},self::$findings);
        $report = array('version' => GAMB_STATIC_WP_VERSION, 'checked_at' => gmdate('c'), 'scope'=>self::$scope,'context'=>self::$context,'success' => $success, 'documents' => self::$documents,'expected_documents'=>self::$expected,'checks'=>$checks,'records'=>self::$records,'findings' => $findings,'limitations'=>array('Inspeção do HTML original e/ou final conforme o escopo indicado.','A inspeção interrompe no primeiro impedimento crítico; os demais documentos não são certificados.','O relatório não executa JavaScript, não é um teste de invasão e não certifica a configuração do host.','Carregamento no navegador, headers HTTP, links de terceiros e alterações feitas por CDN/proxy exigem conferência no destino.'));
        update_option(self::REPORT_OPTION, $report, false);
        update_option(self::$scope==='origin'?self::ORIGIN_OPTION:self::PUBLICATION_OPTION,$report,false);
        return $report;
    }
    public static function processing_failure(): void {
        if (!self::$findings) { self::$findings[] = array('severity' => 'CRITICAL', 'code' => 'export_processing_failed', 'source' => 'export'); }
        self::save(false);
    }
    public static function capture_failure(string $source): void {
        if (count(self::$findings) < 100) { self::$findings[] = array('severity' => 'CRITICAL', 'code' => 'capture_failed', 'source' => self::safe_source($source)); }
        self::save(false);
    }
    public static function audit_directory(string $directory, string $context = 'export_stage'): array {
        self::begin('publication'); self::$context=$context==='delivered'?'delivered':'export_stage';
        $root = realpath($directory);
        if (!$root || !is_dir($root)) { throw new \RuntimeException('Diretório estático inválido.'); }
        $files=array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'html') { continue; }
            $files[]=$file->getPathname();
        }
        self::begin('publication',count($files)); self::$context=$context==='delivered'?'delivered':'export_stage';
        if (!$files) { self::processing_failure(); throw new \RuntimeException('Nenhum documento HTML encontrado para auditoria.'); }
        foreach ($files as $path) {
            if (class_exists(Operation_Progress::class)) { Operation_Progress::phase('audit',1,1,self::$documents,count($files),'Auditando os documentos da publicação.'); }
            $file=new \SplFileInfo($path);
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            self::assert_safe((string)file_get_contents($file->getPathname()), $relative, 'index.html' === $relative, true);
        }
        return self::save(true);
    }

    public static function mark_pending(): void {
        update_option(self::PENDING_OPTION, gmdate('c'), false);
        if (!wp_next_scheduled('gamb_static_guard_post_update_audit')) { wp_schedule_single_event(time() + 10, 'gamb_static_guard_post_update_audit'); }
    }
    public static function revalidate(): void {
        self::begin('origin',2);
        try {
            foreach (array('home' => home_url('/'), 'error' => home_url('/gamb-static-guard-missing-page-404/')) as $kind => $url) {
                Operation_Progress::phase('audit',$kind==='home' ? 1 : 2,3,0,1,'Auditando a origem: '.$kind.'.');
                $response = wp_remote_get($url, array('timeout' => 25, 'limit_response_size' => 5242880, 'headers' => array('X-Gamb-Static-Export' => Frontend_Policy::export_context_token())));
                if (is_wp_error($response) || !Static_Engine::get_instance()->capture_response_is_valid($url, wp_remote_retrieve_response_code($response), wp_remote_retrieve_header($response, 'x-gamb-static-context'), 'error' === $kind)) { throw new \RuntimeException('Contexto da origem incompatível com a revalidação.'); }
                $html = (string)wp_remote_retrieve_body($response);
                self::assert_safe($html, $url, 'home' === $kind);
                $html = Frontend_Policy::postprocess_html(Gamb_Security_Suite::get_instance()->cloak_html_content($html));
                self::assert_safe($html, $url, 'home' === $kind, true);
            }
            Operation_Progress::phase('audit',3,3,0,1,'Consolidando os resultados da auditoria.');
            self::save(true);
        } catch (\Throwable $error) {
            if (!self::$findings) { self::$findings[] = array('severity' => 'CRITICAL', 'code' => 'revalidation_failed', 'source' => 'origin'); }
            self::save(false);
        } finally { delete_option(self::PENDING_OPTION); }
    }
}
