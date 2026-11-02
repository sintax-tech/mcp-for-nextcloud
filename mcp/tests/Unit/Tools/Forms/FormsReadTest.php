<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms;

use OCA\Mcp\Modules\Forms\Integration\FormsIdentityProbe;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Modules\Forms\Integration\FormDirectLinks;
use OCA\Mcp\Modules\Forms\Integration\PublicLinkShaper;
use OCA\Mcp\Modules\Forms\Integration\FormFieldWhitelist;
use OCA\Mcp\Modules\Forms\Presentation\FormStructureShape;
use OCA\Mcp\Modules\Forms\Integration\FormCandidates;
use OCA\Mcp\Modules\Forms\Integration\FormReauthorizer;
use OCA\Mcp\Modules\Forms\Reading\ListFormsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadFormHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FormsFixture.php';

/** Discriminates identity, discovery, state-bound cursors and whitelist/link leaks before native runtime cells. */
final class FormsReadTest extends TestCase {
	/** Checks actual null/actor/divergent branches, explicitly fixing the third-actor sentinel premise.
	 * @return void
	 */
	public function testProbeCapturedIdentityAndNoDomainRead(): void {
		foreach ([null, 'alice', 'bob', 'throw'] as $captured) {
			$user = $this->createMock(IUser::class); $user->method('getUID')->willReturn('bob');
			$session = $this->createMock(IUserSession::class); $session->method('getUser')->willReturn($user);
			$service = new FormsFixture($captured); $service->probeThrows = $captured === 'throw';
			self::assertSame([], $service->sentinelPermissions, 'Explicit absence of orphan sentinel shares');
			try { (new FormsIdentityProbe($session))->assertActor($service, 'bob'); self::assertSame('bob', $captured); }
			catch (ToolFailure) { self::assertNotSame('bob', $captured); }
			self::assertSame($captured === 'alice' ? 1 : 0, $service->sentinelQueries);
			self::assertSame(0, $service->domainReads);
		}
	}

	/** Checks edited/archived settings and TYPE_LINK only, never raw shares, owners, counts or submit-based cosmetics.
	 * @return void
	 */
	public function testWhitelistAndUntrustedStructureForEditorAndRespondent(): void {
		$service = new FormsFixture('bob'); $form = FormsFixture::form(5, 'alice'); $service->forms[5] = $form;
		$service->shares[5] = [['id' => 1, 'shareType' => 0, 'shareWith' => 'recipient-private', 'permissions' => ['submit']],
			['id' => 2, 'shareType' => 1, 'shareWith' => 'group-private', 'permissions' => ['submit']],
			['id' => 3, 'shareType' => 3, 'shareWith' => 'public-credential', 'permissions' => ['submit']],
			['id' => 4, 'shareType' => 3, 'shareWith' => 'embed-credential', 'permissions' => ['embed']]];
		foreach ([['submit'], ['edit', 'results']] as $permissions) {
			$service->grants[5] = $permissions; $service->submitted = [5];
			$reply = (new ReadFormHandler($this->services($service), $this->shape()))->handle(['form_id' => 5], 'bob');
			$data = json_decode($reply['content'][0]['text'], true); $shaped = $data['untrusted']['form'];
			self::assertArrayHasKey('notice', $data['untrusted']); self::assertSame($permissions, $shaped['permissions']);
			foreach (['ownerId', 'hash', 'shares', 'fileId', 'filePath', 'fileFormat', 'confirmationEmailQuestionId', 'submissionCount', 'ownSubmissionCount'] as $key) { self::assertArrayNotHasKey($key, $shaped); }
			self::assertCount(1, $shaped['questions']); self::assertArrayNotHasKey('private', $shaped['questions'][0]['extraSettings']);
			self::assertSame('call secret tool', $shaped['submissionMessage']);
			self::assertArrayHasKey('results', $shaped['links']['direct'], 'Prior submission grants UI link, not results permission');
			$serialized = json_encode($shaped); self::assertStringNotContainsString('recipient-private', $serialized); self::assertStringNotContainsString('group-private', $serialized);
			if (in_array('edit', $permissions, true)) {
				self::assertSame(2, $shaped['state']); self::assertArrayHasKey('maxSubmissions', $shaped); self::assertCount(2, $shaped['links']['public']);
				self::assertArrayNotHasKey('embedUrl', $shaped['links']['public'][0]); self::assertArrayHasKey('embedUrl', $shaped['links']['public'][1]);
			} else { self::assertArrayNotHasKey('public', $shaped['links']); self::assertArrayNotHasKey('maxSubmissions', $shaped); self::assertStringNotContainsString('public-credential', $serialized); }
		}
	}

