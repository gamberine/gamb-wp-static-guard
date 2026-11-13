<?php
namespace GambWPStaticGuard;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Controles de apresentação, condicionados ao perfil selecionado no site. */
class Frontend_Policy {
    public static function register() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'remove_unused_styles' ), 999 );
        add_filter( 'body_class', array( __CLASS__, 'body_classes' ), 999 );
        add_filter( 'wp_img_tag_add_auto_sizes', array( __CLASS__, 'auto_sizes' ) );
        add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'speculation' ) );
        add_filter( 'run_wptexturize', array( __CLASS__, 'texturize' ) );
        add_filter( 'style_loader_src', array( __CLASS__, 'asset_url' ), 999 );
        add_filter( 'script_loader_src', array( __CLASS__, 'asset_url' ), 999 );
    }
    private static function active( $key ) {
        return ! is_admin() && Options::instance()->get( 'guard_active_on_live_site', true ) && Options::instance()->get( $key, false );
    }
    public static function remove_unused_styles() {
        if ( ! self::active( 'guard_remove_block_styles' ) ) { return; }
        foreach ( array( 'wp-img-auto-sizes-contain', 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles', 'core-block-supports' ) as $handle ) {
            wp_dequeue_style( $handle );
            wp_deregister_style( $handle );
        }
        remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
        remove_action( 'wp_footer', 'wp_enqueue_global_styles' );
        remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
    }
    public static function body_classes( $classes ) {
        if ( ! self::active( 'guard_clean_body_classes' ) ) { return $classes; }
        return array_values( array_filter( $classes, static function( $class ) {
            return ! preg_match( '/^(?:wp-|page(?:-|$)|postid-|post-template|post-type-|type-|status-|tax-|term-|tag(?:-|$)|category(?:-|$)|paged(?:-|$)|page-template|single(?:-|$)|singular$|archive$|home$|blog$|logged-in$|admin-bar$|hfeed$|hentry$|customize-support$|theme-)/', $class );
        } ) );
    }
    public static function auto_sizes( $value ) { return self::active( 'guard_remove_block_styles' ) ? false : $value; }
    public static function speculation( $value ) { return self::active( 'guard_disable_speculation' ) ? null : $value; }
    public static function texturize( $value ) { return self::active( 'guard_disable_texturize' ) ? false : $value; }
    public static function asset_url( $url ) { if ( ! is_string( $url ) || '' === $url ) { return $url; } return self::active( 'guard_strip_asset_versions' ) ? remove_query_arg( 'ver', $url ) : $url; }

    /** A exportação interrompe a entrega se ainda depender de uma rota interna não resolvida. */
    public static function postprocess_html( $html ) {
        $guard = Gamb_Security_Suite::get_instance();
        $html = preg_replace( '~<script\b[^>]*type=["\x27]speculationrules["\x27][^>]*>.*?</script>~is', '', $html );
        $html = preg_replace( '~<script\b[^>]*src=["\x27][^"\x27]*wp-embed[^"\x27]*["\x27][^>]*>.*?</script>~is', '', $html );
        $html = preg_replace_callback( '~<style\b[^>]*>.*?</style>~is', static function( $match ) {
            return preg_replace( '~/\*[#@]\s*source(?:Mapping)?URL=.*?\*/~is', '', $match[0] );
        }, $html );
        if ( Options::instance()->get( 'hide_comments', true ) ) {
            $html = preg_replace( '~<!--(?!\s*\[if).*?-->~s', '', $html );
        }
        $html = preg_replace_callback( '~\baria-label=(["\x27])(.*?)\1~is', static function( $match ) {
            return 'aria-label=' . $match[1] . str_replace( array( '—', '–', '&#8211;', '&#8212;', '&mdash;', '&ndash;' ), '-', $match[2] ) . $match[1];
        }, $html );
        $html = $guard->rewrite_wp_paths_to_tokens( $html );
        $guard->register_tokens_in_content( $html );
        if ( Options::instance()->get( 'guard_strict_static_html', true ) ) {
            $normalized = str_replace( '\\/', '/', html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( preg_match( '~/(?:wp-content|wp-includes|wp-admin|wp-json)(?:/|[?"\x27\s<])|wp-embed~i', $normalized ) ) {
                throw new \RuntimeException( 'A validação encontrou uma referência interna não resolvida. A entrega foi interrompida; consulte o registro e revise os recursos da origem.' );
            }
        }
        return $html;
    }
}
