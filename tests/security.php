<?php
declare(strict_types=1);
use GambWPStaticGuard\Gamb_Static_Auditor as Auditor;
use GambWPStaticGuard\Gamb_Static_Sanitizer as Sanitizer;
use GambWPStaticGuard\Gamb_GitHub_Updater as Updater;

defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
function remove_query_arg($key, $url) { $url = preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&#]*&?/', '$1', $url); return rtrim(str_replace('?&', '?', $url), '?&'); }
function get_transient($key) { return $GLOBALS['fixture_transients'][$key][0] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['fixture_transients'][$key] = array($value, $ttl); return true; }
function delete_transient($key) { unset($GLOBALS['fixture_transients'][$key]); return true; }
function delete_site_transient($key) { return delete_transient($key); }
function delete_option($key) { unset($GLOBALS['fixture_options'][$key]); return true; }
function wp_http_validate_url($url) { $host = parse_url($url, PHP_URL_HOST); return $host && !in_array($host, array('127.0.0.1', 'localhost', '169.254.169.254'), true) ? $url : false; }
function wp_safe_remote_get($url, $args = array()) { $GLOBALS['fixture_http_calls'][] = array($url, $args); return ($GLOBALS['fixture_http_handler'])($url, $args); }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_retrieve_header($response, $key) { return $response['headers'][strtolower($key)] ?? ''; }
function wp_tempnam($name) { return tempnam(sys_get_temp_dir(), 'gamb-'); }
function wp_next_scheduled($hook) { return $GLOBALS['fixture_scheduled'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['fixture_scheduled'][$hook] = $time; return true; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wpautop($text) { return '<p>' . $text . '</p>'; }
function wp_allowed_protocols() { return array('http', 'https', 'mailto'); }
if (!function_exists('wp_kses_post')) { function wp_kses_post($text) { return strip_tags($text, '<p>'); } }
function fixture_response($body, $status = 200, $headers = array()) { return array('body' => $body, 'response' => array('code' => $status), 'headers' => $headers); }

$cases = array('Fatal error: example', 'Uncaught Error: example', 'Parse error: example', 'Warning: example', 'Notice: example', 'Deprecated: example', 'WordPress database error', 'Stack trace:', '/var/www/example', 'C:\\private', 'file.php on line 2', '<?php echo 1;', '<?= 1 ?>', '<!-- wp:paragraph -->', 'DB_PASSWORD', 'DB_USER', 'DB_HOST', 'DB_NAME', 'AUTH_KEY', '_wpnonce', 'admin-ajax.php', 'realização não inferida', 'Documentos não fornecidos', 'TODO: example', 'FIXME: example', 'var_dump(1)', 'print_r(1)');
foreach ($cases as $case) { expect(count(Auditor::inspect('<html><body>' . $case . '</body></html>')) > 0, 'Bloquear vazamento configurado: ' . $case); }
expect(in_array('accidental_home_404', Auditor::inspect('<title>Página não encontrada</title>', true), true), 'Recusar título de erro na Home.');
expect(in_array('accidental_home_404', Auditor::inspect('<body class="error404 consumer">', true), true), 'Recusar classe de erro na Home antes da limpeza.');
expect(!in_array('accidental_home_404', Auditor::inspect('<title>Página não encontrada</title>', false), true), 'Permitir documento 404 intencional.');
expect(in_array('home_document_structure', Auditor::inspect('<html><head></head><body><h1>Home</h1></body></html>', true, true), true), 'Recusar Home final sem título no cabeçalho.');
$options->set('audit_unresolved_shortcodes', 'consumer_form');
expect(in_array('unresolved_shortcode', Auditor::inspect('[consumer_form id="1"]'), true), 'Bloquear shortcode configurado.');
expect(!Auditor::inspect('[unrelated value]'), 'Preservar colchetes e shortcodes fora da configuração.');
$options->set('audit_unresolved_shortcodes', '');
$source = '<!doctype html><html><head><style id="wp-new-feature-inline-css">body{color:red}</style><style id="consumer">:root{--wp--preset--color:red;--brand:blue}p{color:var(--brand)}/*# sourceURL=x */</style><script type="speculationrules">{}</script></head><body class="wp-new-feature consumer-home"><!-- internal --><div class="wp-block-demo consumer">14:00 &#8211; 16:00</div><a target="_blank" href="/link" aria-label="A &#8212; B">Link</a><script src="/local.js?ver=1"></script><script>const text="Educação";</script></body></html>';
$clean = Sanitizer::sanitize($source);
file_put_contents(__DIR__ . '/runtime/sanitized.html', $clean);
$bom_document = \GambWPStaticGuard\Gamb_DOM_Parser::parse(Sanitizer::sanitize("\xEF\xBB\xBF\n" . $source));
expect($bom_document->getElementsByTagName('body')->item(0)->getAttribute('class') === 'consumer-home' && $bom_document->getElementsByTagName('head')->item(0)->getElementsByTagName('title')->length === 0 && $bom_document->getElementsByTagName('head')->item(0)->getElementsByTagName('style')->length > 0, 'Remover BOM inicial sem deslocar head ou perder atributos do body.');
expect(!str_contains($clean, 'wp-new-feature') && !str_contains($clean, 'wp-block-demo') && str_contains($clean, 'consumer-home'), 'Limpar classes em todos os elementos preservando as próprias.');
expect(!str_contains($clean, 'speculationrules') && !str_contains($clean, '--wp--preset--') && str_contains($clean, '--brand:blue'), 'Remover estilos, regras e variáveis nativas preservando CSS próprio.');
expect(!str_contains($clean, '<!--') && !str_contains($clean, 'sourceURL'), 'Remover todos os comentários HTML e anotações de origem.');
expect(str_contains($clean, '14:00 - 16:00') && str_contains($clean, 'aria-label="A - B"'), 'Normalizar entidades nos textos e atributos.');
expect(str_contains($clean, 'noopener noreferrer') && str_contains($clean, 'defer') && !str_contains($clean, '?ver='), 'Aplicar tabnabbing, defer e remoção de versão.');
expect(str_contains($clean, 'Educação') && !str_contains($clean, 'Educa&#'), 'Preservar Unicode nos blocos JavaScript com parser legado ou HTML5.');
expect(!Auditor::inspect($clean, false, true), 'Aprovar HTML final sanitizado.');
$options->set('sanitizer_style_whitelist', 'wp-approved');
$white = Sanitizer::sanitize('<html><head><style id="wp-approved">p{color:red}</style></head><body></body></html>');
expect(str_contains($white, 'wp-approved') && !Auditor::inspect($white, false, true), 'Preservar estilo explicitamente permitido.');
$options->set('sanitizer_style_whitelist', '');

$GLOBALS['fixture_http_calls'] = array();
$GLOBALS['fixture_http_handler'] = static fn($url, $args) => fixture_response('body{color:blue}');
$external = Sanitizer::sanitize('<html><head><link rel="stylesheet" href="https://cdn.test/a.css"></head><body><script src="https://cdn.test/a.js"></script></body></html>');
expect(substr_count($external, 'sha384-') === 2 && str_contains($external, 'rel="preconnect"'), 'Calcular SRI e gerar preconnect para CDN.');
Sanitizer::sanitize('<link rel="stylesheet" href="https://cdn.test/a.css">');
expect(count($GLOBALS['fixture_http_calls']) === 2, 'Reutilizar SRI em cache por URL.');
$sri_keys = array_filter(array_keys($GLOBALS['fixture_transients']), static fn($key) => str_starts_with($key, 'gamb_sri_'));
expect($GLOBALS['fixture_transients'][array_values($sri_keys)[0]][1] === 7 * DAY_IN_SECONDS, 'Manter cache SRI por sete dias.');
$count_before = count($GLOBALS['fixture_http_calls']); Sanitizer::sanitize('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">');
expect(count($GLOBALS['fixture_http_calls']) === $count_before, 'Excetuar CSS dinâmico do Google Fonts.');
$blocked = false; try { Sanitizer::remote_integrity('https://127.0.0.1/private'); } catch (RuntimeException $error) { $blocked = true; }
expect($blocked, 'Bloquear SSRF para recurso privado.');
$epoch_before = get_option('gamb_wp_static_guard_sri_epoch', '0'); Sanitizer::invalidate_sri_cache();
expect(get_option('gamb_wp_static_guard_sri_epoch') !== $epoch_before, 'Invalidar geração dos hashes SRI.');

$updater = Updater::instance();
expect(Updater::valid_repository('gamberine/gamb-wp-static-guard') && !Updater::valid_repository('../owner/repo'), 'Validar owner/repo.');
$options->set('github_update_repository', '../invalid');
expect($updater->download_headers(array('timeout' => 10), 'https://other.test/') === array('timeout' => 10) && $updater->latest() === null, 'Não afetar HTTP de terceiros com repositório inválido.');
$options->set('github_update_repository', 'gamberine/gamb-wp-static-guard');
expect($updater->set_token('fixture-secret-token') && $updater->token() === 'fixture-secret-token', 'Criptografar e recuperar token opcional.');
expect(!str_contains(get_option(Updater::TOKEN_OPTION), 'fixture-secret-token'), 'Não persistir token em texto aberto.');
$encrypted = get_option(Updater::TOKEN_OPTION); update_option(Updater::TOKEN_OPTION, substr($encrypted, 0, -6) . 'AAAAAA');
expect($updater->token() === '', 'Recusar token criptografado adulterado.');
$updater->set_token('');
$repo = 'gamberine/gamb-wp-static-guard';
$release = array('tag_name' => 'v5.1.0', 'body' => '<script>unsafe</script>', 'assets' => array(array('name' => 'SHA256SUMS', 'browser_download_url' => 'https://github.com/' . $repo . '/releases/download/v5.1.0/SHA256SUMS'), array('name' => 'gamb-wp-static-guard-5.1.0.zip', 'browser_download_url' => 'https://github.com/' . $repo . '/releases/download/v5.1.0/gamb-wp-static-guard-5.1.0.zip')), 'zipball_url' => 'https://api.github.com/repos/' . $repo . '/zipball/v5.1.0');
$normalized = $updater->normalize_release($release);
expect($normalized['version'] === '5.1.0' && str_ends_with($normalized['package'], '.zip'), 'Priorizar asset ZIP correto em lugar de checksum.');
$fallback = $release; $fallback['assets'] = array();
expect($updater->normalize_release($fallback)['package'] === $release['zipball_url'], 'Oferecer fallback zipball validado.');
$pre = $release; $pre['prerelease'] = true;
expect($updater->normalize_release($pre) === null, 'Ignorar prerelease.');
expect(!$updater->allowed_initial_url('https://evil.test/package.zip') && !$updater->allowed_initial_url('https://github.com/other/repo/releases/download/v1/package.zip'), 'Restringir origem e repositório do pacote.');
$GLOBALS['fixture_http_handler'] = static fn($url, $args) => fixture_response(json_encode($release));
$updated = $updater->filter_updates((object)array('response' => array(), 'no_update' => array()));
expect($updated->response[$updater->plugin_file()]->new_version === '5.1.0', 'Integrar versão mais recente ao transient nativo.');
$information = $updater->plugin_information(false, 'plugin_information', (object)array('slug' => 'gamb-wp-static-guard'));
expect(!str_contains($information->sections['changelog'], '<script>') && str_contains($information->sections['changelog'], '&lt;script&gt;'), 'Renderizar release no modal sem executar HTML recebido do GitHub.');
$github_keys = array_filter(array_keys($GLOBALS['fixture_transients']), static fn($key) => str_starts_with($key, 'gamb_github_'));
expect($GLOBALS['fixture_transients'][array_values($github_keys)[0]][1] === 12 * HOUR_IN_SECONDS, 'Manter cache de release por doze horas.');
expect($updater->pre_download(false, 'https://evil.test/package.zip', null, array('plugin' => $updater->plugin_file())) instanceof WP_Error, 'Recusar pacote não aprovado pelo release.');
expect($updater->pre_download(false, 'https://other.test/package.zip', null, array('plugin' => 'other/other.php')) === false, 'Preservar atualizador de outro plugin.');
expect($updater->pre_download(false, 'https://other.test/package.zip', null, array('plugin' => 'other/other.php', 'plugins' => array('other/other.php', $updater->plugin_file()))) === false, 'Não interceptar outro plugin em atualização em lote.');
$downloading = (new ReflectionClass($updater))->getProperty('downloading'); $downloading->setAccessible(true); $downloading->setValue($updater, true);
$updater->set_token('fixture-download-token');
$api = $updater->download_headers(array(), 'https://api.github.com/repos/' . $repo . '/releases/assets/123');
expect(($api['headers']['Authorization'] ?? '') === 'Bearer fixture-download-token' && $api['headers']['Accept'] === 'application/octet-stream', 'Autorizar somente download de asset na API correta.');
$cdn = $updater->download_headers(array('headers' => array('Authorization' => 'Bearer must-not-leak')), 'https://release-assets.githubusercontent.com/asset');
expect(!isset($cdn['headers']['Authorization']), 'Não enviar PAT ao destino redirecionado.');
$downloading->setValue($updater, false); $updater->set_token('');

if (!class_exists('WP_Http')) { class WP_Http { public static function make_absolute_url($url, $base) { return $url; } } }
$updater->set_token('fixture-private-token');
$private_release = $release;
$private_release['assets'][1]['url'] = 'https://api.github.com/repos/' . $repo . '/releases/assets/123';
$GLOBALS['fixture_http_handler'] = static fn($url, $args) => fixture_response(json_encode($private_release));
$private = $updater->latest(true);
$GLOBALS['fixture_http_calls'] = array();
$GLOBALS['fixture_http_handler'] = static function($url, $args) {
    if (str_contains($url, 'api.github.com')) { file_put_contents($args['filename'], ''); return fixture_response('', 302, array('location' => 'https://release-assets.githubusercontent.com/controlled-package')); }
    file_put_contents($args['filename'], "PK\x03\x04controlled-fixture"); return fixture_response('');
};
$download = $updater->pre_download(false, $private['package'], null, array('plugin' => $updater->plugin_file()));
expect(is_string($download) && is_file($download), 'Baixar pacote privado por redirecionamento controlado.');
expect(isset($GLOBALS['fixture_http_calls'][0][1]['headers']['Authorization']) && !isset($GLOBALS['fixture_http_calls'][1][1]['headers']['Authorization']), 'Não propagar Authorization ao CDN durante download real do atualizador.');
if (is_string($download) && is_file($download)) { unlink($download); }
$GLOBALS['fixture_http_handler'] = static function($url, $args) { file_put_contents($args['filename'], ''); return fixture_response('', 302, array('location' => 'https://evil.test/private')); };
expect($updater->pre_download(false, $private['package'], null, array('plugin' => $updater->plugin_file())) instanceof WP_Error, 'Recusar redirecionamento fora dos destinos GitHub.');
$updater->set_token('');

$folder = sys_get_temp_dir() . '/gamb-source-' . bin2hex(random_bytes(8));
mkdir($folder . '/owner-commit', 0777, true); file_put_contents($folder . '/owner-commit/gamb-wp-static-guard.php', '<?php /* fixture */');
$GLOBALS['wp_filesystem'] = new class { public function exists($path) { return file_exists($path); } public function move($from, $to) { return rename($from, $to); } };
try {
    $renamed = $updater->source_selection($folder . '/owner-commit/', $folder . '/', null, array('plugin' => $updater->plugin_file()));
    expect($renamed === wp_normalize_path($folder) . '/gamb-wp-static-guard/' && is_file($renamed . 'gamb-wp-static-guard.php'), 'Normalizar diretório extraído sem desativar identidade do plugin.');
    expect($updater->source_selection('/outside/', $folder . '/', null, array('plugin' => $updater->plugin_file())) instanceof WP_Error, 'Recusar fonte fora do diretório de extração.');
} finally { unlink($folder . '/gamb-wp-static-guard/gamb-wp-static-guard.php'); rmdir($folder . '/gamb-wp-static-guard'); rmdir($folder); }
$before_update_epoch = get_option('gamb_wp_static_guard_sri_epoch');
$updater->after_update(null, array('action' => 'update', 'type' => 'core'));
expect(get_option('gamb_wp_static_guard_sri_epoch') !== $before_update_epoch && get_option(Auditor::PENDING_OPTION) && wp_next_scheduled('gamb_static_guard_post_update_audit'), 'Invalidar SRI e agendar revalidação após atualização do core.');
$scheduled = $GLOBALS['fixture_scheduled']; $updater->after_update(null, array('action' => 'update', 'type' => 'theme')); $updater->after_update(null, array('action' => 'update', 'type' => 'plugin'));
expect(count($GLOBALS['fixture_scheduled']) === count($scheduled), 'Consolidar auditorias após atualizações de temas e plugins.');
