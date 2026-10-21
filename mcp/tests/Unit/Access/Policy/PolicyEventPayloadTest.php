<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/** Receipt and audit payloads are validated exactly, when built and when read back. */
final class PolicyEventPayloadTest extends TestCase {
    private const RECEIPT_ID = 'ab12ab12ab12ab12ab12ab12ab12ab12-1';

    private static function change(array $override = []): array {
        return $override + ['uid' => 'alice', 'key' => 'grant_notes_edit', 'before' => null, 'after' => '1'];
    }

    public function testValidPayloadsOfBothKindsRoundTrip(): void {
        $receipt = PolicyEventPayload::receipt('root', 'grants.commands', '41', '42', [self::change(), self::change(['uid' => 'bob', 'key' => 'eligible', 'before' => '1', 'after' => null])]);
        $this->assertSame($receipt, PolicyEventPayload::validate(PolicyEventsMapper::KIND_RECEIPT, json_decode(json_encode($receipt), true)));
        $audit = PolicyEventPayload::audit(null, 'service', '9223372036854775806', '9223372036854775807', null, null,
            [['uid' => null, 'key' => 'service_enabled', 'before' => '1', 'after' => '0']]);
        $this->assertSame($audit, PolicyEventPayload::validate(PolicyEventsMapper::KIND_AUDIT, $audit));
        $this->assertIsArray(PolicyEventPayload::audit('root', 'grants.undo', '2', '3', null, self::RECEIPT_ID, [self::change()]));
        $this->assertIsArray(PolicyEventPayload::audit('root', 'grants.bulk', '2', '3', self::RECEIPT_ID, null, []));
        $group = PolicyEventPayload::audit('root', 'group_grants', '2', '3', null, null, [self::groupChange()]);
        $this->assertSame($group, PolicyEventPayload::validate(PolicyEventsMapper::KIND_AUDIT, json_decode(json_encode($group), true)));
        $preview = PolicyEventPayload::preview('root', 'staff', '7', ['_system' => ['eligible'], 'notes' => ['edit']], str_repeat('ab', 32),
            ['state' => 'needs_confirm', 'gen' => str_repeat('c', 32)]);
        $this->assertSame($preview, PolicyEventPayload::validate(PolicyEventsMapper::KIND_PREVIEW, json_decode(json_encode($preview), true)));
        $this->assertIsArray(PolicyEventPayload::preview('root', 'staff', '0', [], str_repeat('ab', 32), null));
    }

    private static function groupChange(array $override = []): array {
        return $override + ['gid' => 'staff', 'key' => 'group_grants', 'before' => null, 'after' => '{"state":"active","grants":{"notes":["edit"]}}'];
    }

    public function testTheGroupSnapshotKeepsStateAndRowsApartFromAbsence(): void {
        $this->assertNull(PolicyEventPayload::groupSnapshot(null, []));
        $this->assertSame('{"state":"needs_confirm","grants":{}}', PolicyEventPayload::groupSnapshot('needs_confirm', []));
        $this->assertSame(PolicyEventPayload::memberHash(['b', 'a']), PolicyEventPayload::memberHash(['a', 'b']));
        $this->assertNotSame(PolicyEventPayload::memberHash(['a', 'b']), PolicyEventPayload::memberHash(['a', 'c']));
    }