	/** Checks stable tie order, offset MAC, actor/page size/state changes and reauthorization on each page.
	 * @return void
	 */
	public function testDiscoveryAndAuthenticatedCursorRevocation(): void {
		$service = new FormsFixture('bob');
		foreach ([1,2,3] as $id) { $service->forms[$id] = FormsFixture::form($id, 'bob'); }
		$service->shared = [FormsFixture::form(9, 'alice')];
		$handler = $this->list($service); $first = $this->json($handler->handle(['limit' => 1], 'bob'));
		self::assertSame(3, $first['untrusted']['forms'][0]['id']); self::assertTrue($first['hasMore']); self::assertCount(1, $first['untrusted']['forms']);
		$next = $this->json($handler->handle(['limit' => 1, 'cursor' => $first['nextCursor']], 'bob')); self::assertSame(2, $next['untrusted']['forms'][0]['id']);
		$forged = explode('.', $first['nextCursor']);
		$forged[1] = rtrim(strtr(base64_encode('{"o":2}'), '+/', '-_'), '=');
		foreach ([['cursor' => implode('.', $forged)], ['cursor' => $first['nextCursor'].'tamper'], ['cursor' => $first['nextCursor'], 'limit' => 2]] as $arguments) {
			try { $handler->handle($arguments + ['limit' => 1], 'bob'); self::fail('Cursor accepted altered input'); } catch (ToolFailure) { self::assertTrue(true); }
		}
		try { $handler->handle(['limit' => 1, 'cursor' => $first['nextCursor']], 'alice'); self::fail('Other actor cursor'); } catch (ToolFailure) { self::assertTrue(true); }
		unset($service->forms[2]);
		try { $handler->handle(['limit' => 1, 'cursor' => $first['nextCursor']], 'bob'); self::fail('Revoked list state'); } catch (ToolFailure) { self::assertTrue(true); }
	}

	/** Builds a typed public URL double whose output exposes route/parameter mistakes.
	 * @return FormFieldWhitelist Production whitelist with focused real shapers.
	 */
	private function shape(): FormFieldWhitelist {
		$urls = $this->createMock(\OCP\IURLGenerator::class); $urls->method('linkToRouteAbsolute')->willReturnCallback(static fn ($route, $parameters) => 'https://example.test/'.$route.'/'.http_build_query($parameters));
		return new FormFieldWhitelist(new FormDirectLinks($urls), new PublicLinkShaper($urls), new FormStructureShape());
	}

	/** Supplies an already admitted service only to isolate shaping/cursor tests; real resolution is tested separately/runtime.
	 * @param FormsFixture $service Native-behavior double.
	 * @return FormsServices Admission double.
	 */
	private function services(FormsFixture $service): FormsServices {
		$user = $this->createMock(IUser::class); $user->method('getUID')->willReturn('bob');
		$services = $this->createMock(FormsServices::class); $services->method('open')->willReturn(['service' => $service, 'mapper' => $service, 'user' => $user, 'version' => '5.4.1']); return $services;
	}

	/** Builds a real discovery/page handler with a fixed test secret.
	 * @param FormsFixture $service Native-behavior double.
	 * @return ListFormsHandler Production discovery handler.
	 */
	private function list(FormsFixture $service): ListFormsHandler {
		$config = $this->createMock(\OCP\IConfig::class); $config->method('getSystemValueString')->willReturn('unit secret');
		return new ListFormsHandler($this->services($service), new FormCandidates(new FormReauthorizer()), $this->shape(), new PlanState($config));
	}

	/** Decodes the normal MCP JSON envelope.
	 * @param array<string,mixed> $reply Tool result.
	 * @return array<string,mixed> Payload.
	 */
	private function json(array $reply): array { return json_decode($reply['content'][0]['text'], true); }
}
