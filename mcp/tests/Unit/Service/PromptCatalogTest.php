<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCA\Mcp\Service\PromptCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The prompts capability in both protocol eras: the legacy initialize advertises it and returns plain
 * results, the modern 2026-07-28 discovery advertises it and every list result carries the cache hints.
 */
final class PromptCatalogTest extends TestCase {
    private GrantPolicy $policy;
    private PromptCatalog $catalog;

    protected function setUp(): void {
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->catalog = new PromptCatalog();
    }

    /**
     * @param GrantPolicy $policy the grants the protocol reads
     * @return McpProtocol a protocol with no tool modules, so only the prompts are exercised
     */
    private function build(GrantPolicy $policy): McpProtocol {
        return new McpProtocol(new ToolRegistry([], $policy, $this->createMock(IAppManager::class),
            $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class)),
            new PromptCatalog(), $policy, $this->createMock(LoggerInterface::class));
    }

    /**
     * @param string $method JSON-RPC method
     * @param array<string, mixed> $params params for it
     * @param string $version protocol version to speak
     * @param GrantPolicy|null $policy grants to read, the fixture policy by default
     * @return object the decoded response body, as objects so resultType and capabilities read naturally
     */
    private function call(string $method, array $params, string $version, ?GrantPolicy $policy = null): object {
        if ($version === McpProtocol::MODERN_VERSION) {
            $params['_meta'] = [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION];
        }
        $out = $this->build($policy ?? $this->policy)
            ->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]), $version, 'alice');
        return json_decode((string)json_encode($out['body']), false, 512, JSON_THROW_ON_ERROR);
    }

    public function testThePromptIsListedWithItsTitleAndNoArguments(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $list = $this->catalog->list($this->policy, 'alice');
        $this->assertCount(1, $list);
        $this->assertSame(PromptCatalog::EDIT_LOCALLY, $list[0]['name']);
        $this->assertSame([], $list[0]['arguments']);
        $this->assertNotSame('', $list[0]['description']);
        $this->assertNotSame('', $list[0]['title']);
    }

    /** Without files.edit the workflow cannot run, so the prompt is not offered. */
    public function testThePromptFollowsTheFilesEditGrant(): void {
        $this->policy->setGrant('alice', 'files', 'edit', false);
        $this->assertSame([], $this->catalog->list($this->policy, 'alice'));
        $this->assertNull($this->catalog->get(PromptCatalog::EDIT_LOCALLY, $this->policy, 'alice'));
    }

    public function testAnUnknownNameResolvesToNothing(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $this->assertNull($this->catalog->get('outro_prompt', $this->policy, 'alice'));
    }

    /** The text has to teach the whole flow, including the fallback and the shared-write confirmation. */
    public function testThePromptTeachesTheCheckoutFlowAndItsFallback(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $text = $this->catalog->get(PromptCatalog::EDIT_LOCALLY, $this->policy, 'alice')['messages'][0]['content']['text'];
        $this->assertSame('user', $this->catalog->get(PromptCatalog::EDIT_LOCALLY, $this->policy, 'alice')['messages'][0]['role']);
        foreach (['files_checkout', 'curl -sS -o', 'curl -sS -T', 'files_replace', 'files_edit',
            'requiresConfirmation', 'confirm_shared', 'files_version_restore', 'single-use', 'run files_checkout',
            '-H "Content-Type: application/octet-stream"'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
        $this->assertStringNotContainsString('ncmcp_co_', $text);
    }

    public function testThePromptTitlesAndDescriptionsAreTranslated(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $listEn = $this->catalog->list($this->policy, 'alice');
        $this->assertSame('Edit a Nextcloud file locally', $listEn[0]['title']);
        $this->assertSame('Local editing workflow for a Nextcloud file.', $this->catalog->get(PromptCatalog::EDIT_LOCALLY, $this->policy, 'alice')['description']);

        \OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n('pt_BR'));
        $listPt = $this->catalog->list($this->policy, 'alice');
        $this->assertSame('Editar um arquivo do Nextcloud localmente', $listPt[0]['title']);
        $this->assertSame('Fluxo de edição local de um arquivo do Nextcloud.', $this->catalog->get(PromptCatalog::EDIT_LOCALLY, $this->policy, 'alice')['description']);

        \OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n('es'));
        $listEs = $this->catalog->list($this->policy, 'alice');
        $this->assertSame('Editar un archivo de Nextcloud localmente', $listEs[0]['title']);
        $this->assertSame('Flujo de edición local de un archivo de Nextcloud.', $this->catalog->get(PromptCatalog::EDIT_LOCALLY, $this->policy, 'alice')['description']);
        \OCA\Mcp\L10n\Translator::reset();
    }

    public function testLegacyInitializeAdvertisesThePromptsCapability(): void {
        $out = $this->build($this->policy)->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => McpProtocol::VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'c', 'version' => '1'],
        ]]), '', 'alice');
        $this->assertStringContainsString('"prompts":{}', (string)json_encode($out['body']));
    }

    public function testLegacyPromptsListAndGetCarryNoResultType(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $list = $this->call('prompts/list', [], McpProtocol::VERSION)->result;
        $this->assertSame([PromptCatalog::EDIT_LOCALLY], array_column($list->prompts, 'name'));
        $this->assertObjectNotHasProperty('resultType', $list);

        $get = $this->call('prompts/get', ['name' => PromptCatalog::EDIT_LOCALLY], McpProtocol::VERSION)->result;
        $this->assertObjectNotHasProperty('resultType', $get);
        $this->assertObjectNotHasProperty('_meta', $get);
        $this->assertSame('text', $get->messages[0]->content->type);
    }

    public function testModernDiscoveryAdvertisesPromptsAsAListChangedFalse(): void {
        $capabilities = $this->call('server/discover', [], McpProtocol::MODERN_VERSION)->result->capabilities;
        $this->assertFalse($capabilities->prompts->listChanged);
    }

    public function testModernPromptsListCarriesTheResultTypeAndCacheHints(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $result = $this->call('prompts/list', [], McpProtocol::MODERN_VERSION)->result;
        $this->assertSame('complete', $result->resultType);
        $this->assertSame([0, 'private'], [$result->ttlMs, $result->cacheScope]);
        $this->assertSame(McpProtocol::SERVER_INFO, (array)$result->_meta->{'io.modelcontextprotocol/serverInfo'});
        $this->assertSame([PromptCatalog::EDIT_LOCALLY], array_column($result->prompts, 'name'));
    }

    public function testModernPromptsGetCarriesTheResultTypeAndServerInfo(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $result = $this->call('prompts/get', ['name' => PromptCatalog::EDIT_LOCALLY], McpProtocol::MODERN_VERSION)->result;
        $this->assertSame('complete', $result->resultType);
        $this->assertSame('nextcloud-mcp', $result->_meta->{'io.modelcontextprotocol/serverInfo'}->name);
        $this->assertNotSame('', $result->messages[0]->content->text);
    }

    public function testAnUnknownPromptIsInvalidParamsInBothEras(): void {
        foreach ([McpProtocol::VERSION, McpProtocol::MODERN_VERSION] as $version) {
            $body = $this->call('prompts/get', ['name' => 'nao_existe'], $version);
            $this->assertSame(-32602, $body->error->code, $version);
        }
    }

    /** A prompt with arguments is not implemented, so passing any is refused rather than ignored. */
    public function testArgumentsAreRefusedBecauseNoPromptTakesAny(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $body = $this->call('prompts/get', ['name' => PromptCatalog::EDIT_LOCALLY, 'arguments' => ['path' => '/x']], McpProtocol::VERSION);
        $this->assertSame(-32602, $body->error->code);
    }

    public function testPromptsDisappearFromTheListWhenTheGrantIsRevoked(): void {
        $this->assertSame([], $this->call('prompts/list', [], McpProtocol::VERSION)->result->prompts);
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $this->assertCount(1, $this->call('prompts/list', [], McpProtocol::VERSION)->result->prompts);
    }
}
