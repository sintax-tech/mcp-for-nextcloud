<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use OCA\Mcp\Modules\Forms\Creation\CreateFormTypes;
use OCA\Mcp\Modules\Forms\FormsVersionProfiles;
use PHPUnit\Framework\TestCase;

/** Verifies explicit native constructor variants and sequential behavior in the active SHA-bound pins, not runtime. */
final class FormsCreationContractTest extends TestCase {
	/** Checks actor capture, app config, write signatures, 201 subsets and the version-specific Files branch.
	 * @return void
	 */
	public function testPinnedCreationGraphAndNativeStageContracts(): void {
		self::assertSame(['5.3.0', '5.4.0', '5.4.1', '5.4.2'], array_keys(FormsSourcePins::all()));
		foreach (array_keys(FormsSourcePins::all()) as $pin) { self::assertArrayHasKey($pin, FormsVersionProfiles::PROFILES); }
		foreach (FormsSourcePins::all() as $pin => $sha) {
			$f = json_decode(file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/forms/' . $pin . '.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($sha, $f['sha256']);
			foreach (FormsCreationApiUsage::SOURCES as $name) { self::assertSame($f['sources'][$name]['sha256'], hash('sha256', $f['sources'][$name]['source'])); }
			foreach (['Controller/ApiController', 'Service/ConfigService', 'Service/SubmissionService'] as $name) {
				self::assertStringContainsString('$this->currentUser=$userSession->getUser();', FormsBehavior::body($f['sources'][$name]['source'], '__construct'), $pin . ':' . $name);
			}
			$controller = $f['classes']['OCA\\Forms\\Controller\\ApiController']['methods'];
			self::assertContains($pin === '5.3.0' ? 'shareMapper' : 'secureRandom', $controller['__construct']);
			self::assertNotContains($pin === '5.3.0' ? 'secureRandom' : 'shareMapper', $controller['__construct']);
			self::assertSame(['appName', 'config', 'appConfig', 'groupManager', 'userSession'], $f['classes']['OCA\\Forms\\Service\\ConfigService']['methods']['__construct']);
			$submission = $f['classes']['OCA\\Forms\\Service\\SubmissionService']['methods']['__construct'];
			self::assertContains($pin === '5.3.0' ? 'mailer' : 'emailValidator', $submission);
			self::assertSame(in_array($pin, ['5.4.0', '5.4.1', '5.4.2'], true), in_array('userConfig', $submission, true));
			self::assertSame(['fromId?'], $controller['newForm']);
			self::assertSame(['formId', 'keyValuePairs'], $controller['updateForm']);
			self::assertSame(['formId', 'type?', 'subtype?', 'text?', 'fromId?', 'position?'], $controller['newQuestion']);
			self::assertSame(['formId', 'questionId', 'keyValuePairs'], $controller['updateQuestion']);
			self::assertSame(['formId', 'questionId', 'optionTexts', 'optionType?'], $controller['newOption']);
			$source = $f['sources']['Controller/ApiController']['source'];
			$new = FormsBehavior::body($source, 'newForm');
			self::assertLessThan(strpos($new, '$this->formMapper->insert($form)'), strpos($new, '$this->configService->canCreateForms()'));
			self::assertStringContainsString('$form->setOwnerId($this->currentUser->getUID())', $new);
			self::assertStringContainsString("if(\$type==='datetime')", FormsBehavior::body($source, 'newQuestion'));
			$options = FormsBehavior::body($source, 'newOption');
			self::assertStringContainsString('catch(IMapperException$e)', $options);
			self::assertStringContainsString('newDataResponse($addedOptions,Http::STATUS_CREATED)', $options);
			self::assertSame(in_array($pin, ['5.4.1', '5.4.2'], true), str_contains(FormsBehavior::body($source, 'updateForm'), 'syncFormUploadFolderName'));
			self::assertStringContainsString('getUnformattedCreationAllowedGroups()', FormsBehavior::body($f['sources']['Service/ConfigService']['source'], 'canCreateForms'));
			self::assertNotContains('datetime', CreateFormTypes::SUPPORTED);
		}
	}
	/** Keeps the chosen public-constructor mechanism explicit; native runtime cells independently test its captures.
	 * @return void
	 */
	public function testProductFactoryUsesFreshActorCapturingCollaborators(): void {
		$source = file_get_contents(__DIR__ . '/../../../../../lib/Modules/Forms/Integration/FormsCreateNativeFactory.php');
		self::assertStringContainsString("new \\OCA\\Forms\\Service\\ConfigService('forms',", $source);
		self::assertStringContainsString('new \\OCA\\Forms\\Service\\SubmissionService(...$native)', $source);
		self::assertStringContainsString('new \\OCA\\Forms\\Controller\\ApiController(...$args)', $source);
		self::assertStringContainsString("$" . "read = $" . "this->forms->open($" . "uid)", $source);
		self::assertStringContainsString('$this->policy->assertAllowed($uid)', $source);
	}
}
