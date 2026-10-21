<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;
final class DeckBoundaryTest extends TestCase
{
    private const SHARED = [
        'OCA\Mcp\Access\Policy\PolicyUnavailable',
        'OCA\Mcp\Mcp\Tools\ToolModule',
        'OCA\Mcp\Mcp\Confirmation\PreviewsWrites',
        'OCA\Mcp\Mcp\Confirmation\RendersPlans',
        'OCA\Mcp\Mcp\Guide\ToolGuideNotes',
        'OCA\Mcp\Mcp\Tools\Results\ToolFailure',
        'OCA\Mcp\Mcp\Confirmation\PlanText',
        'OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException',
        'OCA\Mcp\L10n\Translator',
        'OCA\Mcp\Platform\Time\UserTimezone',
        'OCA\Mcp\Access\AppAvailability\AppEnablement',
    ];
    private static function violations(string $source, bool $contract = false): array
    {
        $bad = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($token->text, '\\');
            if (in_array(
                $name,
                [
                    'Psr\Container\ContainerInterface',
                    'OCP\IServerContainer',
                    'OCP\AppFramework\IAppContainer',
                    'OCP\Server',
                    'OC\Server',
                ],
                true,
            )) {
                $bad[] = $name;
                continue;
            }
            if (!str_starts_with($name, 'OCA\\')) {
                continue;
            }
            if ($contract && ($name === 'OCA\Mcp\Modules\Deck\Contracts' || str_starts_with($name, 'OCA\Mcp\Modules\Deck\Contracts\\'))) {
                continue;
            }
            if (!$contract && ($name === 'OCA\Mcp\Modules\Deck' || str_starts_with($name, 'OCA\Mcp\Modules\Deck\\') || str_starts_with($name, 'OCA\Mcp\Mcp\Tools\\') || str_starts_with($name, 'OCA\Deck\\') || in_array($name, self::SHARED, true))) {
                continue;
            }
            $bad[] = $name;
        }
        return array_values(array_unique($bad));
    }
    public function testCapabilitiesHaveExplicitDependenciesAndContractsAreAppIndependent(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib/Modules/Deck')) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            self::assertSame(
                [],
                self::violations(file_get_contents($f->getPathname()), str_contains($f->getPathname(), '/Contracts/')),
                $f->getPathname(),
            );
        }
    }
    public function testRejectsCrossModuleReferencesAndServiceLocators(): void
    {
        foreach ([
            'OCA\Mcp\Controller\McpController',
            'OCA\Mcp\Modules\Files\FilesModule',
            'Psr\Container\ContainerInterface',
            'OCP\Server',
        ] as $name) {
            self::assertSame([$name], self::violations('<?php use ' . $name . ';'));
            self::assertSame([$name], self::violations('<?php new \\' . $name . '();'));
        }
        self::assertSame(['OCA\Deck\Db\Card'], self::violations('<?php use OCA\Deck\Db\Card;', true));
    }
    private static function retired(string $source): array
    {
        $source = str_replace('\\\\', '\\', $source);
        $names = [
            'OCA\Mcp\Tools' . '\Deck',
            'OCA\Mcp\Tests\Unit\Tools' . '\Deck',
            'lib/Tools' . '/Deck',
            'tests/Unit/Tools' . '/Deck',
        ];
        return array_values(array_filter($names, static fn($name) => str_contains($source, $name)));
    }
    public function testRetiredReferenceAuditCoversServiceIdsAndAutoloadPaths(): void
    {
        $name = 'OCA\Mcp\Tools' . '\Deck\OldOwner';
        self::assertNotEmpty(self::retired('<?php use ' . $name . ';'));
        self::assertNotEmpty(self::retired('$container->get(' . var_export($name, true) . ');'));
        self::assertNotEmpty(self::retired(json_encode(['class' => $name], JSON_THROW_ON_ERROR)));
        self::assertNotEmpty(self::retired('lib/Tools' . '/Deck/OldOwner.php'));
    }
    public function testNoRetiredExecutableOrSupportReferencesRemain(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertDirectoryDoesNotExist($root . '/mcp/lib/Tools' . '/Deck');
        self::assertDirectoryDoesNotExist($root . '/mcp/tests/Unit/Tools' . '/Deck');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $relative = substr($f->getPathname(), strlen($root) + 1);
            if (!preg_match(
                '~^(?:mcp/(?:lib|tests|appinfo|scripts)/|scripts/|\.github/|mcp/(?:composer\.(?:json|lock)|phpunit\.xml)$)~',
                $relative,
            ) || !in_array($f->getExtension(), ['php', 'json', 'xml', 'sh', 'py', 'yml', 'yaml', 'lock'], true)) {
                continue;
            }
            self::assertSame([], self::retired(file_get_contents($f->getPathname())), $relative);
        }
    }
    public function testTalkImportsOnlyTheCardReferenceContract(): void
    {
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib/Modules/Talk')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }
        foreach (\PhpToken::tokenize($source) as $t) {
            if (in_array($t->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_starts_with(ltrim($t->text, '\\'), 'OCA\Mcp\Modules\Deck\\')) {
                self::assertSame('OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader', ltrim($t->text, '\\'));
            }
        }
    }
}
