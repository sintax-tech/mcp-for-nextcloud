<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Inspection;

use OCA\Mcp\Access\Visibility\VisibilityGuard;

use OCA\Mcp\Access\Grants\GrantPolicy;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Inspection\CalendarAccessExplainer;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Throwable;

/**
 * The administrator's inspector of effective access (ticket 13, C3; issue #4 A5 and B6): what the tools of one user
 * may do now and, for one path or one calendar, why the tools reach it or not. Every answer comes from the resolution
 * the tools use (PolicyResolver::resolveEffective, then the scope, the exclusion tags of the VisibilityGuard and the
 * native ACL), never from a parallel rule, so the inspector and the tools cannot disagree. It reads only: nothing is
 * saved, and no observation of group trust is persisted (a diagnosis is no writer command).
 *
 * "allowed" is said only when every gate was evaluated; a gate that could not be is named with not_evaluated. The
 * answers may name the folder an exclusion is inherited from, since they are for the administrator only; the tools
 * keep answering "not found". The caller (the inspector route) checks the administrator before calling and audits.
 */
class AccessInspector {
    public const ALLOWED = CalendarAccessExplainer::ALLOWED;
    public const BLOCKED = CalendarAccessExplainer::BLOCKED;
    public const OUT_OF_SCOPE = CalendarAccessExplainer::OUT_OF_SCOPE;
    public const NOT_EVALUATED = CalendarAccessExplainer::NOT_EVALUATED;
    public const NO_NATIVE_ACCESS = CalendarAccessExplainer::NO_NATIVE_ACCESS;
    /** The path does not exist in the user's tree; from there it looks like one without access. */
    public const NOT_FOUND = 'not_found';
    /** Excluded by the tags; `exclusion` says how (VisibilityGuard::EXPLAINED_*). */
    public const EXCLUDED = 'excluded';

    public function __construct(
        private PolicyResolver $resolver,
        private IRootFolder $root,
        private VisibilityGuard $guard,
        private CalendarAccessExplainer $calendars,
        private FilesScope $filesScope,
    ) {}

    /**
     * The effective access of every catalog operation, as the tools would be admitted now: the first gate that
     * blocks, and the resolved grant beside it, which stays readable when a temporary gate (service, connection, app)
     * blocks. A restriction by resource narrows resources only and never blocks an operation here.
     *
     * @param string $uid the inspected user
     * @return array{revision:string, operations:list<array{module:string, operation:string, allowed:?bool, reasonCode:string, blockingGate:?string, grant:array{allowed:?bool, source:string, groupIds:list<string>}, scope:array{mode:string, count:?int}}>}
     * @throws PolicyUnavailable when the policy cannot be read
     */
    public function effective(string $uid): array {
        return $this->within($uid, static function (PolicyOperationContext $context): array {
            $operations = [];
            foreach (GrantPolicy::CATALOG as $module => $names) {
                foreach ($names as $operation) {
                    $result = $context->resolveEffective($module, $operation);
                    $operations[] = [
                        'module' => $module,
                        'operation' => $operation,
                        'allowed' => $result->allowed,
                        'reasonCode' => $result->reasonCode,
                        'blockingGate' => $result->blockingGate,
                        'grant' => ['allowed' => $result->grantResolution->allowed, 'source' => $result->grantResolution->source,
                            'groupIds' => $result->grantResolution->groupIds],
                        'scope' => ['mode' => $result->scopeResolution->mode, 'count' => $result->scopeResolution->count()],
                    ];
                }
            }
            return ['revision' => $context->revision, 'operations' => $operations];
        });
    }

