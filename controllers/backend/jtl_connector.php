<?php
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Tygh;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_REQUEST['mode'] ?? '';

    if ($mode === 'reset_watchdog') {
        $company_id = (int)($_REQUEST['company_id'] ?? 0);
        if ($company_id > 0) {
            db_query('UPDATE ?:jtl_connector_watchdog_state SET ?u WHERE company_id=?i', [
                'last_ok_ts' => 0,
                'last_error_ts' => 0,
                'consecutive_errors' => 0,
                'last_error' => null,
                'last_notified_ts' => 0,
            ], $company_id);
            fn_set_notification('N', __('notice'), 'Watchdog state reset');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'run_watchdog_tick') {
        if (function_exists('fn_jtl_connector_watchdog_tick')) {
            fn_jtl_connector_watchdog_tick();
            fn_set_notification('N', __('notice'), 'Watchdog tick executed');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'run_pruner') {
        if (function_exists('fn_jtl_connector_prune_logs')) {
            $res = fn_jtl_connector_prune_logs();
            fn_set_notification('N', __('notice'), sprintf(
                'Pruner done. request_log=%d, debug_event=%d, rate_limit=%d, payload_sample=%d, admin_alert=%d',
                (int)($res['request_log'] ?? 0),
                (int)($res['debug_event'] ?? 0),
                (int)($res['rate_limit'] ?? 0),
                (int)($res['payload_sample'] ?? 0),
                (int)($res['admin_alert'] ?? 0)
            ));
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'send_test_email') {
        if (function_exists('fn_jtl_connector_send_admin_email')) {
            $ok = fn_jtl_connector_send_admin_email(0, '[CS-Cart JTL Connector] Test email', "This is a test email from the JTL Connector add-on.\n\nIf you received this, your mail settings are working.\n");
            fn_set_notification($ok ? 'N' : 'E', __('notice'), $ok ? 'Test email sent (best-effort)' : 'Unable to send test email');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'mark_alert_read') {
        $alert_id = (int)($_REQUEST['alert_id'] ?? 0);
        if ($alert_id > 0 && function_exists('fn_jtl_connector_mark_alert_read')) {
            fn_jtl_connector_mark_alert_read($alert_id);
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'delete_alert') {
        $alert_id = (int)($_REQUEST['alert_id'] ?? 0);
        if ($alert_id > 0 && function_exists('fn_jtl_connector_delete_alert')) {
            fn_jtl_connector_delete_alert($alert_id);
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'clear_alerts') {
        $which = (string)($_REQUEST['which'] ?? 'read');
        if (function_exists('fn_jtl_connector_clear_alerts')) {
            fn_jtl_connector_clear_alerts($which);
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }

    if ($mode === 'toggle_internal_scheduler') {
        $enabled = (string)($_REQUEST['enabled'] ?? 'N');
        if (function_exists('fn_jtl_connector_set_addon_setting_value')) {
            fn_jtl_connector_set_addon_setting_value('internal_scheduler_enabled', $enabled === 'Y' ? 'Y' : 'N');
            fn_set_notification('N', __('notice'), $enabled === 'Y' ? 'Internal scheduler enabled (traffic-based).' : 'Internal scheduler disabled.');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'jtl_connector.manage'];
    }
}

if ($mode === 'manage' || $mode === '') {
    fn_jtl_connector_ensure_runtime_tables();

    $vendors = db_get_array(
        'SELECT c.company_id, c.company, v.enabled, v.token, ws.last_ok_ts, ws.last_error_ts, ws.consecutive_errors, ws.last_error '
        . 'FROM ?:companies c '
        . 'LEFT JOIN ?:jtl_connector_vendor v ON v.company_id=c.company_id '
        . 'LEFT JOIN ?:jtl_connector_watchdog_state ws ON ws.company_id=c.company_id '
        . 'ORDER BY c.company_id ASC'
    );

    foreach ($vendors as &$row) {
        $row['endpoint_url'] = fn_jtl_connector_get_endpoint_url((int)$row['company_id']);
        $row['token_tail'] = $row['token'] ? ('…' . substr((string)$row['token'], -6)) : '';
    }
    unset($row);

    Tygh::$app['view']->assign('vendors', $vendors);

    $company_ids = array_map(static fn($r) => (int)$r['company_id'], $vendors);
    $alerts = function_exists('fn_jtl_connector_get_admin_alerts') ? fn_jtl_connector_get_admin_alerts(true, 50) : [];
    $samples = function_exists('fn_jtl_connector_get_payload_samples') ? fn_jtl_connector_get_payload_samples($company_ids, 300) : [];

    // Group samples by company for display (keep a few latest per company+entity).
    $samples_by_company = [];
    foreach ($samples as $s) {
        $cid = (int)($s['company_id'] ?? 0);
        $entity = (string)($s['entity'] ?? '');
        if (!isset($samples_by_company[$cid])) {
            $samples_by_company[$cid] = [];
        }
        if (!isset($samples_by_company[$cid][$entity])) {
            $samples_by_company[$cid][$entity] = [];
        }
        if (count($samples_by_company[$cid][$entity]) >= 3) {
            continue;
        }
        $samples_by_company[$cid][$entity][] = $s;
    }

    Tygh::$app['view']->assign('alerts', $alerts);
    Tygh::$app['view']->assign('samples_by_company', $samples_by_company);
    Tygh::$app['view']->assign('settings', [
        'enabled_global' => fn_jtl_connector_get_addon_setting('enabled', 'N'),
        'watchdog_enabled' => fn_jtl_connector_get_addon_setting('watchdog_enabled', 'Y'),
        'verbose_enabled' => fn_jtl_connector_get_addon_setting('verbose_enabled', 'N'),
        'debug_enabled' => fn_jtl_connector_get_addon_setting('debug_enabled', 'N'),
        'admin_email' => fn_jtl_connector_get_admin_email(),
        'admin_alerts_unread' => function_exists('fn_jtl_connector_get_unread_alert_count') ? fn_jtl_connector_get_unread_alert_count() : 0,
        'cron_url_watchdog' => function_exists('fn_jtl_connector_get_cron_url') ? fn_jtl_connector_get_cron_url('watchdog_tick') : '',
        'cron_url_pruner' => function_exists('fn_jtl_connector_get_cron_url') ? fn_jtl_connector_get_cron_url('prune_logs') : '',
        'internal_scheduler_enabled' => fn_jtl_connector_get_addon_setting('internal_scheduler_enabled', 'N'),
    ]);

    Tygh::$app['view']->display('addons/jtl_connector/views/jtl_connector/backend_manage.tpl');
    exit;
}
