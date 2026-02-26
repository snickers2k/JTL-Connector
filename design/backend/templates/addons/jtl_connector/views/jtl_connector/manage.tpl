{** Vendor-side settings **}
<h1>JTL Connector</h1>

{if $enabled_global != "Y"}
<div class="alert alert-warning">Addon is disabled globally in Add-ons &gt; Manage add-ons.</div>
{/if}

<form method="post" action="{"jtl_connector.manage"|fn_url}">
  <input type="hidden" name="mode" value="save" />

  <div class="control-group">
    <label class="control-label" for="enabled">Enable connector for this vendor</label>
    <div class="controls">
      <input type="checkbox" name="enabled" id="enabled" value="1" {if $vendor_row.enabled == "Y"}checked{/if} />
    </div>
  </div>

  <div class="control-group">
    <label class="control-label">Endpoint URL</label>
    <div class="controls">
      <input type="text" class="input-large" readonly value="{$endpoint_url}" />
      <p class="muted">Use this URL in JTL-Wawi as connector URL. Password = token below.</p>
    </div>
  </div>

  <div class="control-group">
    <label class="control-label">Token (sync password)</label>
    <div class="controls">
      <input type="text" class="input-large" readonly value="{$vendor_row.token}" />
    </div>
  </div>

  <div class="buttons-container">
    <button type="submit" class="btn btn-primary">Save</button>
    <a class="btn" href="{"jtl_connector.rotate_token"|fn_url}" data-ca-dispatch="dispatch[jtl_connector.manage]" data-ca-target-form="jtl_connector_rotate_form">Rotate token</a>
  </div>
</form>

<form id="jtl_connector_rotate_form" method="post" action="{"jtl_connector.manage"|fn_url}">
  <input type="hidden" name="mode" value="rotate_token" />
</form>
