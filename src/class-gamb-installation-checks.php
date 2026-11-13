<?php
namespace GambWPStaticGuard;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Integração portátil sem editar arquivos, conteúdo ou configurações do tema. */
class Installation_Checks {
    const REPORT_OPTION = 'gamb_wp_static_guard_installation_report';
    const EXPORT_OPTION = 'gamb_wp_static_guard_dependency_report';
    private static $fallback_in_use = false;
    private $dependencies = array();
    private $navigation = array();
    private $visited_css = array();
    private $css_bytes = 0;
    private $limited = false;

    public static function register() {
        add_filter( 'template_include', array( __CLASS__, 'fallback_template' ), 99 );
        add_filter( 'body_class', array( __CLASS__, 'fallback_body_class' ), 1000 );
        add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
    }

    public static function should_use_fallback( $is_error, $has_error_template, $is_block_theme, $template, $index ) {
        return $is_error && ! $has_error_template && ! $is_block_theme && $template && $index && wp_normalize_path( $template ) === wp_normalize_path( $index );
    }

    public static function fallback_template( $template ) {
        self::$fallback_in_use = false;
        if ( ! is_404() || ! Options::instance()->get( 'fallback_404', true ) || ! self::should_use_fallback( true, (bool) locate_template( '404.php' ), function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(), $template, get_index_template() ) ) { return $template; }
        self::$fallback_in_use = true;
        wp_enqueue_style( 'gamb-static-error', GAMB_STATIC_WP_URL . '/assets/error-page.css', array(), GAMB_STATIC_WP_VERSION );
        return GAMB_STATIC_WP_PATH . 'src/templates/404.php';
    }

    public static function fallback_body_class( $classes ) {
        if ( self::$fallback_in_use ) { $classes[] = 'gamb-static-error'; }
        return array_values( array_unique( $classes ) );
    }

    public static function robots( $robots ) {
        if ( is_404() ) { unset( $robots['index'] ); $robots['noindex'] = true; }
        return $robots;
    }

    public static function canonical_tag( $tag ) {
        $processor = new \WP_HTML_Tag_Processor( $tag );
        return $processor->next_tag( 'LINK' ) && in_array( 'canonical', preg_split( '/\s+/', strtolower( (string) $processor->get_attribute( 'rel' ) ) ), true );
    }