    /** @return array<string, array{string, array}> */
    public static function invalid(): array {
        $receipt = ['v' => 1, 'actor' => 'root', 'action' => 'grants.update', 'fromRevision' => '0', 'revision' => '1', 'changes' => [self::change()]];
        $audit = ['v' => 1, 'actor' => 'root', 'action' => 'service', 'fromRevision' => '0', 'revision' => '1', 'receiptId' => null, 'undoOf' => null,
            'changes' => [['uid' => null, 'key' => 'service_enabled', 'before' => null, 'after' => '1']]];
        return [
            'version' => ['receipt', ['v' => 2] + $receipt],
            'extra field' => ['receipt', $receipt + ['note' => 'x']],
            'missing field' => ['receipt', array_diff_key($receipt, ['changes' => 1])],
            'no actor' => ['receipt', ['actor' => null] + $receipt],
            'audit action on a receipt' => ['receipt', ['action' => 'service'] + $receipt],
            'revision skips' => ['receipt', ['revision' => '2'] + $receipt],
            'revision as number' => ['receipt', ['revision' => 1] + $receipt],
            'no changes' => ['receipt', ['changes' => []] + $receipt],
            'app key in a receipt' => ['receipt', ['changes' => [['uid' => null, 'key' => 'service_enabled', 'before' => null, 'after' => '1']]] + $receipt],
            'unchanged cell' => ['receipt', ['changes' => [self::change(['before' => '1'])]] + $receipt],
            'unknown key' => ['receipt', ['changes' => [self::change(['key' => 'grant_files_delete'])]] + $receipt],
            'non-string value' => ['receipt', ['changes' => [self::change(['after' => true])]] + $receipt],
            'NUL in uid' => ['receipt', ['changes' => [self::change(['uid' => "a\0b"])]] + $receipt],
            'undo without undoOf' => ['audit', ['action' => 'grants.undo'] + $audit],
            'undoOf on another action' => ['audit', ['undoOf' => self::RECEIPT_ID] + $audit],
            'receipt link with changes' => ['audit', ['receiptId' => self::RECEIPT_ID] + $audit],
            'invalid receipt id' => ['audit', ['receiptId' => 'x', 'changes' => []] + $audit],
            'unknown app key' => ['audit', ['changes' => [['uid' => null, 'key' => 'enabled', 'before' => null, 'after' => '1']]] + $audit],
            'cleanup kind' => ['cleanup', $audit],
            'group change on another action' => ['audit', ['changes' => [self::groupChange()]] + $audit],
            'group save without its change' => ['audit', ['action' => 'group_grants', 'changes' => []] + $audit],
            'group save with a user change' => ['audit', ['action' => 'group_grants', 'changes' => [self::groupChange(), self::change()]] + $audit],
            'unchanged group' => ['audit', ['action' => 'group_grants', 'changes' => [self::groupChange(['before' => self::groupChange()['after']])]] + $audit],
            'group change with a uid' => ['audit', ['action' => 'group_grants', 'changes' => [self::groupChange(['uid' => 'x'])]] + $audit],
            'preview with an unknown grant' => ['preview', ['v' => 1, 'actor' => 'root', 'gid' => 'staff', 'revision' => '1',
                'grants' => ['files' => ['nuke']], 'memberHash' => str_repeat('ab', 32), 'life' => null]],
            'preview with an unsorted set' => ['preview', ['v' => 1, 'actor' => 'root', 'gid' => 'staff', 'revision' => '1',
                'grants' => ['notes' => ['read', 'edit']], 'memberHash' => str_repeat('ab', 32), 'life' => null]],
            'preview with a bad hash' => ['preview', ['v' => 1, 'actor' => 'root', 'gid' => 'staff', 'revision' => '1',
                'grants' => [], 'memberHash' => 'x', 'life' => null]],
            'preview with a bad lifecycle' => ['preview', ['v' => 1, 'actor' => 'root', 'gid' => 'staff', 'revision' => '1',
                'grants' => [], 'memberHash' => str_repeat('ab', 32), 'life' => ['state' => 'gone', 'gen' => str_repeat('c', 32)]]],
        ];
    }

    #[DataProvider('invalid')]
    public function testEveryDeviationIsRefused(string $kind, array $payload): void {
        $this->expectException(UnexpectedValueException::class);
        PolicyEventPayload::validate($kind, $payload);
    }

    public function testCellKeysAreEligibilityOrTheCatalog(): void {
        $this->assertSame('eligible', PolicyEventPayload::userKey(null, 'eligible'));
        $this->assertSame('grant_logs_read', PolicyEventPayload::userKey('logs', 'read'));
        $this->assertNull(PolicyEventPayload::userKey('notes', 'eligible'));
        $this->assertNull(PolicyEventPayload::userKey(null, 'read'));
        $this->assertNull(PolicyEventPayload::userKey('files', 'delete'));
        $this->assertFalse(PolicyEventPayload::validUserKey('connected'), 'connected is the user\'s own switch, not an admin cell');
    }
}
