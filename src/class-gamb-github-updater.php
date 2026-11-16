<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

final class Gamb_GitHub_Updater {
    public const TOKEN_OPTION = 'gamb_wp_static_guard_github_token';
    private const SLUG = 'gamb-wp-static-guard';
    private bool $downloading = false;
    private static ?self $instance = null;
    public static function instance(): self { return self::$instance ??= new self(); }
    public static function register(): void {
        $updater = self::instance();
        add_filter('pre_set_site_transient_update_plugins', array($updater, 'filter_updates'));
        add_filter('plugins_api', array($updater, 'plugin_information'), 20, 3);
        add_filter('http_request_args', array($updater, 'download_headers'), 20, 2);
        add_filter('upgrader_pre_download', array($updater, 'pre_download'), 20, 4);
        add_filter('upgrader_source_selection', array($updater, 'source_selection'), 20, 4);
        add_action('upgrader_process_complete', array($updater, 'after_update'), 20, 2);
        add_action('gamb_static_guard_post_update_audit', array(Gamb_Static_Auditor::class, 'revalidate'));
        add_action('admin_notices', array($updater, 'audit_notice'));
        if (get_option('gamb_wp_static_guard_audit_version', '') !== GAMB_STATIC_WP_VERSION) {
            update_option('gamb_wp_static_guard_audit_version', GAMB_STATIC_WP_VERSION, false);
            Gamb_Static_Auditor::mark_pending();
        }
    }
    public static function valid_repository(string $repository): bool { return (bool)preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$~D', $repository); }
    public function repository(): string {
        $repository = defined('GAMB_GITHUB_REPOSITORY') ? (string)constant('GAMB_GITHUB_REPOSITORY') : (string)Options::instance()->get('github_update_repository', 'gamberine/gamb-wp-static-guard');
        if (!self::valid_repository($repository)) { throw new \RuntimeException('Repositório GitHub inválido. Informe owner/repo.'); }
        return $repository;
    }
    public function plugin_file(): string { return self::SLUG . '/' . self::SLUG . '.php'; }
    private function cache_key(): string { return 'gamb_github_' . hash('sha256', $this->repository() . '|' . hash('sha256', $this->token())); }
    public function clear_cache(): void { try { delete_transient($this->cache_key()); } catch (\RuntimeException $error) { /* Configuração inválida será apresentada na verificação administrativa. */ } delete_site_transient('update_plugins'); }

    public function token(): string {
        if (defined('GAMB_GITHUB_TOKEN')) { return trim((string)constant('GAMB_GITHUB_TOKEN')); }
        $stored = get_option(self::TOKEN_OPTION, '');
        if (!is_string($stored) || '' === $stored || !function_exists('openssl_decrypt')) { return ''; }
        $bytes = base64_decode($stored, true);
        if (false === $bytes || strlen($bytes) < 29) { return ''; }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth') . '|gamb-github', true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), self::SLUG);
        return false === $plain ? '' : $plain;
    }
    public function set_token(string $token): bool {
        if ('' === trim($token)) { delete_option(self::TOKEN_OPTION); return true; }
        if (!function_exists('openssl_encrypt') || strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token)) { return false; }
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($token, 'aes-256-gcm', hash('sha256', wp_salt('auth') . '|gamb-github', true), OPENSSL_RAW_DATA, $iv, $tag, self::SLUG);
        if (false === $encrypted) { return false; }
        update_option(self::TOKEN_OPTION, base64_encode($iv . $tag . $encrypted), false);
        return true;
    }
    private function api_headers(): array {
        $headers = array('Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Gamb-WP-Static-Guard/' . GAMB_STATIC_WP_VERSION);
        $token = $this->token(); if ('' !== $token) { $headers['Authorization'] = 'Bearer ' . $token; }
        return $headers;
    }

