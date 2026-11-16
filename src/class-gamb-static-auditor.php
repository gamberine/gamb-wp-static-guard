<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

final class Gamb_Static_Auditor {
    public const REPORT_OPTION = 'gamb_wp_static_guard_audit_report';
    public const PENDING_OPTION = 'gamb_wp_static_guard_audit_pending';
    private static array $findings = array();
    private static int $documents = 0;

    public static function begin(): void { self::$findings = array(); self::$documents = 0; }
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
        return basename(str_replace('\\', '/', $source));
    }
    public static function save(bool $success): array {
        $report = array('version' => GAMB_STATIC_WP_VERSION, 'checked_at' => gmdate('c'), 'success' => $success, 'documents' => self::$documents, 'findings' => self::$findings);
        update_option(self::REPORT_OPTION, $report, false);
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
    public static function audit_directory(string $directory): array {
        $root = realpath($directory);
        if (!$root || !is_dir($root)) { throw new \RuntimeException('Diretório estático inválido.'); }
        self::begin();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'html') { continue; }
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
        self::begin();
        try {
            foreach (array('home' => home_url('/'), 'error' => home_url('/gamb-static-guard-missing-page-404/')) as $kind => $url) {
                $response = wp_remote_get($url, array('timeout' => 25, 'limit_response_size' => 5242880, 'headers' => array('X-Gamb-Static-Export' => Frontend_Policy::export_context_token())));
                if (is_wp_error($response) || !Static_Engine::get_instance()->capture_response_is_valid($url, wp_remote_retrieve_response_code($response), wp_remote_retrieve_header($response, 'x-gamb-static-context'), 'error' === $kind)) { throw new \RuntimeException('Contexto da origem incompatível com a revalidação.'); }
                $html = (string)wp_remote_retrieve_body($response);
                self::assert_safe($html, $url, 'home' === $kind);
                $html = Frontend_Policy::postprocess_html(Gamb_Security_Suite::get_instance()->cloak_html_content($html));
                self::assert_safe($html, $url, 'home' === $kind, true);
            }
            self::save(true);
        } catch (\Throwable $error) {
            if (!self::$findings) { self::$findings[] = array('severity' => 'CRITICAL', 'code' => 'revalidation_failed', 'source' => 'origin'); }
            self::save(false);
        } finally { delete_option(self::PENDING_OPTION); }
    }
}
