<?php
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Tygh;

$mode = $mode ?? ($_REQUEST['mode'] ?? '');

// Lightweight cron endpoint (token-protected), usable by external schedulers.
// Example:
//   /index.php?dispatch=jtl_connector.cron&task=watchdog_tick&token=... 
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $mode === 'cron') {
    $token = (string)($_REQUEST['token'] ?? '');
    $task = (string)($_REQUEST['task'] ?? '');

    $expected = '';
    if (function_exists('fn_jtl_connector_get_cron_token')) {
        $expected = fn_jtl_connector_get_cron_token();
    }

    if ($expected === '' || !hash_equals($expected, $token)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    $ran = [];
    try {
        if ($task === 'watchdog_tick' || $task === 'all' || $task === '') {
            if (function_exists('fn_jtl_connector_watchdog_tick')) {
                fn_jtl_connector_watchdog_tick();
                $ran[] = 'watchdog_tick';
            }
        }
        if ($task === 'prune_logs' || $task === 'all') {
            if (function_exists('fn_jtl_connector_prune_logs')) {
                fn_jtl_connector_prune_logs();
                $ran[] = 'prune_logs';
            }
        }
    } catch (\Throwable $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Error: ' . $e->getMessage();
        exit;
    }

    echo 'OK ' . implode(',', $ran);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Direct JTL connector endpoint handler
    $enabled = fn_jtl_connector_get_addon_setting('enabled', 'N') === 'Y';
    if (!$enabled) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'JTL Connector disabled';
        exit;
    }

    $company_id = fn_jtl_connector_get_company_scope_from_request();
    $vendor_row = fn_jtl_connector_get_vendor_row($company_id);

    if (fn_jtl_connector_is_multivendor() && $company_id <= 0) {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'company_id is required in Multi-Vendor mode';
        exit;
    }

    if (($vendor_row['enabled'] ?? 'N') !== 'Y') {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Vendor connector disabled';
        exit;
    }

    if (empty($vendor_row['token'])) {
        header('HTTP/1.1 409 Conflict');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Vendor connector token not configured';
        exit;
    }

    // Rate limiting + request audit (best-effort; does not inspect JSON body)
    $log_id = fn_jtl_connector_log_request_start($company_id);
    if (!fn_jtl_connector_rate_limit_allow($company_id)) {
        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Rate limit exceeded';
        fn_jtl_connector_log_request_finish($log_id, false, 0, 'rate_limit');
        fn_jtl_connector_watchdog_update($company_id, false, 'rate_limit');
        fn_jtl_connector_debug_event($company_id, 'WARN', 'rate_limit', 'Rate limit exceeded', [], null, $log_id);
        exit;
    }

    // Boot connector runtime (composer autoload inside addon)
    $autoload = __DIR__ . '/../../lib/jtl_connector_runtime/vendor/autoload.php';
    if (!file_exists($autoload)) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing composer dependencies. Run: composer install in app/addons/jtl_connector/lib/jtl_connector_runtime';
        exit;
    }
    require_once $autoload;

    require_once __DIR__ . '/../../src/Bootstrap.php';

    $t0 = microtime(true);
    try {
        \CsCartJtlConnector\Bootstrap::runEndpoint($company_id, (string)$vendor_row['token']);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        fn_jtl_connector_log_request_finish($log_id, true, $ms, null);
        fn_jtl_connector_watchdog_update($company_id, true, null);

        // Verbose mode stores structured payload samples inside entity push controllers.
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        // Avoid leaking sensitive stack traces to clients; keep detail in logs.
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Connector error';
        fn_jtl_connector_log_request_finish($log_id, false, $ms, $e->getMessage());
        fn_jtl_connector_watchdog_update($company_id, false, $e->getMessage());
        fn_jtl_connector_debug_event($company_id, 'ERROR', 'endpoint_exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], null, $log_id);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Health / info page for humans
    $company_id = fn_jtl_connector_get_company_scope_from_request();
    $vendor_row = fn_jtl_connector_get_vendor_row($company_id);

    Tygh::$app['view']->assign('company_id', $company_id);
    Tygh::$app['view']->assign('vendor_row', $vendor_row);
    Tygh::$app['view']->assign('endpoint_url', fn_jtl_connector_get_endpoint_url($company_id));
    Tygh::$app['view']->display('addons/jtl_connector/views/jtl_connector/info.tpl');
    exit;
}
