<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Mcp\Guide\ToolGuide;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class DavMigrationCompatibilityTest extends TestCase
{
    protected function tearDown(): void { \OCA\Mcp\L10n\Translator::reset(); }

    public function testDefinitionsPublicationAndGuidesPreserveTheBaseline(): void
    {
        \OCA\Mcp\L10n\Translator::reset();
        $calendar = (new \ReflectionClass(\OCA\Mcp\Modules\Calendar\CalendarModule::class))->newInstanceWithoutConstructor();
        $modules = [$calendar];
        foreach ([\OCA\Mcp\Modules\Contacts\ContactsModule::class, \OCA\Mcp\Modules\Tasks\TasksModule::class] as $class) {
            $modules[] = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        }
        $actual = [];
        foreach ($modules as $module) {
            $raw = $module->definitions();
            $published = array_map(fn($d) => \OCA\Mcp\Mcp\Tools\Discovery\ToolPublication::present($d), $raw);
            $actual[] = ['raw' => $raw, 'published' => $published,
                'guide' => (new ToolGuide([$module]))->result($published, ['module' => $raw[0]['module']])];
        }
        $expected = file_get_contents(__DIR__ . '/../../Support/Dav/Compatibility/definitions.json');
        self::assertSame($expected, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function testAllTwelvePlansRenderersAndRepresentativeEnvelopesMatchThePreMigrationCommit(): void {
        $modules = $this->fixtures();
        \OCA\Mcp\L10n\Translator::reset();
        $expected = json_decode(file_get_contents(__DIR__.'/../../Support/Dav/Compatibility/plans.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($expected['plans'] as $name=>$case) {
            $module = $modules[explode('_', $name)[0]];
            $plan = $module->preview($name, $case['arguments'], 'alice');
            if ($name === 'contacts_create_contact') {
                $plan = json_decode(str_replace($plan['after']['uid'], '<generated-contact-uid>', json_encode($plan, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame($case['plan'], $plan, $name);
            self::assertSame($case['rendered'], $module->renderPlan($name, $plan), $name);
        }
        $actual = [
            'calendar_list_calendars'=>$modules['calendar']->call('calendar_list_calendars', [], 'alice'),
            'calendar_not_found'=>$modules['calendar']->call('calendar_delete_event', ['calendar'=>'/missing/', 'uid'=>'missing'], 'alice'),
            'contacts_read_contact'=>$modules['contacts']->call('contacts_read_contact', ['addressbook'=>'/remote.php/dav/addressbooks/users/alice/personal/', 'uri'=>'c1.vcf'], 'alice'),
            'tasks_read_task'=>$modules['tasks']->call('tasks_read_task', ['calendar'=>'/remote.php/dav/calendars/alice/tasks/', 'uid'=>'t1'], 'alice'),
        ];
        self::assertSame($expected['results'], $actual);
        \OCA\Mcp\L10n\Translator::reset();
    }

    public static function confirmedRoutes(): array {
        return [
            ['calendar', 'calendar_update_event', ['calendar'=>'/remote.php/dav/calendars/alice/personal/', 'uid'=>'event', 'summary'=>'Approved'], 'summary', 'Approved'],
            ['contacts', 'contacts_edit_contact', ['addressbook'=>'/remote.php/dav/addressbooks/users/alice/personal/', 'uri'=>'c1.vcf', 'name'=>'Approved'], 'name', 'Approved'],
            ['tasks', 'tasks_complete_task', ['calendar'=>'/remote.php/dav/calendars/alice/tasks/', 'uid'=>'t1'], 'status', 'COMPLETED'],
        ];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('confirmedRoutes')]
    public function testProductionModuleRoutingPassesThroughRegistryApprovalBeforeMutation(string $feature, string $name, array $arguments, string $field, string $value): void {
        $modules = $this->fixtures();
        $config = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG as $module=>$operations) {
            foreach ($operations as $operation) { $policy->setGrant('alice', $module, $operation, true); }
        }
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, $users);
        $registry = new ToolRegistry(array_values($modules), $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, new \Psr\Log\NullLogger()));
        self::assertCount(20, $registry->definitions('alice'));
        $preview = $registry->call($name, $arguments, 'alice');
        self::assertArrayNotHasKey('isError', $preview);
        self::assertTrue($preview['structuredContent']['requiresConfirmation']);
        self::assertSame(0, $this->fixtureObjects[$feature]->writes());
        $result = $registry->call($name, $arguments + ['confirm'=>true], 'alice');
        self::assertArrayNotHasKey('isError', $result);
        $body = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($value, $body[$field]);
        self::assertSame(1, $this->fixtureObjects[$feature]->writes());
    }

    /** Final feature fixtures; module routing is built by Application's production factories. */
    private array $fixtureObjects = [];

    private function fixtures(): array {
        $calendar = new class('fixture') extends \OCA\Mcp\Tests\Support\Calendar\CalendarTestCase {
            public function fixture(): \OCA\Mcp\Mcp\Tools\ToolModule {
                $this->setUp();
                $this->store->addObject(1, 'event.ics', self::ics("UID:event\nSUMMARY:Before\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z"));
                return $this->module;
            }
            public function writes(): int { return count($this->dav->calls); }
        };
        $contacts = new class('fixture') extends \OCA\Mcp\Tests\Support\Contacts\ContactsTestCase {
            private int $writes = 0;
            public function writes(): int { return $this->writes; }
            public function fixture(): \OCA\Mcp\Mcp\Tools\ToolModule {
                $this->setUp();
                $this->dav->method('update')->willReturnCallback(function ($user, $book, $uri, $etag, $data) {
                    $this->writes++;
                    $this->card = ['uri'=>$uri, 'etag'=>'"v2"', 'data'=>$data];
                    return new \OCA\Mcp\Platform\Dav\Transport\DavResult(204, '"v2"');
                });
                return $this->module;
            }
        };
        $tasks = new class('fixture') extends \OCA\Mcp\Tests\Support\Tasks\TasksTestCase {
            private int $writes = 0;
            public function writes(): int { return $this->writes; }
            public function fixture(): \OCA\Mcp\Mcp\Tools\ToolModule {
                $this->setUp();
                $this->dav->method('update')->willReturnCallback(function ($user, $book, $uri, $etag, $data) {
                    $this->writes++;
                    $this->data = $data;
                    return new \OCA\Mcp\Platform\Dav\Transport\DavResult(204, '"v2"');
                });
                return $this->module;
            }
        };
        $this->fixtureObjects = ['calendar'=>$calendar, 'contacts'=>$contacts, 'tasks'=>$tasks];
        $factories = [];
        $context = $this->createMock(\OCP\AppFramework\Bootstrap\IRegistrationContext::class);
        $context->method('registerService')->willReturnCallback(static function (string $id, callable $factory) use (&$factories): void { $factories[$id] = $factory; });
        (new \ReflectionClass(\OCA\Mcp\AppInfo\Application::class))->newInstanceWithoutConstructor()->register($context);
        $modules = [];
        foreach (['calendar'=>$calendar, 'contacts'=>$contacts, 'tasks'=>$tasks] as $feature=>$fixture) {
            $module = $fixture->fixture();
            $tools = (new \ReflectionProperty($module, 'factories'))->getValue($module);
            $container = $this->createMock(\Psr\Container\ContainerInterface::class);
            $references = \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this);
            $container->method('get')->willReturnCallback(static function (string $id) use ($tools, $references): object {
                if ($id === \OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class) { return $references; }
                if (!is_a($id, \OCA\Mcp\Mcp\Tools\Tool::class, true)) { throw new \LogicException('Unexpected dependency '.$id); }
                $tool = $tools[$id::definition()->name]();
                self::assertInstanceOf($id, $tool);
                return $tool;
            });
            $modules[$feature] = $factories[$module::class]($container);
        }
        return $modules;
    }
}
