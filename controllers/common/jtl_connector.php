<?php
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Registry;
use Tygh\Tygh;

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

    \CsCartJtlConnector\Bootstrap::runEndpoint($company_id, (string)$vendor_row['token']);
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

