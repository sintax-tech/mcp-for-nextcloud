<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Access\ResourceScope;

use OCA\Mcp\Tests\Unit\Access\ResourceScope\FilesScopeActivationTest;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * The real FilesScope over a policy double whose Files scope a test sets: the items of the operation's snapshot, read
 * at call time. The end-to-end path through the real writer and resolution core is {@see FilesScopeActivationTest}.
 */
final class FilesScopes {
    /**
     * @param TestCase $test the test creating the doubles
     * @param VisibilityGuard $guard the plain guard whose parentOf() the chains follow
     * @param IRootFolder $root root the owner's tree is resolved from
     * @param \Closure(): (list<string>|null|false) $items selected folder ids, null for unrestricted, false for a
     *        scope that cannot be read
     * @param string|null $user the user of the open operation; null for none open
     */
    public static function over(TestCase $test, VisibilityGuard $guard, IRootFolder $root, \Closure $items, ?string $user = 'alice'): FilesScope {
        $policy = (new \ReflectionMethod($test, 'createMock'))->invoke($test, GrantPolicy::class);
        $policy->method('operationUser')->willReturnCallback(static fn (): ?string => $user);
        $policy->method('scopeItems')->willReturnCallback(static function (string $userId, string $module) use ($items): ?array {
            $value = $items($userId);
            return $value === false ? throw new PolicyUnavailable('Resource scope unavailable') : $value;
        });
        return new FilesScope($policy, $guard, $root);
    }
}
