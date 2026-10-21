<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Content;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use Sabre\VObject\Component\VTodo;
final class TaskCategories
{
    public const MAX_CATEGORIES = 50;
    public const MAX_CATEGORY_LENGTH = 255;
    public function categories(VTodo $task): array
    {
        $values = [];
        foreach ($task->select('CATEGORIES') as $property) {
            foreach ($property->getParts() as $part) {
                $values[] = (string) $part;
            }
        }
        return $this->normalizeCategories($values);
    }
    public function validCategories(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_CATEGORIES) {
            throw new InvalidArgumentException(Translator::t('Task categories must be a list of at most %s texts.', [self::MAX_CATEGORIES]));
        }
        foreach ($value as $category) {
            $length = is_string($category) ? mb_strlen(trim($category)) : 0;
            // preg_match() returns false for invalid UTF-8, which is refused like a control character.
            if ($length < 1 || $length > self::MAX_CATEGORY_LENGTH || preg_match('/\p{Cc}/u', $category) !== 0) {
                throw new InvalidArgumentException(
                    Translator::t(
                        'Each task category must have 1 to %s characters and no control characters.',
                        [self::MAX_CATEGORY_LENGTH]
                    )
                );
            }
        }
        return $this->normalizeCategories($value);
    }
    public function hasCategory(array $item, string $category): bool
    {
        $wanted = $this->foldCategory(trim($category));
        foreach ($item['categories'] ?? [] as $candidate) {
            if ($this->foldCategory($candidate) === $wanted) {
                return true;
            }
        }
        return false;
    }
    public function normalizeCategories(array $values): array
    {
        $seen = [];
        $categories = [];
        foreach ($values as $value) {
            $category = trim($value);
            $key = $this->foldCategory($category);
            if ($category === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $categories[] = $category;
        }
        return $categories;
    }
    public function foldCategory(string $category): string
    {
        // Full case folding, unlike lowercasing, makes "Σ", "σ" and the final "ς" one name.
        return mb_convert_case($category, MB_CASE_FOLD, 'UTF-8');
    }
}
