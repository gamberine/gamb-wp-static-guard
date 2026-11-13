<?php

namespace GambWPStaticGuard;

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Gamb WP Static Guard - 2. GUARD (Suíte de Ocultação, Tokenização Criptográfica, Blindagem e Sanitização DOM).
 */
class Gamb_Security_Suite {

	const PROFILE_VERSION = "4.0.0";
	const OPTION_FLAG     = "gamb_wp_static_guard_profile_version";

	private static $instance = null;

	/** @var array<string,array{disk:string,ext:string,kind:string,rel:string}> Tokens registrados para materialização estática */
	private $export_tokens = array();
	private $export_directories = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->enforce_origin_hardening();
		$this->maybe_auto_configure_options();

		// Interceptador de alta prioridade para servir /v/<token> e /v/d/<token>/... no site dinâmico
		add_action( "init", array( $this, "maybe_serve_cloaked_asset" ), -9999 );

		// Buffer de saída para aplicar ocultação e sanitização DOM em tempo real no site público
		add_action( "template_redirect", array( $this, "start_dynamic_html_cloaking_buffer" ), -9999 );
	}

	public static function get_cloak_key() {
		$salt = defined( "AUTH_KEY" ) && "" !== AUTH_KEY ? AUTH_KEY : ABSPATH;
		return hash( "sha256", $salt . "|gamb_wp_static_guard_v3", true );
	}

	public static function encrypt_token( $kind, $rel_path ) {
		$rel_path   = ltrim( str_replace( "\\", "/", (string) $rel_path ), "/" );
		$clean_path = explode( "#", explode( "?", $rel_path, 2 )[0], 2 )[0];
		$payload    = $kind . ":" . $clean_path;
		$key        = self::get_cloak_key();
		$iv         = substr( hash_hmac( "sha256", $payload, $key, true ), 0, 6 );
		$len        = strlen( $payload );
		$stream     = "";
		$block      = 0;
		while ( strlen( $stream ) < $len ) {
			$stream .= hash( "sha256", $key . $iv . pack( "N", $block ), true );
			$block++;
		}
		$cipher = $payload ^ substr( $stream, 0, $len );
		return rtrim( strtr( base64_encode( $iv . $cipher ), "+/", "-_" ), "=" );
	}

	public static function decrypt_token( $token, $subpath = "" ) {
		$token = preg_replace( "/[^A-Za-z0-9_-]/", "", (string) $token );
		if ( strlen( $token ) < 8 ) {
			return null;
		}
		$padding = strlen( $token ) % 4;
		if ( $padding > 0 ) {
			$token .= str_repeat( "=", 4 - $padding );
		}
		$raw = base64_decode( strtr( $token, "-_", "+/" ), true );
		if ( false === $raw || strlen( $raw ) < 8 ) {
			return null;
		}

		$iv     = substr( $raw, 0, 6 );
		$cipher = substr( $raw, 6 );
		$len    = strlen( $cipher );
		$key    = self::get_cloak_key();
		$stream = "";
		$block  = 0;
		while ( strlen( $stream ) < $len ) {
			$stream .= hash( "sha256", $key . $iv . pack( "N", $block ), true );
			$block++;
		}
		$payload = $cipher ^ substr( $stream, 0, $len );

		$expected_iv = substr( hash_hmac( "sha256", $payload, $key, true ), 0, 6 );
		if ( ! hash_equals( $expected_iv, $iv ) ) {
			return null;
		}

		if ( strpos( $payload, ":" ) !== 1 ) {
			return null;
		}
		$kind     = substr( $payload, 0, 1 );
		$rel_path = ltrim( substr( $payload, 2 ), "/\\" );
		if ( "d" === $kind && "" !== $subpath ) {
			$rel_path = rtrim( $rel_path, "/\\" ) . "/" . ltrim( $subpath, "/\\" );
		}
		if ( "" === $rel_path || strpos( $rel_path, ".." ) !== false || ! self::is_public_asset( $rel_path ) ) {
			return null;
		}

		$base_dir = self::get_base_dir_for_kind( $kind );
		if ( ! $base_dir ) {
			return null;
		}

		$real_base = realpath( $base_dir );
		$candidate = realpath( trailingslashit( $base_dir ) . $rel_path );
		if ( false === $real_base || false === $candidate || ! is_file( $candidate ) ) {
			return null;
		}
		if ( 0 !== strpos( str_replace( "\\", "/", $candidate ), str_replace( "\\", "/", $real_base ) . "/" ) ) {
			return null;
		}

		return array(
			"kind" => $kind,
			"rel"  => $rel_path,
			"disk" => $candidate,
		);
	}

	public static function is_public_asset( $relative ) {
		$relative = str_replace( "\\", "/", (string) $relative );
		if ( preg_match( '~(?:^|/)(?:\.[^/]*|gamb-static-guard-(?:backups|archive)|backups?(?:[-_][^/]*)?)(?:/|$)~i', $relative ) ) { return false; }
		if ( preg_match( '/\.(?:php[0-9]*|phtml|phar)(?:\.|$)/i', basename( $relative ) ) ) { return false; }
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'css', 'js', 'mjs', 'json', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff2', 'woff', 'ttf', 'eot', 'otf', 'pdf', 'mp4', 'webm', 'doc', 'docx', 'xls', 'xlsx', 'zip' ), true );
	}

	public static function get_base_dir_for_kind( $kind ) {
		if ( "t" === $kind ) {
			return get_stylesheet_directory();
		}
		if ( "p" === $kind ) {
			return get_template_directory();
		}
		if ( "u" === $kind ) {
			$uploads = wp_upload_dir();
			return isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
		}
		if ( "c" === $kind ) {
			return ABSPATH . ( defined( "WPINC" ) ? WPINC : "wp-includes" );
		}
		if ( "x" === $kind || "d" === $kind ) {
			return defined( "WP_PLUGIN_DIR" ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . "/plugins" );
		}
		return null;
	}

	public static function normalize_rel_path( $path ) {
		$path  = str_replace( "\\", "/", (string) $path );
		$parts = explode( "/", $path );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( "" === $part || "." === $part ) {
				continue;
			}
			if ( ".." === $part ) {
				array_pop( $out );
			} else {
				$out[] = $part;
			}
		}
		return implode( "/", $out );
	}

	public function maybe_serve_cloaked_asset() {
		if ( empty( $_SERVER["REQUEST_URI"] ) ) {
			return;
		}
		$req_uri  = (string) $_SERVER["REQUEST_URI"];
		$req_path = (string) parse_url( $req_uri, PHP_URL_PATH );
		if ( "" === $req_path ) {
			return;
		}

		$home_path = rtrim( (string) parse_url( home_url( "/" ), PHP_URL_PATH ), "/" );
		if ( "" !== $home_path && 0 === strpos( $req_path, $home_path . "/" ) ) {
			$req_path = substr( $req_path, strlen( $home_path ) );
		}

		if ( preg_match( "#^/v/d/([A-Za-z0-9_-]+)/(.+)$#", $req_path, $m ) ) {
			$info = self::decrypt_token( $m[1], $m[2] );
			if ( $info ) {
				$this->stream_static_asset( $info );
			}
		}

		if ( preg_match( "#^/v/([A-Za-z0-9_-]+?)(?:\\.[a-z0-9]{2,5})?$#i", $req_path, $m ) ) {
			$info = self::decrypt_token( $m[1] );
			if ( $info ) {
				$this->stream_static_asset( $info );
			}
		}
	}

	private function stream_static_asset( $info ) {
		// An earlier BOM or plugin output must never prefix a binary image/font response.
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { break; }
		}
		$file_path = $info["disk"];
		$ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( "php", "phtml", "phar", "inc", "sql", "env", "log", "ini", "sh" ), true ) ) {
			status_header( 404 );
			exit;
		}

		$mime_map = array(
			"css"   => "text/css; charset=UTF-8",
			"js"    => "application/javascript; charset=UTF-8",
			"mjs"   => "application/javascript; charset=UTF-8",
			"json"  => "application/json; charset=UTF-8",
			"jpg"   => "image/jpeg",
			"jpeg"  => "image/jpeg",
			"png"   => "image/png",
			"gif"   => "image/gif",
			"webp"  => "image/webp",
			"avif"  => "image/avif",
			"svg"   => "image/svg+xml",
			"ico"   => "image/x-icon",
			"woff2" => "font/woff2",
			"woff"  => "font/woff",
			"ttf"   => "font/ttf",
			"eot"   => "application/vnd.ms-fontobject",
			"otf"   => "font/otf",
			"mp4"   => "video/mp4",
			"webm"  => "video/webm",
			"pdf"   => "application/pdf",
		);
		$content_type = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : "application/octet-stream";

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( "Content-Type: " . $content_type );
			header( "Cache-Control: public, max-age=0, must-revalidate" );
			header( "X-Content-Type-Options: nosniff" );
			@header_remove( "X-Powered-By" );
			@header_remove( "Server" );
			@header_remove( "X-Pingback" );
		}

		if ( "css" === $ext ) {
			$css = (string) @file_get_contents( $file_path );
			echo $this->cloak_css_content( $css, $info["kind"], $info["rel"] );
			exit;
		}

		if ( "js" === $ext || "mjs" === $ext ) {
			$js = (string) @file_get_contents( $file_path );
			echo $this->sanitize_js_content( $js );
			exit;
		}

		@readfile( $file_path );
		exit;
	}

	public function sanitize_js_content( $js ) {
		if ( ! is_string( $js ) || "" === $js ) {
			return $js;
		}
		if ( Options::instance()->get( "guard_hide_theme_identity", true ) ) {
			$js = preg_replace( "#^\\s*(?:/\\*[\\s\\S]*?\\*/\\s*)+#", "", $js );
			$js = preg_replace( "~//[#@][ \\t]*source(?:Mapping)?URL=[^\\r\\n]*~", "", $js );
		}
		return $js;
	}

	public function cloak_css_content( $css, $kind = "t", $css_rel = "style.css" ) {
		if ( ! is_string( $css ) || "" === $css ) {
			return $css;
		}
		$opts = Options::instance();
		if ( $opts->get( "guard_hide_theme_identity", true ) ) {
			$css = preg_replace( "#/\\*[\\s\\S]*?\\*/#", "", $css );
		}
		if ( ! $opts->get( "guard_universal_token_cloaking", true ) ) {
			return $css;
		}

		$css = $this->rewrite_wp_paths_to_tokens( $css );
		$this->register_tokens_in_content( $css );

		$css_dir = dirname( str_replace( "\\", "/", $css_rel ) );
		if ( "." === $css_dir ) {
			$css_dir = "";
		}
		$base_dir = self::get_base_dir_for_kind( $kind );

		$css = preg_replace_callback(
			"#url\\(\\s*([\x27\"]?)([^)\x27\"]+)\\1\\s*\\)#i",
			function ( $m ) use ( $kind, $css_dir, $base_dir ) {
				$quote   = $m[1];
				$raw_url = trim( $m[2] );
				if (
					"" === $raw_url ||
					0 === strpos( $raw_url, "data:" ) ||
					0 === strpos( $raw_url, "#" ) ||
					0 === strpos( $raw_url, "/v/" ) ||
					preg_match( "#^(?:https?:)?//#i", $raw_url )
				) {
					return $m[0];
				}

				$clean_url = explode( "#", explode( "?", $raw_url, 2 )[0], 2 )[0];
				$combined  = ( "" !== $css_dir ? $css_dir . "/" : "" ) . ltrim( $clean_url, "/" );
				$norm_rel  = self::normalize_rel_path( $combined );
				if ( "" === $norm_rel ) {
					return $m[0];
				}

				$tok = $this->create_and_record_token( $kind, $norm_rel, $base_dir );
				return "" !== $tok ? "url(" . $quote . home_url( "/v/" . $tok ) . $quote . ")" : $m[0];
			},
			$css
		);

		return $css;
	}

	public function start_dynamic_html_cloaking_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( "REST_REQUEST" ) && REST_REQUEST ) ) {
			return;
		}
		$opts = Options::instance();
		if ( $opts->get( "guard_isolate_auth_cache", true ) && is_user_logged_in() ) {
			if ( ! defined( "DONOTCACHEPAGE" ) ) {
				define( "DONOTCACHEPAGE", true );
			}
			if ( ! headers_sent() ) {
				header( "X-cache de páginas-Cache-Control: private, no-store" );
				header( "Cache-Control: private, no-store, no-cache, must-revalidate" );
			}
		}
		if ( $opts->get( "guard_active_on_live_site", true ) ) {
			ob_start( array( $this, "cloak_html_content" ) );
		}
	}

	public function cloak_html_content( $html ) {
		if ( ! is_string( $html ) || "" === $html || strpos( $html, "<" ) === false ) {
			return $html;
		}
		$opts = Options::instance();

		// 1. Remove scripts administrativos de polling caso algum tema/plugin tenha injetado
		$html = preg_replace(
			"#<script\\b[^>]*\\bid=[\x27\"]ss-(?:export-progress-title-js|admin-bar-inline-js)[\x27\"][^>]*>[\\s\\S]*?</script>#i",
			"",
			$html
		);

		// 2. Remove comentários HTML (incluindo assinaturas cache de páginas)
		if ( $opts->get( "hide_comments", true ) ) {
			$html = preg_replace( "#<!--(?!\\s*\\[if|SS_MIN_BLOCK_)[\\s\\S]*?-->#", "", $html );
		}

		// 3. Remove //# sourceURL=..., /* document.write */ e IDs de <link>/<script> que revelam o WordPress/tema
		if ( $opts->get( "guard_hide_language_stack", true ) ) {
			$html = preg_replace( "#//\#[ \\t]*sourceURL=[^\\s<\"\x27]+#", "", $html );
			$html = str_replace( array( "/* document.write */ ", "/* document.write */" ), "", $html );
			$html = preg_replace( "#(<link\\b[^>]*?)\\s+id=[\x27\"][^\x27\"]+-css[\x27\"]#i", "$1", $html );
			$html = preg_replace( "#(<script\\b[^>]*?)\\s+id=[\x27\"][^\x27\"]+-js(?:-(?:after|before|extra))?[\x27\"]#i", "$1", $html );
			$html = preg_replace( "#\\s+data-gt-orig-domain=[\x27\"][^\x27\"]*[\x27\"]#i", "", $html );
		}

		// 4. Garante <html lang="pt-BR"> na tag raiz (Auditoria 3.3)
		if ( $opts->get( "guard_dom_ensure_html_lang", true ) ) {
			$site_lang = function_exists( "get_bloginfo" ) ? get_bloginfo( "language" ) : "pt-BR";
			if ( "" === $site_lang ) {
				$site_lang = "pt-BR";
			}
			$html = preg_replace_callback(
				"#<html\\b([^>]*)>#i",
				function ( $m ) use ( $site_lang ) {
					if ( stripos( $m[1], "lang=" ) !== false ) {
						return $m[0];
					}
					return "<html lang=\"" . esc_attr( $site_lang ) . "\"" . $m[1] . ">";
				},
				$html,
				1
			);
		}

		// 5. Funde atributos class="..." duplicados na mesma tag (Auditoria 3.2)
		if ( $opts->get( "guard_dom_merge_duplicate_class", true ) ) {
			$html = preg_replace_callback(
				"#<([a-zA-Z0-9:-]+)\\b([^>]*\\bclass=[\x27\"][^\x27\"]*[\x27\"][^>]*\\bclass=[\x27\"][^\x27\"]*[\x27\"][^>]*)>#i",
				function ( $m ) {
					$tag     = $m[1];
					$attrs   = $m[2];
					$classes = array();
					$cleaned = preg_replace_callback(
						"#\\s*\\bclass=([\x27\"])([^\x27\"]*)\\1#i",
						function ( $cm ) use ( &$classes ) {
							foreach ( preg_split( "/\\s+/", trim( $cm[2] ) ) as $c ) {
								if ( "" !== $c && ! in_array( $c, $classes, true ) ) {
									$classes[] = $c;
								}
							}
							return "";
						},
						$attrs
					);
					return "<" . $tag . " class=\"" . implode( " ", $classes ) . "\"" . $cleaned . ">";
				},
				$html
			);
		}

		// 6. Remove classes logged-in, admin-bar e theme-* do <body> (Auditoria 1.1)
		if ( $opts->get( "guard_isolate_auth_cache", true ) || $opts->get( "guard_hide_theme_identity", true ) ) {
			$stylesheet_slug = get_stylesheet();
			$template_slug   = get_template();
			$html = preg_replace_callback(
				"#<body\\b([^>]*)>#i",
				function ( $m ) use ( $stylesheet_slug, $template_slug ) {
					return preg_replace_callback(
						"#\\bclass=([\x27\"])([^\x27\"]*)\\1#i",
						function ( $cm ) use ( $stylesheet_slug, $template_slug ) {
							$tokens = preg_split( "/\\s+/", trim( $cm[2] ) );
							$keep   = array();
							foreach ( $tokens as $t ) {
								if (
									"" === $t ||
									in_array( $t, array( "logged-in", "admin-bar", "customize-support", "wp-embed-responsive", "wp-custom-logo" ), true ) ||
									$t === "theme-" . $stylesheet_slug ||
									$t === "theme-" . $template_slug
								) {
									continue;
								}
								$keep[] = $t;
							}
							return "class=" . $cm[1] . implode( " ", $keep ) . $cm[1];
						},
						$m[0]
					);
				},
				$html,
				1
			);
		}

		// 7. Desduplica IDs repetidos no mesmo documento HTML (Auditoria 3.1)
		$seen_ids = array();
		if ( $opts->get( "guard_dom_deduplicate_ids", true ) ) {
			$html = preg_replace_callback(
				"#(<[a-zA-Z0-9:-]+\\b[^>]*?\\bid=([\x27\"]))([^\x27\"]+)(\\2[^>]*>)#i",
				function ( $m ) use ( &$seen_ids ) {
					$id_val = $m[3];
					if ( ! isset( $seen_ids[ $id_val ] ) ) {
						$seen_ids[ $id_val ] = 1;
						return $m[0];
					}
					$seen_ids[ $id_val ]++;
					return $m[1] . $id_val . "-" . $seen_ids[ $id_val ] . $m[4];
				},
				$html
			);
		}

		// 8. Corrige aria-labelledby órfãos em modais (Auditoria 3.3)
		if ( $opts->get( "guard_dom_fix_orphan_aria", true ) ) {
			$html = preg_replace_callback(
				"#\\s+aria-labelledby=([\x27\"])([^\x27\"]+)\\1#i",
				function ( $m ) use ( $seen_ids ) {
					$ref_id = trim( $m[2] );
					if ( ! empty( $seen_ids ) && isset( $seen_ids[ $ref_id ] ) ) {
						return $m[0];
					}
					return " aria-label=\"Modal\"";
				},
				$html
			);
		}

		// 9. Força rel="noopener noreferrer" em todos os links target="_blank" (Auditoria 3.4)
		if ( $opts->get( "guard_dom_noopener_blank", true ) ) {
			$html = preg_replace_callback(
				"#<a\\b([^>]*\\btarget=[\x27\"]_blank[\x27\"][^>]*)>#i",
				function ( $m ) {
					$attrs = $m[1];
					if ( preg_match( "#\\brel=([\x27\"])([^\x27\"]*)\\1#i", $attrs, $rm ) ) {
						$rels = array_filter( preg_split( "/\\s+/", trim( $rm[2] ) ) );
						if ( ! in_array( "noopener", $rels, true ) ) {
							$rels[] = "noopener";
						}
						if ( ! in_array( "noreferrer", $rels, true ) ) {
							$rels[] = "noreferrer";
						}
						$new_rel = "rel=" . $rm[1] . implode( " ", $rels ) . $rm[1];
						$attrs   = preg_replace( "#\\brel=([\x27\"])([^\x27\"]*)\\1#i", $new_rel, $attrs, 1 );
						return "<a" . $attrs . ">";
					}
					return "<a" . $attrs . " rel=\"noopener noreferrer\">";
				},
				$html
			);
		}

		// 10. Garante alt="" e decoding="async" em tags <img> (Auditoria 3.3)
		if ( $opts->get( "guard_dom_img_accessibility", true ) ) {
			$html = preg_replace_callback(
				"#<img\\b([^>]*)>#i",
				function ( $m ) {
					$attrs    = $m[1];
					$self_cls = "";
					if ( substr( rtrim( $attrs ), -1 ) === "/" ) {
						$attrs    = rtrim( substr( rtrim( $attrs ), 0, -1 ) );
						$self_cls = " /";
					}
					if ( stripos( $attrs, "alt=" ) === false ) {
						$attrs .= " alt=\"\"";
					}
					if ( stripos( $attrs, "decoding=" ) === false ) {
						$attrs .= " decoding=\"async\"";
					}
					return "<img" . $attrs . $self_cls . ">";
				},
				$html
			);
		}

		// 11. Substituições personalizadas definidas pelo usuário
		$replacements = $opts->get( "custom_replacements", array() );
		if ( is_array( $replacements ) ) {
			foreach ( $replacements as $rule ) {
				if ( is_array( $rule ) && ! empty( $rule["search"] ) ) {
					$html = str_replace( (string) $rule["search"], isset( $rule["replace"] ) ? (string) $rule["replace"] : "", $html );
				}
			}
		}

		// 12. Converte todos os caminhos de tema, imagens, plugins e wp-includes para /v/<token_criptografado>
		if ( $opts->get( "guard_universal_token_cloaking", true ) ) {
			$html = $this->rewrite_wp_paths_to_tokens( $html );
		}

		return $html;
	}

	private function create_and_record_token( $kind, $rel_path, $base_dir = null ) {
		$clean_rel = ltrim( explode( "#", explode( "?", (string) $rel_path, 2 )[0], 2 )[0], "/\\" );
		if ( ! self::is_public_asset( $clean_rel ) ) { return ''; }
		$tok       = self::encrypt_token( $kind, $clean_rel );
		if ( null === $base_dir ) {
			$base_dir = self::get_base_dir_for_kind( $kind );
		}
		if ( $base_dir ) {
			$disk = trailingslashit( $base_dir ) . $clean_rel;
			$real_base = realpath( $base_dir );
			$real_file = realpath( $disk );
			if ( ! $real_base || ! $real_file || 0 !== strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_base ) ) ) ) { return ''; }
			if ( is_file( $disk ) ) {
				$ext = strtolower( pathinfo( $disk, PATHINFO_EXTENSION ) );
				$this->export_tokens[ $tok ] = array(
					"disk" => $disk,
					"ext"  => $ext,
					"kind" => $kind,
					"rel"  => $clean_rel,
				);
			}
		}
		return $tok;
	}

	public function rewrite_wp_paths_to_tokens( $text ) {
		$host            = (string) parse_url( home_url( "/" ), PHP_URL_HOST );
		$stylesheet_slug = get_stylesheet();
		$template_slug   = get_template();
		$uploads         = wp_upload_dir();
		$uploads_basedir = isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
		$theme_basedir   = get_stylesheet_directory();
		$parent_basedir  = get_template_directory();
		$inc_basedir     = ABSPATH . ( defined( "WPINC" ) ? WPINC : "wp-includes" );
		$plugin_basedir  = defined( "WP_PLUGIN_DIR" ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . "/plugins" );

		$origin = untrailingslashit( home_url() );
		$origin_prefix = "(?:" . preg_quote( $origin, "#" ) . "|" . preg_quote( (string) parse_url( $origin, PHP_URL_PATH ), "#" ) . ")?";

		if ( "" !== $stylesheet_slug ) {
			$text = preg_replace_callback(
				"#" . $origin_prefix . "/wp-content/themes/" . preg_quote( $stylesheet_slug, "#" ) . "/([^\"\x27\\s<>()\\\\]+)#i",
				function ( $m ) use ( $theme_basedir ) {
					$tok = $this->create_and_record_token( "t", $m[1], $theme_basedir );
					return "" !== $tok ? home_url( "/v/" . $tok ) : $m[0];
				},
				$text
			);
		}

		if ( "" !== $template_slug && $template_slug !== $stylesheet_slug ) {
			$text = preg_replace_callback(
				"#" . $origin_prefix . "/wp-content/themes/" . preg_quote( $template_slug, "#" ) . "/([^\"\x27\\s<>()\\\\]+)#i",
				function ( $m ) use ( $parent_basedir ) {
					$tok = $this->create_and_record_token( "p", $m[1], $parent_basedir );
					return "" !== $tok ? home_url( "/v/" . $tok ) : $m[0];
				},
				$text
			);
		}

		if ( Options::instance()->get( "guard_encrypt_image_paths", true ) ) {
			$text = preg_replace_callback(
				"#" . $origin_prefix . "/wp-content/uploads/([^\"\x27\\s<>()\\\\]+)#i",
				function ( $m ) use ( $uploads_basedir ) {
					$tok = $this->create_and_record_token( "u", $m[1], $uploads_basedir );
					return "" !== $tok ? home_url( "/v/" . $tok ) : $m[0];
				},
				$text
			);
		}

		$text = preg_replace_callback(
			"#" . $origin_prefix . "/wp-includes/([^\"\x27\\s<>()\\\\]+)#i",
			function ( $m ) use ( $inc_basedir ) {
				$tok = $this->create_and_record_token( "c", $m[1], $inc_basedir );
				return "" !== $tok ? home_url( "/v/" . $tok ) : $m[0];
			},
			$text
		);

		$text = preg_replace_callback(
			"#" . $origin_prefix . "/wp-content/plugins/([^\"\x27\\s<>()\\\\]+)#i",
			function ( $m ) use ( $plugin_basedir ) {
				$tok = $this->create_and_record_token( "x", $m[1], $plugin_basedir );
				return "" !== $tok ? home_url( "/v/" . $tok ) : $m[0];
			},
			$text
		);

		$text = preg_replace_callback(
			"#(?:https?:\\\\/\\\\/" . preg_quote( $host, "#" ) . ")?\\\\/wp-content\\\\/plugins\\\\/([a-zA-Z0-9._-]+(?:\\\\/[a-zA-Z0-9._-]+)*)\\\\/#i",
			function ( $m ) {
				$dir_rel = str_replace( "\\/", "/", $m[1] );
				$tok     = self::encrypt_token( "d", $dir_rel );
				$base = realpath( self::get_base_dir_for_kind( 'd' ) );
				$directory = $base ? realpath( $base . '/' . $dir_rel ) : false;
				if ( $directory && is_dir( $directory ) && 0 === strpos( wp_normalize_path( $directory ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
					$this->export_directories[$tok] = $directory;
				}
				return "\\/v\\/d\\/" . $tok . "\\/";
			},
			$text
		);

		return $text;
	}

	/** Re-register resources tokenized by the separate HTTP request serving a page. */
	public function register_tokens_in_content( $content ) {
		$content = str_replace( "\\/", "/", $content );
		preg_match_all( '#/v/([A-Za-z0-9_-]+)(?:\.[a-z0-9]+)?#i', $content, $matches );
		foreach ( array_unique( $matches[1] ) as $token ) {
			$info = self::decrypt_token( $token );
			if ( $info ) {
				$this->create_and_record_token( $info['kind'], $info['rel'] );
			}
		}
	}

	public function materialize_cloaked_assets_in_archive( $archive_dir = null ) {
		if ( null === $archive_dir ) {
			$archive_dir = Options::instance()->get_archive_dir();
		}
		if ( empty( $archive_dir ) || ! is_dir( $archive_dir ) || ! is_writable( $archive_dir ) ) {
			return 0;
		}
		$archive_dir = trailingslashit( $archive_dir );
		$v_dir       = $archive_dir . "v";
		@wp_mkdir_p( $v_dir );

		$processed = array();
		$queue     = $this->export_tokens;
		$count     = 0;

		while ( ! empty( $queue ) ) {
			$next_batch = array();
			foreach ( $queue as $tok => $info ) {
				if ( isset( $processed[ $tok ] ) ) {
					continue;
				}
				$processed[ $tok ] = true;
				$src_path = $info["disk"];
				if ( ! is_file( $src_path ) || ! self::is_public_asset( $info["rel"] ) ) {
					continue;
				}
				$ext = $info["ext"];
				if ( "css" === $ext ) {
					$raw_css     = (string) @file_get_contents( $src_path );
					$cloaked_css = $this->cloak_css_content( $raw_css, $info["kind"], $info["rel"] );
					$cloaked_css = Static_Engine::get_instance()->apply_url_replacement_mode( $cloaked_css, "v/" . $tok . ".css" );
					Static_Engine::get_instance()->write_export_file( $v_dir . "/" . $tok . ".css", $cloaked_css );
					Static_Engine::get_instance()->write_export_file( $v_dir . "/" . $tok, $cloaked_css );
					$count++;
					foreach ( $this->export_tokens as $new_tok => $new_info ) {
						if ( ! isset( $processed[ $new_tok ] ) ) {
							$next_batch[ $new_tok ] = $new_info;
						}
					}
				} elseif ( "js" === $ext || "mjs" === $ext ) {
					$raw_js   = (string) @file_get_contents( $src_path );
					$clean_js = $this->sanitize_js_content( $raw_js );
					$clean_js = Static_Engine::get_instance()->apply_url_replacement_mode( $clean_js, "v/" . $tok . ".js" );
					Static_Engine::get_instance()->write_export_file( $v_dir . "/" . $tok . ".js", $clean_js );
					Static_Engine::get_instance()->write_export_file( $v_dir . "/" . $tok, $clean_js );
					$count++;
				} else {
					if ( ! @copy( $src_path, $v_dir . "/" . $tok . ( "" !== $ext ? "." . $ext : "" ) ) ) { throw new \RuntimeException( "Falha ao copiar recurso tokenizado." ); }
					if ( ! @copy( $src_path, $v_dir . "/" . $tok ) ) { throw new \RuntimeException( "Falha ao copiar recurso tokenizado." ); }
					$count++;
				}
			}
			$queue = $next_batch;
		}

		foreach ( $this->export_directories as $token => $directory ) {
			$this->copy_dir_filtered( $directory, $v_dir . '/d/' . $token );
		}

		return $count;
	}

	/**
	 * Enfileira todos os arquivos do tema ativo (e tema pai) como tokens /v/<token>
	 * para a exportação rápida "Somente o Tema (Theme Only)".
	 */
	public function register_all_theme_assets_as_tokens() {
		$allowed_exts = array( "css", "js", "mjs", "json", "jpg", "jpeg", "png", "gif", "webp", "avif", "svg", "ico", "woff2", "woff", "ttf", "eot", "otf" );
		$roots = array( "t" => get_stylesheet_directory() );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$roots["p"] = get_template_directory();
		}

		foreach ( $roots as $kind => $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$base_len = strlen( rtrim( $dir, "/\\" ) ) + 1;
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $path => $info ) {
				if ( ! $info->isFile() || $info->isLink() ) {
					continue;
				}
				$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, $allowed_exts, true ) ) {
					continue;
				}
				$rel = str_replace( "\\", "/", substr( $path, $base_len ) );
				$this->create_and_record_token( $kind, $rel, $dir );
			}
		}
	}

	private function copy_dir_filtered( $src_dir, $dst_dir ) {
		if ( ! is_dir( $src_dir ) ) {
			return;
		}
		$allowed_exts = array( "css", "js", "mjs", "jpg", "jpeg", "png", "gif", "webp", "avif", "svg", "ico", "woff2", "woff", "ttf", "eot", "otf" );
		$base_len     = strlen( rtrim( $src_dir, "/\\" ) ) + 1;
		$iterator     = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $path => $info ) {
			if ( ! $info->isFile() || $info->isLink() ) {
				continue;
			}
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_exts, true ) ) {
				continue;
			}
			$rel  = str_replace( "\\", "/", substr( $path, $base_len ) );
			$dest = trailingslashit( $dst_dir ) . $rel;
			@wp_mkdir_p( dirname( $dest ) );
			@copy( $path, $dest );
		}
	}

	public function maybe_auto_configure_options() {
		// Atualizações não reaplicam nem sobrescrevem o perfil escolhido pelo administrador.
		if ( false === get_option( self::OPTION_FLAG, false ) ) {
			Options::instance()->save();
			add_option( self::OPTION_FLAG, self::PROFILE_VERSION, '', false );
		}
	}

	public static function apply_hardened_profile() {
		$defaults = Options::get_default_options();
		$current  = get_option( Options::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// Aplica todos os padrões seguros preservando credenciais de deploy caso já configuradas
		$preserve_keys = array(
			"local_dir",
			"github_user",
			"github_email",
			"github_personal_access_token",
			"github_repository",
			"github_branch",
			"github_folder_path",
			"github_webhook_url",
			"aws_region",
			"aws_access_key",
			"aws_access_secret",
			"aws_bucket",
			"aws_subdirectory",
			"aws_webhook_url",
			"ftp_host",
			"ftp_port",
			"ftp_user",
			"ftp_pass",
			"ftp_folder",
			"sftp_host",
			"sftp_port",
			"sftp_user",
			"sftp_pass",
			"sftp_folder",
			"webhook_url",
		);

		$merged = $defaults;
		foreach ( $preserve_keys as $pk ) {
			if ( ! empty( $current[ $pk ] ) ) {
				$merged[ $pk ] = $current[ $pk ];
			}
		}

		update_option( Options::OPTION_KEY, $merged );
		update_option( self::OPTION_FLAG, self::PROFILE_VERSION );
		Options::reinstance();

		self::maybe_install_mu_plugin();
		self::purge_page_caches();
	}

	public static function purge_page_caches() {
		if ( function_exists( "wp_cache_flush" ) ) {
			@wp_cache_flush();
		}
		do_action( "gamb_wp_static_guard_purge_page_caches" );
	}

	private function enforce_origin_hardening() {
		$opts = Options::instance();

		if ( ! defined( "DISALLOW_FILE_EDIT" ) ) {
			define( "DISALLOW_FILE_EDIT", true );
		}

		if ( $opts->get( "guard_security_headers", true ) ) {
			@ini_set( "session.cookie_httponly", "1" );
			if ( is_ssl() ) {
				@ini_set( "session.cookie_secure", "1" );
			}
			@ini_set( "session.cookie_samesite", "Lax" );
		}

		if ( $opts->get( "disable_xmlrpc", true ) ) {
			add_filter( "xmlrpc_enabled", "__return_false", 999 );
			add_filter( "xmlrpc_methods", "__return_empty_array", 999 );
		}

		if ( $opts->get( "guard_hide_language_stack", true ) ) {
			add_filter( "x_redirect_by", "__return_false", 999 );
			remove_action( "template_redirect", "rest_output_link_header", 11 );
			remove_action( "template_redirect", "wp_shortlink_header", 11 );
			remove_action( "wp_head", "rest_output_link_wp_head", 10 );
			remove_action( "wp_head", "wp_shortlink_wp_head", 10 );
		}

		if ( $opts->get( "hide_generator", true ) ) {
			remove_action( "wp_head", "wp_generator" );
			add_filter( "the_generator", "__return_empty_string" );
		}
		if ( $opts->get( "hide_rsd", true ) ) {
			remove_action( "wp_head", "rsd_link" );
		}
		if ( $opts->get( "disable_wlw_manifest", true ) ) {
			remove_action( "wp_head", "wlwmanifest_link" );
		}
		if ( $opts->get( "hide_prefetch", true ) ) {
			add_filter( 'wp_resource_hints', static function( $hints, $relation ) { return 'dns-prefetch' === $relation ? array() : $hints; }, 10, 2 );
		}
		if ( $opts->get( "hide_emotes", true ) ) {
			remove_action( "wp_head", "print_emoji_detection_script", 7 );
			remove_action( "wp_print_styles", "print_emoji_styles" );
		}
		if ( $opts->get( "disable_embed", true ) ) {
			remove_action( "wp_head", "wp_oembed_add_discovery_links" );
			remove_action( "wp_head", "wp_oembed_add_host_js" );
		}

		if ( $opts->get( "guard_filter_test_posts", true ) ) {
			add_filter( "get_previous_post_where", array( $this, "filter_adjacent_post_where" ), 999 );
			add_filter( "get_next_post_where", array( $this, "filter_adjacent_post_where" ), 999 );
		}

		if ( $opts->get( "guard_security_headers", true ) ) {
			add_filter( "wp_headers", function( $headers ) {
				unset( $headers["X-Pingback"], $headers["x-pingback"], $headers["X-Powered-By"], $headers["Link"] );
				$headers["X-Content-Type-Options"]     = "nosniff";
				$headers["X-Frame-Options"]            = "SAMEORIGIN";
				$headers["Referrer-Policy"]            = "strict-origin-when-cross-origin";
				$headers["Permissions-Policy"]         = "accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()";
				$headers["Cross-Origin-Opener-Policy"] = "same-origin-allow-popups";
				$headers["Content-Security-Policy"]    = "frame-ancestors \x27self\x27; object-src \x27none\x27; base-uri \x27self\x27;";
				if ( is_ssl() ) {
					$headers["Strict-Transport-Security"] = "max-age=31536000";
				}
				return $headers;
			}, 999 );

			add_action( "send_headers", function() {
				if ( ! headers_sent() ) {
					@header_remove( "X-Powered-By" );
					@header_remove( "X-Pingback" );
					@header_remove( "Server" );
					@header_remove( "Link" );
				}
			}, 999 );
		}

		if ( $opts->get( "guard_restrict_rest_api", true ) ) {
			add_filter( "rest_authentication_errors", function( $result ) {
				if ( ! empty( $result ) ) {
					return $result;
				}
				if ( ! is_user_logged_in() ) {
					$req_uri = isset( $_SERVER["REQUEST_URI"] ) ? (string) $_SERVER["REQUEST_URI"] : "";
					if ( stripos( $req_uri, "/wp/v2/" ) !== false ) {
						return new \WP_Error(
							"rest_restricted",
							"Acesso restrito pela Guarda de Segurança.",
							array( "status" => 403 )
						);
					}
				}
				return $result;
			}, 99 );
		}

		add_action( "template_redirect", function() {
			if ( ! is_admin() && isset( $_REQUEST["author"] ) ) {
				wp_safe_redirect( home_url( "/" ), 301 );
				exit;
			}
		}, 1 );

		add_filter( "login_errors", function() {
			return "Credenciais inválidas. Verifique seus dados e tente novamente.";
		}, 999 );
	}

	public function filter_adjacent_post_where( $where ) {
		if ( ! is_string( $where ) ) {
			return $where;
		}
		return $where . " AND p.post_password = \x27\x27 AND p.post_name NOT LIKE \x27%teste-senha%\x27";
	}

	public static function maybe_install_mu_plugin() {
		$directory = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! wp_mkdir_p( $directory ) || ! is_writable( $directory ) ) { return false; }
		$file = trailingslashit( $directory ) . 'gamb-static-origin-shield.php';
		$code = <<<'PHP'
<?php
/** Plugin Name: Gamb WP Static Guard - Proteção da origem
 * Version: 4.0.0
 * License: GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$entry = 'gamb-wp-static-guard/gamb-wp-static-guard.php';
$active = in_array( $entry, (array) get_option( 'active_plugins', array() ), true );
$network = is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[$entry] );
if ( ! $active && ! $network ) { return; }
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) { define( 'DISALLOW_FILE_EDIT', true ); }
add_filter( 'xmlrpc_enabled', '__return_false', 999 );
add_filter( 'x_redirect_by', '__return_false', 999 );
PHP;
		if ( is_file( $file ) && file_get_contents( $file ) === $code ) { return true; }
		return false !== file_put_contents( $file, $code );
	}

	public static function get_htaccess_content() {
		$base = rtrim( '/' . trim( (string) Options::instance()->get( 'relative_path', '' ), '/' ), '/' );
		$rules = "Options -Indexes -ExecCGI -Includes\nServerSignature Off\nDirectoryIndex index.html\n";
		$rules .= "ErrorDocument 404 " . $base . "/404.html\nErrorDocument 403 " . $base . "/404.html\n";
		$rules .= "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
		$rules .= "RewriteRule ^404\\.html$ - [END]\n";
		$rules .= "RewriteCond %{REQUEST_METHOD} !^(GET|HEAD|OPTIONS)$ [NC]\nRewriteRule ^ - [F,END]\n";
		$rules .= "RewriteCond %{QUERY_STRING} (?:^|&)author=\\d+ [NC]\nRewriteRule ^ - [F,END]\n";
		$rules .= "RewriteRule ^(?:wp-admin|wp-login\\.php|xmlrpc\\.php|wp-config\\.php|wp-cron\\.php|wp-json)(?:/|$) - [F,END,NC]\n";
		foreach ( array( 'css', 'js', 'mjs', 'json', 'woff2', 'woff', 'ttf', 'otf', 'eot', 'webp', 'jpg', 'jpeg', 'png', 'svg', 'gif', 'avif', 'ico', 'pdf', 'mp4', 'webm', 'doc', 'docx', 'xls', 'xlsx', 'zip' ) as $ext ) {
			$rules .= "RewriteCond %{REQUEST_FILENAME}." . $ext . " -f\nRewriteRule ^v/([A-Za-z0-9_-]+)$ v/$1." . $ext . " [END]\n";
		}
		// END prevents the parent WordPress front controller from handling missing static files.
		$rules .= "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^ - [R=404,END]\n</IfModule>\n";
		$rules .= "<FilesMatch \"\\.(?:php[0-9]?|phtml|phar|pl|py|cgi|asp|aspx|jsp|sh|bash|exe|dll)$\">\nRequire all denied\n</FilesMatch>\n";
		$rules .= "<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n";
		$rules .= "<IfModule mod_mime.c>\nAddType text/css .css\nAddType application/javascript .js .mjs\nAddType application/json .json\nAddType font/woff2 .woff2\nAddType image/avif .avif\n</IfModule>\n";
		$rules .= "<IfModule mod_headers.c>\nHeader unset X-Powered-By\nHeader unset X-Pingback\nHeader unset Link\n";
		$rules .= "Header unset Server\nHeader always set Strict-Transport-Security \"max-age=31536000\" env=HTTPS\nHeader always set Permissions-Policy \"camera=(), microphone=(), geolocation=()\"\nHeader always set Cross-Origin-Opener-Policy \"same-origin-allow-popups\"\n";
		$rules .= "Header always set X-Content-Type-Options \"nosniff\"\nHeader always set X-Frame-Options \"SAMEORIGIN\"\nHeader always set Referrer-Policy \"strict-origin-when-cross-origin\"\n";
		$rules .= "Header always set Content-Security-Policy \"frame-ancestors 'self'; object-src 'none'; base-uri 'self';\"\n</IfModule>\n";
		return $rules;
	}

	public static function get_headers_file_content() {
		return "/*\n"
			. "  X-Frame-Options: SAMEORIGIN\n"
			. "  X-Content-Type-Options: nosniff\n"
			. "  Referrer-Policy: strict-origin-when-cross-origin\n"
			. "  Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()\n"
			. "  Cross-Origin-Opener-Policy: same-origin-allow-popups\n"
			. "  Strict-Transport-Security: max-age=31536000\n"
			. "  Content-Security-Policy: upgrade-insecure-requests; frame-ancestors \x27self\x27; object-src \x27none\x27; base-uri \x27self\x27;\n\n"
			. "/v/*\n"
			. "  Cache-Control: public, max-age=0, must-revalidate\n";
	}
}
