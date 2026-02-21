<?php
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Registry;
use Tygh\Tygh;

$auth = & Tygh::$app['session']['auth'];
$company_id = (int)($auth['company_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_REQUEST['mode'] ?? '';

    $row = fn_jtl_connector_get_vendor_row($company_id);

    if ($mode === 'save') {
        $row['enabled'] = !empty($_REQUEST['enabled']) ? 'Y' : 'N';
        if (empty($row['token'])) {
            $row['token'] = fn_jtl_connector_generate_token();
        }
        fn_jtl_connector_upsert_vendor($row);
        fn_set_notification('N', __('notice'), __('saved'));
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'rotate_token') {
        $row['token'] = fn_jtl_connector_generate_token();
        fn_jtl_connector_upsert_vendor($row);
        fn_set_notification('N', __('notice'), 'Token rotated');
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }
}

if ($mode === 'manage' || $mode === '') {
    $row = fn_jtl_connector_get_vendor_row($company_id);

    Tygh::$app['view']->assign('vendor_row', $row);
    Tygh::$app['view']->assign('company_id', $company_id);
    Tygh::$app['view']->assign('endpoint_url', fn_jtl_connector_get_endpoint_url($company_id));
    Tygh::$app['view']->assign('enabled_global', fn_jtl_connector_get_addon_setting('enabled', 'N'));

    Tygh::$app['view']->display('addons/jtl_connector/views/jtl_connector/manage.tpl');
    exit;
}

