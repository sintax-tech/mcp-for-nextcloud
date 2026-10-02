<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;

/** A validated plan, plus the confirmation a shared item needs when the caller has not given it. */
final class MovePlan {
    /**
     * @param array<string, mixed> $plan the plan as the client sees it
     * @param list<array{from:string, to:string}> $planned every item that passed the checks, in the order of the batch,
     *   the shared ones included: they run once the caller has the shared confirmation
     * @param array<string, mixed>|null $confirmation the guard payload for the first non-personal item
     */
    public function __construct(
        public readonly array $plan,
        public readonly array $planned,
        public readonly ?array $confirmation,
    ) {}

    /** @return bool whether every item may run */
    public function isOk(): bool {
        return (bool)$this->plan['ok'];
    }
}

/**
 * Reads a batch and says what would happen, without touching anything.
 *
 * This is the plan the user approves before the batch runs, and it is rebuilt again when the batch does run:
 * a blocked item does not stop it, because the agent has to see the whole list to be able to fix it, and a
 * plan of 200 items where 1 conflicts is a question for the user, not a failure to hide behind. The same
 * checks a single move runs come from Reorganization::inspect(), so a plan can never approve a move the
 * tool would refuse.
 */
final class MovePlanner {
    public function __construct(
        private Reorganization $reorganization,
        private NodeAccessInfo $access,
        private SharedWriteGuard $guard,
        private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null,
    ) {}

    /**
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param list<array{from:string, to:string}> $moves requested moves
     * @param list<string> $mkdirs folders the batch would create
     * @return MovePlan the plan, the runnable items and the pending confirmation
     */
    public function plan(Folder $root, string $userId, array $moves, array $mkdirs): MovePlan {
        $planned = [];
        $personal = [];
        $conflicts = [];
        $denied = [];
        $shared = [];
        $confirmation = null;
        foreach ($moves as $item) {
            $from = (string)$item['from'];
            $to = (string)$item['to'];
            try {
                $check = $this->reorganization->inspect($root, $from, $to, mkdirs: $mkdirs);
            } catch (MoveConflict $e) {
                $conflicts[] = ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()];
                continue;
            } catch (ToolFailure $e) {
                $denied[] = ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()];
                continue;
            }
            $payload = $this->guard->guard($check->source, $userId, $from, false)
                ?? $this->guard->guard($check->destination, $userId, $to, false);
            // A shared item stays in the run, in its place in the order: once the user confirms the shared ones, the
            // batch they approved is the whole list, and dropping an item would run one nobody asked for.
            $planned[] = ['from' => $from, 'to' => $to];
            if ($payload !== null) {
                $shared[] = ['from' => $from, 'to' => $to, 'scope' => $payload['scope'] ?? 'shared'];
                // The first non-personal item is the one the user is asked about; the rest are in the plan.
                $confirmation ??= $payload;
                continue;
            }
            $personal[] = ['from' => $from, 'to' => $to];
        }
        $dirs = $this->planDirs($root, $mkdirs);
        $plan = [
            'ok' => $conflicts === [] && $denied === [],
            'moves' => array_map(fn (array $item) => $item + ['ok' => true], $personal),
            'conflicts' => $conflicts,
            'denied' => $denied,
            'shared' => $shared,
            'mkdirs' => $dirs,
            'summary' => [
                'total' => count($moves),
                'planned' => count($personal),
                'conflicts' => count($conflicts),
                'denied' => count($denied),
                'shared' => count($shared),
                'mkdirs' => count(array_filter($dirs, fn (array $dir) => $dir['willCreate'])),
            ],
        ];
        return new MovePlan($plan, $planned, $confirmation);
    }

    /**
     * What the batch would do about the folders it was asked to create. A path that is already there is
     * reported as such and not counted as a creation: the undo only removes what the batch really made.
     *
     * @param Folder $root the user's folder
     * @param list<string> $paths requested folders
     * @return list<array{path:string, exists:bool, willCreate:bool}>
     */
    private function planDirs(Folder $root, array $paths): array {
        $dirs = [];
        foreach ($paths as $path) {
            $relative = ltrim(\OCA\Mcp\Tools\Common\PathGuard::normalize($path), '/');
            $exists = false;
            if ($relative === '') {
                $exists = true;
            } elseif ($root->nodeExists($relative)) {
                $node = null;
                try {
                    $node = $root->get($relative);
                } catch (\Throwable) {}
                if ($node !== null && ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node))) {
                    $exists = true;
                }
            }
            $dirs[] = ['path' => $path, 'exists' => $exists, 'willCreate' => !$exists];
        }
        return $dirs;
    }
}
