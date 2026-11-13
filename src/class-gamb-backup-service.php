<?php

namespace Simply_Static;

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Gamb WP Static Guard — Serviço de Backup Estilo Duplicator & Diagnóstico de Segurança.
 */
class Backup_Service {

	const BACKUP_DIR_NAME = "gamb-backups";

	public static function get_backup_dir() {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
		$dir     = trailingslashit( $base ) . self::BACKUP_DIR_NAME;

		if ( ! is_dir( $dir ) ) {
			@wp_mkdir_p( $dir );
		}

		$htaccess = trailingslashit( $dir ) . ".htaccess";
		if ( ! is_file( $htaccess ) ) {
			$ht_content = "# Gamb WP Static Guard — Proteção de Diretório de Backups\n"
				. "<IfModule mod_authz_core.c>\n"
				. "    Require all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "    Order allow,deny\n"
				. "    Deny from all\n"
				. "</IfModule>\n";
			@file_put_contents( $htaccess, $ht_content );
		}

		$index_php = trailingslashit( $dir ) . "index.php";
		if ( ! is_file( $index_php ) ) {
			@file_put_contents( $index_php, "<?php // Silêncio é ouro.\n" );
		}

		return $dir;
	}

	public static function dump_database( $sql_file_path ) {
		global $wpdb;

		$fh = @fopen( $sql_file_path, "w" );
		if ( ! $fh ) {
			return new \WP_Error( "file_write_error", "Não foi possível criar o arquivo de despejo SQL." );
		}

		$timestamp  = current_time( "mysql" );
		$db_version = method_exists( $wpdb, "db_version" ) ? $wpdb->db_version() : "desconhecida";

		fwrite( $fh, "-- =====================================================================\n" );
		fwrite( $fh, "-- Gamb WP Static Guard — Snapshot Completo do Banco de Dados\n" );
		fwrite( $fh, "-- Data: " . $timestamp . "\n" );
		fwrite( $fh, "-- Versão do WordPress: " . get_bloginfo( "version" ) . "\n" );
		fwrite( $fh, "-- Versão MySQL: " . $db_version . "\n" );
		fwrite( $fh, "-- Prefixo das Tabelas: " . $wpdb->prefix . "\n" );
		fwrite( $fh, "-- URL de Origem: " . home_url( "/" ) . "\n" );
		fwrite( $fh, "-- =====================================================================\n\n" );
		fwrite( $fh, "SET FOREIGN_KEY_CHECKS = 0;\n" );
		fwrite( $fh, "SET SQL_MODE = \x27NO_AUTO_VALUE_ON_ZERO\x27;\n" );
		fwrite( $fh, "SET NAMES utf8mb4;\n\n" );

		$tables = $wpdb->get_col( "SHOW TABLES LIKE \x27" . $wpdb->esc_like( $wpdb->prefix ) . "%\x27" );
		if ( empty( $tables ) ) {
			$tables = $wpdb->get_col( "SHOW TABLES" );
		}

		$table_count = 0;

		foreach ( (array) $tables as $table ) {
			$table = (string) $table;
			if ( "" === $table ) {
				continue;
			}

			$create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! $create_row || empty( $create_row[1] ) ) {
				continue;
			}

			fwrite( $fh, "--\n-- Estrutura da tabela `{$table}`\n--\n" );
			fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $fh, $create_row[1] . ";\n\n" );

