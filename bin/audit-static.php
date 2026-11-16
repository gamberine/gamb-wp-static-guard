<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$arguments = getopt('', array('wp-root:', 'directory:', 'revalidate', 'help'));
if (isset($arguments['help']) || !isset($arguments['directory']) && !isset($arguments['revalidate'])) {
    fwrite(STDOUT, "Uso: php bin/audit-static.php --wp-root=/wordpress --directory=/publicacao\nOu: php bin/audit-static.php --wp-root=/wordpress --revalidate\n"); exit(isset($arguments['help']) ? 0 : 2);
}
$root = realpath((string)($arguments['wp-root'] ?? getcwd()));
if (!$root || !is_file($root . '/wp-load.php')) { fwrite(STDERR, "Informe uma instalação WordPress válida em --wp-root.\n"); exit(2); }
define('DISABLE_WP_CRON', true);
ob_start(); require $root . '/wp-load.php'; ob_end_clean();
if (!class_exists('\\GambWPStaticGuard\\Gamb_Static_Auditor')) { fwrite(STDERR, "Ative uma versão compatível do Gamb WP Static Guard nesta instalação.\n"); exit(2); }
try {
    if (isset($arguments['revalidate'])) { \GambWPStaticGuard\Gamb_Static_Auditor::revalidate(); $report = get_option(\GambWPStaticGuard\Gamb_Static_Auditor::REPORT_OPTION, array()); }
    else { $report = \GambWPStaticGuard\Gamb_Static_Auditor::audit_directory((string)$arguments['directory']); }
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(!empty($report['success']) ? 0 : 1);
} catch (Throwable $error) {
    $report = get_option(\GambWPStaticGuard\Gamb_Static_Auditor::REPORT_OPTION, array());
    fwrite(STDOUT, json_encode(array('success' => false, 'message' => 'CRITICAL: auditoria interrompida.', 'report' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
