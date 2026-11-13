<?php
/** Testes independentes: migração, limites de caminhos e pós-processamento. */
$site = __DIR__ . '/runtime/site/';
foreach ( array( '', 'wp-content/themes/example/', 'wp-content/uploads/', 'wp-content/plugins/', 'wp-includes/' ) as $part ) { if ( ! is_dir( $site . $part ) ) { mkdir( $site . $part, 0777, true ); } }
file_put_contents( $site . 'wp-content/themes/example/style.css', 'body{color:#123456}' );
define( 'ABSPATH', $site ); define( 'WP_CONTENT_DIR', $site . 'wp-content' ); define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' ); define( 'WPINC', 'wp-includes' ); define( 'AUTH_KEY', 'automated-fixture-not-a-production-secret' ); define( 'GAMB_STATIC_WP_VERSION', '4.0.0' );
$legacy_key = base64_decode( 'c2ltcGx5LXN0YXRpYw==' );
$legacy = array( 'use_minify' => false, 'relative_path' => '0', 'local_dir' => '/dedicated/output', 'ss_single_auto_export' => false, 'ss_single_auto_export_delay' => 0 );
$GLOBALS['fixture_options'] = array( $legacy_key => $legacy );
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['fixture_options'] ) ? $GLOBALS['fixture_options'][$key] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { if ( array_key_exists( $key, $GLOBALS['fixture_options'] ) ) { return false; } $GLOBALS['fixture_options'][$key] = $value; return true; }
function update_option( $key, $value ) { $GLOBALS['fixture_options'][$key] = $value; return true; }
function wp_load_alloptions() { return $GLOBALS['fixture_options']; }
function maybe_unserialize( $value ) { return $value; }
function is_admin() { return false; }
function home_url( $path = '' ) { return 'https://example.test/site' . $path; }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
function trailingslashit( $path ) { return untrailingslashit( $path ) . '/'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function get_stylesheet() { return 'example'; }
function get_template() { return 'example'; }
function get_stylesheet_directory() { return WP_CONTENT_DIR . '/themes/example'; }
function get_template_directory() { return get_stylesheet_directory(); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads' ); }
function get_temp_dir() { return trailingslashit( sys_get_temp_dir() ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
require dirname( __DIR__ ) . '/src/class-gamb-options.php';
require dirname( __DIR__ ) . '/src/class-gamb-guard-suite.php';
require dirname( __DIR__ ) . '/src/class-gamb-frontend-policy.php';
require dirname( __DIR__ ) . '/src/class-gamb-static-engine.php';
$checks = 0;
function expect( $condition, $name ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $name ); } $checks++; }
$options = \GambWPStaticGuard\Options::instance();
expect( $options->get( 'use_minify' ) === false, 'Preservar false.' );
expect( $options->get( 'relative_path' ) === '0', 'Preservar string zero.' );
expect( $options->get( 'auto_export' ) === false, 'Preservar opção automática desativada.' );
expect( $options->get( 'auto_export_delay' ) === 0, 'Preservar zero inteiro.' );
expect( get_option( $legacy_key ) === $legacy, 'Preservar a origem da migração.' );
$options->set( 'guard_clean_body_classes', true );
expect( \GambWPStaticGuard\Frontend_Policy::body_classes( array( 'page', 'page-id-123', 'wp-singular', 'wp-theme-example', 'consumer-home', 'custom-layout' ) ) === array( 'consumer-home', 'custom-layout' ), 'Manter classes do projeto.' );
$reflection = new ReflectionClass( '\GambWPStaticGuard\Gamb_Security_Suite' );
$guard = $reflection->newInstanceWithoutConstructor();
$singleton = $reflection->getProperty( 'instance' ); $singleton->setAccessible( true ); $singleton->setValue( null, $guard );
expect( \GambWPStaticGuard\Gamb_Security_Suite::normalize_rel_path( 'assets/../style.css' ) === 'style.css', 'Normalizar caminho.' );
$token = \GambWPStaticGuard\Gamb_Security_Suite::encrypt_token( 't', 'style.css' );
$decoded = \GambWPStaticGuard\Gamb_Security_Suite::decrypt_token( $token );
expect( $decoded && $decoded['rel'] === 'style.css', 'Resolver recurso válido.' );
expect( \GambWPStaticGuard\Gamb_Security_Suite::decrypt_token( \GambWPStaticGuard\Gamb_Security_Suite::encrypt_token( 't', '../outside.txt' ) ) === null, 'Rejeitar travessia.' );
expect( \GambWPStaticGuard\Gamb_Security_Suite::decrypt_token( substr( $token, 0, -2 ) . 'xx' ) === null, 'Rejeitar token alterado.' );
$html = '<html><body><!-- internal --><style>p{color:red}/*# sourceURL=example */</style><script type="speculationrules">{"url":"/wp-admin/"}</script><img src="https://example.test/site/wp-content/themes/example/style.css"></body></html>';
$clean = \GambWPStaticGuard\Frontend_Policy::postprocess_html( $html );
expect( strpos( $clean, 'sourceURL' ) === false && strpos( $clean, '<!--' ) === false, 'Remover comentários de origem.' );
expect( strpos( $clean, 'speculationrules' ) === false, 'Remover regras especulativas.' );
expect( strpos( $clean, '/wp-content/' ) === false && strpos( $clean, '/v/' ) !== false, 'Tokenizar recursos antes da validação.' );
$blocked = false;
try { \GambWPStaticGuard\Frontend_Policy::postprocess_html( '<a href="/wp-admin/">Administração</a>' ); } catch ( RuntimeException $error ) { $blocked = true; }
expect( $blocked, 'Interromper HTML com referência residual.' );
$engine = ( new ReflectionClass( '\GambWPStaticGuard\Static_Engine' ) )->newInstanceWithoutConstructor();
$options->set( 'destination_url_type', 'relative' )->set( 'relative_path', '/preview' );
$url = $engine->apply_url_replacement_mode( 'https://example.test/site/index.php/news/', 'index.html' );
expect( $url === '/preview/news/', 'Converter permalinks para publicação estática.' );
expect( $engine->apply_url_replacement_mode( $url, 'index.html' ) === $url, 'Evitar substituição duplicada.' );
expect( ! \GambWPStaticGuard\Gamb_Security_Suite::is_public_asset( 'handler.php' ), 'Rejeitar código de servidor.' );
expect( ! \GambWPStaticGuard\Gamb_Security_Suite::is_public_asset( 'database.sql' ), 'Rejeitar dump SQL.' );
expect( ! \GambWPStaticGuard\Gamb_Security_Suite::is_public_asset( 'gamb-static-guard-backups/package.zip' ), 'Rejeitar diretório de backup.' );
expect( ! \GambWPStaticGuard\Gamb_Security_Suite::is_public_asset( '.private/config.json' ), 'Rejeitar arquivos ocultos.' );
echo $checks . " verificações independentes concluídas.\n";
