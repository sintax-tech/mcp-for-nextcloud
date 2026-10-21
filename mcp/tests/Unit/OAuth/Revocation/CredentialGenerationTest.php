<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth\Revocation;

use OCA\Mcp\OAuth\Revocation\CredentialGeneration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialGenerationTest extends TestCase {
    public static function credentials(): array {
        $gens = ['issue_seq' => '5', 'revoked_below' => '3'];
        return [
            'live' => [['auth_gen' => '4', 'auth_global_epoch' => '2'], $gens, '2', true],
            'live as integers' => [['auth_gen' => 4, 'auth_global_epoch' => 2], $gens, '2', true],
            'at the cut' => [['auth_gen' => '3', 'auth_global_epoch' => '2'], $gens, '2', false],
            'old epoch' => [['auth_gen' => '4', 'auth_global_epoch' => '1'], $gens, '2', false],
            'legacy row of a fresh user' => [['auth_gen' => 1, 'auth_global_epoch' => 0], ['issue_seq' => '1', 'revoked_below' => '0'], '0', true],
            'missing generation' => [['auth_global_epoch' => '2'], $gens, '2', false],
            'non canonical' => [['auth_gen' => '04', 'auth_global_epoch' => '2'], $gens, '2', false],
        ];
    }

    #[DataProvider('credentials')]
    public function testACredentialIsValidOnlyAtTheCurrentEpochAndAboveTheCut(array $row, array $generations, string $epoch, bool $live): void {
        $this->assertSame($live, CredentialGeneration::live($row, $generations, $epoch));
    }
}
