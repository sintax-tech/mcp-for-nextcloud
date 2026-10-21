<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** Grant-only counting must never become an authorization decision. */
final class GroupCountingBoundaryTest extends TestCase {
    /** Token scanning covers literal method references even when invoked indirectly, plus unknown dynamic calls. */
    private static function countingReferences(string $source): array {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($token): bool => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $references = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) continue;
            if (in_array($token[0], [T_STRING, T_CONSTANT_ENCAPSED_STRING], true)) {
                $value = $token[0] === T_STRING ? $token[1] : stripcslashes(substr($token[1], 1, -1));
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($previous) && $previous[0] === T_FUNCTION) continue;
                if (in_array($value, ['beginManyGrants', 'inherited'], true)) $references[] = $value;
            }
            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $next = $tokens[$i + 1] ?? null;
                if (is_array($next) && $next[0] === T_VARIABLE && ($tokens[$i + 2] ?? null) === '(') {
                    $references[] = 'dynamic method';
                } elseif ($next === '{') {
                    // Reject dynamically computed callable names; dynamic property access remains allowed.
                    $end = $i + 2;
                    $depth = 1;
                    for (; $end < count($tokens) && $depth > 0; ++$end) {
                        if ($tokens[$end] === '{') ++$depth;
                        if ($tokens[$end] === '}') --$depth;
                    }
                    if (($tokens[$end] ?? null) === '(') $references[] = 'dynamic method';
                }
            }
        }
        return array_values(array_unique($references));
    }

    public function testCountingBoundaryDetectsIndirectAndDynamicCalls(): void {
        foreach (['beginManyGrants', 'inherited'] as $method) {
            foreach ([
                '$obj->' . $method . '();',
                '[$obj, "' . $method . '"]();',
                'call_user_func([$obj, "' . $method . '"]);',
                '$m = "' . $method . '"; $obj->$m();',
                '$obj->{"' . $method . '"}();',
            ] as $source) {
                $this->assertContains($method, self::countingReferences('<?php ' . $source));
            }
        }
        $this->assertContains('dynamic method', self::countingReferences('<?php $obj->$m();'));
        $this->assertContains('dynamic method', self::countingReferences('<?php $obj->{$m}();'));
        $this->assertSame([], self::countingReferences('<?php function inherited() {} // beginManyGrants'));
    }

    public function testGrantOnlyMethodsAreReferencedOnlyByTheAdminCountingReadModel(): void {
        $lib = dirname(__DIR__, 2) . '/lib';
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib)) as $file) {
            if ($file->getExtension() !== 'php') continue;
            foreach (self::countingReferences(file_get_contents($file->getPathname())) as $method) {
                $callers[$method][] = substr($file->getPathname(), strlen($lib) + 1);
            }
        }
        ksort($callers);
        foreach ($callers as &$files) {
            sort($files);
        }
        unset($files);
        // The two admin read models: the group statistics and the preview of a reset. Both count for an administrator and
        // never decide access (the preview class repeats the notice).
        $this->assertSame(['beginManyGrants' => ['Access/Grants/Groups/GroupDirectory.php', 'Access/Grants/OverridePreview.php'],
            'inherited' => ['Access/Grants/Groups/GroupDirectory.php', 'Access/Grants/OverridePreview.php']],
            $callers, 'Grant-only references and dynamic methods must never escape the admin read models');
        foreach (['Access/Policy/Resolution/PolicyResolver.php', 'Access/Policy/Resolution/PolicyOperationContext.php', 'Access/Grants/OverridePreview.php'] as $file) {
            $this->assertStringContainsString('admin counting only, not an access decision',
                file_get_contents($lib . '/' . $file));
        }
    }

}