    /**
     * Why the Files tools of a user can or cannot read one path (contract A.4), in the order the tools decide it
     * (NodeAccess::get, contract A.5): the policy gates and files.read, the path normalized like the tools and
     * resolved, then the scope, then the exclusion tags, then the native read permission.
     *
     * @param string $uid the inspected user
     * @param string $path absolute path in the user's own tree ("/Projects/plan.md")
     * @return array{reason:string, gate:?string, exclusion:?string, folder:?string} folder is the path, in the user's
     *         tree, of the tagged ancestor an exclusion is inherited from
     * @throws InvalidArgumentException for a path outside the contract
     */
    public function path(string $uid, string $path): array {
        try {
            // The tools' own rule (control characters, '..', '.', empty segments, backslashes).
            $path = PathGuard::normalize($path);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid request');
        }
        try {
            return $this->within($uid, fn (PolicyOperationContext $context): array => $this->explainPath($context, $uid, $path));
        } catch (PolicyUnavailable) {
            return self::answer(self::NOT_EVALUATED, 'policy');
        }
    }

    /**
     * @param string $uid the inspected user
     * @param string $module calendar or tasks
     * @param string $calendarId canonical decimal calendar id
     * @param string $operation catalog operation of the module
     * @return array{reason:string, gate:?string}
     * @throws InvalidArgumentException for input outside the contract
     */
    public function calendar(string $uid, string $module, string $calendarId, string $operation): array {
        return $this->calendars->explain($uid, $module, $calendarId, $operation);
    }

    private function explainPath(PolicyOperationContext $context, string $uid, string $path): array {
        $module = $context->resolveEffective('files', 'read');
        if ($module->allowed !== true) {
            return self::answer($module->allowed === null ? self::NOT_EVALUATED : self::BLOCKED, $module->blockingGate);
        }
        try {
            $home = $this->root->getUserFolder($uid);
            $node = $path === '/' ? $home : $home->get(ltrim($path, '/'));
        } catch (NotFoundException) {
            return self::answer(self::NOT_FOUND, 'native_acl');
        } catch (Throwable) {
            return self::answer(self::NOT_EVALUATED, 'native_acl');
        }
        try {
            // The candidates the tools use (mounted chain and owner's chain above a share), built only when this
            // snapshot restricts Files, so explanation and decision never diverge.
            $ids = $this->filesScope->candidatesIn($context->scope()->resolution('files'), $node);
        } catch (Throwable) {
            // The candidates of the scope could not be built: the scope gate is the one not evaluated.
            return self::answer(self::NOT_EVALUATED, 'resource_scope_unknown');
        }
        $scoped = $context->resolveEffective('files', 'read', $ids);
        if ($scoped->allowed !== true) {
            return self::answer($scoped->allowed === null ? self::NOT_EVALUATED : self::OUT_OF_SCOPE, $scoped->blockingGate);
        }
        $why = $this->guard->explain($node);
        if ($why['reason'] === VisibilityGuard::EXPLAINED_NOT_EVALUATED) {
            return self::answer(self::NOT_EVALUATED, 'exclusion');
        }
        if ($why['reason'] !== VisibilityGuard::EXPLAINED_VISIBLE) {
            $folder = $why['folder'] === null ? null : $home->getRelativePath($why['folder']->getPath());
            return self::answer(self::EXCLUDED, 'exclusion', $why['reason'], $folder);
        }
        // Last, as NodeAccess::get checks it after the exclusion guard.
        try {
            $readable = $node->isReadable();
        } catch (Throwable) {
            return self::answer(self::NOT_EVALUATED, 'native_acl');
        }
        return $readable ? self::answer(self::ALLOWED, null) : self::answer(self::NO_NATIVE_ACCESS, 'native_acl');
    }

    /**
     * @template T
     * @param \Closure(PolicyOperationContext): T $read
     * @return T
     * @throws PolicyUnavailable when the policy cannot be read
     */
    private function within(string $uid, \Closure $read): mixed {
        $context = $this->resolver->begin($uid, [], [], false);
        try {
            return $read($context);
        } finally {
            $context->close();
        }
    }

    /** @return array{reason:string, gate:?string, exclusion:?string, folder:?string} */
    private static function answer(string $reason, ?string $gate, ?string $exclusion = null, ?string $folder = null): array {
        return ['reason' => $reason, 'gate' => $gate, 'exclusion' => $exclusion, 'folder' => $folder];
    }
}
