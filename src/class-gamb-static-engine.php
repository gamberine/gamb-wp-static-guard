<?php

namespace GambWPStaticGuard;

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Gamb WP Static Guard - 1. STATIC (Motor de Conversão, Otimização, Busca Local e Publicação Estática).
 *
 * Suporta:
 * - Somente o Tema (theme - Prioridade Rápida)
 * - Apenas Alterações (update - Incremental)
 * - Página Única (single - Single Push / Auto Push)
 * - Site Completo (export - Full Site)
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
		add_action( 'transition_post_status', array( $this, 'on_post_status_changed' ), 25, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 25, 2 );
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
		$lock = @fopen( get_temp_dir() . 'gamb-static-' . md5( ABSPATH ) . '.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) { fclose( $lock ); }
			return array( 'success' => false, 'message' => 'Já existe uma exportação em andamento ou o lock não pôde ser criado.' );
		}
		try {
			return $this->perform_export( $scope, $post_id );
		} catch ( \Throwable $error ) {
			self::log_activity( 'Exportação falhou: ' . $error->getMessage() );
			return array( 'success' => false, 'message' => $error->getMessage() );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	public function resolve_scope( $scope ) {
		return ! Options::instance()->get( 'first_full_export_completed', false ) ? 'export' : $scope;
	}

	private function perform_export( $scope, $post_id ) {
		$opts = Options::instance();
		if ( "" === $scope ) {
			$scope = (string) $opts->get( "generate_type", "theme" );
		}
		if ( ! in_array( $scope, array( "theme", "update", "single", "export" ), true ) ) {
			$scope = "theme";
		}

		if ( 'single' === $scope ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) {
				return array( 'success' => false, 'message' => 'Selecione um conteúdo público, publicado e sem senha.' );
			}
			// Keep archives, related links and the search index synchronized after a single push.
			$scope = 'export';
		}
		$scope = $this->resolve_scope( $scope );
		if ( 'update' === $scope ) { $scope = 'export'; }
		if ( 'local' === $opts->get( 'delivery_method' ) ) {
			$destination = $this->validate_local_destination( $opts->get( 'local_dir', '' ) );
			if ( is_wp_error( $destination ) ) {
				return array( 'success' => false, 'message' => $destination->get_error_message() );
			}
		} elseif ( 'zip' !== $opts->get( 'delivery_method' ) ) {
			return array( 'success' => false, 'message' => 'Este motor implementa entrega local e ZIP. Selecione um desses destinos.' );
		}
		$archive_dir = trailingslashit( $opts->get_archive_dir() );

		$scope_labels = array(
			"theme"  => "Recursos do tema",
			"update" => "Atualização completa",
			"single" => "Publicação do conteúdo",
			"export" => "Site completo",
		);
		$scope_label = isset( $scope_labels[ $scope ] ) ? $scope_labels[ $scope ] : $scope;

		// Backup automático pré-exportação se ativado
		if ( $opts->get( "auto_backup_before_export", false ) ) {
			self::log_activity( "Criando backup preventivo do banco de dados (SQL) antes da exportação..." );
			Backup_Service::create_backup( "db" );
		}

		self::log_activity( "Iniciando exportação estática - Escopo: " . $scope_label );

		// Limpa o diretório temporário quando for exportação de tema ou site completo
		if ( in_array( $scope, array( "theme", "export" ), true ) && $opts->get( "clear_directory_before_export", true ) ) {
			$this->clean_directory_contents( $archive_dir );
		}
		@wp_mkdir_p( $archive_dir );

		$guard        = Gamb_Security_Suite::get_instance();
		$exported_cnt = 0;
		$page_failures = array();
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

			for ( $cursor = 0; $cursor < count( $urls ); $cursor++ ) {
				$url_info = $urls[ $cursor ];
				$url      = $url_info["url"];
				$rel_file = $url_info["rel_file"];
				$html     = $this->fetch_local_url_html( $url, '404.html' === $rel_file );
				if ( "" === trim( $html, "\xEF\xBB\xBF \t\r\n" ) ) {
					$page_failures[] = $url;
					continue;
				}
				if ( 'export' === $scope && '404.html' !== $rel_file && $opts->get( 'smart_crawl', true ) ) {
					$this->append_linked_pages( $html, $url, $urls );
				}

				// Aplica camuflagem GUARD (tokenização /v/<token>, limpeza de comentários, sanitização DOM)
				$processed_html = Frontend_Policy::postprocess_html( $guard->cloak_html_content( $html ) );
				$guard->register_tokens_in_content( $processed_html );

				// Aplica substituição de URLs conforme modo selecionado (relative, absolute, offline)
				$processed_html = $this->apply_url_replacement_mode( $processed_html, $rel_file );

				// Coleta dados para o índice de busca local busca local
				if ( $opts->get( "use_search", true ) && "404.html" !== $rel_file ) {
					$item = $this->extract_search_item( $processed_html, $url_info["public_path"] );
					if ( $item ) {
						$search_items[] = $item;
					}
					$processed_html = $this->inject_local_search_script( $processed_html );
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
				$this->write_export_file( $dest_file, $processed_html );
				$exported_cnt++;
			}

			// Gera índice de busca estática local busca local
			if ( $opts->get( "use_search", true ) && ! empty( $search_items ) ) {
				$index_json = wp_json_encode( $search_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$this->write_export_file( $archive_dir . "search-index.json", $index_json );
				$exported_cnt++;
				self::log_activity( sprintf( "Índice de pesquisa local gerado com %d páginas indexadas.", count( $search_items ) ) );
			}
		}

		if ( ! empty( $page_failures ) ) {
			self::log_activity( 'Exportação interrompida: páginas não puderam ser obtidas. O destino foi preservado.' );
			return array( 'success' => false, 'message' => 'Falha ao obter páginas; consulte o registro.', 'failed_urls' => $page_failures );
		}

		if ( $opts->get( 'use_search', true ) && 'theme' !== $scope ) {
			wp_mkdir_p( $archive_dir . 'static' );
			copy( GAMB_STATIC_WP_PATH . 'assets/static-search.js', $archive_dir . 'static/search.js' );
		}
		// 3. Materializa todos os tokens criptografados (/v/<token> e /v/d/<token>/) no diretório estático
		$tokens_cnt   = $guard->materialize_cloaked_assets_in_archive( $archive_dir );
		$exported_cnt += $tokens_cnt;

		// 4. Escreve arquivos de blindagem de servidor (.htaccess e _headers) na raiz da exportação
		$this->write_export_file( $archive_dir . ".htaccess", Gamb_Security_Suite::get_htaccess_content() );
		$this->write_export_file( $archive_dir . "_headers", Gamb_Security_Suite::get_headers_file_content() );
		$exported_cnt += 2;

		// 5. Entrega no destino configurado (ZIP, Diretório Local, GitHub, AWS S3, FTP, SFTP)
		$delivery_method = (string) $opts->get( "delivery_method", "zip" );
		$deploy_result   = $this->deliver_archive( $archive_dir, $delivery_method, $scope );
		if ( is_wp_error( $deploy_result ) ) {
			self::log_activity( $deploy_result->get_error_message() );
			return array( 'success' => false, 'message' => $deploy_result->get_error_message() );
		}

		if ( 'export' === $scope ) { $opts->set( 'first_full_export_completed', true ); }
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
			'first_full_export_completed' => (bool) $opts->get( 'first_full_export_completed', false ),
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
			if ( 0 !== strpos( $url, trailingslashit( home_url() ) ) ) { return; }
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
			$path = preg_replace( '#^/index\\.php/#', '/', $path );
			if ( preg_match( '#(?:^|/)\.\.(?:/|$)|\\\\#', rawurldecode( $path ) ) ) { return; }
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
			"posts_per_page" => "update" === $scope ? 50 : -1,
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
			foreach ( $target_pts as $pt ) {
				$archive = get_post_type_archive_link( $pt );
				if ( $archive ) { $add_url( $archive ); }
			}
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
				$add_url( home_url( "/gamb-static-guard-missing-page-404/" ), "404.html" );
			}
		}

		return $urls;
	}

	/** Follow public navigation, including archive pagination, without crawling admin or search. */
	private function append_linked_pages( $html, $current_url, &$urls ) {
		$home = untrailingslashit( home_url() );
		$known = array_column( $urls, 'url' );
		$excluded = array_filter( array_map( 'trim', explode( "\n", (string) Options::instance()->get( 'urls_to_exclude', '' ) ) ) );
		preg_match_all( '#<a\b[^>]*\bhref=["\x27]([^"\x27]+)["\x27]#i', $html, $matches );
		foreach ( $matches[1] as $href ) {
			$href = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(?:mailto|tel|javascript):#i', $href ) ) { continue; }
			$url = \WP_Http::make_absolute_url( $href, $current_url );
			$url = explode( '#', $url, 2 )[0];
			if ( strpos( $url, $home . '/' ) !== 0 || in_array( $url, $known, true ) || parse_url( $url, PHP_URL_QUERY ) ) { continue; }
			$path = substr( (string) parse_url( $url, PHP_URL_PATH ), strlen( (string) parse_url( $home, PHP_URL_PATH ) ) );
			$path = preg_replace( '#^/index\.php/#', '/', $path );
			foreach ( $urls as $existing ) {
				if ( trim( $existing['public_path'], '/' ) === trim( $path, '/' ) ) { continue 2; }
			}
			if ( preg_match( '#^/(?:v|wp-admin|wp-content|wp-includes|wp-json|externo)(?:/|$)|\.[a-z0-9]+$#i', $path ) ) { continue; }
			foreach ( $excluded as $pattern ) {
				if ( false !== stripos( $url, $pattern ) ) { continue 2; }
			}
			$post_id = url_to_postid( $url );
			$post = $post_id ? get_post( $post_id ) : null;
			if ( $post && ( 'publish' !== $post->post_status || '' !== $post->post_password ) ) { continue; }
			$urls[] = array( 'url' => $url, 'public_path' => $path, 'rel_file' => trim( $path, '/' ) . '/index.html' );
			$known[] = $url;
		}
	}

	/** Recusa erros disfarçados de página válida, especialmente na raiz da publicação. */
	public function capture_response_is_valid( $url, $status, $context, $expected_404 = false ) {
		$identified = preg_match( '/^front=([01]);error=([01])$/D', trim( (string) $context ), $flags );
		if ( $expected_404 ) { return 404 === $status && $identified && '0' === $flags[1] && '1' === $flags[2]; }
		if ( 200 !== $status || ( $identified && '1' === $flags[2] ) || ( '' !== (string) $context && ! $identified ) ) { return false; }
		if ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			return $identified && '1' === $flags[1] && '0' === $flags[2];
		}
		return true;
	}

	private function fetch_local_url_html( $url, $expected_404 = false ) {
		$resp = wp_remote_get(
			$url,
			array(
				"timeout"   => 25,
				"sslverify" => true,
				"headers"   => array(
					"User-Agent" => "Gamb-WP-Static-Guard/" . GAMB_STATIC_WP_VERSION,
					'X-Gamb-Static-Export' => Frontend_Policy::export_context_token(),
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			self::log_activity( 'Falha ao buscar ' . $url . ': ' . $resp->get_error_message() );
			return '';
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$context = wp_remote_retrieve_header( $resp, 'x-gamb-static-context' );
		if ( ! $this->capture_response_is_valid( $url, $code, $context, $expected_404 ) ) {
			self::log_activity( 'Captura recusada: status HTTP ou contexto da página incompatível em ' . $url . ' (HTTP ' . $code . ').' );
			return '';
		}
		return (string) wp_remote_retrieve_body( $resp );
	}

	public function apply_url_replacement_mode( $html, $rel_file ) {
		$opts = Options::instance();
		$mode = (string) $opts->get( "destination_url_type", "relative" );
		$home = untrailingslashit( home_url() );
		// WordPress can use /index.php/ permalinks; the static site has no PHP router.
		$html = str_replace( $home . '/index.php/', $home . '/', $html );

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
			$origin_path = rtrim( (string) parse_url( $home, PHP_URL_PATH ), '/' );
			if ( '' !== $origin_path ) {
				$already_target = '';
				if ( 0 === strpos( $repl, $origin_path . '/' ) ) {
					$already_target = '(?!' . preg_quote( substr( $repl, strlen( $origin_path ) + 1 ), '#' ) . '/)';
				}
				$html = preg_replace( '#(?<=["\'\s(=])' . preg_quote( $origin_path, '#' ) . '/' . $already_target . '#', $repl . '/', $html );
			}
			$html    = str_replace( array( $home, str_replace( '/', '\\/', $home ) ), array( $repl, str_replace( '/', '\\/', $repl ) ), $html );
		}

		return $html;
	}

	public function write_export_file( $path, $contents ) {
		if ( false === @file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( 'Não foi possível gravar o arquivo exportado: ' . basename( $path ) );
		}
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
			if ( ! $info->isFile() || $info->isLink() ) {
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
				$css = $this->apply_url_replacement_mode( $css, "static/ui/" . $rel );
				if ( $minify_css ) {
					$css = $this->minify_css_string( $css );
				}
				$this->write_export_file( $dest, $css );
			} elseif ( "js" === $ext ) {
				$js = (string) @file_get_contents( $path );
				$js = $guard->sanitize_js_content( $js );
				$js = $this->apply_url_replacement_mode( $js, "static/ui/" . $rel );
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
				$this->write_export_file( $dest, $js );
			} else {
				if ( ! @copy( $path, $dest ) ) { throw new \RuntimeException( "Falha ao copiar recurso do tema." ); }
			}
			$count++;
		}

		return $count;
	}

	private function deliver_archive( $archive_dir, $method, $scope ) {
		$opts = Options::instance();

		if ( "local" === $method ) {
			$local_dir = $this->validate_local_destination( $opts->get( 'local_dir', '' ) );
			if ( is_wp_error( $local_dir ) ) { return $local_dir; }
			if ( "" !== $local_dir && is_dir( $local_dir ) && is_writable( $local_dir ) ) {
				if ( ! $this->copy_directory_all( $archive_dir, $local_dir ) ) {
					return new \WP_Error( "copy_failed", "Falha ao copiar arquivos para o destino local." );
				}
				$manifest = $this->update_local_manifest( $archive_dir, $local_dir, $scope );
				if ( is_wp_error( $manifest ) ) { return $manifest; }
				return array( "delivered" => "local", "path" => $local_dir );
			}
		}

		if ( 'zip' !== $method ) {
			return new \WP_Error( 'invalid_delivery', 'Destino indisponível ou método ainda não implementado. Selecione ZIP ou um diretório local gravável.' );
		}
		// Gera pacote ZIP para download imediato
		$uploads  = wp_upload_dir();
		$base_dir = isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
		$zip_name = "gamb-static-" . $scope . "-" . gmdate( "Y-m-d-His" ) . ".zip";
		$zip_path = trailingslashit( $base_dir ) . $zip_name;

		$files = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $archive_dir, \RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $path => $info ) {
			if ( $info->isFile() && ! $info->isLink() ) { $files[] = wp_normalize_path( $path ); }
		}
		if ( class_exists( "\\ZipArchive" ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				$base_len = strlen( rtrim( $archive_dir, "/\\" ) ) + 1;
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $archive_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $path => $info ) {
					if ( $info->isFile() && ! $info->isLink() ) {
						$rel = str_replace( "\\", "/", substr( $path, $base_len ) );
						$zip->addFile( $path, $rel );
					}
				}
				$zip->close();
			}
		} else {
			// WordPress ships PclZip; a global PHP extension change is unnecessary.
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip = new \PclZip( $zip_path );
			if ( 0 === $zip->create( $files, PCLZIP_OPT_REMOVE_PATH, untrailingslashit( wp_normalize_path( $archive_dir ) ) ) ) {
				return new \WP_Error( 'zip_failed', 'Não foi possível criar o ZIP: ' . $zip->errorInfo( true ) );
			}
		}

		$size = is_file( $zip_path ) ? (int) filesize( $zip_path ) : 0;
		if ( 0 === $size ) { return new \WP_Error( "zip_failed", "Não foi possível criar o ZIP." ); }
		$dl   = admin_url( "admin-post.php?action=gamb_download_static_zip&file=" . rawurlencode( $zip_name ) . "&_wpnonce=" . wp_create_nonce( "gamb_download_static_" . $zip_name ) );

		return array(
			"delivered"      => "zip",
			"filename"       => $zip_name,
			"size"           => $size,
			"size_formatted" => size_format( $size ),
			"download_url"   => $dl,
		);
	}

	private function validate_local_destination( $path ) {
		$path = trim( (string) $path );
		$real = realpath( $path );
		if ( '' === $path || false === $real || ! is_dir( $real ) || ! is_writable( $real ) ) {
			return new \WP_Error( 'invalid_local', 'Crie e selecione uma pasta local existente e gravável, dedicada ao site estático.' );
		}
		$target = strtolower( trailingslashit( wp_normalize_path( $real ) ) );
		$wp_root = strtolower( trailingslashit( wp_normalize_path( realpath( ABSPATH ) ) ) );
		if ( 0 === strpos( $wp_root, $target ) || $target === strtolower( trailingslashit( wp_normalize_path( dirname( $real ) ) ) ) ) {
			return new \WP_Error( 'unsafe_local', 'O destino não pode ser a raiz do WordPress, uma pasta ancestral ou a raiz do disco.' );
		}
		foreach ( array( WP_CONTENT_DIR, ABSPATH . 'wp-admin', ABSPATH . WPINC, ABSPATH . '.codex-artifacts' ) as $protected ) {
			$protected = strtolower( trailingslashit( wp_normalize_path( $protected ) ) );
			if ( 0 === strpos( $target, $protected ) ) {
				return new \WP_Error( 'unsafe_local', 'Escolha uma pasta de publicação fora dos diretórios internos do WordPress.' );
			}
		}
		foreach ( array( 'wp-config.php', 'wp-load.php', '.git', '.env' ) as $marker ) {
			if ( file_exists( trailingslashit( $real ) . $marker ) ) {
				return new \WP_Error( 'unsafe_local', 'O destino contém arquivos de uma aplicação; escolha uma pasta dedicada.' );
			}
		}
		return $real;
	}

	/** Remove only stale files recorded by this plugin; preserve unrelated files. */
	private function update_local_manifest( $archive, $destination, $scope ) {
		$file = trailingslashit( $destination ) . '.gamb-static-files.json';
		$previous = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
		$previous = is_array( $previous ) ? $previous : array();
		$current = array();
		$prefix = strlen( trailingslashit( $archive ) );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $archive, \RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $path => $info ) {
			if ( $info->isFile() && ! $info->isLink() ) { $current[] = str_replace( '\\', '/', substr( $path, $prefix ) ); }
		}
		if ( 'export' === $scope && Options::instance()->get( 'clear_directory_before_export', true ) ) {
			foreach ( array_diff( $previous, $current ) as $relative ) {
				if ( ! is_string( $relative ) || preg_match( '#(^/|^[A-Za-z]:|(?:^|/)\.\.(?:/|$)|\\\\)#', $relative ) ) { continue; }
				$stale = trailingslashit( $destination ) . $relative;
				$resolved = realpath( $stale );
				$base = trailingslashit( wp_normalize_path( realpath( $destination ) ) );
				if ( $resolved && 0 === strpos( strtolower( wp_normalize_path( $resolved ) ), strtolower( $base ) ) && is_file( $stale ) && ! is_link( $stale ) ) {
					if ( ! unlink( $stale ) ) { return new \WP_Error( 'stale_file', 'Falha ao remover arquivo estático desatualizado: ' . $relative ); }
					$directory = dirname( $stale );
					while ( wp_normalize_path( $directory ) !== rtrim( $base, '/' ) && is_dir( $directory ) && ! is_link( $directory ) && 2 === count( scandir( $directory ) ) ) {
						rmdir( $directory );
						$directory = dirname( $directory );
					}
				}
			}
		} else {
			$current = array_values( array_unique( array_merge( $previous, $current ) ) );
		}
		if ( false === file_put_contents( $file, wp_json_encode( $current ) ) ) {
			return new \WP_Error( 'manifest_failed', 'Não foi possível gravar o manifesto de publicação.' );
		}
		return true;
	}

	private function copy_directory_all( $src, $dst ) {
		$base_len = strlen( rtrim( $src, "/\\" ) ) + 1;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $path => $info ) {
			if ( $info->isFile() && ! $info->isLink() ) {
				$rel  = str_replace( "\\", "/", substr( $path, $base_len ) );
				$dest = trailingslashit( $dst ) . $rel;
				@wp_mkdir_p( dirname( $dest ) );
				$parent = realpath( dirname( $dest ) );
				$base = trailingslashit( wp_normalize_path( realpath( $dst ) ) );
				if ( ! $parent || is_link( $dest ) || 0 !== strpos( strtolower( trailingslashit( wp_normalize_path( $parent ) ) ), strtolower( $base ) ) ) { return false; }
				if ( ! @copy( $path, $dest ) ) { return false; }
			}
		}
		return true;
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
			if ( $info->isDir() && ! $info->isLink() ) {
				@rmdir( $info->getPathname() );
			} else {
				@unlink( $info->getPathname() );
			}
		}
	}

	private function extract_search_item( $html, $public_path ) {
		$title = "";
		if ( preg_match( "#<title[^>]*>([\\s\\S]*?)</title>#i", $html, $m ) ) {
			$title = html_entity_decode( trim( wp_strip_all_tags( $m[1] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$body = $html;
		if ( preg_match( "#<body[^>]*>([\\s\\S]*?)</body>#i", $html, $bm ) ) {
			$body = $bm[1];
		}
		$body    = preg_replace( "#<(script|style|nav|footer|header)[^>]*>[\\s\\S]*?</\\1>#i", " ", $body );
		$text    = html_entity_decode( trim( preg_replace( "/\\s+/u", " ", wp_strip_all_tags( $body ) ) ), ENT_QUOTES | ENT_HTML5, "UTF-8" );
		$excerpt = mb_substr( $text, 0, 220 );
		if ( "" === $title && "" === $text ) {
			return null;
		}
		return array(
			"url"     => $this->apply_url_replacement_mode( home_url( $public_path ), "index.html" ),
			"title"   => $title,
			"excerpt" => $excerpt,
			"content" => mb_substr( $text, 0, 4000 ),
		);
	}

	private function inject_local_search_script( $html ) {
		if ( strpos( $html, 'id="gamb-static-search"' ) !== false || stripos( $html, '</body>' ) === false ) {
			return $html;
		}
		$src = $this->apply_url_replacement_mode( home_url( '/static/search.js' ), 'index.html' );
		$index = $this->apply_url_replacement_mode( home_url( '/search-index.json' ), 'index.html' );
		$script = '<script id="gamb-static-search" defer src="' . esc_url( $src ) . '" data-index="' . esc_url( $index ) . '"></script>';
		return preg_replace( '#</body>#i', $script . '</body>', $html, 1 );
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
		$this->schedule_auto_export( $post );
	}

	public function on_post_status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status ) { $this->schedule_auto_export( $post ); }
	}

	public function on_post_deleted( $post_id, $post ) {
		if ( $post && 'publish' === $post->post_status ) { $this->schedule_auto_export( $post ); }
	}

	private function schedule_auto_export( $post ) {
		$opts = Options::instance();
		if ( ! in_array( $post->post_type, (array) ( $opts->get( 'post_types', array() ) ?: get_post_types( array( 'public' => true ), 'names' ) ), true ) ) { return; }
		if ( $opts->get( "single_enabled", true ) && $opts->get( "auto_export", true ) && ! wp_next_scheduled( 'gamb_static_guard_auto_export_cron', array( 0 ) ) ) {
			$delay = max( 0, (int) $opts->get( "auto_export_delay", 0 ) );
			wp_schedule_single_event( time() + max( 2, $delay ), 'gamb_static_guard_auto_export_cron', array( 0 ) );
		}
	}

	public function run_scheduled_single_export( $post_id = 0 ) {
		$result = $this->run_export( 'export' );
		if ( empty( $result['success'] ) ) { self::log_activity( 'Auto Push falhou: ' . $result['message'] ); }
	}

	private function maybe_dispatch_webhook( $scope, $files_count ) {
		$opts        = Options::instance();
		$webhook_url = trim( (string) $opts->get( "webhook_url", "" ) );
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
