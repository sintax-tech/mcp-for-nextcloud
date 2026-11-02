<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\People;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Observed answers of users_search at the move commit, before the handler, the schema and the catalog were split.
 * The same inputs on this tree have to reproduce people-characterization.json. The module is built by its
 * constructor shape, so the identical file runs on the move commit and on the split tree.
 */
final class PeopleCharacterizationTest extends TestCase {
	private const FIXTURE = __DIR__ . '/people-characterization.json';

	/** @var list<array<string, mixed>> what the search double and the logger saw during one call */
	private array $seen = [];

	public function testTheSplitPreservesTheCharacterizedAnswers(): void {
		$json = json_encode($this->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RE14_FREEZE') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}
		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}

	/** @return array<string, mixed> */
	private function report(): array {
		$many = ['users' => array_map(fn (int $i): array => $this->user('u' . $i, 'User ' . $i), range(1, 30))];
		$mixed = [
			'exact' => ['users' => [$this->user('vendas', 'Vendas')], 'groups' => [$this->group('vendas', 'Vendas')]],
			'users' => [$this->user('vera', 'Vera Lima', 'vera@example.invalid'), $this->user('vendas', 'Vendas')],
			'groups' => [$this->group('vendas-2', 'Vendas Regionais'), $this->group('vendas', 'Vendas')],
		];
		$cases = [];
		foreach ([
			'users_only' => [$mixed, ['query' => 'vendas']],
			'groups_asked' => [$mixed, ['query' => 'vendas', 'include_groups' => true]],
			'groups_false_explicit' => [$mixed, ['query' => 'vendas', 'include_groups' => false]],
			'limit_default' => [$many, ['query' => 'user']],
			'limit_one' => [$many, ['query' => 'user', 'limit' => 1]],
			'limit_max' => [$many, ['query' => 'user', 'limit' => 25]],
			'limit_zero' => [$many, ['query' => 'user', 'limit' => 0]],
			'limit_above_max' => [$many, ['query' => 'user', 'limit' => 26]],
			'limit_negative' => [$many, ['query' => 'user', 'limit' => -3]],
			'query_empty' => [$many, ['query' => '']],
			'query_blank' => [$many, ['query' => '    ']],
			'query_one_char' => [$many, ['query' => 'a']],
			'query_two_chars' => [$many, ['query' => 'ab']],
			'query_padded' => [$many, ['query' => '  ab  ']],
			'query_100' => [$many, ['query' => str_repeat('a', 100)]],
			'query_101' => [$many, ['query' => str_repeat('a', 101)]],
			'query_multibyte' => [$many, ['query' => 'çã']],
			'query_missing' => [$many, []],
			'emails' => [['users' => [
				$this->user('a1', 'A One', 'a1@example.invalid'),
				$this->user('a2', 'A Two', 'not an email'),
				$this->user('a3', 'A Three', 'no-at-sign'),
				$this->user('a4', 'A Four', ''),
				$this->user('a5', 'A Five', null),
				$this->user('a6', 'A Six', 'john doe@example.invalid'),
			]], ['query' => 'aa']],
			'dedupe_and_empty_id' => [['users' => [
				$this->user('x', 'X'), $this->user('x', 'X again'), $this->user('', 'Nobody'),
			], 'groups' => [$this->group('x', 'Group X'), $this->group('x', 'Group X again'), $this->group('', 'Empty')]],
				['query' => 'xx', 'include_groups' => true]],
			'label_falls_back_to_id' => [['users' => [['value' => ['shareWith' => 'nolabel']]],
				'groups' => [['value' => ['shareWith' => 'nogroup']]]], ['query' => 'no', 'include_groups' => true]],
			'non_array_match_skipped' => [['users' => ['junk', $this->user('ok', 'Ok')]], ['query' => 'ok']],
			'empty_result' => [[], ['query' => 'nobody']],
		] as $name => [$result, $arguments]) {
			$cases[$name] = $this->observe($result, $arguments);
		}
		$cases['core_failure'] = $this->observe(new \RuntimeException('secret-term-leak'), ['query' => 'secret-term-leak']);
		$cases['unknown_tool'] = $this->observe([], ['query' => 'ab'], 'users_other');
		$module = $this->module([], $this->seen);
		return [
			'cases' => $cases,
			'definitions' => array_map(static function (array $definition): array {
				// The registry rebuilds the published form, so only the order of the internal keys is free; the schema keeps its own.
				ksort($definition);
				return $definition;
			}, $module->definitions()),
			'guide_notes' => $module->guideNotes(),
		];
	}

	/**
	 * @param array<string, mixed>|\Throwable $result what the core search returns, or throws
	 * @param array<string, mixed> $arguments arguments of the call
	 * @return array<string, mixed> the answer or the refusal, the search call and the log records
	 */
	private function observe(array|\Throwable $result, array $arguments, string $tool = 'users_search'): array {
		$this->seen = [];
		$module = $this->module($result, $this->seen);
		$answer = [];
		try {
			$out = $module->call($tool, $arguments, 'alice');
			$answer = ['ok' => json_decode($out['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR), 'raw' => $out['content'][0]['text']];
		} catch (\Throwable $e) {
			$answer = ['refused' => $e instanceof \InvalidArgumentException ? 'invalid-argument' : 'other', 'message' => $e->getMessage()];
			if (!$e instanceof \InvalidArgumentException) {
				$answer['kind'] = (new \ReflectionClass($e))->getShortName();
			}
		}
		return ['answer' => $answer, 'search' => $this->seen['search'] ?? null, 'log' => $this->seen['log'] ?? []];
	}

	/** @param array<string, mixed>|\Throwable $result */
	private function module(array|\Throwable $result, array &$seen): object {
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willReturnCallback(function ($term, $types, $lookup, $limit, $offset) use ($result, &$seen) {
			$seen['search'] = [$term, $types, $lookup, $limit, $offset];
			if ($result instanceof \Throwable) {
				throw $result;
			}
			return [$result, false];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$seen): void {
			$seen['log'][] = [$message, $context];
		});
		$class = 'OCA\\Mcp\\Modules\\People\\PeopleModule';
		$first = (new \ReflectionClass($class))->getConstructor()?->getParameters()[0] ?? null;
		if ($first !== null && $first->getName() === 'search') {
			return new $class($search, $logger);
		}
		$handler = new ('OCA\\Mcp\\Modules\\People\\SearchPeopleHandler')($search, $logger);
		$tool = new ('OCA\\Mcp\\Modules\\People\\Tools\\SearchPeople')($handler);
		return new $class($this->container(['OCA\\Mcp\\Modules\\People\\Tools\\SearchPeople' => $tool]));
	}

	/** @param array<string, object> $services */
	private function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}

	/** @return array<string, mixed> */
	private function user(string $uid, string $name, ?string $email = null): array {
		return ['label' => $name, 'subline' => $email, 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $uid]];
	}

	/** @return array<string, mixed> */
	private function group(string $gid, string $name): array {
		return ['label' => $name, 'value' => ['shareType' => IShare::TYPE_GROUP, 'shareWith' => $gid]];
	}
}
