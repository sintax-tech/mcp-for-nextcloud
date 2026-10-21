<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Talk;

final class TalkNativeFixtures
{
    public function __construct(
        public ObjectProvider $manager,
        public ObjectProvider $chatManager,
        public ObjectProvider $participantService,
        public ObjectProvider $roomService,
        public ConstantsProvider $conversationConstants,
    ) {}

    public static function create(\Closure $mock): self
    {
        return new self(
            $mock(ObjectProvider::class),
            $mock(ObjectProvider::class),
            $mock(ObjectProvider::class),
            $mock(ObjectProvider::class),
            $mock(ConstantsProvider::class),
        );
    }
}
