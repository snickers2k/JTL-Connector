<h1>JTL Connector Endpoint</h1>
<p><b>company_id:</b> {$company_id}</p>
<p><b>Endpoint URL:</b> <code>{$endpoint_url}</code></p>
<p><b>Vendor enabled:</b> {$vendor_row.enabled}</p>
<p><b>Token configured:</b> {if $vendor_row.token}YES{else}NO{/if}</p>
<p>This endpoint responds to JTL RPC calls via POST. For vendor setup, use vendor panel: Add-ons &gt; JTL Connector.</p>
