<?php
declare(strict_types=1);

namespace GambWPStaticGuard;

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Gamb WP Static Guard - Serviço de Backup Estilo pacote de recuperação & Diagnóstico de Segurança.
 */
class Backup_Service {

	const BACKUP_DIR_NAME = "gamb-backups";
	const RETENTION_LIMIT = 6;

	public static function get_backup_dir() {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads["basedir"] ) ? $uploads["basedir"] : ( WP_CONTENT_DIR . "/uploads" );
		$dir     = trailingslashit( $base ) . self::BACKUP_DIR_NAME;

		if ( ! is_dir( $dir ) ) {
			@wp_mkdir_p( $dir );
		}

		$htaccess = trailingslashit( $dir ) . ".htaccess";
		if ( ! is_file( $htaccess ) ) {
			$ht_content = "# Gamb WP Static Guard - Proteção de Diretório de Backups\n"
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
		fwrite( $fh, "-- Gamb WP Static Guard - Snapshot Completo do Banco de Dados\n" );
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
			Operation_Progress::phase('backup',1,4,$table_count,count((array)$tables),'Copiando tabelas do banco de dados.');
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

	public static function zip_backend(): string {
		if ( class_exists( '\ZipArchive' ) ) {
			return 'native';
		}
		if ( function_exists( 'gzopen' ) && function_exists( 'gzdeflate' ) ) {
			$core_zip = @fopen( ABSPATH . 'wp-admin/includes/class-pclzip.php', 'rb' );
			if ( $core_zip ) {
				fclose( $core_zip );
				return 'pclzip';
			}
		}
		return '';
	}

	public static function create_backup( $type = "full" ) {
		$lock = @fopen( get_temp_dir() . 'gamb-backup-' . md5( ABSPATH ) . '.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( $lock ) { fclose( $lock ); } return new \WP_Error( 'backup_busy', 'Outra cópia de segurança está em andamento.' ); }
		try {
			if ( 'full' === $type && '' === self::zip_backend() ) {
				return new \WP_Error( 'zip_required', 'O backup de banco, tema e extensões requer ZIP nativo ou PclZip do WordPress com zlib.', array( 'status' => 422 ) );
			}
			$result = self::perform_backup( $type );
			if ( is_wp_error( $result ) ) { return $result; }
			$path = trailingslashit( self::get_backup_dir() ) . $result['filename'];
			if ( ! is_file( $path ) || ! filesize( $path ) ) { return new \WP_Error( 'backup_empty', 'A cópia não foi concluída. As cópias anteriores foram preservadas.' ); }
			$history_file = trailingslashit( self::get_backup_dir() ) . '.gamb-backup-history.json';
			$history = is_file( $history_file ) ? json_decode( file_get_contents( $history_file ), true ) : array();
			$history = is_array( $history ) ? $history : array();
			$history[$result['filename']] = microtime( true );
			if ( false === file_put_contents( $history_file, wp_json_encode( $history ) ) ) { return new \WP_Error( 'backup_history', 'Não foi possível registrar a ordem das cópias de segurança.' ); }
			Operation_Progress::phase('backup',4,4,0,1,'Aplicando a retenção das seis cópias mais recentes.');
			$retention = self::enforce_retention();
			if ( is_wp_error( $retention ) ) { return $retention; }
			$result['retained_backups'] = count( self::list_backups() );
			return $result;
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}

	public static function enforce_retention() {
		$backups = self::list_backups();
		foreach ( array_slice( $backups, self::RETENTION_LIMIT ) as $backup ) {
			if ( ! self::delete_backup( $backup['filename'] ) ) { return new \WP_Error( 'backup_retention', 'Não foi possível remover uma cópia antiga. Verifique a permissão do diretório de backups.' ); }
		}
		return true;
	}

	private static function perform_backup( $type = "full" ) {
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

		$backend = self::zip_backend();
		if ( 'db' === $type && 'native' !== $backend ) {
			$sql_name = $prefix . $date_stamp . "-" . $rand_token . ".sql";
			$final_sql = trailingslashit( $backup_dir ) . $sql_name;
			if ( ! @rename( $sql_path, $final_sql ) ) { return new \WP_Error( "backup_move", "Não foi possível concluir a cópia SQL." ); }
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

		Operation_Progress::phase('backup',2,4,0,1,'Preparando o pacote de segurança.');
		$manifest = array(
			"generator"      => "Gamb WP Static Guard",
			"version"        => GAMB_STATIC_WP_VERSION,
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
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$readme = "# Gamb WP Static Guard - Pacote de Backup (Estilo pacote de recuperação)\n\n"
			. "Gerado em: " . current_time( "mysql" ) . "\n"
			. "Tipo: " . ( "full" === $type ? "Completo (Banco de Dados SQL + Tema + Plugins)" : "Banco de Dados SQL + Manifesto" ) . "\n\n"
			. "## Conteúdo:\n"
			. "- database.sql: Despejo completo das tabelas MySQL.\n"
			. "- manifest.json: Metadados do ambiente WordPress.\n"
			. ( "full" === $type ? "- wp-content/: Arquivos do tema ativo e plugins.\n\n" : "\n" )
			. "## Como Restaurar:\n"
			. "1. Importe `database.sql` no seu banco MySQL via phpMyAdmin ou CLI.\n"
			. "2. Caso seja backup completo, extraia a pasta `wp-content/` na raiz da instalação WordPress.\n";
		$partial_path = $zip_path . '.part';
		try {
			if ( false === $manifest_json ) {
				return new \WP_Error( 'backup_manifest', 'Não foi possível gerar o manifesto da cópia.' );
			}
			$files = array( 'database.sql' => $sql_path );
			if ( 'full' === $type ) {
				self::collect_directory_files( $files, get_stylesheet_directory(), 'wp-content/themes/' . get_stylesheet() );
				if ( get_template_directory() !== get_stylesheet_directory() ) {
					self::collect_directory_files( $files, get_template_directory(), 'wp-content/themes/' . get_template() );
				}
				$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
				self::collect_directory_files( $files, $plugins_dir, 'wp-content/plugins' );
			}
			$metadata = array( 'manifest.json' => $manifest_json, 'LEIA-ME-RESTAURACAO.txt' => $readme );
			Operation_Progress::phase( 'backup', 3, 4, 0, 1, 'Compactando a cópia de segurança.' );
			$result = self::write_zip( $backend, $partial_path, $files, $metadata );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! is_file( $partial_path ) || ! filesize( $partial_path ) || ! @rename( $partial_path, $zip_path ) ) {
				return new \WP_Error( 'backup_zip', 'Não foi possível concluir o pacote ZIP.' );
			}
		} catch ( \RuntimeException $error ) {
			return new \WP_Error( 'backup_zip', 'Não foi possível ler os arquivos ou concluir o pacote ZIP.' );
		} finally {
			if ( is_file( $partial_path ) ) {
				@unlink( $partial_path );
			}
			@unlink( $sql_path );
		}

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

	private static function collect_directory_files( array &$files, $src_dir, $zip_prefix ) {
		if ( ! is_dir( $src_dir ) ) {
			return;
		}
		$base_len  = strlen( rtrim( $src_dir, "/\\" ) ) + 1;
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$skip_dirs = array( ".git", "node_modules", "gamb-backups", "gamb-static-guard-archive" );
		foreach ( $iterator as $path => $info ) {
			if ( ! $info->isFile() || $info->isLink() ) {
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
			$files[ $zip_prefix . '/' . $rel ] = $path;
		}
	}

	private static function write_zip( string $backend, string $path, array $files, array $metadata ) {
		$error = new \WP_Error( 'backup_zip', 'Não foi possível gerar um pacote ZIP com todos os arquivos da cópia.' );
		if ( 'native' === $backend ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return $error;
			}
			$complete = true;
			foreach ( $files as $name => $file ) {
				$complete = $zip->addFile( $file, $name ) && $complete;
			}
			foreach ( $metadata as $name => $contents ) {
				$complete = $zip->addFromString( $name, $contents ) && $complete;
			}
			if ( method_exists( $zip, 'registerProgressCallback' ) ) {
				$zip->registerProgressCallback( 0.02, static function ( $ratio ) {
					Operation_Progress::phase( 'backup', 3, 4, (int) round( $ratio * 10000 ), 10000, 'Compactando a cópia de segurança.' );
				} );
			}
			return $zip->close() && $complete ? true : $error;
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$zip = new \PclZip( $path );
		$entries = array();
		$expected_sizes = array();
		// PclZip uses ZIP32 and holds individual files in memory.
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$total = 0;
		$largest = 0;
		foreach ( $files as $name => $file ) {
			$size = @filesize( $file );
			if ( false === $size ) {
				return $error;
			}
			$total += $size;
			$largest = max( $largest, $size );
			$expected_sizes[ $name ] = $size;
			$entries[] = array( PCLZIP_ATT_FILE_NAME => $file, PCLZIP_ATT_FILE_NEW_FULL_NAME => $name );
		}
		foreach ( $metadata as $name => $contents ) {
			$expected_sizes[ $name ] = strlen( $contents );
			$entries[] = array( PCLZIP_ATT_FILE_NAME => $name, PCLZIP_ATT_FILE_CONTENT => $contents );
		}
		if ( count( $entries ) >= 65535 || $total >= 2000000000 || ( $limit > 0 && $largest > ( $limit - memory_get_usage( true ) ) / 4 ) ) {
			return new \WP_Error( 'backup_zip_limit', 'Os arquivos excedem o limite de compactação do PclZip ou da memória do PHP. Habilite a extensão ZIP e tente novamente.' );
		}
		$result = $zip->create( $entries, PCLZIP_OPT_TEMP_FILE_OFF );
		if ( ! is_array( $result ) || count( $result ) !== count( $expected_sizes ) ) {
			return $error;
		}
		foreach ( $result as $entry ) {
			$name = $entry['stored_filename'];
			if ( 'ok' !== $entry['status'] || ! isset( $expected_sizes[ $name ] ) || $entry['size'] !== $expected_sizes[ $name ] ) {
				return $error;
			}
			unset( $expected_sizes[ $name ] );
		}
		return empty( $expected_sizes ) ? true : $error;
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

		$history_file = trailingslashit( $backup_dir ) . '.gamb-backup-history.json';
		$history = is_file( $history_file ) ? json_decode( file_get_contents( $history_file ), true ) : array();
		$history = is_array( $history ) ? $history : array();
		$list = array();
		foreach ( $files as $file ) {
			$fname = basename( $file );
			if ( is_link( $file ) || ! preg_match( '/^gamb-(?:db|full)-backup-[A-Za-z0-9-]+\.(?:zip|sql)$/', $fname ) ) {
				continue;
			}
			$size   = (int) filesize( $file );
			$mtime  = $history[$fname] ?? (int) filemtime( $file );
			$is_db  = strpos( $fname, "gamb-db-" ) === 0;
			$list[] = array(
				"filename"       => $fname,
				"type_label"     => $is_db ? "Banco de Dados (SQL)" : "Completo (SQL + Arquivos)",
				"size"           => $size,
				"size_formatted" => size_format( $size ),
				"created_at"     => date_i18n( "d/m/Y H:i:s", (int) $mtime ),
				"timestamp"      => $mtime,
				"download_url"   => admin_url( "admin-post.php?action=gamb_download_backup&file=" . rawurlencode( $fname ) . "&_wpnonce=" . wp_create_nonce( "gamb_download_backup_" . $fname ) ),
			);
		}

		usort( $list, function ( $a, $b ) {
			return ( $b["timestamp"] <=> $a["timestamp"] ) ?: strcmp( $b["filename"], $a["filename"] );
		} );

		return $list;
	}

	public static function delete_backup( $filename ) {
		if ( ! is_string( $filename ) || ! preg_match( '/^gamb-(?:db|full)-backup-[A-Za-z0-9-]+\.(?:zip|sql)$/', $filename ) ) { return false; }
		$base = realpath( self::get_backup_dir() );
		$path = $base ? $base . DIRECTORY_SEPARATOR . $filename : '';
		$real = $path ? realpath( $path ) : false;
		return $real && dirname( $real ) === $base && ! is_link( $path ) && is_file( $path ) ? unlink( $path ) : false;
	}

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
				"label"    => "Suporte ZIP",
				"ok"       => '' !== self::zip_backend(),
				"detail"   => match ( self::zip_backend() ) {
					'native' => "Extensão ZIP nativa disponível.",
					'pclzip' => "PclZip disponível para o backup completo. O backup de banco é gerado em SQL.",
					default  => "Habilite ZIP ou zlib para gerar pacotes de backup.",
				},
			),
			array(
				"category" => "1. STATIC - Diretórios",
				"label"    => "Diretório de Exportação Estática Gravável",
				"ok"       => is_dir( $archive_dir ) && is_writable( $archive_dir ),
				"detail"   => $archive_dir,
			),
			array(
				"category" => "2. GUARD - Segurança & Ocultação",
				"label"    => "Tokenização Criptográfica Universal (/v/<token>)",
				"ok"       => (bool) $opts->get( "guard_universal_token_cloaking", true ),
				"detail"   => $opts->get( "guard_universal_token_cloaking", true ) ? "Ativa (ocultando caminhos de tema, imagens, plugins e wp-includes)." : "Desativada.",
			),
			array(
				"category" => "2. GUARD - Segurança & Ocultação",
				"label"    => "Bloqueio de XML-RPC & REST API Anônima",
				"ok"       => (bool) $opts->get( "disable_xmlrpc", true ) && (bool) $opts->get( "guard_restrict_rest_api", true ),
				"detail"   => "Protegendo endpoints /xmlrpc.php e /wp-json/wp/v2/* contra raspagem e força bruta.",
			),
			array(
				"category" => "2. GUARD - Segurança & Ocultação",
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
