<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Presentation;

use OCA\Mcp\Modules\Tasks\Content\TaskDocument;
use OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter;
use Sabre\VObject\Component\VCalendar;

/** Maps only the public copy of a task, leaving the native document intact for writes. */
final class TaskOutput {
    public function __construct(private TaskDocument $data, private OutputReferenceFilter $references) {}

    public function item(string $userId, VCalendar $calendar): array {
        return $this->data->item($this->references->outputCalendar($userId, $calendar));
    }
}