    /** Completa apenas os contextos identificados pelo motor, sem impor título ou classes do consumidor. */
    public static function complete_document( $html, $is_home, $is_error ) {
        if ( ( $is_home || $is_error ) && ! preg_match( '~</head\s*>~i', $html ) ) { throw new \RuntimeException( 'O documento da Home ou do erro 404 não possui cabeçalho HTML completo. Revise o modelo do tema.' ); }
        if ( $is_error ) {
            $html = preg_replace_callback( '~<link\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>~i', static function( $match ) { return self::canonical_tag( $match[0] ) ? '' : $match[0]; }, $html );
            $processor = new \WP_HTML_Tag_Processor( $html );
            $has_robots = false;
            while ( $processor->next_tag( 'META' ) ) {
                if ( 'robots' !== strtolower( (string) $processor->get_attribute( 'name' ) ) ) { continue; }
                $has_robots = true;
                $content = preg_replace( '/(?:^|[\s,])(?:index|noindex)(?=$|[\s,])/i', '', (string) $processor->get_attribute( 'content' ) );
                $processor->set_attribute( 'content', trim( $content, ' ,' ) . ( trim( $content, ' ,' ) ? ', ' : '' ) . 'noindex' );
            }
            $html = $processor->get_updated_html();
            return $has_robots ? $html : preg_replace( '~</head\s*>~i', '<meta name="robots" content="noindex" />' . "\n</head>", $html, 1 );
        }
        if ( ! $is_home || ! Options::instance()->get( 'ensure_home_canonical', true ) ) { return $html; }
        $processor = new \WP_HTML_Tag_Processor( $html );
        while ( $processor->next_tag( 'LINK' ) ) {
            if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( (string) $processor->get_attribute( 'rel' ) ) ), true ) ) { return $html; }
        }
        return preg_replace( '~</head\s*>~i', '<link rel="canonical" href="' . esc_url( home_url( '/' ) ) . '" />' . "\n</head>", $html, 1 );
    }

    public static function external_url( $url ) {
        if ( 0 === strpos( $url, '//' ) ) { $url = ( wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ?: 'https' ) . ':' . $url; }
        $parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) );
        if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) { return false; }
        return strtolower( $parts['host'] ) !== strtolower( $home['host'] ) || ( $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 ) ) !== ( $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 ) );
    }

    /** Não persiste parâmetros, fragmentos nem credenciais de endereços de terceiros. */
    public static function public_reference( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['host'] ) ) { return ''; }
        return ( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?[parâmetros omitidos]' : '' );
    }

    private function inspect_url( $value, $base, $kind, $follow_css = false ) {
        if ( ! is_string( $value ) || '' === trim( $value ) || preg_match( '~^(?:data:|blob:|mailto:|tel:|javascript:|#)~i', trim( $value ) ) ) { return; }
        $url = \WP_Http::make_absolute_url( html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
        if ( self::external_url( $url ) ) {
            if ( count( $this->dependencies ) >= 200 ) { $this->limited = true; return; }
            $reference = self::public_reference( $url );
            $this->dependencies[$kind . '|' . $reference] = array( 'kind' => $kind, 'url' => $reference );
        } elseif ( $follow_css ) { $this->inspect_local_css( $url ); }
    }

    private function inspect_local_css( $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) ) { return; }
        $disk = false;
        $css_base = $url;
        if ( preg_match( '~/v/(?:d/)?([^/]+)(?:/([^?#]*))?$~', $path, $matches ) ) {
            $info = Gamb_Security_Suite::decrypt_token( $matches[1], $matches[2] ?? '' );
            if ( $info ) { $disk = $info['disk']; $css_base = trailingslashit( self::asset_base_url( $info['kind'] ) ) . $info['rel']; }
        } else {
            foreach ( array( 't', 'p', 'u', 'c', 'x' ) as $kind ) {
                $root = Gamb_Security_Suite::get_base_dir_for_kind( $kind );
                if ( ! $root ) { continue; }
                $base_url = self::asset_base_url( $kind );
                $prefix = trailingslashit( (string) wp_parse_url( $base_url, PHP_URL_PATH ) );
                if ( 0 !== strpos( $path, $prefix ) ) { continue; }
                $relative = rawurldecode( substr( $path, strlen( $prefix ) ) );
                $candidate = realpath( trailingslashit( $root ) . $relative ); $resolved_root = realpath( $root );
                if ( $candidate && $resolved_root && 0 === strpos( wp_normalize_path( $candidate ), trailingslashit( wp_normalize_path( $resolved_root ) ) ) ) { $disk = $candidate; break; }
            }
        }
        if ( ! $disk || ! is_file( $disk ) || 'css' !== strtolower( pathinfo( $disk, PATHINFO_EXTENSION ) ) || isset( $this->visited_css[$disk] ) ) { return; }
        if ( count( $this->visited_css ) >= 32 || filesize( $disk ) > 1048576 || $this->css_bytes + filesize( $disk ) > 8388608 ) { $this->limited = true; return; }
        $this->visited_css[$disk] = true; $this->css_bytes += filesize( $disk );
        $this->inspect_css( (string) file_get_contents( $disk ), $css_base );
    }

    private static function asset_base_url( $kind ) {
        if ( 't' === $kind ) { return get_stylesheet_directory_uri(); }
        if ( 'p' === $kind ) { return get_template_directory_uri(); }
        if ( 'u' === $kind ) { return wp_upload_dir()['baseurl']; }
        return 'c' === $kind ? includes_url() : plugins_url();
    }

    public function inspect_css( $css, $base ) {
        $css = preg_replace( '~/\*[\s\S]*?\*/~', '', $css );
        preg_match_all( '~url\(\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s)]+))\s*\)|@import\s+["\x27]([^"\x27]+)["\x27]~i', $css, $matches, PREG_SET_ORDER );
        foreach ( $matches as $match ) {
            $value = $match[1] ?: ( $match[2] ?: ( $match[3] ?? '' ) );
            if ( isset( $match[4] ) && '' !== $match[4] ) { $value = $match[4]; }
            $this->inspect_url( $value, $base, 'css-resource', true );
        }
    }

    public function inspect_html( $html, $base ) {
        $processor = new \WP_HTML_Tag_Processor( $html );
        while ( $processor->next_tag() ) {
            $tag = $processor->get_tag();
            if ( 'A' === $tag ) {
                $url = \WP_Http::make_absolute_url( (string) $processor->get_attribute( 'href' ), $base );
                if ( self::external_url( $url ) && count( $this->navigation ) < 200 ) { $this->navigation[self::public_reference( $url )] = true; }
                continue;
            }
            if ( 'LINK' === $tag ) {
                $rel = preg_split( '/\s+/', strtolower( (string) $processor->get_attribute( 'rel' ) ) );
                if ( array_intersect( $rel, array( 'stylesheet', 'preload', 'modulepreload', 'preconnect', 'dns-prefetch', 'icon' ) ) ) {
                    $this->inspect_url( $processor->get_attribute( 'href' ), $base, in_array( 'stylesheet', $rel, true ) ? 'stylesheet' : 'resource-hint', in_array( 'stylesheet', $rel, true ) );
                }
            }
            if ( in_array( $tag, array( 'SCRIPT', 'IMG', 'SOURCE', 'VIDEO', 'AUDIO', 'IFRAME', 'EMBED', 'INPUT' ), true ) ) { $this->inspect_url( $processor->get_attribute( 'src' ), $base, strtolower( $tag ) ); }
            if ( 'OBJECT' === $tag ) { $this->inspect_url( $processor->get_attribute( 'data' ), $base, 'object' ); }
            if ( 'FORM' === $tag ) { $this->inspect_url( $processor->get_attribute( 'action' ), $base, 'form-service' ); }
            $this->inspect_url( $processor->get_attribute( 'poster' ), $base, 'poster' );
            $srcset = $processor->get_attribute( 'srcset' );
            if ( is_string( $srcset ) && false === stripos( $srcset, 'data:' ) ) { foreach ( explode( ',', $srcset ) as $candidate ) { $this->inspect_url( preg_split( '/\s+/', trim( $candidate ) )[0], $base, 'srcset' ); } }
            $style = $processor->get_attribute( 'style' );
            if ( is_string( $style ) ) { $this->inspect_css( $style, $base ); }
        }
        preg_match_all( '~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles );
        foreach ( $styles[1] as $css ) { $this->inspect_css( $css, $base ); }
    }

    public function dependency_report() {
        return array( 'dependencies' => array_values( $this->dependencies ), 'external_navigation_count' => count( $this->navigation ), 'local_stylesheets_checked' => count( $this->visited_css ), 'limited' => $this->limited, 'scope' => 'HTML e CSS local; requisições montadas em JavaScript exigem conferência no navegador.' );
    }

    /** O hash considera os bytes finais, depois da reescrita de URLs e dos comentários. */
    public static function refresh_integrity( $html, $archive_dir ) {
        $processor = new \WP_HTML_Tag_Processor( $html );
        while ( $processor->next_tag() ) {
            $tag = $processor->get_tag();
            if ( 'SCRIPT' !== $tag && ! ( 'LINK' === $tag && in_array( 'stylesheet', preg_split( '/\s+/', strtolower( (string) $processor->get_attribute( 'rel' ) ) ), true ) ) ) { continue; }
            $url = $processor->get_attribute( 'SCRIPT' === $tag ? 'src' : 'href' );
            if ( ! is_string( $url ) ) { continue; }
            $path = wp_parse_url( $url, PHP_URL_PATH );
            if ( ! is_string( $path ) || ! preg_match( '~(?:^|/)v/([A-Za-z0-9_-]+(?:\.(?:css|js|mjs))?)$~D', $path, $match ) ) { continue; }
            // Apenas arquivos já materializados pelo plugin, nunca o destino indicado por uma URL remota.
            $expected_url = Static_Engine::get_instance()->apply_url_replacement_mode( home_url( '/v/' . $match[1] ), 'index.html' );
            if ( wp_parse_url( $url, PHP_URL_HOST ) && $url !== $expected_url ) { continue; }
            $file = trailingslashit( $archive_dir ) . 'v/' . $match[1];
            if ( ! is_file( $file ) || is_link( $file ) ) { continue; }
            $processor->set_attribute( 'integrity', 'sha384-' . base64_encode( hash_file( 'sha384', $file, true ) ) );
            $processor->set_attribute( 'crossorigin', 'anonymous' );
        }
        return $processor->get_updated_html();
    }

    public static function finalize_integrity( $archive_dir ) {
        if ( ! Options::instance()->get( 'refresh_local_integrity', true ) ) { return; }
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $archive_dir, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( $file->isLink() || ! $file->isFile() || 'html' !== strtolower( $file->getExtension() ) ) { continue; }
            $before = (string) file_get_contents( $file->getPathname() );
            $after = self::refresh_integrity( $before, $archive_dir );
            if ( $after !== $before ) { Static_Engine::get_instance()->write_export_file( $file->getPathname(), $after ); }
        }
    }

    public static function latest( $run_if_missing = false ) {
        $report = get_option( self::REPORT_OPTION, array() );
        return $run_if_missing && ( ! is_array( $report ) || ( $report['version'] ?? '' ) !== GAMB_STATIC_WP_VERSION ) ? self::run() : $report;
    }

    public static function run() {
        $checks = array(); $audit = new self();
        $add = static function( $id, $status, $message ) use ( &$checks ) { $checks[] = array( 'id' => $id, 'status' => $status, 'message' => $message ); };
        $hash_available = function_exists( 'hash_hmac' ) && in_array( 'sha256', hash_algos(), true );
        $add( 'crypto', $hash_available ? 'ok' : 'error', $hash_available ? 'HMAC SHA-256 disponível para validação dos tokens.' : 'HMAC SHA-256 indisponível; os tokens de recursos não podem ser validados.' );
        $add( 'zip', class_exists( '\ZipArchive' ) ? 'ok' : 'warning', class_exists( '\ZipArchive' ) ? 'Extensão ZIP disponível.' : 'ZIP nativo indisponível: a exportação estática usa a alternativa do WordPress; o backup de banco com tema e extensões requer ZIP.' );
        $types = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );
        $add( 'post_types', 'ok', 'Tipos públicos disponíveis: ' . implode( ', ', $types ) . '.' );
        $add( 'first_export', 'ok', Options::instance()->get( 'first_full_export_completed', false ) ? 'Primeira publicação completa já concluída.' : 'A primeira publicação será completa; os outros escopos serão liberados após a entrega.' );
        if ( Options::instance()->get( 'auto_export', true ) && defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) { $add( 'cron', 'warning', 'O agendador por visitas está desabilitado. A atualização automática requer agendador externo configurado.' ); }
        foreach ( array( 'home' => home_url( '/' ), 'error' => home_url( '/gamb-static-guard-missing-page-404/' ) ) as $kind => $url ) {
            $response = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2097152, 'headers' => array( 'X-Gamb-Static-Export' => Frontend_Policy::export_context_token() ) ) );
            if ( is_wp_error( $response ) ) { $add( $kind, 'error', 'Não foi possível consultar a origem: ' . $kind . '. Verifique o acesso HTTP local.' ); continue; }
            $status = (int) wp_remote_retrieve_response_code( $response );
            $valid = Static_Engine::get_instance()->capture_response_is_valid( $url, $status, wp_remote_retrieve_header( $response, 'x-gamb-static-context' ), 'error' === $kind );
            $add( $kind, $valid ? 'ok' : 'error', ( 'home' === $kind ? 'Home' : 'Página de erro' ) . ': HTTP ' . $status . ( $valid ? ', contexto WordPress confirmado.' : ', contexto incompatível com a publicação.' ) );
            $html = (string) wp_remote_retrieve_body( $response );
            if ( strlen( $html ) >= 2097152 ) { $audit->limited = true; }
            $audit->inspect_html( $html, $url );
            if ( 'home' === $kind ) {
                $processor = new \WP_HTML_Tag_Processor( $html ); $canonical_count = 0;
                while ( $processor->next_tag( 'LINK' ) ) { if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( (string) $processor->get_attribute( 'rel' ) ) ), true ) ) { $canonical_count++; } }
                $add( 'canonical', 1 === $canonical_count ? 'ok' : 'warning', 1 === $canonical_count ? 'Canonical da Home presente.' : ( 0 === $canonical_count && Options::instance()->get( 'ensure_home_canonical', true ) ? 'Canonical ausente na origem; será acrescentado à Home exportada.' : 'Revise os canonicals da Home no tema ou na extensão de SEO.' ) );
            }
        }
        $has_theme_error = (bool) locate_template( '404.php' );
        $add( 'error_template', $has_theme_error ? 'ok' : 'warning', $has_theme_error ? 'O modelo 404 do tema será preservado.' : 'Temas clássicos sem modelo 404 recebem o modelo de apoio quando usam o fallback index.php. Modelos de blocos e de outras extensões são preservados.' );
        $dependencies = $audit->dependency_report();
        $add( 'dependencies', empty( $dependencies['dependencies'] ) && ! $dependencies['limited'] ? 'ok' : 'warning', count( $dependencies['dependencies'] ) . ' dependência(s) externa(s) identificada(s). Links de navegação são contabilizados separadamente. ' . ( $dependencies['limited'] ? 'O limite de inspeção foi atingido.' : '' ) );
        $report = array( 'version' => GAMB_STATIC_WP_VERSION, 'checked_at' => current_time( 'mysql' ), 'checks' => $checks, 'resources' => $dependencies );
        update_option( self::REPORT_OPTION, $report, false );
        return $report;
    }
}
