<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Talk;

use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Modules\Talk\TalkToolCatalog;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\TalkErrors;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Tools\ListConversations;
use OCA\Mcp\Modules\Talk\Tools\ReadMessages;
use Psr\Log\LoggerInterface;

final class TalkModuleFixtures
{
    /** Construct real tools and workflows, replacing only the explicitly supplied leaves. */
    public static function create(array $services, LoggerInterface $logger): TalkModule
    {
        $services[LoggerInterface::class] = $logger;
        $services[ResultBoundary::class] = new ResultBoundary(new TalkErrors(), $logger);
        $services[PreviewBoundary::class] = new PreviewBoundary(new TalkErrors(), $logger);
        $get = static function (string $class) use (&$get, &$services): object {
            if (isset($services[$class])) {
                return $services[$class];
            }
            if ($class === ListConversations::class) {
                return new ListConversations(
                    $get(ConversationReader::class)->listConversations(...),
                    $services[ResultBoundary::class],
                );
            }
            if ($class === ReadMessages::class) {
                return new ReadMessages(
                    $get(ConversationReader::class)->readMessages(...),
                    $services[ResultBoundary::class],
                );
            }
            $reflection = new \ReflectionClass($class);
            $arguments = [];
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $arguments[] = $get($parameter->getType()->getName());
            }
            return $reflection->newInstanceArgs($arguments);
        };
        $factories = [];
        foreach (TalkToolCatalog::classes() as $class) {
            $factories[$class::definition()->name] = static fn() => $get($class);
        }
        return new TalkModule($factories, $services[ResultBoundary::class], $services[PreviewBoundary::class]);
    }
}
