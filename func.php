<?php
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Registry;

function fn_jtl_connector_get_addon_setting(string $name, $default = null)
{
    $val = Registry::get('addons.jtl_connector.' . $name);
    return $val !== null ? $val : $default;
}

function fn_jtl_connector_now(): int
{
    return time();
}

function fn_jtl_connector_generate_token(): string
{
    return bin2hex(random_bytes(24));
}

function fn_jtl_connector_get_vendor_row(int $company_id): array
{
    $row = db_get_row('SELECT * FROM ?:jtl_connector_vendor WHERE company_id = ?i', $company_id);
    if (!$row) {
        $now = fn_jtl_connector_now();
        $row = [
            'company_id' => $company_id,
            'enabled' => 'N',
            'token' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        db_query('INSERT INTO ?:jtl_connector_vendor ?e', $row);
    }
    return $row;
}

function fn_jtl_connector_upsert_vendor(array $data): void
{
    $data['updated_at'] = fn_jtl_connector_now();
    db_query('REPLACE INTO ?:jtl_connector_vendor ?e', $data);
}

function fn_jtl_connector_get_endpoint_url(int $company_id): string
{
    // JTL-Wawi accepts query strings; no need for webserver rewrites.
    // Example: https://marketplace.tld/index.php?dispatch=jtl_connector.endpoint&company_id=123
    return fn_url('jtl_connector.endpoint?company_id=' . $company_id, 'C');
}

function fn_jtl_connector_get_db_params(): array
{
    // CS-Cart usually exposes db config via Registry::get('config.db_*')
    $cfg = Registry::get('config');
    $host = $cfg['db_host'] ?? (defined('DB_HOST') ? DB_HOST : 'localhost');
    $name = $cfg['db_name'] ?? (defined('DB_NAME') ? DB_NAME : '');
    $user = $cfg['db_user'] ?? (defined('DB_USER') ? DB_USER : '');
    $pass = $cfg['db_password'] ?? (defined('DB_PASSWORD') ? DB_PASSWORD : '');
    return [
        'host' => $host,
        'name' => $name,
        'username' => $user,
        'password' => $pass,
    ];
}

function fn_jtl_connector_is_multivendor(): bool
{
    return (Registry::get('settings.General.multi_vendor') === 'Y');
}

function fn_jtl_connector_get_company_scope_from_request(): int
{
    // Multi-vendor: company_id is required; Single store: company_id=0.
    $company_id = (int)($_REQUEST['company_id'] ?? 0);
    if (!fn_jtl_connector_is_multivendor()) {
        return 0;
    }
    return $company_id;
}

