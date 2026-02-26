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

/**
 * Creates runtime tables if they are missing.
 * This is a defensive fallback for add-on upgrades, so the endpoint doesn't
 * hard-fail if SQL migrations haven't been executed.
 */
function fn_jtl_connector_ensure_runtime_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Minimal schema for request logs & rate limiting + debug + watchdog.
    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_request_log (
            log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            ts INT UNSIGNED NOT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT \'\',
            user_agent VARCHAR(255) NOT NULL DEFAULT \'\',
            content_length INT UNSIGNED NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            ok CHAR(1) NOT NULL DEFAULT \'Y\',
            error TEXT NULL,
            PRIMARY KEY (log_id),
            KEY idx_company_ts (company_id, ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_rate_limit (
            company_id INT UNSIGNED NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            cnt INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (company_id, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_debug_event (
            event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            ts INT UNSIGNED NOT NULL,
            level VARCHAR(8) NOT NULL DEFAULT \'INFO\',
            code VARCHAR(64) NOT NULL DEFAULT \'\',
            message TEXT NULL,
            context_json MEDIUMTEXT NULL,
            payload_snippet MEDIUMTEXT NULL,
            log_id INT UNSIGNED NULL,
            PRIMARY KEY (event_id),
            KEY idx_company_ts (company_id, ts),
            KEY idx_log_id (log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_watchdog_state (
            company_id INT UNSIGNED NOT NULL,
            last_ok_ts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error_ts INT UNSIGNED NOT NULL DEFAULT 0,
            consecutive_errors INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            last_notified_ts INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_variation_parent (
            company_id INT UNSIGNED NOT NULL,
            group_code VARCHAR(64) NOT NULL,
            parent_product_id INT UNSIGNED NOT NULL,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (company_id, group_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_admin_alert (
            alert_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL DEFAULT 0,
            ts INT UNSIGNED NOT NULL,
            level VARCHAR(8) NOT NULL DEFAULT \'ERROR\',
            code VARCHAR(64) NOT NULL DEFAULT \'\',
            title VARCHAR(255) NOT NULL DEFAULT \'\',
            message TEXT NULL,
            is_read CHAR(1) NOT NULL DEFAULT \'N\',
            hash CHAR(40) NOT NULL DEFAULT \'\',
            PRIMARY KEY (alert_id),
            KEY idx_unread_ts (is_read, ts),
            KEY idx_company_ts (company_id, ts),
            KEY idx_hash (hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_payload_sample (
            sample_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            ts INT UNSIGNED NOT NULL,
            entity VARCHAR(64) NOT NULL,
            direction VARCHAR(8) NOT NULL DEFAULT \'push\',
            snippet MEDIUMTEXT NULL,
            log_id INT UNSIGNED NULL,
            PRIMARY KEY (sample_id),
            KEY idx_company_entity_ts (company_id, entity, ts),
            KEY idx_log_id (log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    db_query(
        'CREATE TABLE IF NOT EXISTS ?:jtl_connector_scheduler_state (
            id TINYINT UNSIGNED NOT NULL,
            cron_token VARCHAR(64) NOT NULL DEFAULT \'\',
            last_watchdog_ts INT UNSIGNED NOT NULL DEFAULT 0,
            last_pruner_ts INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    db_query('INSERT IGNORE INTO ?:jtl_connector_scheduler_state (id, cron_token, last_watchdog_ts, last_pruner_ts) VALUES (1, \'\', 0, 0)');

    // Best-effort migration: mapping.host INT -> BIGINT to avoid overflow.
    try {
        $prefix = Registry::get('config.table_prefix');
        $table = $prefix . 'jtl_connector_mapping';
        $col = db_get_row("SHOW COLUMNS FROM `$table` LIKE 'host'");
        if ($col && isset($col['Type']) && stripos((string)$col['Type'], 'bigint') === false) {
            db_query("ALTER TABLE `$table` MODIFY COLUMN `host` BIGINT NOT NULL");
        }
    } catch (\Throwable $e) {
        // Ignore migration failures; keep runtime functional.
    }
}

function fn_jtl_connector_admin_notifications_enabled(): bool
{
    return fn_jtl_connector_get_addon_setting('admin_notifications_enabled', 'Y') === 'Y';
}

function fn_jtl_connector_debug_enabled(): bool
{
    return fn_jtl_connector_get_addon_setting('debug_enabled', 'N') === 'Y';
}

function fn_jtl_connector_verbose_enabled(): bool
{
    return fn_jtl_connector_get_addon_setting('verbose_enabled', 'N') === 'Y';
}

function fn_jtl_connector_verbose_payload_max_kb(): int
{
    return max(0, (int)fn_jtl_connector_get_addon_setting('verbose_payload_max_kb', '256'));
}

function fn_jtl_connector_verbose_redact_enabled(): bool
{
    return fn_jtl_connector_get_addon_setting('verbose_redact_enabled', 'Y') === 'Y';
}

/**
 * Prepare a payload snippet for storage (truncate + optional redaction).
 */
function fn_jtl_connector_prepare_payload_snippet(string $raw, int $max_kb): string
{
    $raw = (string)$raw;
    $max_bytes = max(0, $max_kb) * 1024;
    if ($max_bytes > 0 && strlen($raw) > $max_bytes) {
        $raw = substr($raw, 0, $max_bytes);
    }

    if (!fn_jtl_connector_verbose_redact_enabled()) {
        return $raw;
    }

    // Prefer JSON-aware redaction when possible.
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $decoded = fn_jtl_connector_redact_array_recursive($decoded, '');
        $out = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($out) && $out !== '') {
            if ($max_bytes > 0 && strlen($out) > $max_bytes) {
                $out = substr($out, 0, $max_bytes);
            }
            return $out;
        }
    }

    // Fallback: regex-based redaction for non-JSON bodies.
    return fn_jtl_connector_redact_text($raw, $max_bytes);
}

function fn_jtl_connector_redact_array_recursive($value, string $path)
{
    if (!is_array($value)) {
        return $value;
    }

    $out = [];
    foreach ($value as $k => $v) {
        $k_str = is_string($k) ? $k : '';
        $lk = $k_str !== '' ? strtolower($k_str) : '';
        $sub_path = $path;
        if ($k_str !== '') {
            $sub_path = $path === '' ? $lk : ($path . '.' . $lk);
        }

        // Always sensitive keys
        if ($lk !== '' && preg_match('/(token|pass(word)?|secret|auth(entication|orization)?|api[_-]?key|signature|bearer)/i', $lk)) {
            $out[$k] = '***';
            continue;
        }
        if ($lk !== '' && preg_match('/email/i', $lk)) {
            $out[$k] = '***@***';
            continue;
        }
        if ($lk !== '' && preg_match('/(phone|mobile|tel)/i', $lk)) {
            $out[$k] = '***';
            continue;
        }
        if ($lk !== '' && preg_match('/(street|address|zip|postcode|postal|city|state|country|iban|bic|swift|vat|tax[_-]?id)/i', $lk)) {
            $out[$k] = '***';
            continue;
        }

        // Contextual: only redact names when in a customer/address context.
        if ($lk !== '' && preg_match('/(first[_-]?name|last[_-]?name|name)/i', $lk)) {
            if (preg_match('/(customer|billing|shipping|address|invoice|delivery|person)/i', $path)) {
                $out[$k] = '***';
                continue;
            }
        }

        if (is_array($v)) {
            $out[$k] = fn_jtl_connector_redact_array_recursive($v, $sub_path);
        } elseif (is_string($v)) {
            $out[$k] = fn_jtl_connector_redact_scalar_string($v);
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

function fn_jtl_connector_redact_scalar_string(string $s): string
{
    // Emails
    $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '***@***', $s);

    // Authorization headers / bearer tokens inside strings
    $s = preg_replace('/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1***', $s);

    // Long hex strings (common for tokens)
    $s = preg_replace('/\b[a-f0-9]{32,}\b/i', '***', $s);

    // Long base64-ish strings
    $s = preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,2}\b/', '***', $s);

    return $s;
}

function fn_jtl_connector_redact_text(string $raw, int $max_bytes): string
{
    $out = fn_jtl_connector_redact_scalar_string($raw);
    // Also redact typical JSON-like token fields if present in text.
    $out = preg_replace('/("(?:token|access_token|refresh_token|api[_-]?key|password|secret)"\s*:\s*)"[^"]*"/i', '$1"***"', $out);

    if ($max_bytes > 0 && strlen($out) > $max_bytes) {
        $out = substr($out, 0, $max_bytes);
    }
    return $out;
}

function fn_jtl_connector_debug_event(int $company_id, string $level, string $code, string $message = '', array $context = [], ?string $payload_snippet = null, ?int $log_id = null): void
{
    if (!fn_jtl_connector_debug_enabled() && !fn_jtl_connector_verbose_enabled()) {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();
    $ctx = null;
    if (!empty($context)) {
        $ctx = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!fn_jtl_connector_verbose_enabled()) {
        $payload_snippet = null;
    }
    db_query('INSERT INTO ?:jtl_connector_debug_event ?e', [
        'company_id' => $company_id,
        'ts' => fn_jtl_connector_now(),
        'level' => mb_substr($level, 0, 8),
        'code' => mb_substr($code, 0, 64),
        'message' => $message,
        'context_json' => $ctx,
        'payload_snippet' => $payload_snippet,
        'log_id' => $log_id,
    ]);
}

function fn_jtl_connector_alert_hash(int $company_id, string $code, string $title, string $message): string
{
    return sha1($company_id . '|' . $code . '|' . $title . '|' . $message);
}

function fn_jtl_connector_add_admin_alert(int $company_id, string $level, string $code, string $title, string $message, int $dedupe_seconds = 3600): void
{
    if (!fn_jtl_connector_admin_notifications_enabled()) {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();
    $now = fn_jtl_connector_now();
    $level = mb_substr($level, 0, 8);
    $code = mb_substr($code, 0, 64);
    $title = mb_substr($title, 0, 255);

    $hash = fn_jtl_connector_alert_hash($company_id, $code, $title, $message);
    if ($dedupe_seconds > 0) {
        $recent = (int)db_get_field(
            'SELECT COUNT(*) FROM ?:jtl_connector_admin_alert WHERE hash=?s AND ts>=?i',
            $hash,
            $now - $dedupe_seconds
        );
        if ($recent > 0) {
            return;
        }
    }

    db_query('INSERT INTO ?:jtl_connector_admin_alert ?e', [
        'company_id' => $company_id,
        'ts' => $now,
        'level' => $level,
        'code' => $code,
        'title' => $title,
        'message' => $message,
        'is_read' => 'N',
        'hash' => $hash,
    ]);
}

function fn_jtl_connector_get_admin_alerts(bool $only_unread = true, int $limit = 50): array
{
    fn_jtl_connector_ensure_runtime_tables();
    $limit = max(1, min(200, $limit));
    if ($only_unread) {
        return db_get_array('SELECT * FROM ?:jtl_connector_admin_alert WHERE is_read=\'N\' ORDER BY ts DESC LIMIT ?i', $limit);
    }
    return db_get_array('SELECT * FROM ?:jtl_connector_admin_alert ORDER BY ts DESC LIMIT ?i', $limit);
}

function fn_jtl_connector_get_unread_alert_count(): int
{
    fn_jtl_connector_ensure_runtime_tables();
    return (int)db_get_field('SELECT COUNT(*) FROM ?:jtl_connector_admin_alert WHERE is_read=\'N\'');
}

function fn_jtl_connector_mark_alert_read(int $alert_id): void
{
    fn_jtl_connector_ensure_runtime_tables();
    db_query('UPDATE ?:jtl_connector_admin_alert SET is_read=\'Y\' WHERE alert_id=?i', $alert_id);
}

function fn_jtl_connector_delete_alert(int $alert_id): void
{
    fn_jtl_connector_ensure_runtime_tables();
    db_query('DELETE FROM ?:jtl_connector_admin_alert WHERE alert_id=?i', $alert_id);
}

function fn_jtl_connector_clear_alerts(string $mode = 'read'): void
{
    fn_jtl_connector_ensure_runtime_tables();
    if ($mode === 'all') {
        db_query('DELETE FROM ?:jtl_connector_admin_alert');
        return;
    }
    db_query('DELETE FROM ?:jtl_connector_admin_alert WHERE is_read=\'Y\'');
}

function fn_jtl_connector_payload_samples_keep(): int
{
    return max(1, min(50, (int)fn_jtl_connector_get_addon_setting('payload_samples_keep', '3')));
}

function fn_jtl_connector_payload_sample_max_kb(): int
{
    return max(1, (int)fn_jtl_connector_get_addon_setting('payload_sample_max_kb', '64'));
}

function fn_jtl_connector_model_to_array($value, int $depth = 0)
{
    if ($depth > 3) {
        return '…';
    }
    if ($value === null || is_scalar($value)) {
        return $value;
    }
    if (is_array($value)) {
        $out = [];
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i++ > 50) {
                $out['…'] = 'truncated';
                break;
            }
            $out[$k] = fn_jtl_connector_model_to_array($v, $depth + 1);
        }
        return $out;
    }
    if (!is_object($value)) {
        return (string)$value;
    }

    try {
        if ($value instanceof \JsonSerializable) {
            return fn_jtl_connector_model_to_array($value->jsonSerialize(), $depth + 1);
        }
    } catch (\Throwable $e) {
        // ignore
    }

    if (method_exists($value, 'toArray')) {
        try {
            return fn_jtl_connector_model_to_array($value->toArray(), $depth + 1);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    $vars = get_object_vars($value);
    if (!empty($vars)) {
        $out = [];
        $i = 0;
        foreach ($vars as $k => $v) {
            if ($i++ > 50) {
                $out['…'] = 'truncated';
                break;
            }
            $out[$k] = fn_jtl_connector_model_to_array($v, $depth + 1);
        }
        return $out;
    }

    // As a last resort, attempt to call simple getters (best-effort).
    $out = ['__class' => get_class($value)];
    $methods = get_class_methods($value);
    $i = 0;
    foreach ($methods as $m) {
        if ($i++ > 40) {
            $out['…'] = 'truncated';
            break;
        }
        if (strpos($m, 'get') !== 0) {
            continue;
        }
        try {
            $ref = new \ReflectionMethod($value, $m);
            if ($ref->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $key = lcfirst(substr($m, 3));
            $out[$key] = fn_jtl_connector_model_to_array($value->{$m}(), $depth + 1);
        } catch (\Throwable $e) {
            // ignore
        }
    }
    return $out;
}

function fn_jtl_connector_capture_payload_sample(int $company_id, string $entity, string $direction, $model, ?int $log_id = null): void
{
    if (!fn_jtl_connector_verbose_enabled()) {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();

    $arr = fn_jtl_connector_model_to_array($model);
    $json = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return;
    }

    $snippet = fn_jtl_connector_prepare_payload_snippet($json, fn_jtl_connector_payload_sample_max_kb());
    $entity = mb_substr((string)$entity, 0, 64);
    $direction = mb_substr((string)$direction, 0, 8);

    db_query('INSERT INTO ?:jtl_connector_payload_sample ?e', [
        'company_id' => $company_id,
        'ts' => fn_jtl_connector_now(),
        'entity' => $entity,
        'direction' => $direction,
        'snippet' => $snippet,
        'log_id' => $log_id,
    ]);

    // Keep only last N samples per vendor+entity.
    $keep = fn_jtl_connector_payload_samples_keep();
    $ids = db_get_fields(
        'SELECT sample_id FROM ?:jtl_connector_payload_sample WHERE company_id=?i AND entity=?s ORDER BY ts DESC LIMIT ?i',
        $company_id,
        $entity,
        $keep
    );
    if (!empty($ids)) {
        db_query(
            'DELETE FROM ?:jtl_connector_payload_sample WHERE company_id=?i AND entity=?s AND sample_id NOT IN (?n)',
            $company_id,
            $entity,
            $ids
        );
    }
}

function fn_jtl_connector_get_payload_samples(array $company_ids, int $limit_total = 200): array
{
    fn_jtl_connector_ensure_runtime_tables();
    if (empty($company_ids)) {
        return [];
    }
    $limit_total = max(1, min(1000, $limit_total));
    return db_get_array(
        'SELECT * FROM ?:jtl_connector_payload_sample WHERE company_id IN (?n) ORDER BY ts DESC LIMIT ?i',
        $company_ids,
        $limit_total
    );
}

function fn_jtl_connector_get_admin_email(): string
{
    $custom = trim((string)fn_jtl_connector_get_addon_setting('watchdog_notify_email', ''));
    if ($custom !== '') {
        return $custom;
    }
    $email = (string)Registry::get('settings.Company.company_site_administrator');
    if ($email !== '') {
        return $email;
    }
    // Fallback to default company email if the above setting is absent.
    $fallback = (string)Registry::get('settings.Company.company_users_department');
    return $fallback;
}

function fn_jtl_connector_get_cron_token(): string
{
    fn_jtl_connector_ensure_runtime_tables();
    $token = (string)db_get_field('SELECT cron_token FROM ?:jtl_connector_scheduler_state WHERE id=1');
    $token = trim($token);
    if ($token !== '') {
        return $token;
    }
    // Generate a stable token (best-effort). Stored in DB so it survives restarts.
    try {
        $token = bin2hex(random_bytes(24));
    } catch (\Throwable $e) {
        $token = sha1((string)microtime(true) . '|' . (string)mt_rand());
    }
    db_query('UPDATE ?:jtl_connector_scheduler_state SET cron_token=?s WHERE id=1', $token);
    return $token;
}

function fn_jtl_connector_get_cron_url(string $task): string
{
    $token = fn_jtl_connector_get_cron_token();
    $task = rawurlencode($task);
    return fn_url('jtl_connector.cron?task=' . $task . '&token=' . $token, 'C');
}

function fn_jtl_connector_set_addon_setting_value(string $name, string $value): void
{
    // Prefer Settings API (more stable across CS-Cart versions).
    try {
        if (class_exists('Tygh\\Settings')) {
            /** @noinspection PhpFullyQualifiedNameUsageInspection */
            \Tygh\Settings::instance()->updateValue($name, $value, 'jtl_connector');
            Registry::set('addons.jtl_connector.' . $name, $value);
            return;
        }
    } catch (\Throwable $e) {
        // fallback below
    }

    // Fallback: update registry only (may not persist in some versions).
    try {
        Registry::set('addons.jtl_connector.' . $name, $value);
    } catch (\Throwable $e) {
        // ignore
    }
}

function fn_jtl_connector_watchdog_update(int $company_id, bool $ok, ?string $error = null): void
{
    if (fn_jtl_connector_get_addon_setting('watchdog_enabled', 'Y') !== 'Y') {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();
    $now = fn_jtl_connector_now();

    $state = db_get_row('SELECT * FROM ?:jtl_connector_watchdog_state WHERE company_id=?i', $company_id);
    if (!$state) {
        $state = [
            'company_id' => $company_id,
            'last_ok_ts' => 0,
            'last_error_ts' => 0,
            'consecutive_errors' => 0,
            'last_error' => null,
            'last_notified_ts' => 0,
        ];
        db_query('INSERT INTO ?:jtl_connector_watchdog_state ?e', $state);
    }

    if ($ok) {
        db_query('UPDATE ?:jtl_connector_watchdog_state SET ?u WHERE company_id=?i', [
            'last_ok_ts' => $now,
            'consecutive_errors' => 0,
            'last_error' => null,
        ], $company_id);
        return;
    }

    $consecutive = (int)($state['consecutive_errors'] ?? 0) + 1;
    db_query('UPDATE ?:jtl_connector_watchdog_state SET ?u WHERE company_id=?i', [
        'last_error_ts' => $now,
        'consecutive_errors' => $consecutive,
        'last_error' => $error,
    ], $company_id);

    $threshold = max(1, (int)fn_jtl_connector_get_addon_setting('watchdog_fail_threshold', '3'));
    $cooldown = max(0, (int)fn_jtl_connector_get_addon_setting('watchdog_cooldown_min', '60')) * 60;
    $last_notified = (int)($state['last_notified_ts'] ?? 0);
    if ($consecutive < $threshold) {
        return;
    }
    if ($cooldown > 0 && ($now - $last_notified) < $cooldown) {
        return;
    }

    // Notify admin via admin-panel alert + email (best-effort).
    $subject = sprintf('[CS-Cart JTL Connector] Vendor %d sync errors (%d)', $company_id, $consecutive);
    $body = "The JTL connector endpoint reported consecutive failures.\n\n"
        . "Vendor/company_id: {$company_id}\n"
        . "Consecutive failures: {$consecutive}\n"
        . "Last error: " . ($error ?? '(none)') . "\n"
        . "Timestamp: {$now}\n";

    fn_jtl_connector_add_admin_alert(
        $company_id,
        'ERROR',
        'watchdog_failures',
        sprintf('Vendor %d: sync failures (%d)', $company_id, $consecutive),
        trim($body),
        $cooldown > 0 ? $cooldown : 3600
    );

    fn_jtl_connector_send_admin_email($company_id, $subject, $body);

    db_query('UPDATE ?:jtl_connector_watchdog_state SET last_notified_ts=?i WHERE company_id=?i', $now, $company_id);
}

function fn_jtl_connector_watchdog_tick(): void
{
    if (fn_jtl_connector_get_addon_setting('watchdog_enabled', 'Y') !== 'Y') {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();

    $idle_min = (int)fn_jtl_connector_get_addon_setting('watchdog_idle_minutes', '1440');
    if ($idle_min <= 0) {
        return;
    }
    $idle_sec = $idle_min * 60;
    $now = fn_jtl_connector_now();

    $cooldown = max(0, (int)fn_jtl_connector_get_addon_setting('watchdog_cooldown_min', '60')) * 60;
    $rows = db_get_array('SELECT * FROM ?:jtl_connector_watchdog_state');
    foreach ($rows as $st) {
        $company_id = (int)$st['company_id'];
        $last_ok = (int)($st['last_ok_ts'] ?? 0);
        $last_notified = (int)($st['last_notified_ts'] ?? 0);
        if ($last_ok <= 0 || ($now - $last_ok) < $idle_sec) {
            continue;
        }
        if ($cooldown > 0 && ($now - $last_notified) < $cooldown) {
            continue;
        }

        $subject = sprintf('[CS-Cart JTL Connector] No successful sync for vendor %d', $company_id);
        $body = "No successful JTL sync was recorded for this vendor within the configured idle window.\n\n"
            . "Vendor/company_id: {$company_id}\n"
            . "Last OK timestamp: {$last_ok}\n"
            . "Idle minutes threshold: {$idle_min}\n";

        fn_jtl_connector_add_admin_alert(
            $company_id,
            'WARN',
            'watchdog_idle',
            sprintf('Vendor %d: no successful sync (idle)', $company_id),
            trim($body),
            $cooldown > 0 ? $cooldown : 3600
        );

        if (fn_jtl_connector_send_admin_email($company_id, $subject, $body)) {
            db_query('UPDATE ?:jtl_connector_watchdog_state SET last_notified_ts=?i WHERE company_id=?i', $now, $company_id);
        }
    }
}

function fn_jtl_connector_internal_scheduler_should_run(string $area): bool
{
    if (fn_jtl_connector_get_addon_setting('internal_scheduler_enabled', 'N') !== 'Y') {
        return false;
    }
    $areas = (string)fn_jtl_connector_get_addon_setting('internal_scheduler_area', 'A');
    if ($areas === 'A') {
        return $area === 'A';
    }
    // "AC" => admin + storefront
    return in_array($area, ['A', 'C'], true);
}

function fn_jtl_connector_internal_scheduler_tick(string $area): void
{
    if (!fn_jtl_connector_internal_scheduler_should_run($area)) {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();

    $now = fn_jtl_connector_now();
    $row = db_get_row('SELECT * FROM ?:jtl_connector_scheduler_state WHERE id=1');
    if (!$row) {
        db_query('INSERT IGNORE INTO ?:jtl_connector_scheduler_state (id, cron_token, last_watchdog_ts, last_pruner_ts) VALUES (1, \'\', 0, 0)');
        $row = ['last_watchdog_ts' => 0, 'last_pruner_ts' => 0];
    }

    $wd_interval = max(30, (int)fn_jtl_connector_get_addon_setting('internal_scheduler_watchdog_interval_sec', '600'));
    $pr_interval = max(300, (int)fn_jtl_connector_get_addon_setting('internal_scheduler_pruner_interval_sec', '86400'));
    $last_wd = (int)($row['last_watchdog_ts'] ?? 0);
    $last_pr = (int)($row['last_pruner_ts'] ?? 0);

    // Run at most one heavy task per request.
    if ($now - $last_wd >= $wd_interval) {
        try {
            fn_jtl_connector_watchdog_tick();
            db_query('UPDATE ?:jtl_connector_scheduler_state SET last_watchdog_ts=?i WHERE id=1', $now);
        } catch (\Throwable $e) {
            fn_jtl_connector_debug_event(0, 'ERROR', 'internal_scheduler_watchdog_failed', $e->getMessage());
        }
        return;
    }

    if ($now - $last_pr >= $pr_interval) {
        try {
            fn_jtl_connector_prune_logs();
            db_query('UPDATE ?:jtl_connector_scheduler_state SET last_pruner_ts=?i WHERE id=1', $now);
        } catch (\Throwable $e) {
            fn_jtl_connector_debug_event(0, 'ERROR', 'internal_scheduler_pruner_failed', $e->getMessage());
        }
    }
}

function fn_jtl_connector_send_admin_email(int $company_id, string $subject, string $body): bool
{
    $to = fn_jtl_connector_get_admin_email();
    if ($to === '') {
        return false;
    }
    try {
        $lang = defined('CART_LANGUAGE') ? CART_LANGUAGE : (string)fn_jtl_connector_get_addon_setting('default_language', 'en');
        // Prefer modern mailer service when available.
        if (class_exists('Tygh\\Tygh') && isset(\Tygh\Tygh::$app['mailer'])) {
            /** @var \Tygh\Mailer\Mailer $mailer */
            $mailer = \Tygh\Tygh::$app['mailer'];
            $mailer->send([
                'to' => $to,
                'from' => 'default_company_site_administrator',
                'data' => [
                    'body' => $body,
                    'subject' => $subject,
                ],
            ], 'A', $lang);
            return true;
        }

        // Fallback: legacy helper if present.
        if (function_exists('fn_send_mail')) {
            fn_send_mail($to, 'company_site_administrator', $subject, $body, '', $company_id, $lang);
            return true;
        }
    } catch (\Throwable $e) {
        return false;
    }
    return false;
}



/**
 * Prune request logs / debug events / rate-limit windows according to retention settings.
 * Returns deleted row counts.
 */
function fn_jtl_connector_prune_logs(): array
{
    fn_jtl_connector_ensure_runtime_tables();
    $now = fn_jtl_connector_now();

    $deleted = [
        'request_log' => 0,
        'debug_event' => 0,
        'rate_limit' => 0,
        'payload_sample' => 0,
        'admin_alert' => 0,
    ];

    $days = (int)fn_jtl_connector_get_addon_setting('retain_request_log_days', '30');
    if ($days > 0) {
        $cutoff = $now - ($days * 86400);
        $cnt = (int)db_get_field('SELECT COUNT(*) FROM ?:jtl_connector_request_log WHERE ts < ?i', $cutoff);
        if ($cnt > 0) {
            db_query('DELETE FROM ?:jtl_connector_request_log WHERE ts < ?i', $cutoff);
            $deleted['request_log'] = $cnt;
        }
    }

    $days = (int)fn_jtl_connector_get_addon_setting('retain_debug_event_days', '14');
    if ($days > 0) {
        $cutoff = $now - ($days * 86400);
        $cnt = (int)db_get_field('SELECT COUNT(*) FROM ?:jtl_connector_debug_event WHERE ts < ?i', $cutoff);
        if ($cnt > 0) {
            db_query('DELETE FROM ?:jtl_connector_debug_event WHERE ts < ?i', $cutoff);
            $deleted['debug_event'] = $cnt;
        }
    }

    $days = (int)fn_jtl_connector_get_addon_setting('retain_rate_limit_days', '2');
    if ($days > 0) {
        $cutoff = $now - ($days * 86400);
        $cnt = (int)db_get_field('SELECT COUNT(*) FROM ?:jtl_connector_rate_limit WHERE window_start < ?i', $cutoff);
        if ($cnt > 0) {
            db_query('DELETE FROM ?:jtl_connector_rate_limit WHERE window_start < ?i', $cutoff);
            $deleted['rate_limit'] = $cnt;
        }
    }

    $days = (int)fn_jtl_connector_get_addon_setting('retain_payload_samples_days', '7');
    if ($days > 0) {
        $cutoff = $now - ($days * 86400);
        $cnt = (int)db_get_field('SELECT COUNT(*) FROM ?:jtl_connector_payload_sample WHERE ts < ?i', $cutoff);
        if ($cnt > 0) {
            db_query('DELETE FROM ?:jtl_connector_payload_sample WHERE ts < ?i', $cutoff);
            $deleted['payload_sample'] = $cnt;
        }
    }

    // Admin alerts retention: optionally prune read alerts earlier than the full retention.
    $days_read = (int)fn_jtl_connector_get_addon_setting('retain_admin_alert_read_days', '30');
    if ($days_read > 0) {
        $cutoff = $now - ($days_read * 86400);
        $cnt = (int)db_get_field(
            "SELECT COUNT(*) FROM ?:jtl_connector_admin_alert WHERE is_read='Y' AND ts < ?i",
            $cutoff
        );
        if ($cnt > 0) {
            db_query("DELETE FROM ?:jtl_connector_admin_alert WHERE is_read='Y' AND ts < ?i", $cutoff);
            $deleted['admin_alert'] += $cnt;
        }
    }

    $days_all = (int)fn_jtl_connector_get_addon_setting('retain_admin_alert_days', '90');
    if ($days_all > 0) {
        $cutoff = $now - ($days_all * 86400);
        $cnt = (int)db_get_field('SELECT COUNT(*) FROM ?:jtl_connector_admin_alert WHERE ts < ?i', $cutoff);
        if ($cnt > 0) {
            db_query('DELETE FROM ?:jtl_connector_admin_alert WHERE ts < ?i', $cutoff);
            $deleted['admin_alert'] += $cnt;
        }
    }

    return $deleted;
}

function fn_jtl_connector_is_addon_active(string $addon): bool
{
    $status = db_get_field('SELECT status FROM ?:addons WHERE addon=?s', $addon);
    return $status === 'A';
}

function fn_jtl_connector_product_variations_should_run(): bool
{
    if (fn_jtl_connector_get_addon_setting('use_product_variations', 'Y') !== 'Y') {
        return false;
    }
    return fn_jtl_connector_is_addon_active('product_variations');
}

function fn_jtl_connector_table_exists(string $table_short): bool
{
    $prefix = Registry::get('config.table_prefix');
    $table = $prefix . $table_short;
    $res = db_get_field('SHOW TABLES LIKE ?s', $table);
    return !empty($res);
}

function fn_jtl_connector_table_has_column(string $table_short, string $column): bool
{
    $prefix = Registry::get('config.table_prefix');
    $table = $prefix . $table_short;
    $row = db_get_row("SHOW COLUMNS FROM `$table` LIKE ?s", $column);
    return !empty($row);
}

/**
 * Hook: show pending alerts in admin area and run optional internal scheduler.
 *
 * Runs best-effort across CS-Cart versions.
 */
function fn_jtl_connector_dispatch_before_display(&$controller, &$mode, &$action, &$dispatch_extra, &$area): void
{
    // Internal scheduler (traffic-based) - runs before display.
    try {
        fn_jtl_connector_internal_scheduler_tick((string)$area);
    } catch (\Throwable $e) {
        // Never break page rendering.
    }

    if (!fn_jtl_connector_admin_notifications_enabled() || (string)$area !== 'A') {
        return;
    }

    // Avoid spamming popups: show at most once per 10 minutes per session.
    if (isset($_SESSION['jtl_connector_last_alert_popup']) && (int)$_SESSION['jtl_connector_last_alert_popup'] > 0) {
        $last = (int)$_SESSION['jtl_connector_last_alert_popup'];
        if ((fn_jtl_connector_now() - $last) < 600) {
            return;
        }
    }

    $cnt = 0;
    try {
        $cnt = fn_jtl_connector_get_unread_alert_count();
    } catch (\Throwable $e) {
        $cnt = 0;
    }
    if ($cnt <= 0) {
        return;
    }

    // Show a simple notification banner.
    if (function_exists('fn_set_notification')) {
        fn_set_notification('W', 'JTL Connector', sprintf('%d unread JTL Connector alert(s). Open JTL Connector page for details.', $cnt));
        $_SESSION['jtl_connector_last_alert_popup'] = fn_jtl_connector_now();
    }
}

/**
 * Best-effort: extract variation metadata from a JTL Product model.
 * Returns [master_host_id, [feature_name => variant_value]] or null.
 */
function fn_jtl_connector_extract_variation_meta($jtl_product): ?array
{
    try {
        // 1) Find master/parent identifier.
        $master = null;
        foreach (['getMasterProductId', 'getParentProductId', 'getParentId', 'getMasterId', 'getVariationParentId'] as $m) {
            if (is_object($jtl_product) && method_exists($jtl_product, $m)) {
                $master = $jtl_product->{$m}();
                break;
            }
        }

        // Identity-like objects may have getHost() or getId().
        $master_host = null;
        if (is_object($master)) {
            foreach (['getHost', 'getHostId', 'getId'] as $m) {
                if (method_exists($master, $m)) {
                    $master_host = $master->{$m}();
                    break;
                }
            }
        } elseif (is_scalar($master)) {
            $master_host = $master;
        }
        if ($master_host === null || (string)$master_host === '' || (int)$master_host <= 0) {
            return null;
        }

        // 2) Extract variation attributes.
        $attrs = [];
        foreach (['getVariationAttributes', 'getVariationValues', 'getVariations', 'getVariationCombinations', 'getAttributes'] as $m) {
            if (is_object($jtl_product) && method_exists($jtl_product, $m)) {
                $val = $jtl_product->{$m}();
                // Try to normalize: accept array of key/value or objects.
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        if (is_string($k) && (is_string($v) || is_numeric($v))) {
                            $attrs[trim($k)] = (string)$v;
                        } elseif (is_object($v)) {
                            $name = null;
                            $value = null;
                            foreach (['getName', 'getAttribute', 'getKey', 'getLabel'] as $nm) {
                                if (method_exists($v, $nm)) {
                                    $name = $v->{$nm}();
                                    break;
                                }
                            }
                            foreach (['getValue', 'getVariant', 'getOptionValue', 'getLabel'] as $vm) {
                                if (method_exists($v, $vm)) {
                                    $value = $v->{$vm}();
                                    break;
                                }
                            }
                            if (is_string($name) && $name !== '' && $value !== null && $value !== '') {
                                $attrs[trim($name)] = (string)$value;
                            }
                        }
                    }
                }
                break;
            }
        }

        $attrs = array_filter($attrs, static fn($v) => trim((string)$v) !== '');
        if (empty($attrs)) {
            return null;
        }
        return [(int)$master_host, $attrs];
    } catch (\Throwable $e) {
        return null;
    }
}

function fn_jtl_connector_find_feature_id(int $company_id, string $name, string $lang_code): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    // Try exact match in descriptions.
    $feature_id = db_get_field(
        'SELECT fd.feature_id FROM ?:product_features_descriptions fd '
        . 'INNER JOIN ?:product_features f ON f.feature_id = fd.feature_id '
        . 'WHERE fd.lang_code=?s AND fd.description=?s AND (f.company_id=?i OR f.company_id=0) '
        . 'ORDER BY f.company_id DESC LIMIT 1',
        $lang_code,
        $name,
        $company_id
    );
    if ($feature_id) {
        return (int)$feature_id;
    }
    return null;
}

function fn_jtl_connector_create_feature(int $company_id, string $name, string $lang_code, string $purpose): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    if (function_exists('fn_update_product_feature')) {
        $data = [
            'company_id' => $company_id,
            'description' => $name,
            'feature_type' => 'S',
            'purpose' => $purpose,
            'feature_style' => 'dropdown',
            'filter_style' => 'checkbox',
        ];
        $id = fn_update_product_feature($data, 0, $lang_code);
        return $id ? (int)$id : null;
    }
    return null;
}

function fn_jtl_connector_find_or_create_feature(int $company_id, string $name, string $lang_code, string $purpose): ?int
{
    $fid = fn_jtl_connector_find_feature_id($company_id, $name, $lang_code);
    if ($fid) {
        return $fid;
    }
    if (fn_jtl_connector_get_addon_setting('variations_auto_create_features', 'N') !== 'Y') {
        return null;
    }
    return fn_jtl_connector_create_feature($company_id, $name, $lang_code, $purpose);
}

function fn_jtl_connector_find_feature_variant_id(int $feature_id, string $variant, string $lang_code): ?int
{
    $variant = trim($variant);
    if ($variant === '') {
        return null;
    }
    // Try standard CS-Cart tables.
    if (fn_jtl_connector_table_exists('product_feature_variants') && fn_jtl_connector_table_exists('product_feature_variant_descriptions')) {
        $vid = db_get_field(
            'SELECT vd.variant_id FROM ?:product_feature_variant_descriptions vd '
            . 'INNER JOIN ?:product_feature_variants v ON v.variant_id = vd.variant_id '
            . 'WHERE v.feature_id=?i AND vd.lang_code=?s AND vd.variant=?s LIMIT 1',
            $feature_id,
            $lang_code,
            $variant
        );
        if ($vid) {
            return (int)$vid;
        }
    }
    // Alternative naming in some versions.
    if (fn_jtl_connector_table_exists('product_feature_variants') && fn_jtl_connector_table_exists('product_feature_variants_descriptions')) {
        $vid = db_get_field(
            'SELECT vd.variant_id FROM ?:product_feature_variants_descriptions vd '
            . 'INNER JOIN ?:product_feature_variants v ON v.variant_id = vd.variant_id '
            . 'WHERE v.feature_id=?i AND vd.lang_code=?s AND vd.variant=?s LIMIT 1',
            $feature_id,
            $lang_code,
            $variant
        );
        if ($vid) {
            return (int)$vid;
        }
    }
    return null;
}

function fn_jtl_connector_create_feature_variant(int $feature_id, string $variant, string $lang_code): ?int
{
    $variant = trim($variant);
    if ($variant === '') {
        return null;
    }
    if (function_exists('fn_update_product_feature_variant')) {
        // Signature differs between versions; this matches the hook docs.
        $variant_id = fn_update_product_feature_variant($feature_id, 'S', $variant, $lang_code);
        return $variant_id ? (int)$variant_id : null;
    }
    return null;
}

function fn_jtl_connector_find_or_create_feature_variant(int $feature_id, string $variant, string $lang_code): ?int
{
    $vid = fn_jtl_connector_find_feature_variant_id($feature_id, $variant, $lang_code);
    if ($vid) {
        return $vid;
    }
    if (fn_jtl_connector_get_addon_setting('variations_auto_create_features', 'N') !== 'Y') {
        return null;
    }
    return fn_jtl_connector_create_feature_variant($feature_id, $variant, $lang_code);
}

function fn_jtl_connector_pv_get_or_create_group(int $company_id, string $group_code): ?int
{
    if (!fn_jtl_connector_table_exists('product_variation_groups')) {
        return null;
    }
    $group_code = preg_replace('/[^A-Za-z0-9_\-]/', '_', $group_code);
    $group_code = mb_substr($group_code, 0, 64);
    if ($group_code === '') {
        return null;
    }

    $params = [$group_code];
    $cond = 'code=?s';
    if (fn_jtl_connector_table_has_column('product_variation_groups', 'company_id')) {
        $cond .= ' AND company_id=?i';
        $params[] = $company_id;
    }
    $group_id = db_get_field('SELECT group_id FROM ?:product_variation_groups WHERE ' . $cond . ' LIMIT 1', ...$params);
    if ($group_id) {
        return (int)$group_id;
    }

    $data = [
        'code' => $group_code,
        'created_at' => fn_jtl_connector_now(),
        'updated_at' => fn_jtl_connector_now(),
    ];
    if (fn_jtl_connector_table_has_column('product_variation_groups', 'company_id')) {
        $data['company_id'] = $company_id;
    }
    $new_id = db_query('INSERT INTO ?:product_variation_groups ?e', $data);
    return $new_id ? (int)$new_id : null;
}

function fn_jtl_connector_pv_ensure_group_features(int $group_id, array $feature_purposes): void
{
    if (!fn_jtl_connector_table_exists('product_variation_group_features')) {
        return;
    }
    // Upsert: remove missing, insert present.
    db_query('DELETE FROM ?:product_variation_group_features WHERE group_id=?i', $group_id);
    foreach ($feature_purposes as $feature_id => $purpose) {
        db_query('INSERT INTO ?:product_variation_group_features ?e', [
            'group_id' => $group_id,
            'feature_id' => (int)$feature_id,
            'purpose' => (string)$purpose,
        ]);
    }
}

function fn_jtl_connector_pv_get_or_set_parent(int $company_id, string $group_code, int $product_id): int
{
    fn_jtl_connector_ensure_runtime_tables();
    $existing = db_get_field('SELECT parent_product_id FROM ?:jtl_connector_variation_parent WHERE company_id=?i AND group_code=?s', $company_id, $group_code);
    if ($existing) {
        return (int)$existing;
    }
    db_query('REPLACE INTO ?:jtl_connector_variation_parent ?e', [
        'company_id' => $company_id,
        'group_code' => $group_code,
        'parent_product_id' => $product_id,
        'created_at' => fn_jtl_connector_now(),
    ]);
    return $product_id;
}

function fn_jtl_connector_pv_upsert_group_product(int $group_id, int $company_id, int $product_id, int $parent_product_id): void
{
    if (!fn_jtl_connector_table_exists('product_variation_group_products')) {
        return;
    }
    $data = [
        'group_id' => $group_id,
        'product_id' => $product_id,
        'parent_product_id' => $parent_product_id,
    ];
    if (fn_jtl_connector_table_has_column('product_variation_group_products', 'company_id')) {
        $data['company_id'] = $company_id;
    }
    db_query('REPLACE INTO ?:product_variation_group_products ?e', $data);
}

function fn_jtl_connector_try_group_product_variation(int $company_id, int $product_id, $jtl_product, string $lang_code): void
{
    if (!fn_jtl_connector_product_variations_should_run()) {
        return;
    }
    // Group-based variation system only.
    if (!fn_jtl_connector_table_exists('product_variation_groups')) {
        return;
    }

    $meta = fn_jtl_connector_extract_variation_meta($jtl_product);
    if ($meta === null) {
        return;
    }
    [$master_host, $attrs] = $meta;

    $purpose = (string)fn_jtl_connector_get_addon_setting('variations_group_purpose', 'group_variation_catalog_item');
    $group_code = sprintf('JTL_%d_%d', $company_id, $master_host);
    $group_id = fn_jtl_connector_pv_get_or_create_group($company_id, $group_code);
    if (!$group_id) {
        fn_jtl_connector_debug_event($company_id, 'WARN', 'pv_group_create_failed', 'Could not create/find variation group', ['group_code' => $group_code]);
        return;
    }

    // Build feature values and ensure features exist.
    $feature_purposes = [];
    $product_features = [];
    foreach ($attrs as $fname => $fval) {
        $feature_id = fn_jtl_connector_find_or_create_feature($company_id, (string)$fname, $lang_code, $purpose);
        if (!$feature_id) {
            fn_jtl_connector_debug_event($company_id, 'WARN', 'pv_missing_feature', 'Missing variation feature; grouping skipped', ['feature' => $fname, 'value' => $fval]);
            return;
        }
        $variant_id = fn_jtl_connector_find_or_create_feature_variant($feature_id, (string)$fval, $lang_code);
        if (!$variant_id) {
            fn_jtl_connector_debug_event($company_id, 'WARN', 'pv_missing_variant', 'Missing feature variant; grouping skipped', ['feature_id' => $feature_id, 'variant' => $fval]);
            return;
        }
        $feature_purposes[$feature_id] = $purpose;

        // fn_update_product understands this structure in most versions.
        $product_features[$feature_id] = [
            'feature_id' => (string)$feature_id,
            'variant_id' => (string)$variant_id,
        ];
    }

    fn_jtl_connector_pv_ensure_group_features($group_id, $feature_purposes);

    // Choose one parent product per group (first seen).
    $parent = fn_jtl_connector_pv_get_or_set_parent($company_id, preg_replace('/[^A-Za-z0-9_\-]/', '_', $group_code), $product_id);
    $parent_product_id = ($parent === $product_id) ? 0 : $parent;

    // Update product with variation fields where possible.
    if (function_exists('fn_update_product')) {
        $pairs = [];
        foreach ($product_features as $fid => $fd) {
            $pairs[] = $fid . ':' . $fd['variant_id'];
        }
        $data = [
            'variation_group_id' => $group_id,
            'variation_feature_values' => implode(',', $pairs),
            'product_features' => $product_features,
            'company_id' => $company_id,
            'lang_code' => $lang_code,
        ];
        // Some versions also accept these fields.
        if ($parent_product_id > 0) {
            $data['variation_parent_product_id'] = $parent_product_id;
        }
        fn_update_product($data, $product_id, $lang_code);
    }

    fn_jtl_connector_pv_upsert_group_product($group_id, $company_id, $product_id, $parent_product_id);
    fn_jtl_connector_debug_event($company_id, 'INFO', 'pv_grouped', 'Product grouped as variation', [
        'product_id' => $product_id,
        'group_id' => $group_id,
        'group_code' => $group_code,
        'parent_product_id' => $parent_product_id,
        'attrs' => $attrs,
    ]);
}

function fn_jtl_connector_log_request_start(int $company_id): int
{
    fn_jtl_connector_ensure_runtime_tables();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

    db_query('INSERT INTO ?:jtl_connector_request_log ?e', [
        'company_id' => $company_id,
        'ts' => fn_jtl_connector_now(),
        'ip' => $ip,
        'user_agent' => mb_substr($ua, 0, 255),
        'content_length' => max(0, $len),
        'duration_ms' => 0,
        'ok' => 'Y',
        'error' => null,
    ]);

    return (int)db_get_field('SELECT LAST_INSERT_ID()');
}

function fn_jtl_connector_log_request_finish(int $log_id, bool $ok, int $duration_ms, ?string $error = null): void
{
    fn_jtl_connector_ensure_runtime_tables();
    db_query('UPDATE ?:jtl_connector_request_log SET ?u WHERE log_id = ?i', [
        'duration_ms' => max(0, $duration_ms),
        'ok' => $ok ? 'Y' : 'N',
        'error' => $error,
    ], $log_id);
}

/**
 * Basic per-vendor request rate limiting.
 * Returns true if allowed, false if throttled.
 */
function fn_jtl_connector_rate_limit_allow(int $company_id): bool
{
    fn_jtl_connector_ensure_runtime_tables();

    $limit = (int)fn_jtl_connector_get_addon_setting('rate_limit_per_min', '120');
    if ($limit <= 0) {
        return true;
    }

    $window = (int)floor(fn_jtl_connector_now() / 60);

    // Increment then check.
    db_query(
        'INSERT INTO ?:jtl_connector_rate_limit (company_id, window_start, cnt) VALUES (?i, ?i, 1) '
        . 'ON DUPLICATE KEY UPDATE cnt = cnt + 1',
        $company_id,
        $window
    );
    $cnt = (int)db_get_field('SELECT cnt FROM ?:jtl_connector_rate_limit WHERE company_id=?i AND window_start=?i', $company_id, $window);
    return $cnt <= $limit;
}

// Hooks (keep them safe/no-op across CS-Cart versions)

function fn_jtl_connector_get_companies(&$params, &$items_per_page, &$lang_code)
{
    // no-op (reserved for future admin UX)
}

function fn_jtl_connector_delete_company($company_id, $result = null)
{
    $company_id = (int)$company_id;
    if ($company_id <= 0) {
        return;
    }
    fn_jtl_connector_ensure_runtime_tables();
    db_query('DELETE FROM ?:jtl_connector_vendor WHERE company_id=?i', $company_id);
    db_query('DELETE FROM ?:jtl_connector_mapping WHERE company_id=?i', $company_id);
    db_query('DELETE FROM ?:jtl_connector_rate_limit WHERE company_id=?i', $company_id);
    db_query('DELETE FROM ?:jtl_connector_request_log WHERE company_id=?i', $company_id);
    db_query('DELETE FROM ?:jtl_connector_debug_event WHERE company_id=?i', $company_id);
    db_query('DELETE FROM ?:jtl_connector_watchdog_state WHERE company_id=?i', $company_id);
    db_query('DELETE FROM ?:jtl_connector_variation_parent WHERE company_id=?i', $company_id);
}

function fn_jtl_connector_change_order_status_post()
{
    // no-op (StatusChange is handled by the JTL connector runtime, not via CS-Cart hooks)
}

