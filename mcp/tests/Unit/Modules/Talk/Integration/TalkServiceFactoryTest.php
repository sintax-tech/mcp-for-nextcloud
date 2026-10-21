<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Talk\Integration;

use OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory;
use OCA\Mcp\Modules\Talk\Integration\TalkAvailability;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use PHPUnit\Framework\TestCase;

final class TalkServiceFactoryTest extends TestCase
{
    public function testProviderIsLazyAndRechecksTheUserBeforeEveryResolution(): void
    {
        self::assertTrue(class_exists(TalkServiceFactory::class), 'A fixed lazy native provider must exist');
        $availability = $this->createMock(TalkAvailability::class);
        $availability
            ->expects($this->exactly(2))
            ->method('isEnabledFor')
            ->willReturnCallback(fn(string $user): bool => $user === 'alice');
        $calls = 0;
        $native = new \stdClass();
        $factory = new TalkServiceFactory(
            \stdClass::class,
            function () use (&$calls, $native) {
                ++$calls;
                return $native;
            },
            $availability,
        );
        self::assertSame(0, $calls);
        self::assertSame($native, $factory->forUser('alice'));
        try {
            $factory->forUser('bob');
            self::fail('Disabled user must be refused');
        } catch (TalkUnavailableException) {
            self::assertSame(1, $calls);
        }
    }

    public function testEnablementChangesAreRecheckedWithoutCachingNativeServices(): void
    {
        $availability = $this->createMock(TalkAvailability::class);
        $availability
            ->expects($this->exactly(2))
            ->method('isEnabledFor')
            ->with('alice')
            ->willReturnOnConsecutiveCalls(true, false);
        $calls = 0;
        $factory = new TalkServiceFactory(
            \stdClass::class,
            function () use (&$calls) {
                ++$calls;
                return new \stdClass();
            },
            $availability,
        );
        self::assertInstanceOf(\stdClass::class, $factory->forUser('alice'));
        try {
            $factory->forUser('alice');
            self::fail('Disabled app was cached');
        } catch (TalkUnavailableException) {
            self::assertSame(1, $calls);
        }
    }

    public function testUnexpectedResolutionErrorsPreserveTheirIdentity(): void
    {
        $availability = $this->createMock(TalkAvailability::class);
        $availability->method('isEnabledFor')->willReturn(true);
        $cause = new \RuntimeException('container failure');
        $factory = new TalkServiceFactory(\stdClass::class, static fn() => throw $cause, $availability);
        try {
            $factory->forUser('alice');
            self::fail('Expected resolution failure');
        } catch (\RuntimeException $error) {
            self::assertSame($cause, $error);
        }
    }
}
