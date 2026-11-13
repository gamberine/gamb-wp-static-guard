<?php
/** Testes independentes: migração, limites de caminhos e pós-processamento. */
$site = __DIR__ . '/runtime/site/';
foreach ( array( '', 'wp-content/themes/example/', 'wp-content/uploads/', 'wp-content/plugins/', 'wp-includes/' ) as $part ) { if ( ! is_dir( $site . $part ) ) { mkdir( $site . $part, 0777, true ); } }
file_put_contents( $site . 'wp-content/themes/example/style.css', 'body{color:#123456}' );
define( 'ABSPATH', $site ); define( 'WP_CONTENT_DIR', $site . 'wp-content' ); define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' ); define( 'WPINC', 'wp-includes' ); define( 'AUTH_KEY', 'automated-fixture-not-a-production-secret' ); define( 'GAMB_STATIC_WP_VERSION', '4.1.2' );
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
function wp_salt( $scheme = '' ) { return AUTH_KEY; }
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
require dirname( __DIR__ ) . '/src/class-gamb-backup-service.php';
function date_i18n( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
function size_format( $size ) { return $size . ' B'; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce( $action ) { return 'test-nonce'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
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
expect( ! \GambWPStaticGuard\Gamb_Security_Suite::is_public_asset( 'gamb-backups/package.zip' ), 'Rejeitar cópias administrativas.' );
expect( ! \GambWPStaticGuard\Gamb_Security_Suite::is_public_asset( 'handler.php.jpg' ), 'Rejeitar dupla extensão de servidor.' );
$options->set( 'first_full_export_completed', false );
expect( $engine->resolve_scope( 'theme' ) === 'export' && $engine->resolve_scope( 'update' ) === 'export', 'Exigir primeira conversão completa no servidor.' );
$options->set( 'first_full_export_completed', true );
expect( $engine->resolve_scope( 'theme' ) === 'theme', 'Liberar escopos após publicação completa.' );
$backup_dir = \GambWPStaticGuard\Backup_Service::get_backup_dir();
for ( $i = 1; $i <= 8; $i++ ) { $path = $backup_dir . '/gamb-db-backup-fixture-' . $i . '.sql'; file_put_contents( $path, '-- fixture' ); touch( $path, 1000000000 + $i ); }
file_put_contents( $backup_dir . '/unrelated.txt', 'preserve' );
expect( \GambWPStaticGuard\Backup_Service::enforce_retention() === true, 'Aplicar retenção.' );
$backups = \GambWPStaticGuard\Backup_Service::list_backups();
expect( count( $backups ) === 6 && $backups[0]['filename'] === 'gamb-db-backup-fixture-8.sql' && $backups[5]['filename'] === 'gamb-db-backup-fixture-3.sql', 'Preservar somente as seis cópias mais recentes.' );
expect( is_file( $backup_dir . '/unrelated.txt' ), 'Preservar arquivos não administrados pelo plugin.' );
expect( ! \GambWPStaticGuard\Backup_Service::delete_backup( '../unrelated.txt' ), 'Bloquear exclusão fora do escopo.' );
expect( \GambWPStaticGuard\Gamb_Security_Suite::strip_non_license_comments( '/*! Copyright Example. Licensed under MIT. */body{}/* internal */' ) === '/*! Copyright Example. Licensed under MIT. */body{}', 'Preservar licença e remover comentário interno de CSS.' );
expect( strpos( $guard->sanitize_js_content( '/*! @license Example */function example(){}' ), '@license' ) !== false, 'Preservar aviso legal de JavaScript.' );
expect( \GambWPStaticGuard\Frontend_Policy::body_classes( array( 'error404', 'search', 'search-results', 'search-no-results', 'archive', 'archive-date', 'gaepe-home', 'gaepe-erro', 'custom-layout' ) ) === array( 'gaepe-home', 'gaepe-erro', 'custom-layout' ), 'Remover classes nativas de erro, pesquisa e arquivo, preservando classes próprias.' );
expect( strlen( \GambWPStaticGuard\Frontend_Policy::export_context_token() ) === 64, 'Assinar a identificação do contexto de captura.' );
expect( $engine->capture_response_is_valid( home_url( '/' ), 200, 'front=1;error=0' ), 'Aceitar Home com HTTP 200 e contexto real correto.' );
expect( ! $engine->capture_response_is_valid( home_url( '/' ), 200, 'front=0;error=1' ), 'Recusar soft 404 na Home.' );
expect( ! $engine->capture_response_is_valid( home_url( '/' ), 404, 'front=1;error=0' ), 'Recusar HTTP 404 na Home.' );
expect( ! $engine->capture_response_is_valid( home_url( '/' ), 200, '' ), 'Não publicar a Home sem confirmação do contexto.' );
expect( ! $engine->capture_response_is_valid( home_url( '/news/' ), 200, 'front=0;error=1' ), 'Recusar página normal em contexto de erro.' );
expect( $engine->capture_response_is_valid( home_url( '/missing/' ), 404, 'front=0;error=1', true ), 'Aceitar erro 404 esperado.' );
expect( ! $engine->capture_response_is_valid( home_url( '/missing/' ), 200, 'front=0;error=1', true ), 'Exigir HTTP 404 real no documento de erro.' );
expect( $engine->capture_response_is_valid( home_url( '/manual.html' ), 200, '' ), 'Preservar captura de HTML local fora do roteamento WordPress.' );
echo $checks . " verificações independentes concluídas.\n";
