<?php
/**
 * CS-Cart JTL Connector: Log pruner.
 *
 * Run via cron to prune request logs / debug events / rate-limit windows according to add-on settings.
 *
 * Example:
 *   php app/addons/jtl_connector/cli/prune_logs.php
 */

$root = dirname(__DIR__, 3); // .../app

if (!defined('BOOTSTRAP')) {
    define('BOOTSTRAP', true);
}

$candidates = [
    $root . '/init.php',
    dirname($root) . '/init.php',
    dirname($root) . '/app/init.php',
];

$boot = null;
foreach ($candidates as $p) {
    if (is_file($p)) {
        $boot = $p;
        break;
    }
}

if ($boot === null) {
    fwrite(STDERR, "Unable to locate CS-Cart init.php. Adjust bootstrap path in cli/prune_logs.php\n");
    exit(2);
}

require_once $boot;

if (function_exists('fn_jtl_connector_prune_logs')) {
    $res = fn_jtl_connector_prune_logs();
    echo "Prune done. request_log=" . (int)($res['request_log'] ?? 0)
        . ", debug_event=" . (int)($res['debug_event'] ?? 0)
        . ", rate_limit=" . (int)($res['rate_limit'] ?? 0)
        . ", payload_sample=" . (int)($res['payload_sample'] ?? 0)
        . ", admin_alert=" . (int)($res['admin_alert'] ?? 0) . "\n";
    exit(0);
}

fwrite(STDERR, "Pruner function not found. Is the add-on installed and enabled?\n");
exit(1);
