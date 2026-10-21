<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Server;
use Throwable;

/**
 * Records, for the cell's artifact, how the real mounts look to the code the visibility guard runs: the class, ids,
 * storage and owner of each node and the result of every step of the guard's walk. It asserts only that the walk could
 * be recorded; the scenarios assert behavior.
 */
final class DiagnosticsTest extends ExclusionTestCase {
    public function testRecordsWhatTheGuardSeesInEachKindOfMount(): void {
        $external = $this->makeFile('external', 'diag.txt', 'diag');
        $this->record('external file of alice', $external);

        $gf = $this->makeFile('groupfolder', 'diag.txt', 'diag');
        $this->record('groupfolder file of alice', $gf);

        $shareName = 'diag-gf-' . $this->run . '.txt';
        $gfShared = $this->makeFile('groupfolder', $shareName, 'diag');
        try {
            $this->shareToUser($gfShared, self::ALICE, self::BOB);
            $bobNode = $this->userFolder(self::BOB)->get($shareName);
            $this->record('groupfolder file shared to bob, as bob sees it', $bobNode);
        } catch (Throwable $e) {
            $this->observe('groupfolder share to bob: ' . $e::class . ': ' . $e->getMessage());
        }

        $home = $this->makeFile('home', 'diag.txt', 'diag');
        $this->shareToUser($home, self::ALICE, self::BOB);
        try {
            $this->record('home file shared to bob, as bob sees it', $this->userFolder(self::BOB)->get('diag.txt'));
        } catch (Throwable $e) {
            $this->observe('home file shared to bob, as bob sees it: ' . $e::class . ': ' . $e->getMessage());
        }

        // The steps a files_read of the external file takes, replayed one by one for alice with their exceptions.
        $relative = trim($this->path('external', 'diag.txt'), '/');
        $steps = [
            'get' => fn () => $this->userFolder(self::ALICE)->get($relative),
            'isReadable' => fn () => $this->userFolder(self::ALICE)->get($relative)->isReadable(),
            'describe' => fn () => \OCP\Server::get(\OCA\Mcp\Platform\FileAccess\Nodes\NodeAccessInfo::class)->describe($this->userFolder(self::ALICE)->get($relative), self::ALICE),
            'getContent' => fn () => $this->userFolder(self::ALICE)->get($relative)->getContent(),
            'guard' => fn () => $this->newGuard()->isVisible($this->userFolder(self::ALICE)->get($relative)),
        ];
        foreach ($steps as $name => $step) {
            try {
                $this->observe("replay $name: " . substr(json_encode($step()), 0, 300));
            } catch (Throwable $e) {
                $this->observe("replay $name FAILED: " . $e::class . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        $dav = \OCA\Mcp\Tests\Integration\Support\Http::request('GET', \OCA\Mcp\Tests\Integration\Support\Http::BASE . '/remote.php/dav/files/' . self::ALICE . '/' . $relative,
            ['Authorization: ' . $this->login(self::ALICE)->authorization()]);
        $this->observe('WebDAV GET of the external file as alice -> HTTP ' . $dav['status']);

        // What the server's own HTTP endpoints answer for the same places, without any exclusion configured.
        $alice = $this->mcp();
        $bob = $this->mcp(self::BOB);
        foreach ([[$alice, '/'], [$alice, '/mcp-it-ext-wide'], [$alice, $this->path('external')], [$bob, '/']] as [$client, $path]) {
            $reply = $client->tool('files_list', ['path' => $path]);
            $this->observe('files_list ' . $client->uid() . ' ' . $path . ' -> ' . substr(preg_replace('/\s+/', ' ', $reply->raw), 0, 300));
        }
        foreach ([[$alice, $this->path('external', 'diag.txt')], [$alice, $this->path('groupfolder', 'diag.txt')], [$bob, '/' . $shareName],
            [$bob, '/diag.txt']] as [$client, $path]) {
            $reply = $client->tool('files_read', ['path' => $path]);
            $this->observe('files_read ' . $client->uid() . ' ' . $path . ' (no exclusion) -> ' . substr(preg_replace('/\s+/', ' ', $reply->raw), 0, 200));
        }
        $mkdir = $alice->write('files_mkdir', ['path' => $this->path('external', 'made')]);
        $this->observe('files_mkdir external (no exclusion) -> ' . substr(preg_replace('/\s+/', ' ', $mkdir->raw), 0, 200));
        $this->exclude();
        foreach ([[$alice, $this->path('external', 'diag.txt')], [$alice, $this->path('groupfolder', 'diag.txt')], [$bob, '/' . $shareName],
            [$bob, '/diag.txt']] as [$client, $path]) {
            $reply = $client->tool('files_read', ['path' => $path]);
            $this->observe('files_read ' . $client->uid() . ' ' . $path . ' (exclusion configured, nothing tagged) -> ' . substr(preg_replace('/\s+/', ' ', $reply->raw), 0, 200));
        }

        // The same walk in a fresh process, the way a web request meets the tree for the first time.
        $probe = dirname(__DIR__) . '/Support/chain-probe.php';
        $probes = [['alice', self::ALICE, $this->path('external', 'diag.txt')], ['alice', self::ALICE, $this->path('groupfolder', 'diag.txt')],
            ['alice', self::ALICE, $this->path('home', 'diag.txt')], ['bob', self::BOB, $shareName], ['bob', self::BOB, 'diag.txt']];
        foreach ($probes as [$who, $uid, $path]) {
            $output = [];
            exec('php -d memory_limit=512M ' . escapeshellarg($probe) . ' ' . escapeshellarg($uid) . ' ' . escapeshellarg(trim($path, '/')) . ' 2>&1', $output);
            $this->observe("fresh process, $who, " . trim($path, '/') . ":\n    " . implode("\n    ", array_map(static fn (string $l): string => substr($l, 0, 300), $output)));
        }
        $this->addToAssertionCount(1);
    }

    private function record(string $label, Node $node): void {
        $lines = [];
        $current = $node;
        $rootFolder = Server::get(IRootFolder::class);
        for ($i = 0; $i < 12; $i++) {
            try {
                $storage = $current->getStorage();
                $mount = $current->getMountPoint();
                $owner = $current->getOwner();
                $lines[] = sprintf('%s id=%d path=%s storage=%s(%s) shared=%s mount=%s owner=%s', $current::class, (int)$current->getId(),
                    $current->getPath(), $storage::class, $storage->getId(), $storage->instanceOfStorage(ISharedStorage::class) ? 'yes' : 'no',
                    $mount->getMountPoint(), $owner === null ? 'null' : $owner->getUID());
            } catch (Throwable $e) {
                $lines[] = $current::class . ' step failed: ' . $e::class . ': ' . $e->getMessage();
            }
            if ($current instanceof IRootFolder) {
                break;
            }
            try {
                $current = $current->getParent();
            } catch (Throwable $e) {
                $lines[] = 'getParent failed: ' . $e::class . ': ' . $e->getMessage();
                break;
            }
        }
        try {
            $owner = $node->getOwner();
            if ($owner !== null) {
                $folder = $rootFolder->getUserFolder($owner->getUID());
                $found = $folder->getById((int)$node->getId());
                $lines[] = 'owner tree lookup of id ' . $node->getId() . ' in ' . $owner->getUID() . ': ' . count($found) . ' node(s)';
            } else {
                $lines[] = 'owner tree lookup impossible: getOwner() is null';
            }
        } catch (Throwable $e) {
            $lines[] = 'owner tree lookup failed: ' . $e::class . ': ' . $e->getMessage();
        }
        $this->observe($label . ":\n    " . implode("\n    ", $lines));
    }
}
