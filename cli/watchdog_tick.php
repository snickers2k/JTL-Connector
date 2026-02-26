<?php
/**
 * CS-Cart JTL Connector: Watchdog tick.
 *
 * Run via cron to detect "idle" situations (no successful sync for N minutes)
 * and alert admins.
 *
 * Example:
 *   php app/addons/jtl_connector/cli/watchdog_tick.php
 */

// Attempt to bootstrap CS-Cart from the add-on directory.
$root = dirname(__DIR__, 3); // .../app

if (!defined('BOOTSTRAP')) {
    define('BOOTSTRAP', true);
}

// CS-Cart bootstrap path: app/init.php (varies by install). Try common locations.
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
    fwrite(STDERR, "Unable to locate CS-Cart init.php. Adjust bootstrap path in cli/watchdog_tick.php\n");
    exit(2);
}

require_once $boot;

if (function_exists('fn_jtl_connector_watchdog_tick')) {
    fn_jtl_connector_watchdog_tick();
    echo "Watchdog tick done\n";
    exit(0);
}

fwrite(STDERR, "Watchdog function not found. Is the add-on installed and enabled?\n");
exit(1);
