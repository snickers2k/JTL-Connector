<h1>JTL Connector</h1>

{if $settings.enabled_global != "Y"}
<div class="alert alert-warning">Addon is disabled globally in Add-ons &gt; Manage add-ons.</div>
{/if}

<div class="buttons-container">
  <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline; margin-right:8px;">
    <input type="hidden" name="mode" value="run_watchdog_tick" />
    <button type="submit" class="btn">Run watchdog tick</button>
  </form>

  <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline; margin-right:8px;">
    <input type="hidden" name="mode" value="run_pruner" />
    <button type="submit" class="btn">Run log pruner</button>
  </form>

  <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline;">
    <input type="hidden" name="mode" value="send_test_email" />
    <button type="submit" class="btn">Send test email</button>
  </form>
</div>

<p>
  <small>
    Debug: <strong>{$settings.debug_enabled}</strong> &nbsp;|&nbsp;
    Verbose: <strong>{$settings.verbose_enabled}</strong> &nbsp;|&nbsp;
    Notify email: <strong>{$settings.admin_email}</strong>
  </small>
</p>

<h2>Automation</h2>
<div class="well">
  <p>
    <strong>Internal scheduler (traffic-based):</strong>
    {if $settings.internal_scheduler_enabled == "Y"}
      <span class="label label-success">Enabled</span>
    {else}
      <span class="label">Disabled</span>
    {/if}
    <br/>
    <small>
      This runs watchdog/pruner when the site gets traffic (best-effort). For reliable monitoring, use cron or an external scheduler calling the URLs below.
    </small>
  </p>

  <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline; margin-right:8px;">
    <input type="hidden" name="mode" value="toggle_internal_scheduler" />
    <input type="hidden" name="enabled" value="{if $settings.internal_scheduler_enabled == "Y"}N{else}Y{/if}" />
    <button type="submit" class="btn">{if $settings.internal_scheduler_enabled == "Y"}Disable internal scheduler{else}Enable internal scheduler{/if}</button>
  </form>

  <hr/>
  <p><strong>Cron URLs (secure token):</strong></p>
  <p>
    <small>Watchdog tick:</small><br/>
    <input type="text" class="input-xxlarge" readonly value="{$settings.cron_url_watchdog}" />
  </p>
  <p>
    <small>Log pruner:</small><br/>
    <input type="text" class="input-xxlarge" readonly value="{$settings.cron_url_pruner}" />
  </p>
  <p>
    <small>Example crontab:</small><br/>
    <pre style="white-space:pre-wrap;">*/10 * * * * curl -fsS "{$settings.cron_url_watchdog}" >/dev/null 2>&1
0 3 * * * curl -fsS "{$settings.cron_url_pruner}" >/dev/null 2>&1</pre>
  </p>
</div>

<h2>Admin Alerts</h2>
{if $settings.admin_alerts_unread > 0}
  <p>
    <span class="label label-warning">{$settings.admin_alerts_unread} unread</span>
    &nbsp;
    <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline;">
      <input type="hidden" name="mode" value="clear_alerts" />
      <input type="hidden" name="which" value="read" />
      <button type="submit" class="btn">Clear read alerts</button>
    </form>
  </p>
{/if}

{if $alerts|count > 0}
  <table class="table table-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>Vendor</th>
        <th>Level</th>
        <th>Title</th>
        <th>Message</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {foreach $alerts as $a}
        <tr>
          <td>{if $a.ts}{$a.ts|date_format:"%Y-%m-%d %H:%M"}{else}-{/if}</td>
          <td>{if $a.company_id}{$a.company_id}{else}Global{/if}</td>
          <td>{$a.level}</td>
          <td>{$a.title|escape}</td>
          <td><small>{$a.message|truncate:160|escape}</small></td>
          <td style="white-space:nowrap;">
            <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline;">
              <input type="hidden" name="mode" value="mark_alert_read" />
              <input type="hidden" name="alert_id" value="{$a.alert_id}" />
              <button type="submit" class="btn">Mark read</button>
            </form>
            <form method="post" action="{"jtl_connector.manage"|fn_url}" style="display:inline;">
              <input type="hidden" name="mode" value="delete_alert" />
              <input type="hidden" name="alert_id" value="{$a.alert_id}" />
              <button type="submit" class="btn">Delete</button>
            </form>
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
{else}
  <div class="alert alert-info">No unread alerts.</div>
{/if}

<table class="table table-middle">
  <thead>
    <tr>
      <th>Company ID</th>
      <th>Vendor</th>
      <th>Enabled</th>
      <th>Endpoint</th>
      <th>Token</th>
      <th>Last OK</th>
      <th>Errors</th>
      <th>Last error</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  {foreach $vendors as $v}
    <tr>
      <td>{$v.company_id}</td>
      <td>{$v.company}</td>
      <td>{if $v.enabled=="Y"}<span class="label label-success">Y</span>{else}<span class="label">N</span>{/if}</td>
      <td><input type="text" class="input-large" readonly value="{$v.endpoint_url}" /></td>
      <td>{$v.token_tail}</td>
      <td>{if $v.last_ok_ts}{$v.last_ok_ts|date_format:"%Y-%m-%d %H:%M"}{else}-{/if}</td>
      <td>{if $v.consecutive_errors}{$v.consecutive_errors}{else}0{/if}</td>
      <td>{if $v.last_error}{$v.last_error|truncate:80}{else}-{/if}</td>
      <td>
        <form method="post" action="{"jtl_connector.manage"|fn_url}">
          <input type="hidden" name="mode" value="reset_watchdog" />
          <input type="hidden" name="company_id" value="{$v.company_id}" />
          <button type="submit" class="btn">Reset watchdog</button>
        </form>
      </td>
    </tr>
  {/foreach}
  </tbody>
</table>

<h2>Payload Samples (latest)</h2>
{if $settings.verbose_enabled != "Y"}
  <div class="alert alert-warning">Verbose logging is disabled. Enable it to capture and view payload samples.</div>
{/if}

{foreach $vendors as $v}
  {assign var=cid value=$v.company_id}
  <h3>{$v.company|escape} (ID {$v.company_id})</h3>
  {if isset($samples_by_company[$cid]) && $samples_by_company[$cid]|count > 0}
    {foreach $samples_by_company[$cid] as $entity => $items}
      <h4>{$entity|escape}</h4>
      {foreach $items as $s}
        <p><small>{$s.ts|date_format:"%Y-%m-%d %H:%M"} &nbsp;|&nbsp; {$s.direction|escape}</small></p>
        <pre style="white-space:pre-wrap;">{$s.snippet|escape}</pre>
      {/foreach}
    {/foreach}
  {else}
    <div class="alert">No samples yet.</div>
  {/if}
{/foreach}
