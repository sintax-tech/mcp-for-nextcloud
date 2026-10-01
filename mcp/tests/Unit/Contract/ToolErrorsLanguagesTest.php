<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DraftApproval;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\GroupCreator;
use OCA\Mcp\Tools\Talk\ReferenceLinker;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A failure the server refuses to run is the most read text of the app: it is what the person sees when
 * something did not work. Deck, Notes and Talk each have one here, triggered the way the client would
 * trigger it, and it has to reach the result in the language of the account in all three languages.
 */
final class ToolErrorsLanguagesTest extends TestCase {
    /** `deck_read_card` against a card the Deck refuses: one message for "not found" and "no permission". */
    private const DECK = [
        'en' => 'Card, list or board not found, or no permission.',
        'pt_BR' => 'Card, lista ou quadro não encontrado ou sem permissão.',
        'es' => 'Tarjeta, lista o tablero no encontrado, o sin permiso.',
    ];

    /** `talk_read_messages` against a conversation the caller is not part of. */
    private const TALK = [
        'en' => 'conversation not found or not accessible',
        'pt_BR' => 'conversa não encontrada ou sem acesso',
        'es' => 'conversación no encontrada o sin acceso',
    ];

    /** `notes_delete` where the trash bin would not take the note back. */
    private const NOTES = [
        'en' => 'Deletion blocked: the trash bin (files_trashbin) is not active for this note, so it would not be recoverable.',
        'pt_BR' => 'Exclusão bloqueada: a lixeira (files_trashbin) não está ativa para esta nota, então ela não seria recuperável.',
        'es' => 'Eliminación bloqueada: la papelera (files_trashbin) no está activa para esta nota, por lo que no sería recuperable.',
    ];

    protected function tearDown(): void {
        Translator::reset();
    }

    public function testADeckFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::DECK as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $gateway = $this->createMock(DeckGatewayInterface::class);
            $gateway->method('findCard')->willThrowException(new DoesNotExistException('card 7 is gone'));
            $module = new DeckToolModule(
                $this->createMock(ContainerInterface::class),
                $this->appManager(),
                $this->createMock(LoggerInterface::class),
                $this->createMock(\OCP\IURLGenerator::class),
                $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
                $this->userManager(),
            );
            (new \ReflectionProperty(DeckToolModule::class, 'gateway'))->setValue($module, $gateway);

            $this->assertSame($expected, $this->call($module, 'deck_read_card', ['cardId' => 7]), $language);
        }
    }

    public function testATalkFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::TALK as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $this->assertSame($expected, $this->call($this->talkModule(), 'talk_read_messages', ['conversation_token' => 'abcd1234']), $language);
        }
    }

    public function testANotesFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::NOTES as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $this->assertSame($expected, $this->call($this->notesModule(), 'notes_delete', ['id' => 1, 'confirm' => true]), $language);
        }
    }

    /**
     * Runs one tool call through the registry, exactly as the protocol does, and returns the text the client
     * receives, so what is asserted here is the message that reaches the person and not the exception behind it.
     *
     * @return string text of the error result
     */
    private function call(ToolModule $module, string $name, array $arguments): string {
        $config = new InMemoryConfig();
        $policy = new GrantPolicy($config->mock($this));
        foreach (GrantPolicy::CATALOG as $grantedModule => $operations) {
            foreach ($operations as $operation) {
                $policy->setGrant('alice', $grantedModule, $operation, true);
            }
        }
        $registry = new ToolRegistry([$module], $policy, $this->appManager(), $this->userManager(), $this->createMock(LoggerInterface::class));
        $result = $registry->call($name, $arguments, 'alice');
        $this->assertArrayHasKey('isError', $result, $name . ' must be refused as an error result');
        return (string)$result['content'][0]['text'];
    }

    /** Every app is on but the trash bin, which is the condition the Notes refusal names. */
    private function appManager(): IAppManager {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(
            static fn (string $app, ?IUser $user = null): bool => $app !== 'files_trashbin',
        );
        return $apps;
    }

    private function userManager(): IUserManager {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        return $users;
    }

    private function notesModule(): NotesModule {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->createMock(Folder::class));
        $repository = new NotesRepository($root, $this->createMock(IConfig::class));
        return new NotesModule($repository, $this->appManager(), $this->userManager(), $this->createMock(SharedWriteGuard::class), $this->createMock(NodeAccessInfo::class));
    }

    /**
     * The Talk services blow up the way a missing or unreachable conversation does, so the refusal is built by
     * the resolver itself instead of being written into a mock.
     */
    private function talkModule(): TalkModule {
        $services = $this->createMock(TalkServices::class);
        $services->method('manager')->willThrowException(new \RuntimeException('spreed is unreachable'));
        $resolver = new ConversationResolver($services);
        $reader = new ConversationReader($services, $resolver, $this->createMock(ActorNames::class));
        return new TalkModule(
            $services,
            $reader,
            $resolver,
            $this->createMock(ConversationWriter::class),
            $this->createMock(FileSharer::class),
            $this->createMock(DraftApproval::class),
            $this->createMock(UserConversationResolver::class),
            $this->createMock(GroupCreator::class),
            $this->createMock(ReferenceLinker::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}
