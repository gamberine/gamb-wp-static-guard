<?php
/** Integração com a HTML API oficial, sem banco de dados e sem imitar seu parser. */
$core = rtrim( getenv( 'GAMB_WP_HTML_API' ), '/\\' ) . '/wp-includes/html-api/';
if ( ! is_file( $core . 'class-wp-html-tag-processor.php' ) ) { throw new RuntimeException( 'Informe um pacote oficial do WordPress em GAMB_WP_HTML_API.' ); }
if ( is_file( $core . '../utf8.php' ) ) { require_once $core . '../utf8.php'; }
require_once $core . '../kses.php';
require_once $core . '../plugin.php';
if ( is_file( $core . '../class-wp-token-map.php' ) ) { require_once $core . '../class-wp-token-map.php'; }
if ( is_file( $core . 'html5-named-character-references.php' ) ) { require_once $core . 'html5-named-character-references.php'; }
spl_autoload_register( static function( $class ) use ( $core ) {
    if ( 0 !== strpos( $class, 'WP_HTML_' ) ) { return; }
    $path = $core . 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
    if ( is_file( $path ) ) { require_once $path; }
} );
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function __( $message, $domain = '' ) { return $message; }
function _doing_it_wrong( $name, $message, $version ) { throw new RuntimeException( $name . ': ' . $message ); }
function get_stylesheet_directory_uri() { return home_url( '/wp-content/themes/example' ); }
function get_template_directory_uri() { return get_stylesheet_directory_uri(); }
function includes_url() { return home_url( '/wp-includes/' ); }
function plugins_url() { return home_url( '/wp-content/plugins/' ); }
class WP_Http {
    public static function make_absolute_url( $url, $base ) {
        if ( preg_match( '~^https?://~i', $url ) ) { return $url; }
        if ( 0 === strpos( $url, '//' ) ) { return parse_url( $base, PHP_URL_SCHEME ) . ':' . $url; }
        $parts = parse_url( $base ); $origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        return $origin . ( 0 === strpos( $url, '/' ) ? $url : rtrim( dirname( $parts['path'] ?? '/' ), '/' ) . '/' . $url );
    }
}

$document = '<!doctype html><html><head><title>Consumer Portal</title></head><body class="consumer">Original content</body></html>';
$completed = \GambWPStaticGuard\Installation_Checks::complete_document( $document, true, false );
expect( strpos( $completed, 'rel="canonical" href="https://example.test/site/"' ) !== false, 'Completar canonical da Home.' );
expect( \GambWPStaticGuard\Installation_Checks::complete_document( $completed, true, false ) === $completed, 'Não duplicar canonical.' );
expect( strpos( $completed, '<body class="consumer">Original content' ) !== false && strpos( $completed, '<title>Consumer Portal</title>' ) !== false, 'Preservar título, classes e conteúdo próprios.' );
$seo = str_replace( '</head>', '<LINK rel=canonical href="https://preferred.test/" /></head>', $document );
expect( \GambWPStaticGuard\Installation_Checks::complete_document( $seo, true, false ) === $seo, 'Preservar canonical explícito da extensão de SEO.' );
expect( \GambWPStaticGuard\Installation_Checks::complete_document( $document, false, false ) === $document, 'Não completar canonical de outra página.' );
$error = \GambWPStaticGuard\Installation_Checks::complete_document( $seo, false, true );
expect( stripos( $error, 'canonical' ) === false && strpos( $error, 'content="noindex"' ) !== false, 'Remover canonical e impedir indexação do erro.' );
$robots = str_replace( '</head>', '<meta name="robots" content="index, follow, max-image-preview:large"></head>', $seo );
$robots = \GambWPStaticGuard\Installation_Checks::complete_document( $robots, false, true );
expect( strpos( $robots, 'max-image-preview:large' ) !== false && strpos( $robots, 'noindex' ) !== false && ! preg_match( '/content="index[ ,]/', $robots ), 'Preservar outras diretivas de robôs e remover index do erro.' );
$options->set( 'ensure_home_canonical', false );
expect( \GambWPStaticGuard\Installation_Checks::complete_document( $document, true, false ) === $document, 'Respeitar canonical automático desabilitado.' );
$options->set( 'ensure_home_canonical', true );
$blocked = false;
try { \GambWPStaticGuard\Installation_Checks::complete_document( '<body>fragment</body>', true, false ); } catch ( RuntimeException $error ) { $blocked = true; }
expect( $blocked, 'Recusar cabeçalho HTML incompleto em contexto obrigatório.' );

