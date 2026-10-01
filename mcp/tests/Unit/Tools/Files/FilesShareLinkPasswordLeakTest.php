<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\ToolRegistry;
use Psr\Log\LoggerInterface;

/**
 * Invariant 2, end to end through the registry the server runs: a password the server generated for a link appears in
 * the result of the one execution that generated it, in text and in structuredContent, and nowhere else.
 */
final class FilesShareLinkPasswordLeakTest extends FilesToolsTestCase {
    private const PASSWORD = 'Unique-Gener4ted#Secret';
    private const FILE = '/Documentos/ata.md';
    /** @var list<array<int, mixed>> every call to every logger the tools and the registry hold */
    private array $logged = [];
    private ToolRegistry $registry;

    protected function setUp(): void {
        parent::setUp();
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
        $registryLogger = $this->createMock(LoggerInterface::class);
        foreach ([$this->shareLogger, $this->logger, $registryLogger] as $logger) {
            foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
                $logger->method($level)->willReturnCallback(function (mixed ...$args): void {
                    $this->logged[] = $args;
                });
            }
        }
        $this->registry = new ToolRegistry([$this->module], $this->sharePolicy, $this->apps, $this->users, $registryLogger);
    }

    /** @return string everything a call returned, text and structured content, as one searchable string */
    private static function all(array $result): string {
        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function assertNoPassword(string $where, string $haystack): void {
        self::assertStringNotContainsString(self::PASSWORD, $haystack, "a senha vazou em: $where");
    }

    public function testThePasswordIsInTheExecutionResultOnly(): void {
        $this->shares->settings['shareApiLinkEnforcePassword'] = true;
        $arguments = ['path' => self::FILE, 'with' => 'link', 'password' => true];

        $this->linkPasswords = [self::PASSWORD];
        $plan = $this->registry->call('files_share', $arguments, 'alice');
        self::assertTrue($plan['structuredContent']['requiresConfirmation'] ?? false);
        $this->assertNoPassword('plano (texto)', $plan['content'][0]['text']);
        $this->assertNoPassword('plano (structuredContent)', self::all($plan['structuredContent']));
        self::assertSame([self::PASSWORD], $this->linkPasswords, 'o plano não gera senha');

        $result = $this->registry->call('files_share', $arguments + ['confirm' => true], 'alice');
        self::assertArrayNotHasKey('isError', $result);
        self::assertStringContainsString(self::PASSWORD, $result['content'][0]['text']);
        self::assertSame(self::PASSWORD, $result['structuredContent']['password']);

        $list = $this->registry->call('files_list_shares', [], 'alice');
        $this->assertNoPassword('files_list_shares', self::all($list));
        $this->assertNoPassword('files_list_shares por caminho', self::all($this->registry->call('files_list_shares', ['path' => self::FILE], 'alice')));

        $again = ['path' => self::FILE, 'with' => 'link', 'expires' => '2026-10-10'];
        $secondPlan = $this->registry->call('files_share', $again, 'alice');
        $this->assertNoPassword('segundo plano', self::all($secondPlan));
        $second = $this->registry->call('files_share', $again + ['confirm' => true], 'alice');
        self::assertArrayNotHasKey('isError', $second);
        self::assertSame('update', $second['structuredContent']['action']);
        $this->assertNoPassword('segundo files_share', self::all($second));

        $this->assertNoPassword('log', self::all($this->logged));
        $this->assertNoPassword('armazenamento do compartilhamento', (string)$this->shares->shares[0]->getPassword());
    }

    /** A refusal of the core after the password was generated logs the class only and returns no password. */
    public function testACoreRefusalAfterGeneratingLeaksNothing(): void {
        $this->linkPasswords = [self::PASSWORD];
        $this->shares->failWrite = new \OCP\HintException('Password ' . self::PASSWORD . ' is too weak');
        $result = $this->registry->call('files_share', ['path' => self::FILE, 'with' => 'link', 'password' => true, 'confirm' => true], 'alice');
        self::assertTrue($result['isError'] ?? false);
        $this->assertNoPassword('erro', self::all($result));
        $this->assertNoPassword('log', self::all($this->logged));
        self::assertNotSame([], $this->logged, 'a recusa fica registrada, só com a classe');
    }
}
