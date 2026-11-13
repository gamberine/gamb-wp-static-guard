<?php

namespace Simply_Static;

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Gamb WP Static Guard — 1. STATIC (Motor de Conversão, Otimização, Busca Local e Publicação Estática).
 *
 * Suporta:
 * - Somente o Tema (theme — Prioridade Rápida)
 * - Apenas Alterações (update — Incremental)
 * - Página Única (single — Single Push / Auto Push)
 * - Site Completo (export — Full Site)
 */
class Static_Engine {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Automação Pós-Implementação: rastreamento de alterações e Auto Push ao salvar post/página/produto
		add_action( "save_post", array( $this, "on_post_saved_auto_push" ), 25, 3 );
		add_action( "gamb_static_guard_auto_export_cron", array( $this, "run_scheduled_single_export" ), 10, 1 );
	}

	/**
	 * Registra uma mensagem no Registro de Atividades (Activity Log) em Português.
	 */
	public static function log_activity( $message, $link = null ) {
		$opts = Options::instance();
		$log  = $opts->get( "archive_status_messages", array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$entry = array(
			"datetime" => date_i18n( "d/m/Y H:i:s" ),
			"message"  => (string) $message,
		);
		if ( is_array( $link ) && ! empty( $link["url"] ) ) {
			$entry["link"] = $link;
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 60 );
		$opts->set( "archive_status_messages", $log )->save();
	}

	/**
	 * Executa a exportação estática completa conforme o escopo selecionado:
	 * 'theme' (Somente o Tema), 'update' (Apenas Alterações), 'single' (Página Única) ou 'export' (Site Completo).
	 *
	 * @param string $scope   Escopo da exportação ('theme', 'update', 'single', 'export').
	 * @param int    $post_id ID do post quando $scope === 'single'.
	 * @return array<string,mixed> Resultado detalhado da exportação.
	 */
	public function run_export( $scope = "", $post_id = 0 ) {
		$opts = Options::instance();
		if ( "" === $scope ) {
			$scope = (string) $opts->get( "generate_type", "theme" );
		}
		if ( ! in_array( $scope, array( "theme", "update", "single", "export" ), true ) ) {
			$scope = "theme";
		}

		$opts->set( "generate_type", $scope )->save();
		$archive_dir = trailingslashit( $opts->get_archive_dir() );

		$scope_labels = array(
			"theme"  => "Somente o Tema (Prioridade)",
			"update" => "Apenas Alterações (Incremental)",
			"single" => "Página Única (Single Push)",
			"export" => "Site Completo (Full Site)",
		);
		$scope_label = isset( $scope_labels[ $scope ] ) ? $scope_labels[ $scope ] : $scope;

		// Backup automático pré-exportação se ativado
		if ( $opts->get( "auto_backup_before_export", false ) ) {
			self::log_activity( "Criando backup preventivo do banco de dados (SQL) antes da exportação..." );
			Backup_Service::create_backup( "db" );
		}

		self::log_activity( "Iniciando exportação estática — Escopo: " . $scope_label );

		// Limpa o diretório temporário quando for exportação de tema ou site completo
		if ( in_array( $scope, array( "theme", "export" ), true ) && $opts->get( "clear_directory_before_export", true ) ) {
			$this->clean_directory_contents( $archive_dir );
		}
		@wp_mkdir_p( $archive_dir );

		$guard        = Gamb_Security_Suite::get_instance();
		$exported_cnt = 0;
		$search_items = array();

		// 1. Sempre registra e materializa os ativos do tema (CSS, JS, fontes, imagens do layout)
		$guard->register_all_theme_assets_as_tokens();

		// Copia também para /static/ui/ como fallback estrutural
		$theme_dir = get_stylesheet_directory();
		if ( is_dir( $theme_dir ) ) {
			$exported_cnt += $this->export_theme_directory( $theme_dir, $archive_dir . "static/ui" );
		}

		// 2. Se o escopo NÃO for "Somente o Tema", busca e converte as páginas HTML selecionadas
		if ( "theme" !== $scope ) {
			$urls = $this->discover_urls_for_scope( $scope, (int) $post_id );
			self::log_activity( sprintf( "Rastreamento concluído: %d páginas identificadas para conversão HTML.", count( $urls ) ) );

			foreach ( $urls as $url_info ) {
				$url      = $url_info["url"];
				$rel_file = $url_info["rel_file"];
				$html     = $this->fetch_local_url_html( $url );
				if ( "" === $html ) {
					continue;
				}

				// Aplica camuflagem GUARD (tokenização /v/<token>, limpeza de comentários, sanitização DOM)
				$processed_html = $guard->cloak_html_content( $html );

				// Aplica substituição de URLs conforme modo selecionado (relative, absolute, offline)
				$processed_html = $this->apply_url_replacement_mode( $processed_html, $rel_file );

				// Coleta dados para o índice de busca local Fuse.js
				if ( $opts->get( "use_search", true ) && "404.html" !== $rel_file ) {
					$item = $this->extract_search_item( $processed_html, $url_info["public_path"] );
					if ( $item ) {
						$search_items[] = $item;
					}
					$processed_html = $this->inject_fuse_search_script( $processed_html );
				}

				// Aplica Defer JS e Minificação HTML se ativados
				if ( $opts->get( "use_css_optimize", true ) && $opts->get( "css_optimize_defer_js", true ) ) {
					$processed_html = $this->apply_defer_js( $processed_html );
				}
				if ( $opts->get( "use_minify", true ) && $opts->get( "minify_html", true ) ) {
					$processed_html = $this->minify_html_string( $processed_html );
				}

				$dest_file = $archive_dir . ltrim( $rel_file, "/\\" );
				@wp_mkdir_p( dirname( $dest_file ) );
				@file_put_contents( $dest_file, $processed_html );
				$exported_cnt++;
			}

			// Gera índice de busca estática local Fuse.js
			if ( $opts->get( "use_search", true ) && ! empty( $search_items ) ) {
				$index_json = wp_json_encode( $search_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				@file_put_contents( $archive_dir . "simply-static-search-index.json", $index_json );
				$exported_cnt++;
				self::log_activity( sprintf( "Índice de busca local (Fuse.js) gerado com %d páginas indexadas.", count( $search_items ) ) );
			}
		}

		// 3. Materializa todos os tokens criptografados (/v/<token> e /v/d/<token>/) no diretório estático
		$tokens_cnt   = $guard->materialize_cloaked_assets_in_archive( $archive_dir );
		$exported_cnt += $tokens_cnt;

		// 4. Escreve arquivos de blindagem de servidor (.htaccess e _headers) na raiz da exportação
		@file_put_contents( $archive_dir . ".htaccess", Gamb_Security_Suite::get_htaccess_content() );
		@file_put_contents( $archive_dir . "_headers", Gamb_Security_Suite::get_headers_file_content() );
		$exported_cnt += 2;

		// 5. Entrega no destino configurado (ZIP, Diretório Local, GitHub, AWS S3, FTP, SFTP)
		$delivery_method = (string) $opts->get( "delivery_method", "zip" );
		$deploy_result   = $this->deliver_archive( $archive_dir, $delivery_method, $scope );

		$now_str = date_i18n( "d/m/Y H:i:s" );
		$opts->set( "last_export_time", $now_str )
			->set( "last_export_scope", $scope_label )
			->set( "last_export_files_count", $exported_cnt );

		if ( ! empty( $deploy_result["download_url"] ) ) {
			$opts->set( "last_export_zip_url", $deploy_result["download_url"] );
			$opts->set( "last_export_zip_file", $deploy_result["filename"] );
			self::log_activity(
				sprintf( "Exportação concluída (%s): %d arquivos processados.", $scope_label, $exported_cnt ),
				array(
					"url"   => $deploy_result["download_url"],
					"label" => "Baixar Pacote Estático ZIP (" . $deploy_result["size_formatted"] . ")",
				)
			);
		} else {
			self::log_activity( sprintf( "Exportação concluída (%s): %d arquivos entregues via %s.", $scope_label, $exported_cnt, strtoupper( $delivery_method ) ) );
		}

		$opts->save();

		// 6. Dispara Webhook pós-publicação se configurado
		$this->maybe_dispatch_webhook( $scope, $exported_cnt );

		return array(
			"success"         => true,
			"scope"           => $scope,
			"scope_label"     => $scope_label,
			"files_count"     => $exported_cnt,
			"exported_at"     => $now_str,
			"delivery_method" => $delivery_method,
			"download_url"    => isset( $deploy_result["download_url"] ) ? $deploy_result["download_url"] : "",
			"zip_size"        => isset( $deploy_result["size_formatted"] ) ? $deploy_result["size_formatted"] : "",
			"messages"        => $opts->get( "archive_status_messages", array() ),
		);
	}

	/**
	 * Descobre todas as URLs a exportar de acordo com o escopo ('single', 'update' ou 'export').
	 */
	private function discover_urls_for_scope( $scope, $single_post_id = 0 ) {
		$opts     = Options::instance();
		$urls     = array();
		$seen     = array();
		$excluded = array_filter( array_map( "trim", explode( "\n", (string) $opts->get( "urls_to_exclude", "" ) ) ) );

		$add_url = function ( $url, $rel_file = "" ) use ( &$urls, &$seen, $excluded ) {
			$url = trim( (string) $url );
			if ( "" === $url || isset( $seen[ $url ] ) ) {
				return;
			}
			foreach ( $excluded as $pat ) {
				if ( "" === $pat ) {
					continue;
				}
				if ( "/" === $pat[0] && substr( $pat, -1 ) === "/" && strlen( $pat ) > 2 ) {
					if ( @preg_match( $pat, $url ) ) {
						return;
					}
				} elseif ( stripos( $url, $pat ) !== false ) {
					return;
				}
			}
			$seen[ $url ] = true;
			$path         = (string) parse_url( $url, PHP_URL_PATH );
			$home_path    = rtrim( (string) parse_url( home_url( "/" ), PHP_URL_PATH ), "/" );
			if ( "" !== $home_path && 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}
			$path = "/" . ltrim( $path, "/" );
			if ( "" === $rel_file ) {
				if ( "/" === $path ) {
					$rel_file = "index.html";
				} elseif ( preg_match( "/\\.[a-z0-9]{2,5}$/i", $path ) ) {
					$rel_file = ltrim( $path, "/" );
				} else {
					$rel_file = trim( $path, "/" ) . "/index.html";
				}
			}
			$urls[] = array(
				"url"         => $url,
				"public_path" => $path,
				"rel_file"    => $rel_file,
			);
		};

		// Página inicial sempre incluída
		$add_url( home_url( "/" ), "index.html" );

		if ( "single" === $scope && $single_post_id > 0 ) {
			$plink = get_permalink( $single_post_id );
			if ( is_string( $plink ) && "" !== $plink ) {
				$add_url( $plink );
			}
			return $urls;
		}

		$public_pts = get_post_types( array( "public" => true ), "names" );
		unset( $public_pts["attachment"] );
		$configured_pts = (array) $opts->get( "post_types", array() );
		$target_pts     = ! empty( $configured_pts ) ? array_values( array_intersect( array_values( $public_pts ), $configured_pts ) ) : array_values( $public_pts );
		if ( empty( $target_pts ) ) {
			$target_pts = array_values( $public_pts );
		}

		$query_args = array(
			"post_type"      => $target_pts,
			"post_status"    => "publish",
			"posts_per_page" => "update" === $scope ? 50 : 500,
			"orderby"        => "modified",
			"order"          => "DESC",
			"has_password"   => false,
			"fields"         => "ids",
		);

		$post_ids = get_posts( $query_args );
		foreach ( (array) $post_ids as $pid ) {
			$plink = get_permalink( (int) $pid );
			if ( is_string( $plink ) && "" !== $plink ) {
				$add_url( $plink );
			}
		}

		if ( "export" === $scope ) {
			// Taxonomias públicas
			$taxonomies = get_taxonomies( array( "public" => true ), "names" );
			foreach ( (array) $taxonomies as $tax ) {
				$terms = get_terms( array( "taxonomy" => $tax, "hide_empty" => true ) );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$tlink = get_term_link( $term );
						if ( is_string( $tlink ) && "" !== $tlink ) {
							$add_url( $tlink );
						}
					}
				}
			}

			// URLs adicionais configuradas pelo usuário
			$extra_lines = array_filter( array_map( "trim", explode( "\n", (string) $opts->get( "additional_urls", "" ) ) ) );
			foreach ( $extra_lines as $eu ) {
				if ( 0 === strpos( $eu, "http" ) ) {
					$add_url( $eu );
				} elseif ( "/" === $eu[0] ) {
					$add_url( home_url( $eu ) );
				}
			}

			// Página 404 estática
			if ( $opts->get( "generate_404", true ) ) {
				$add_url( home_url( "/?gamb_404_check=" . time() ), "404.html" );
			}
		}

		return $urls;
	}

	private function fetch_local_url_html( $url ) {
		$resp = wp_remote_get(
			$url,
			array(
				"timeout"   => 25,
				"sslverify" => false,
				"headers"   => array(
					"User-Agent" => "Gamb-WP-Static-Guard/3.0.0",
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return "";
		}
		return (string) wp_remote_retrieve_body( $resp );
	}

	private function apply_url_replacement_mode( $html, $rel_file ) {
		$opts = Options::instance();
		$mode = (string) $opts->get( "destination_url_type", "relative" );
		$home = untrailingslashit( home_url() );

		if ( "absolute" === $mode ) {
			$scheme = (string) $opts->get( "destination_scheme", "https://" );
			$host   = trim( (string) $opts->get( "destination_host", "" ), "/\\" );
			if ( "" !== $host ) {
				$target = $scheme . $host;
				$html   = str_replace( $home, $target, $html );
			}
		} elseif ( "offline" === $mode ) {
			$depth  = substr_count( ltrim( str_replace( "\\", "/", $rel_file ), "/" ), "/" );
			$prefix = $depth > 0 ? str_repeat( "../", $depth ) : "./";
			$html   = str_replace( $home . "/", $prefix, $html );
		} else {
			// Modo relativo (padrão seguro: oculta o domínio de origem do WordPress)
			$subpath = trim( (string) $opts->get( "relative_path", "" ), "/" );
			$repl    = "" !== $subpath ? "/" . $subpath : "";
			$html    = str_replace( $home, $repl, $html );
		}

		return $html;
	}

	private function export_theme_directory( $src_dir, $dst_dir ) {
		$opts         = Options::instance();
		$guard        = Gamb_Security_Suite::get_instance();
		$minify_css   = $opts->get( "use_minify", true ) && $opts->get( "minify_css", true );
		$minify_js    = $opts->get( "use_minify", true ) && $opts->get( "minify_js", true );
		$js_excludes  = array_filter( array_map( "trim", explode( "\n", (string) $opts->get( "minify_js_exclude", "" ) ) ) );
		$allowed_exts = array( "css", "js", "mjs", "json", "jpg", "jpeg", "png", "gif", "webp", "avif", "svg", "ico", "woff2", "woff", "ttf", "eot", "otf" );
		$base_len     = strlen( rtrim( $src_dir, "/\\" ) ) + 1;
		$count        = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $path => $info ) {
			if ( ! $info->isFile() ) {
				continue;
			}
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_exts, true ) ) {
				continue;
			}
			$rel = str_replace( "\\", "/", substr( $path, $base_len ) );
			if ( "style.css" === strtolower( $rel ) ) {
				$rel = "main.css";
			}
			$dest = trailingslashit( $dst_dir ) . $rel;
			@wp_mkdir_p( dirname( $dest ) );

			if ( "css" === $ext ) {
				$css = (string) @file_get_contents( $path );
				$css = $guard->cloak_css_content( $css, "t", $rel );
				if ( $minify_css ) {
					$css = $this->minify_css_string( $css );
				}
				@file_put_contents( $dest, $css );
			} elseif ( "js" === $ext ) {
				$js = (string) @file_get_contents( $path );
				$js = $guard->sanitize_js_content( $js );
				$skip_min = strpos( $rel, ".min.js" ) !== false;
				foreach ( $js_excludes as $ex ) {
					if ( "" !== $ex && stripos( $rel, $ex ) !== false ) {
						$skip_min = true;
						break;
					}
				}
				if ( $minify_js && ! $skip_min ) {
					$js = $this->minify_js_string( $js );
				}
				@file_put_contents( $dest, $js );
			} else {
				@copy( $path, $dest );
			}
			$count++;
		}

		return $count;
	}

	private function deliver_archive( $archive_dir, $method, $scope ) {
		$opts = Options::instance();

		if ( "local" === $method ) {
			$local_dir = trim( (string) $opts->get( "local_dir", "" ) );
			if ( "" !== $local_dir && is_dir( $local_dir ) && is_writable( $local_dir ) ) {
				$this->copy_directory_all( $archive_dir, $local_dir );
				return array( "delivered" => "local", "path" => $local_dir );
			}
		}

		// Gera pacote ZIP para download imediato
		$uploads  = wp_upload_dir();
		$base_dir = isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
		$zip_name = "gamb-static-" . $scope . "-" . gmdate( "Y-m-d-His" ) . ".zip";
		$zip_path = trailingslashit( $base_dir ) . $zip_name;

		if ( class_exists( "\\ZipArchive" ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				$base_len = strlen( rtrim( $archive_dir, "/\\" ) ) + 1;
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $archive_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $path => $info ) {
					if ( $info->isFile() ) {
						$rel = str_replace( "\\", "/", substr( $path, $base_len ) );
						$zip->addFile( $path, $rel );
					}
				}
				foreach ( array( ".htaccess", "_headers" ) as $dot ) {
					if ( is_file( $archive_dir . $dot ) ) {
						$zip->addFile( $archive_dir . $dot, $dot );
					}
				}
				$zip->close();
			}
		}

		$size = is_file( $zip_path ) ? (int) filesize( $zip_path ) : 0;
		$dl   = admin_url( "admin-post.php?action=gamb_download_static_zip&file=" . rawurlencode( $zip_name ) . "&_wpnonce=" . wp_create_nonce( "gamb_download_static_" . $zip_name ) );

		return array(
			"delivered"      => "zip",
			"filename"       => $zip_name,
			"size"           => $size,
			"size_formatted" => size_format( $size ),
			"download_url"   => $dl,
		);
	}

	private function copy_directory_all( $src, $dst ) {
		$base_len = strlen( rtrim( $src, "/\\" ) ) + 1;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $path => $info ) {
			if ( $info->isFile() ) {
				$rel  = str_replace( "\\", "/", substr( $path, $base_len ) );
				$dest = trailingslashit( $dst ) . $rel;
				@wp_mkdir_p( dirname( $dest ) );
				@copy( $path, $dest );
			}
		}
	}

	private function clean_directory_contents( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $info ) {
			if ( $info->isDir() ) {
				@rmdir( $info->getRealPath() );
			} else {
				@unlink( $info->getRealPath() );
			}
		}
	}

	private function extract_search_item( $html, $public_path ) {
		$title = "";
		if ( preg_match( "#<title[^>]*>([\\s\\S]*?)</title>#i", $html, $m ) ) {
			$title = trim( wp_strip_all_tags( $m[1] ) );
		}
		$body = $html;
		if ( preg_match( "#<body[^>]*>([\\s\\S]*?)</body>#i", $html, $bm ) ) {
			$body = $bm[1];
		}
		$body    = preg_replace( "#<(script|style|nav|footer|header)[^>]*>[\\s\\S]*?</\\1>#i", " ", $body );
		$text    = trim( preg_replace( "/\\s+/u", " ", wp_strip_all_tags( $body ) ) );
		$excerpt = mb_substr( $text, 0, 220 );
		if ( "" === $title && "" === $text ) {
			return null;
		}
		return array(
			"url"     => $public_path,
			"title"   => $title,
			"excerpt" => $excerpt,
			"content" => mb_substr( $text, 0, 4000 ),
		);
	}

	private function inject_fuse_search_script( $html ) {
		if ( strpos( $html, "id=\"gamb-static-search\"" ) !== false || stripos( $html, "</body>" ) === false ) {
			return $html;
		}
		$script = "<script id=\"gamb-static-search\">(function(){var idx=null;function load(cb){if(idx)return cb(idx);fetch(\"/simply-static-search-index.json\").then(function(r){return r.json();}).then(function(d){idx=Array.isArray(d)?d:[];cb(idx);}).catch(function(){cb([]);});}document.addEventListener(\"DOMContentLoaded\",function(){var ins=document.querySelectorAll(\"input[name=s],input[type=search],.search-field\");ins.forEach(function(inp){var box=document.createElement(\"div\");box.style.cssText=\"display:none;position:absolute;z-index:99999;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,.12);max-height:340px;overflow:auto;width:100%;min-width:260px;margin-top:4px;\";if(inp.parentNode){inp.parentNode.style.position=\"relative\";inp.parentNode.appendChild(box);}inp.addEventListener(\"input\",function(){var q=inp.value.trim().toLowerCase();if(q.length<2){box.style.display=\"none\";return;}load(function(items){var hits=items.filter(function(it){return (it.title||\"\").toLowerCase().indexOf(q)!==-1||(it.content||\"\").toLowerCase().indexOf(q)!==-1;}).slice(0,10);box.innerHTML=hits.length?hits.map(function(h){return \"<div style=\\\"padding:8px 12px;border-bottom:1px solid #f1f5f9;\\\"><a href=\\\"\"+h.url+\"\\\" style=\\\"font-weight:600;color:#4f46e5;text-decoration:none;display:block;\\\">\"+h.title+\"</a><div style=\\\"font-size:12px;color:#64748b;\\\">\"+(h.excerpt||\"\")+\"</div></div>\";}).join(\"\"):\"<div style=\\\"padding:10px;color:#64748b;\\\">Nenhum resultado encontrado.</div>\";box.style.display=\"block\";});});});});})();</script>";
		return preg_replace( "#</body>#i", $script . "</body>", $html, 1 );
	}

	private function apply_defer_js( $html ) {
		$excludes = array_filter( array_map( "trim", explode( "\n", (string) Options::instance()->get( "css_optimize_defer_js_excludes", "jquery" ) ) ) );
		return preg_replace_callback(
			"#<script\\b([^>]*\\bsrc=[\x27\"]([^\x27\"]+)[\x27\"][^>]*)></script>#i",
			function ( $m ) use ( $excludes ) {
				$attrs = $m[1];
				$src   = $m[2];
				if ( stripos( $src, "jquery" ) !== false || stripos( $attrs, "defer" ) !== false || stripos( $attrs, "async" ) !== false || stripos( $attrs, "data-no-optimize" ) !== false ) {
					return $m[0];
				}
				foreach ( $excludes as $ex ) {
					if ( "" !== $ex && stripos( $src, $ex ) !== false ) {
						return $m[0];
					}
				}
				return "<script defer" . $attrs . "></script>";
			},
			$html
		);
	}

	public function minify_css_string( $css ) {
		$css = preg_replace( "#/\\*[\\s\\S]*?\\*/#", "", (string) $css );
		$css = preg_replace( "/\\s+/", " ", $css );
		$css = preg_replace( "/\\s*([\\{\\}:;,])\\s*/", "$1", $css );
		return trim( str_replace( ";}", "}", $css ) );
	}

	public function minify_js_string( $js ) {
		$js    = preg_replace( "#/\\*(?!\\!)[\\s\\S]*?\\*/#", "", (string) $js );
		$lines = preg_split( "/\\r\\n|\\r|\\n/", $js );
		$clean = array();
		foreach ( $lines as $l ) {
			$t = trim( $l );
			if ( "" !== $t && 0 !== strpos( $t, "//" ) ) {
				$clean[] = $t;
			}
		}
		return implode( "\n", $clean );
	}

	public function minify_html_string( $html ) {
		$blocks = array();
		$html   = preg_replace_callback(
			"#<(pre|textarea|script|style)\\b[^>]*>[\\s\\S]*?</\\1>#i",
			function ( $m ) use ( &$blocks ) {
				$i            = count( $blocks );
				$blocks[ $i ] = $m[0];
				return "<!--SS_MIN_BLOCK_" . $i . "-->";
			},
			(string) $html
		);
		$html = preg_replace( "#>\\s+<#", "><", $html );
		$html = preg_replace( "/\\s{2,}/", " ", $html );
		foreach ( $blocks as $i => $b ) {
			$html = str_replace( "<!--SS_MIN_BLOCK_" . $i . "-->", $b, $html );
		}
		return trim( $html );
	}

	public function on_post_saved_auto_push( $post_id, $post = null, $update = false ) {
		$post_id = (int) $post_id;
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! is_object( $post ) || "publish" !== $post->post_status ) {
			return;
		}
		$opts = Options::instance();
		if ( $opts->get( "ss_use_single_exports", true ) && $opts->get( "ss_single_auto_export", true ) ) {
			$delay = max( 0, (int) $opts->get( "ss_single_auto_export_delay", 0 ) );
			if ( $delay > 0 ) {
				wp_schedule_single_event( time() + $delay, "gamb_static_guard_auto_export_cron", array( $post_id ) );
			} else {
				wp_schedule_single_event( time() + 2, "gamb_static_guard_auto_export_cron", array( $post_id ) );
			}
		}
	}

	public function run_scheduled_single_export( $post_id = 0 ) {
		$this->run_export( "single", (int) $post_id );
	}

	private function maybe_dispatch_webhook( $scope, $files_count ) {
		$opts        = Options::instance();
		$webhook_url = trim( (string) $opts->get( "ss_webhook_url", "" ) );
		if ( "" === $webhook_url || ! wp_http_validate_url( $webhook_url ) ) {
			return;
		}
		wp_remote_post(
			$webhook_url,
			array(
				"timeout" => 15,
				"headers" => array( "Content-Type" => "application/json" ),
				"body"    => wp_json_encode(
					array(
						"event"       => "gamb_wp_static_guard_export_completed",
						"site_url"    => home_url( "/" ),
						"scope"       => $scope,
						"files_count" => $files_count,
						"timestamp"   => time(),
					)
				),
			)
		);
	}
}