			$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			if ( $total_rows > 0 ) {
				$batch_size = 500;
				$offset     = 0;
				while ( $offset < $total_rows ) {
					$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}", ARRAY_A );
					if ( empty( $rows ) ) {
						break;
					}
					$columns   = array_keys( $rows[0] );
					$col_sql   = "`" . implode( "`, `", $columns ) . "`";
					$val_lines = array();
					foreach ( $rows as $row ) {
						$escaped_vals = array();
						foreach ( $row as $val ) {
							if ( null === $val ) {
								$escaped_vals[] = "NULL";
							} elseif ( is_numeric( $val ) && ! is_string( $val ) ) {
								$escaped_vals[] = $val;
							} else {
								$escaped_vals[] = "\x27" . $wpdb->_real_escape( (string) $val ) . "\x27";
							}
						}
						$val_lines[] = "(" . implode( ", ", $escaped_vals ) . ")";
					}
					if ( ! empty( $val_lines ) ) {
						fwrite( $fh, "INSERT INTO `{$table}` ({$col_sql}) VALUES\n" . implode( ",\n", $val_lines ) . ";\n" );
					}
					$offset += $batch_size;
				}
			}
			fwrite( $fh, "\n" );
			$table_count++;
		}

		fwrite( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
		fclose( $fh );

		return $table_count;
	}

	public static function create_backup( $type = "full" ) {
		$backup_dir = self::get_backup_dir();
		$date_stamp = gmdate( "Y-m-d-His" );
		$rand_token = substr( md5( uniqid( "gamb_backup_", true ) ), 0, 8 );
		$prefix     = "db" === $type ? "gamb-db-backup-" : "gamb-full-backup-";
		$zip_name   = $prefix . $date_stamp . "-" . $rand_token . ".zip";
		$zip_path   = trailingslashit( $backup_dir ) . $zip_name;
		$sql_path   = trailingslashit( $backup_dir ) . "database-" . $rand_token . ".sql";

		$tables_dumped = self::dump_database( $sql_path );
		if ( is_wp_error( $tables_dumped ) ) {
			return $tables_dumped;
		}

		if ( ! class_exists( "\\ZipArchive" ) ) {
			$sql_name = $prefix . $date_stamp . "-" . $rand_token . ".sql";
			$final_sql = trailingslashit( $backup_dir ) . $sql_name;
			@rename( $sql_path, $final_sql );
			return array(
				"success"        => true,
				"filename"       => $sql_name,
				"size_formatted" => size_format( (int) filesize( $final_sql ) ),
				"type"           => "sql",
				"tables_count"   => $tables_dumped,
				"created_at"     => date_i18n( "d/m/Y H:i:s" ),
				"download_url"   => admin_url( "admin-post.php?action=gamb_download_backup&file=" . rawurlencode( $sql_name ) . "&_wpnonce=" . wp_create_nonce( "gamb_download_backup_" . $sql_name ) ),
			);
		}

		$zip = new \ZipArchive();
		$res = $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( true !== $res ) {
			@unlink( $sql_path );
			return new \WP_Error( "zip_create_error", "Não foi possível criar o arquivo ZIP de backup." );
		}

		$zip->addFile( $sql_path, "database.sql" );

		$manifest = array(
			"generator"      => "Gamb WP Static Guard",
			"version"        => "3.0.0",
			"backup_type"    => $type,
			"created_at"     => current_time( "mysql" ),
			"site_url"       => site_url(),
			"home_url"       => home_url(),
			"blogname"       => get_bloginfo( "name" ),
			"wp_version"     => get_bloginfo( "version" ),
			"db_prefix"      => isset( $GLOBALS["wpdb"] ) ? $GLOBALS["wpdb"]->prefix : "wp_",
			"tables_dumped"  => $tables_dumped,
			"active_theme"   => get_stylesheet(),
			"active_plugins" => get_option( "active_plugins", array() ),
		);
		$zip->addFromString( "manifest.json", wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$readme = "# Gamb WP Static Guard — Pacote de Backup (Estilo Duplicator)\n\n"
			. "Gerado em: " . current_time( "mysql" ) . "\n"
			. "Tipo: " . ( "full" === $type ? "Completo (Banco de Dados SQL + Tema + Plugins)" : "Banco de Dados SQL + Manifesto" ) . "\n\n"
			. "## Conteúdo:\n"
			. "- database.sql: Despejo completo das tabelas MySQL.\n"
			. "- manifest.json: Metadados do ambiente WordPress.\n"
			. ( "full" === $type ? "- wp-content/: Arquivos do tema ativo e plugins.\n\n" : "\n" )
			. "## Como Restaurar:\n"
			. "1. Importe `database.sql` no seu banco MySQL via phpMyAdmin ou CLI.\n"
			. "2. Caso seja backup completo, extraia a pasta `wp-content/` na raiz da instalação WordPress.\n";
		$zip->addFromString( "LEIA-ME-RESTAURACAO.txt", $readme );

		if ( "full" === $type ) {
			self::add_directory_to_zip( $zip, get_stylesheet_directory(), "wp-content/themes/" . get_stylesheet() );
			if ( get_template_directory() !== get_stylesheet_directory() ) {
				self::add_directory_to_zip( $zip, get_template_directory(), "wp-content/themes/" . get_template() );
			}
			$plugins_dir = defined( "WP_PLUGIN_DIR" ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . "/plugins" );
			self::add_directory_to_zip( $zip, $plugins_dir, "wp-content/plugins" );
		}

		$zip->close();
		@unlink( $sql_path );

		$size = is_file( $zip_path ) ? (int) filesize( $zip_path ) : 0;

		return array(
			"success"        => true,
			"filename"       => $zip_name,
			"size"           => $size,
			"size_formatted" => size_format( $size ),
			"type"           => $type,
			"tables_count"   => $tables_dumped,
			"created_at"     => date_i18n( "d/m/Y H:i:s" ),
			"download_url"   => admin_url( "admin-post.php?action=gamb_download_backup&file=" . rawurlencode( $zip_name ) . "&_wpnonce=" . wp_create_nonce( "gamb_download_backup_" . $zip_name ) ),
		);
	}

	private static function add_directory_to_zip( \ZipArchive $zip, $src_dir, $zip_prefix ) {
		if ( ! is_dir( $src_dir ) ) {
			return;
		}
		$base_len  = strlen( rtrim( $src_dir, "/\\" ) ) + 1;
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$skip_dirs = array( ".git", "node_modules", "gamb-backups", "gamb-static-guard-archive" );
		foreach ( $iterator as $path => $info ) {
			if ( ! $info->isFile() ) {
				continue;
			}
			$rel  = str_replace( "\\", "/", substr( $path, $base_len ) );
			$skip = false;
			foreach ( $skip_dirs as $sd ) {
				if ( strpos( $rel, $sd . "/" ) === 0 || strpos( $rel, "/" . $sd . "/" ) !== false ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			$zip->addFile( $path, $zip_prefix . "/" . $rel );
		}
	}

	public static function list_backups() {
		$backup_dir = self::get_backup_dir();
		if ( ! is_dir( $backup_dir ) ) {
			return array();
		}

		$files = glob( trailingslashit( $backup_dir ) . "gamb-*.*" );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$list = array();
		foreach ( $files as $file ) {
			$fname = basename( $file );
			if ( ! preg_match( "/\\.(zip|sql)$/i", $fname ) ) {
				continue;
			}
			$size   = (int) filesize( $file );
			$mtime  = (int) filemtime( $file );
			$is_db  = strpos( $fname, "gamb-db-" ) === 0;
			$list[] = array(
				"filename"       => $fname,
				"type_label"     => $is_db ? "Banco de Dados (SQL)" : "Completo (SQL + Arquivos)",
				"size"           => $size,
				"size_formatted" => size_format( $size ),
				"created_at"     => date_i18n( "d/m/Y H:i:s", $mtime ),
				"timestamp"      => $mtime,
				"download_url"   => admin_url( "admin-post.php?action=gamb_download_backup&file=" . rawurlencode( $fname ) . "&_wpnonce=" . wp_create_nonce( "gamb_download_backup_" . $fname ) ),
			);
		}

		usort( $list, function ( $a, $b ) {
			return $b["timestamp"] - $a["timestamp"];
		} );

		return $list;
	}

	public static function delete_backup( $filename ) {
		$filename   = sanitize_file_name( $filename );
		$backup_dir = self::get_backup_dir();
		$path       = trailingslashit( $backup_dir ) . $filename;
		if ( is_file( $path ) && strpos( $filename, "gamb-" ) === 0 && preg_match( "/\\.(zip|sql)$/i", $filename ) ) {
			return @unlink( $path );
		}
		return false;
	}

	/**
	 * Executa verificação completa de diagnóstico de ambiente e segurança em Português.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function run_diagnostics() {
		$opts        = Options::instance();
		$backup_dir  = self::get_backup_dir();
		$archive_dir = $opts->get_archive_dir();
		$mu_file     = ( defined( "WPMU_PLUGIN_DIR" ) ? WPMU_PLUGIN_DIR : ( WP_CONTENT_DIR . "/mu-plugins" ) ) . "/gamb-static-origin-shield.php";

		return array(
			array(
				"category" => "Ambiente & Servidor",
				"label"    => "Versão do PHP (" . PHP_VERSION . ")",
				"ok"       => version_compare( PHP_VERSION, "7.4", ">=" ),
				"detail"   => "Requer PHP 7.4 ou superior.",
			),
			array(
				"category" => "Ambiente & Servidor",
				"label"    => "Extensão ZipArchive",
				"ok"       => class_exists( "\\ZipArchive" ),
				"detail"   => class_exists( "\\ZipArchive" ) ? "Ativa para geração de pacotes estáticos e backups." : "Ausente (usando fallback SQL/PclZip).",
			),
			array(
				"category" => "1. STATIC — Diretórios",
				"label"    => "Diretório de Exportação Estática Gravável",
				"ok"       => is_dir( $archive_dir ) && is_writable( $archive_dir ),
				"detail"   => $archive_dir,
			),
			array(
				"category" => "2. GUARD — Segurança & Ocultação",
				"label"    => "Tokenização Criptográfica Universal (/v/<token>)",
				"ok"       => (bool) $opts->get( "guard_universal_token_cloaking", true ),
				"detail"   => $opts->get( "guard_universal_token_cloaking", true ) ? "Ativa (ocultando caminhos de tema, imagens, plugins e wp-includes)." : "Desativada.",
			),
			array(
				"category" => "2. GUARD — Segurança & Ocultação",
				"label"    => "Bloqueio de XML-RPC & REST API Anônima",
				"ok"       => (bool) $opts->get( "disable_xmlrpc", true ) && (bool) $opts->get( "guard_restrict_rest_api", true ),
				"detail"   => "Protegendo endpoints /xmlrpc.php e /wp-json/wp/v2/* contra raspagem e força bruta.",
			),
			array(
				"category" => "2. GUARD — Segurança & Ocultação",
				"label"    => "MU-Plugin Origin Shield",
				"ok"       => is_file( $mu_file ),
				"detail"   => is_file( $mu_file ) ? "Instalado em wp-content/mu-plugins/gamb-static-origin-shield.php." : "Rodando via hook padrão do plugin.",
			),
			array(
				"category" => "Backup & Proteção",
				"label"    => "Diretório Protegido de Backups",
				"ok"       => is_dir( $backup_dir ) && is_writable( $backup_dir ) && is_file( $backup_dir . "/.htaccess" ),
				"detail"   => $backup_dir . " (Protegido por .htaccess)",
			),
		);
	}
}
