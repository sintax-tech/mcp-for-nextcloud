<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Tables\Integration\CreateColumnAction;
use OCA\Mcp\Modules\Tables\Integration\SchemaGateway;
use OCA\Mcp\Modules\Tables\Integration\SchemaNativeWriter;
use OCA\Mcp\Modules\Tables\Integration\SchemaObservation;
use OCA\Mcp\Modules\Tables\Integration\SchemaServices;
use OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Modules\Tables\Writing\SchemaFlow;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/** Exercises the actual create-column preparation wire before either native writer entry point. */
final class Tables240GuardWiringTest extends TestCase {
	/** @return iterable<string,array{bool,bool}> Both dangerous selections in preview and confirmation. */
	public static function cases(): iterable {
		foreach ([false, true] as $confirm) {
			foreach ([false, true] as $explicit) yield ($confirm ? 'confirm' : 'preview') . ($explicit ? '-missing-source' : '-all-columns') => [$confirm, $explicit];
		}
	}

	/**
	 * @param bool $confirm Whether the confirmed entry point is called.
	 * @param bool $explicit Whether an explicit selection omits the existing source.
	 * @return void Removing columnAdded from preparation must lose the specific refusal and fail this test.
	 */
	#[DataProvider('cases')]
	public function testCreateColumnRefusesBeforeAnyNativeWrite(bool $confirm, bool $explicit): void {
		Translator::reset();
		$scene = new SchemaPreparationScene($this);
		$scene->entities['view'][3]->data += ['viewSettings' => ['cardTitleSource' => 2]];
		$scene->entities['view'][3]->data['columnSettings'] = $explicit ? [['columnId' => 5]] : [];
		/** Instrumented native ColumnService boundary; only an actual create call increments the counter. */
		$nativeColumns = new class {
			/** @var int Native create calls. */
			public int $calls = 0;
			/**
			 * @param mixed ...$args Official ColumnService create arguments.
			 * @return SchemaNativeEntity Synthetic new column.
			 */
			public function create(mixed ...$args): SchemaNativeEntity { $this->calls++; return new SchemaNativeEntity(['id' => 10]); }
		};
		$services = $this->createMock(SchemaServices::class);
		$services->method('get')->with('columns', 'alice')->willReturn($nativeColumns);
		/** Real writer with an entry counter; its parent still delegates to the instrumented native service. */
		$writer = new class($services) extends SchemaNativeWriter {
			/** @var int Writer entry calls. */
			public int $calls = 0;
			/**
			 * @param array<string,mixed> $prepared Validated intent.
			 * @param string $uid Actor.
			 * @return int|null Native ID.
			 */
			public function createColumn(array $prepared, string $uid): ?int { $this->calls++; return parent::createColumn($prepared, $uid); }
		};
		$action = new CreateColumnAction($scene->preparation, $writer, $this->createMock(SchemaObservation::class));
		$config = (new InMemoryConfig())->mock($this);
		$grants = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$grants->setGrant('alice', 'tables', 'schema', true);
		$caps = $this->createMock(TablesWriteCapabilities::class);
		$caps->method('keys')->willReturn(['tables.native_schema_api', 'tables.native_sharing_api']);
		$caps->method('supports')->willReturn(true);
		$caps->method('profile')->willReturn('2.4.0');
		$flow = new SchemaFlow(new SchemaGateway(), new PlanState($config), $grants, $this->createMock(TablesSessionGuard::class),
			$caps, new CapabilityRegistry([$caps], $this->createMock(LoggerInterface::class)));
		try {
			$flow->handle($action, ['table_id' => 1, 'view_ids' => [3], 'title' => 'New', 'type' => 'text', 'subtype' => 'line',
				'confirm' => $confirm, 'confirm_shared' => true, 'plan_state' => 'untrusted-confirmation-state'], 'alice');
			self::fail('A card-source-erasing creation was not refused');
		} catch (ArgumentValidationException $error) {
			self::assertSame('view_ids', $error->details()['field']);
			self::assertStringContainsString('view_ids', $error->getMessage());
			self::assertStringContainsString('card title/background source', $error->clientMessage());
		} catch (Throwable $error) {
			self::fail('Expected the view_ids preservation refusal, got ' . $error::class);
		} finally {
			self::assertSame(0, $writer->calls, 'The native writer must not be reached');
			self::assertSame(0, $nativeColumns->calls, 'ColumnService::create must not be reached');
			Translator::reset();
		}
	}
}
