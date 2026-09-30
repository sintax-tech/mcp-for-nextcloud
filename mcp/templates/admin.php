<?php
/** @var array $_ */
\OCP\Util::addScript('mcp', 'admin-user-picker');
$escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="section" id="mcp-admin">
    <h2>MCP</h2>
    <p>Endpoint: <code><?= $escape($_['endpoint']) ?></code></p>
    <p>Nextcloud 33 test release. Only the diagnostic tool is available in this sprint.</p>
    <form method="post" action="<?= $escape($_['globalAction']) ?>">
        <input type="hidden" name="requesttoken" value="<?= $escape($_['token']) ?>">
        <input type="hidden" name="enabled" value="<?= $_['enabled'] ? '0' : '1' ?>">
        <p>Service: <strong><?= $_['enabled'] ? 'enabled' : 'disabled' ?></strong></p>
        <button type="submit"><?= $_['enabled'] ? 'Disable' : 'Enable' ?> MCP</button>
    </form>
    <h3>User access and grants</h3>
    <p>New users, including administrators, cannot connect until enabled here. Write grants are disabled by default.</p>
    <form method="get" action="<?= $escape($_['settingsUrl']) ?>">
        <label for="mcp_uid">Nextcloud user ID</label>
        <input id="mcp_uid" name="mcp_uid" list="mcp_uid_list" value="<?= $escape($_['selectedUid']) ?>" autocomplete="off" required>
        <datalist id="mcp_uid_list"></datalist>
        <button type="submit">Load user</button>
    </form>
    <?php if ($_['selectedUid'] !== ''): ?>
        <p>Selected: <strong><?= $escape($_['selectedUid']) ?></strong></p>
        <form method="post" action="<?= $escape($_['userAction']) ?>">
            <input type="hidden" name="requesttoken" value="<?= $escape($_['token']) ?>">
            <input type="hidden" name="uid" value="<?= $escape($_['selectedUid']) ?>">
            <input type="hidden" name="kind" value="eligible">
            <input type="hidden" name="enabled" value="<?= $_['selectedEligible'] ? '0' : '1' ?>">
            <p>Connection eligibility: <strong><?= $_['selectedEligible'] ? 'enabled' : 'disabled' ?></strong></p>
            <button type="submit"><?= $_['selectedEligible'] ? 'Remove eligibility' : 'Allow connection' ?></button>
        </form>
        <table>
            <thead><tr><th>Module</th><th>Operation</th><th>Grant</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($_['catalog'] as $module => $operations): ?>
                <?php foreach ($operations as $operation): ?>
                    <?php $granted = $_['grants'][$module][$operation]; ?>
                    <tr>
                        <td><?= $escape($module) ?></td>
                        <td><?= $escape($operation) ?></td>
                        <td><?= $granted ? 'allowed' : 'denied' ?></td>
                        <td>
                            <form method="post" action="<?= $escape($_['userAction']) ?>">
                                <input type="hidden" name="requesttoken" value="<?= $escape($_['token']) ?>">
                                <input type="hidden" name="uid" value="<?= $escape($_['selectedUid']) ?>">
                                <input type="hidden" name="kind" value="grant">
                                <input type="hidden" name="module" value="<?= $escape($module) ?>">
                                <input type="hidden" name="operation" value="<?= $escape($operation) ?>">
                                <input type="hidden" name="enabled" value="<?= $granted ? '0' : '1' ?>">
                                <button type="submit"><?= $granted ? 'Revoke' : 'Grant' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p>These are app grants. Nextcloud resource ACLs will also be checked when data tools are implemented.</p>
    <?php endif; ?>
</div>
