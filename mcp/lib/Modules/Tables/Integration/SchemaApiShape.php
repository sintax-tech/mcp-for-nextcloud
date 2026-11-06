<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Extracted signatures of the five official source pins; no native objects are built. */
final class SchemaApiShape {
	/** @var array<string,list<array>>|null Decoded signatures, once in this PHP request. */
	private static ?array $signatures = null;
	/**
	 * @param string $profile Version family.
	 * @return list<array> Expected native members and their signatures.
	 */
	public static function members(string $profile): array {
		$data = self::$signatures ??= json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/appinfo/tables-schema-api-signatures.json'), true, 64, JSON_THROW_ON_ERROR);
		if (!isset($data[$profile]) || $data[$profile] === []) return [];
		$members = $data[$profile];
		foreach (['OCA\\Tables\\Db\\Table' => ['ownership', 'title', 'description', 'emoji', 'archived'],
			'OCA\\Tables\\Db\\View' => ['tableId', 'title', 'description', 'emoji'],
			'OCA\\Tables\\Db\\Column' => ['tableId', 'type', 'subtype', 'mandatory', 'description'],
			'OCA\\Tables\\Db\\Share' => ['nodeId', 'nodeType', 'receiver', 'receiverType', 'permissionRead', 'permissionCreate', 'permissionUpdate', 'permissionDelete', 'permissionManage']] as $class => $fields) {
			foreach ($fields as $field) $members[] = [$class, '@' . $field, [], ''];
		}
		return $members;
	}
	/**
	 * @param array $entry Native class/method and expected arguments.
	 * @return bool Exact consumed shape.
	 */
	public static function matches(array $entry): bool {
		[$class, $method, $expected, $return] = $entry;
		if (!class_exists($class)) return false;
		if (str_starts_with($method, '@')) return property_exists($class, substr($method, 1));
		if (!method_exists($class, $method)) return false;
		$reflection = new \ReflectionMethod($class, $method);
		if (!$reflection->isPublic() || count($reflection->getParameters()) !== count($expected)) return false;
		foreach ($reflection->getParameters() as $i => $parameter) {
			$type = self::type($parameter->getType(), $class);
			if ($parameter->getName() !== $expected[$i]['name'] || $type !== $expected[$i]['type'] || $parameter->isOptional() !== $expected[$i]['optional']) return false;
		}
		return self::type($reflection->getReturnType(), $class) === $return;
	}
	/**
	 * @param \ReflectionType|null $type Declared type.
	 * @param string $class Declaring native class.
	 * @return string Fully qualified type, resolving native aliases without instantiation.
	 */
	private static function type(?\ReflectionType $type, string $class): string {
		if ($type === null) return '';
		if (!$type instanceof \ReflectionNamedType) return (string)$type;
		$name = $type->getName();
		if (in_array($name, ['self', 'static'], true)) $name = $class;
		return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
	}
}