$audit = new \GambWPStaticGuard\Installation_Checks();
$audit->inspect_html( '<a href="https://institution.test/path/">Institution</a><link rel="stylesheet" href="https://cdn.test/a.css?token=hidden"><script src="//cdn.test/a.js"></script><img srcset="https://images.test/a.png 1x, https://images.test/b.png 2x"><style>body{background:url(https://images.test/bg.png)}</style><iframe src="https://service.test/embed"></iframe>', home_url( '/' ) );
$resources = $audit->dependency_report();
expect( count( $resources['dependencies'] ) === 6, 'Inventariar CSS, scripts, imagens responsivas, estilo incorporado e serviço externo.' );
expect( $resources['external_navigation_count'] === 1, 'Separar links de navegação das dependências.' );
expect( strpos( json_encode( $resources ), 'token=hidden' ) === false, 'Não registrar parâmetro do recurso externo.' );
$audit->inspect_css( '/* url(https://license.test/notice) */ @import "https://fonts.test/font.css";@font-face{src:url(https://fonts.test/font.woff2)}p{background:url(data:image/png;base64,AAAA)}', home_url( '/' ) );
expect( count( $audit->dependency_report()['dependencies'] ) === 8, 'Inspecionar import e fontes sem confundir avisos legais ou dados incorporados.' );

file_put_contents( get_stylesheet_directory() . '/style.css', '@import "nested.css";' );
file_put_contents( get_stylesheet_directory() . '/nested.css', '@import "style.css";@font-face{src:url(https://fonts.test/nested.woff2)}' );
$nested_audit = new \GambWPStaticGuard\Installation_Checks();
$nested_audit->inspect_html( '<link rel="stylesheet" href="' . home_url( '/v/' . $token ) . '">', home_url( '/' ) );
expect( count( $nested_audit->dependency_report()['dependencies'] ) === 1 && $nested_audit->dependency_report()['local_stylesheets_checked'] === 2, 'Resolver CSS relativo ao arquivo original, inclusive sob token, sem repetir importação circular.' );

$fixture = sys_get_temp_dir() . '/gamb-integrity-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture . '/v', 0777, true );
try {
    file_put_contents( $fixture . '/v/fixture_asset', 'body{color:#123456}' );
    $integrity_html = '<html><head><link rel="stylesheet" href="/preview/v/fixture_asset" integrity="old"></head><body><script src="https://remote.test/v/fixture_asset" integrity="external"></script><script src="//remote.test/v/fixture_asset" integrity="external-relative"></script></body></html>';
    $integrity_html = \GambWPStaticGuard\Installation_Checks::refresh_integrity( $integrity_html, $fixture );
    expect( strpos( $integrity_html, 'sha384-' . base64_encode( hash( 'sha384', 'body{color:#123456}', true ) ) ) !== false, 'Calcular SRI sobre os bytes finais.' );
    expect( strpos( $integrity_html, 'integrity="external"' ) !== false && strpos( $integrity_html, 'integrity="external-relative"' ) !== false, 'Não substituir SRI de recursos de outra origem.' );
    expect( strpos( $integrity_html, 'crossorigin="anonymous"' ) !== false, 'Completar crossorigin de recurso local com SRI.' );
} finally { unlink( $fixture . '/v/fixture_asset' ); rmdir( $fixture . '/v' ); rmdir( $fixture ); }
