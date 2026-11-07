<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Creation\MailDraftRequest;
use OCA\Mcp\Modules\Mail\Reading\{ListMessagesHandler, MailCursor, MailSearchCriteria, ReadMessageHandler};

/** Shared Mail definitions and repeated property shapes, preserving their published order. */
final class MailSchema {
	/** Static schema construction only. */
	private function __construct() {}

	/**
	 * @param string $name published tool name
	 * @param string $operation read or the separate write grant
	 * @param string $description published English description
	 * @param array<string, array<string, mixed>> $properties properties in published order
	 * @param list<string> $required required property names
	 * @param list<string>|null $grants alternate read grants, independent of mail.read
	 * @param list<string> $requires installation capability keys
	 * @param bool $requiredLast whether the legacy message schema placed required after additionalProperties
	 * @return ToolDefinition the static Mail definition without resolving native services
	 */
	public static function definition(string $name, string $operation, string $description, array $properties = [], array $required = [], ?array $grants = null, array $requires = [], bool $requiredLast = false): ToolDefinition {
		$schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
		if ($required !== [] && !$requiredLast) { $schema['required'] = $required; }
		$schema['additionalProperties'] = false;
		if ($required !== [] && $requiredLast) { $schema['required'] = $required; }
		return new ToolDefinition($name, 'mail', $operation, $description, $schema, app: 'mail', grantAnyOf: $grants, requires: $requires);
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published id property
	 */
	public static function id(string $description): array {
		return ['type' => 'integer', 'minimum' => 1, 'description' => $description];
	}

	/**
	 * @return array<string, mixed> the published messageLimit property
	 */
	public static function messageLimit(): array {
		return ['type' => 'integer', 'minimum' => 1, 'maximum' => ListMessagesHandler::MAX_LIMIT, 'default' => ListMessagesHandler::DEFAULT_LIMIT, 'description' => 'Messages per page (default 25, at most 100). A page also takes every message of the date it ends on, so it can be longer.'];
	}

	/**
	 * @return array<string, mixed> the published cursor property
	 */
	public static function cursor(): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => MailCursor::MAX_LENGTH, 'description' => 'nextCursor of the previous page, with the same folder and filters; omit for the first page.'];
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published addressFragments property
	 */
	public static function addressFragments(string $description): array {
		return ['type' => 'array', 'maxItems' => MailSearchCriteria::MAX_ADDRESSES, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT], 'description' => $description];
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published searchText property
	 */
	public static function searchText(string $description): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT, 'description' => $description];
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published date property
	 */
	public static function date(string $description): array {
		return ['type' => 'string', 'minLength' => 10, 'maxLength' => 25, 'description' => $description];
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published boolean property
	 */
	public static function boolean(string $description): array {
		return ['type' => 'boolean', 'description' => $description];
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published maxChars property
	 */
	public static function maxChars(string $description): array {
		return ['type' => 'integer', 'minimum' => 1, 'maximum' => MailBodyText::MAX_CHARS, 'default' => ReadMessageHandler::DEFAULT_MAX_CHARS, 'description' => $description];
	}

	/**
	 * @return array<string, mixed> the published planState property
	 */
	public static function planState(): array {
		return PlanState::property();
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published recipients property
	 */
	public static function recipients(string $description): array {
		return ['type' => 'array', 'maxItems' => MailDraftRequest::MAX_RECIPIENTS, 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 254], 'description' => $description];
	}

	/**
	 * @return array<string, mixed> the published subject property
	 */
	public static function subject(): array {
		return ['type' => 'string', 'maxLength' => MailDraftRequest::MAX_SUBJECT, 'description' => 'Subject, one line.'];
	}

	/**
	 * @param string $description exact published property description
	 * @return array<string, mixed> the published body property
	 */
	public static function body(string $description): array {
		return ['type' => 'string', 'maxLength' => MailDraftRequest::MAX_BODY, 'description' => $description];
	}
}
