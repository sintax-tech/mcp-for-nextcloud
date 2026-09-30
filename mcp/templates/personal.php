<?php
/** @var array $_ */
$escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="section" id="mcp-personal">
    <h2>MCP connection</h2>
    <p>Endpoint: <code><?= $escape($_['endpoint']) ?></code></p>
    <p>Service: <strong><?= $_['global'] ? 'enabled' : 'disabled' ?></strong>. Administrator eligibility: <strong><?= $_['eligible'] ? 'allowed' : 'not allowed' ?></strong>.</p>
    <p>Your connection: <strong><?= $_['connected'] ? 'active' : 'disabled' ?></strong>.</p>
    <form method="post" action="<?= $escape($_['action']) ?>">
        <input type="hidden" name="requesttoken" value="<?= $escape($_['token']) ?>">
        <input type="hidden" name="enabled" value="<?= $_['connected'] ? '0' : '1' ?>">
        <button type="submit" <?= !$_['connected'] && (!$_['eligible'] || !$_['global']) ? 'disabled' : '' ?>><?= $_['connected'] ? 'Disconnect' : 'Connect' ?></button>
    </form>
    <p>Create an individual app password in Nextcloud Settings → Security, then use it with your Nextcloud user ID in a client that supports HTTP Basic authentication and Streamable HTTP MCP 2025-06-18. This app never asks for your Nextcloud password.</p>
    <p>Disconnecting blocks access to this app on the next request. It does not revoke the app password; revoke that separately in Nextcloud Settings → Security.</p>
</div>