    public function normalize_release(array $release): ?array {
        if (!empty($release['draft']) || !empty($release['prerelease']) || !preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?)$/D', (string)($release['tag_name'] ?? ''), $matches)) { return null; }
        $repository = $this->repository(); $asset = null;
        foreach ((array)($release['assets'] ?? array()) as $candidate) {
            if (!is_array($candidate) || !preg_match('/\.zip$/iD', (string)($candidate['name'] ?? ''))) { continue; }
            if (!$this->allowed_initial_url((string)($candidate['browser_download_url'] ?? ''), $repository)) { continue; }
            if (null === $asset || in_array($candidate['name'], array(self::SLUG . '-' . $matches[1] . '.zip', self::SLUG . '.zip'), true)) { $asset = $candidate; }
        }
        $package = $asset['browser_download_url'] ?? ($release['zipball_url'] ?? '');
        if ($asset && '' !== $this->token() && $this->allowed_initial_url((string)($asset['url'] ?? ''), $repository)) { $package = $asset['url']; }
        if (!$this->allowed_initial_url((string)$package, $repository)) { return null; }
        $digest = is_string($asset['digest'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/D', $asset['digest']) ? substr($asset['digest'], 7) : '';
        return array('version' => $matches[1], 'package' => $package, 'url' => 'https://github.com/' . $repository . '/releases/tag/' . rawurlencode($release['tag_name']), 'body' => substr((string)($release['body'] ?? ''), 0, 100000), 'digest' => $digest);
    }
    public function latest(bool $force = false): ?array {
        try { $repository = $this->repository(); $key = $this->cache_key(); } catch (\RuntimeException $error) { return null; }
        $cached = get_transient($key);
        if (!$force && is_array($cached)) { return empty($cached['version']) ? null : $cached; }
        $response = wp_safe_remote_get('https://api.github.com/repos/' . $this->repository() . '/releases/latest', array('timeout' => 15, 'redirection' => 0, 'limit_response_size' => 1048576, 'headers' => $this->api_headers()));
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) { set_transient($key, array(), 5 * MINUTE_IN_SECONDS); return null; }
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        $release = is_array($decoded) ? $this->normalize_release($decoded) : null;
        set_transient($key, $release ?? array(), 12 * HOUR_IN_SECONDS);
        return $release;
    }
    public function filter_updates(mixed $transient): mixed {
        if (!is_object($transient)) { return $transient; }
        $release = $this->latest();
        if (!$release) { return $transient; }
        $item = (object)array('id' => 'https://github.com/' . $this->repository(), 'slug' => self::SLUG, 'plugin' => $this->plugin_file(), 'new_version' => $release['version'], 'url' => $release['url'], 'package' => $release['package'], 'requires' => '6.2', 'requires_php' => '8.1');
        if (version_compare($release['version'], GAMB_STATIC_WP_VERSION, '>')) { $transient->response[$this->plugin_file()] = $item; unset($transient->no_update[$this->plugin_file()]); }
        else { $transient->no_update[$this->plugin_file()] = $item; unset($transient->response[$this->plugin_file()]); }
        return $transient;
    }
    public function plugin_information(mixed $result, string $action, mixed $args): mixed {
        if ('plugin_information' !== $action || !is_object($args) || ($args->slug ?? '') !== self::SLUG) { return $result; }
        $release = $this->latest(); if (!$release) { return $result; }
        return (object)array('name' => 'Gamb WP Static Guard', 'slug' => self::SLUG, 'version' => $release['version'], 'author' => 'Gamb', 'homepage' => 'https://github.com/' . $this->repository(), 'requires' => '6.2', 'requires_php' => '8.1', 'download_link' => $release['package'], 'sections' => array('description' => 'Publicação estática com auditoria e controles de exposição.', 'changelog' => wp_kses_post(wpautop(esc_html($release['body'])))));
    }
    private function own_operation(array $extra): bool {
        if (isset($extra['plugin'])) { return $extra['plugin'] === $this->plugin_file(); }
        $plugins = (array)($extra['plugins'] ?? array());
        return count($plugins) === 1 && in_array($this->plugin_file(), $plugins, true);
    }
    public function allowed_initial_url(string $url, ?string $repository = null): bool {
        $repository ??= $this->repository(); $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) { return false; }
        $path = $parts['path'] ?? ''; $host = strtolower($parts['host'] ?? '');
        return ('github.com' === $host && str_starts_with($path, '/' . $repository . '/releases/download/')) || ('api.github.com' === $host && (preg_match('~^/repos/' . preg_quote($repository, '~') . '/releases/assets/\d+$~D', $path) || str_starts_with($path, '/repos/' . $repository . '/zipball/')));
    }
    private function allowed_redirect(string $url): bool {
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) { return false; }
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, array('release-assets.githubusercontent.com', 'objects.githubusercontent.com'), true)) { return true; }
        if ('codeload.github.com' === $host) { return str_starts_with($parts['path'] ?? '', '/' . $this->repository() . '/'); }
        return $this->allowed_initial_url($url);
    }
    public function download_headers(array $args, string $url): array {
        if (!$this->downloading) { return $args; }
        $args['headers'] = (array)($args['headers'] ?? array());
        foreach (array_keys($args['headers']) as $key) { if (strtolower((string)$key) === 'authorization') { unset($args['headers'][$key]); } }
        if ('api.github.com' === wp_parse_url($url, PHP_URL_HOST) && $this->allowed_initial_url($url)) {
            $args['headers'] = array_merge($args['headers'], $this->api_headers()); $args['headers']['Accept'] = 'application/octet-stream';
        }
        return $args;
    }
    public function pre_download(mixed $reply, string $package, mixed $upgrader, array $extra): mixed {
        if (false !== $reply || !$this->own_operation($extra)) { return $reply; }
        $release = $this->latest();
        if (!$release || !hash_equals($release['package'], $package) || !$this->allowed_initial_url($package)) { return new \WP_Error('gamb_untrusted_package', 'Pacote GitHub não autorizado para esta atualização.'); }
        $file = wp_tempnam('gamb-static-guard.zip');
        if (!$file) { return new \WP_Error('gamb_temp_file', 'Não foi possível preparar o pacote de atualização.'); }
        $this->downloading = true; $url = $package;
        try {
            for ($redirect = 0; $redirect < 5; $redirect++) {
                if (!$this->allowed_redirect($url)) { throw new \RuntimeException('Destino do download recusado.'); }
                $response = wp_safe_remote_get($url, array('timeout' => 60, 'redirection' => 0, 'stream' => true, 'filename' => $file, 'limit_response_size' => 104857601, 'headers' => $this->download_headers(array(), $url)['headers']));
                if (is_wp_error($response)) { throw new \RuntimeException('Falha de comunicação durante o download.'); }
                $status = (int)wp_remote_retrieve_response_code($response);
                if (in_array($status, array(301, 302, 303, 307, 308), true)) { $url = \WP_Http::make_absolute_url((string)wp_remote_retrieve_header($response, 'location'), $url); continue; }
                if ($status !== 200 || !is_file($file) || filesize($file) < 4 || filesize($file) > 104857600) { throw new \RuntimeException('Pacote vazio, inválido ou acima do limite de 100 MiB.'); }
                $handle = fopen($file, 'rb'); $signature = $handle ? fread($handle, 2) : ''; if ($handle) { fclose($handle); }
                if ('PK' !== $signature || ('' !== $release['digest'] && !hash_equals($release['digest'], hash_file('sha256', $file)))) { throw new \RuntimeException('Integridade do pacote GitHub inválida.'); }
                return $file;
            }
            throw new \RuntimeException('Limite de redirecionamentos do pacote atingido.');
        } catch (\Throwable $error) {
            if (is_file($file)) { unlink($file); }
            return new \WP_Error('gamb_download_failed', $error->getMessage());
        } finally { $this->downloading = false; }
    }
    public function source_selection(mixed $source, string $remote_source, mixed $upgrader, array $extra): mixed {
        if (is_wp_error($source) || !$this->own_operation($extra)) { return $source; }
        global $wp_filesystem;
        $source = trailingslashit(wp_normalize_path((string)$source)); $parent = trailingslashit(wp_normalize_path($remote_source));
        if (!str_starts_with($source, $parent) || str_contains(substr($source, strlen($parent)), '..') || !$wp_filesystem || !$wp_filesystem->exists($source . self::SLUG . '.php')) { return new \WP_Error('gamb_invalid_source', 'A distribuição não contém o plugin esperado.'); }
        $destination = $parent . self::SLUG . '/';
        if ($source === $destination) { return $source; }
        if ($wp_filesystem->exists($destination) || !$wp_filesystem->move(untrailingslashit($source), untrailingslashit($destination))) { return new \WP_Error('gamb_source_rename', 'Não foi possível normalizar a pasta do plugin.'); }
        return $destination;
    }
    public function after_update(mixed $upgrader, array $extra): void {
        if (($extra['action'] ?? '') !== 'update' || !in_array($extra['type'] ?? '', array('core', 'theme', 'plugin'), true)) { return; }
        Gamb_Static_Sanitizer::invalidate_sri_cache(); $this->clear_cache(); Gamb_Static_Auditor::mark_pending();
    }
    public function audit_notice(): void {
        if (!current_user_can('manage_options')) { return; }
        $pending = get_option(Gamb_Static_Auditor::PENDING_OPTION, false);
        $report = get_option(Gamb_Static_Auditor::REPORT_OPTION, array());
        if (!$pending && (empty($report) || !empty($report['success']))) { return; }
        echo '<div class="notice notice-warning"><p>' . esc_html($pending ? 'Gamb Static Guard: revalidação de segurança agendada após atualização.' : 'Gamb Static Guard: a auditoria encontrou impedimentos. Consulte Atualizações e auditoria antes de publicar.') . ' <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">Abrir painel</a></p></div>';
    }
}
