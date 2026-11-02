<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Polls\Creation\CreatePlan;
use OCA\Mcp\Modules\Polls\Creation\CreateRequest;

/** One closed creation schema, with runtime validation of conditional item shapes. */
final class CreateDefinition {
	/**
	 * Declares the tool; item-specific alternatives are validated by CreateRequest even on direct calls.
	 * @return array<string,mixed> registry definition
	 */
	public static function get(): array {
		return ['name'=>CreatePlan::TOOL,'description'=>CreateMessages::TOOL,'module'=>'polls','operation'=>'create','app'=>'polls',
			'inputSchema'=>['type'=>'object','required'=>['type','title'],'additionalProperties'=>false,'properties'=>[
				'type'=>['type'=>'string','enum'=>['textPoll','datePoll']],
				'title'=>['type'=>'string','minLength'=>1,'maxLength'=>128],
				'options'=>['type'=>'array','maxItems'=>100,'default'=>[],'description'=>'0 to 100 initial options. textPoll items: only text (1..1024 characters). datePoll items: timestamp (positive UTC seconds) and optional duration (0..2678400 seconds). Items and their fields are strictly validated.',
					'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
						'text'=>['type'=>'string','minLength'=>1,'maxLength'=>1024],
						'timestamp'=>['type'=>'integer','minimum'=>1,'maximum'=>253402214400],
						'duration'=>['type'=>'integer','minimum'=>0,'maximum'=>2678400]]]],
				PlanState::ARGUMENT=>PlanState::property()]]];
	}
}
