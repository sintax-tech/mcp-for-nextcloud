<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Revocation;

use OCA\Mcp\OAuth\Revocation\CauseProcessor;

/**
 * Facts an event handler captured inside a caller's transaction (contracts §3.3.6), held in this process's memory
 * only: no query may run there, and the app cannot know whether that transaction will commit. Nothing is persisted, so
 * this is an auxiliary attempt, not durability; the items are processed at the first safe point of the process
 * (CauseProcessor::flush()) or, as a last resort, by the shutdown callback this queue registers once.
 *
 * Three kinds of item:
 * - undated: a user cause seen with no stored row and no date (the Before itself was inside the transaction);
 * - dated: the After of a cause already stored with its gen, delivered inside a transaction;
 * - group: a deletion or creation of a group seen inside a transaction (its lifecycle goes to needs_confirm, §3.3.6).
 */
class DeferredQueue {
    public const UNDATED = 'undated';
    public const DATED = 'dated';
    public const GROUP = 'group';

    /** @var array<string, array{kind:string, uid:string, causeKey:?string, gen:?string, gid?:string}> */
    private array $items = [];
    private bool $shutdownRegistered = false;

    /**
     * @param callable(): void $onShutdown registered with register_shutdown_function the first time an item arrives
     */
    public function undated(string $uid, callable $onShutdown): void {
        $this->keep(['kind' => self::UNDATED, 'uid' => $uid, 'causeKey' => null, 'gen' => null]);
        $this->registerShutdown($onShutdown);
    }

    /**
     * @param callable(): void $onShutdown registered with register_shutdown_function the first time an item arrives
     */
    public function dated(string $uid, string $causeKey, string $gen, callable $onShutdown): void {
        $this->keep(['kind' => self::DATED, 'uid' => $uid, 'causeKey' => $causeKey, 'gen' => $gen]);
        $this->registerShutdown($onShutdown);
    }

    /**
     * A group deletion or creation captured inside a caller's transaction; uid is empty for this kind.
     *
     * @param callable(): void $onShutdown registered with register_shutdown_function the first time an item arrives
     */
    public function group(string $gid, callable $onShutdown): void {
        $this->keep(['kind' => self::GROUP, 'uid' => '', 'causeKey' => null, 'gen' => null, 'gid' => $gid]);
        $this->registerShutdown($onShutdown);
    }

    /**
     * Removes and returns every item, in capture order; the caller puts back what it could not process.
     *
     * @return list<array{kind:string, uid:string, causeKey:?string, gen:?string, gid?:string}>
     */
    public function take(): array {
        $items = array_values($this->items);
        $this->items = [];
        return $items;
    }

    /**
     * Adds an item, or puts back one taken; the same fact captured twice is one item.
     *
     * @param array{kind:string, uid:string, causeKey:?string, gen:?string, gid?:string} $item kept for the next safe point
     */
    public function keep(array $item): void {
        $this->items[$item['kind'] . "\0" . $item['uid'] . "\0" . $item['causeKey'] . "\0" . $item['gen'] . "\0" . ($item['gid'] ?? '')] = $item;
    }

    public function isEmpty(): bool {
        return $this->items === [];
    }

    private function registerShutdown(callable $onShutdown): void {
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $this->register($onShutdown);
        }
    }

    /** The process hook; runs at the end of the script in HTTP and CLI, but not on SIGTERM/SIGKILL (§3.3.6). */
    protected function register(callable $onShutdown): void {
        register_shutdown_function($onShutdown);
    }
}
