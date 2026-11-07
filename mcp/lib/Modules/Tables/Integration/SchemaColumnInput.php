<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Writing\SchemaColumnChanges;
use OCA\Mcp\Modules\Tables\Writing\SchemaRequest;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCA\Mcp\Modules\Tables\Presentation\ColumnOptions;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;

/** Fuses preserved fields before native validation; never copies client technical fields. */
class SchemaColumnInput {
	/**
	 * @param SchemaServices $services Native validator.
	 * @param UsergroupWhitelist $users Typed identities.
	 */
	public function __construct(private SchemaServices $services, private UsergroupWhitelist $users) {}
	/**
	 * @param array<string,mixed> $changes Typed request.
	 * @param array<string,mixed>|null $current Native snapshot.
	 * @param string $uid Caller.
	 * @param string $profile Profile.
	 * @return array{dto:object,data:array<string,mixed>,rewrittenFields:list<string>} Validated DTO and final native values.
	 */
	public function of(array $changes, ?array $current, string $uid, string $profile): array {
		if ($current !== null && ($current['type'] ?? '') === 'relation') SchemaRequest::invalid('column_id');
		foreach ($changes['options'] ?? [] as $option) {
			if (($option['name'] ?? '') === 'selectionOptions') {
				if (!is_array($option['value'] ?? null) || !array_is_list($option['value'])) SchemaRequest::invalid('options');
				foreach ($option['value'] as $value) {
					if (!is_array($value) || array_diff(array_keys($value), ['id', 'label'])) SchemaRequest::invalid('options');
				}
			}
		}
		if ($current !== null && is_string($current['selectionDefault'] ?? null)) {
			$current['selectionDefault'] = json_decode($current['selectionDefault'], true, 32, JSON_THROW_ON_ERROR);
		}
		$data = SchemaColumnChanges::of($changes, $current, $profile === '1.0.10');
		if (($data['type'] ?? '') === 'usergroup') {
			$data['usergroupDefault'] = $this->users->entries($data, $data['usergroupDefault'] ?? [], $this->services->version(), $uid);
			foreach ($data['usergroupDefault'] as $entry) if ($entry['type'] === 2) {
				// Defaults skip the row business validator: repeat its actor-bound native team eligibility check.
				if (!SchemaNativeCalls::run(fn (): bool => $this->services->get('circles', $uid)->circleExists($entry['id'], $uid))) SchemaRequest::invalid('options');
			}
		}
		$fields = ['title', 'description', 'mandatory', 'customSettings'];
		foreach (ColumnOptions::TYPE_FIELDS as $type => $names) if ($type !== 'relation') $fields = [...$fields, ...$names];
		if ($current === null) $fields = [...$fields, 'type', 'subtype'];
		$native = array_intersect_key($data, array_flip($fields));
		foreach (['selectionOptions', 'selectionDefault', 'usergroupDefault', 'customSettings'] as $key) {
			if (array_key_exists($key, $native) && $native[$key] !== null && !is_string($native[$key])) $native[$key] = json_encode($native[$key], JSON_THROW_ON_ERROR);
		}
		foreach ($changes['options'] ?? [] as $option) if (($option['name'] ?? '') === 'selectionDefault' && $option['value'] === null) $native['selectionDefault'] = 'null';
		if ($current !== null && $profile === '2.3.2' && (isset($native['selectionDefault']) || isset($native['selectionOptions']))) {
			// Always send both halves so native normalization cannot drop preserved choices/defaults.
			$native['selectionOptions'] = json_encode($data['selectionOptions'] ?? [], JSON_THROW_ON_ERROR);
			$native['selectionDefault'] = json_encode($data['selectionDefault'] ?? null, JSON_THROW_ON_ERROR);
		}
		$class = 'OCA\\Tables\\Dto\\Column';
		try {
			$dto = $class::createFromArray($native);
			if (!in_array($profile, ['1.0.0', '2.0.0'], true)) $this->services->get('validator', $uid)->validate($dto, $current === null);
		} catch (\Throwable) { throw new ToolFailure(SchemaMessages::incompatible()); }
		return ['dto' => $dto, 'data' => $data, 'rewrittenFields' => array_keys($native)];
	}
}
