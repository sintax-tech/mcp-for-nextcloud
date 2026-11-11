<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsBehavior;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsSourcePins;
use PHPUnit\Framework\TestCase;

/**
 * Binds the structure tools to the four active hashed Forms sources (5.3.0, 5.4.0, 5.4.1, 5.4.2). Static fixtures, not runtime:
 * the integration cells NC34/5.4.2 and NC35/5.4.1 cover the installed app. Older app sources remain shared signature proofs.
 */
final class StructureSourceContractTest extends TestCase {
	/** Signatures and the native behavior each structure operation relies on, in every pin. @return void */
	public function testFourPinnedStructureContracts(): void {
		foreach (FormsSourcePins::all() as $pin => $sha) {
			$f = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/nextcloud-api/forms/' . $pin . '.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($sha, $f['sha256'], $pin);
			foreach (['Controller/ApiController', 'Service/FormsService', 'Db/OptionMapper', 'Service/SubmissionService'] as $name) {
				self::assertSame($f['sources'][$name]['sha256'], hash('sha256', $f['sources'][$name]['source']), "$pin $name");
			}
			$methods = $f['classes']['OCA\\Forms\\Controller\\ApiController']['methods'];
			self::assertSame(['formId', 'type?', 'subtype?', 'text?', 'fromId?', 'position?'], $methods['newQuestion']);
			self::assertSame(['formId', 'newOrder'], $methods['reorderQuestions']);
			self::assertSame(['formId', 'questionId', 'optionTexts', 'optionType?'], $methods['newOption']);
			self::assertSame(['formId', 'questionId', 'newOrder', 'optionType?'], $methods['reorderOptions']);
			self::assertSame(['formId', 'questionId'], $methods['deleteQuestion']);
			self::assertSame(['formId', 'questionId', 'optionId'], $methods['deleteOption']);
			$api = $f['sources']['Controller/ApiController']['source'];
			foreach (['newQuestion', 'reorderQuestions', 'newOption', 'reorderOptions', 'deleteQuestion', 'deleteOption', 'updateQuestion'] as $method) {
				$body = FormsBehavior::body($api, $method);
				self::assertStringStartsWith('getFormIfAllowed($formId,Constants::PERMISSION_EDIT);$this->formsService->obtainFormLock($form);',
					substr($body, strpos($body, 'getFormIfAllowed(')), "$pin $method admits EDIT then locks first");
				self::assertStringContainsString('isFormArchived($form)', $body, "$pin $method");
			}
			$new = FormsBehavior::body($api, 'newQuestion');
			self::assertStringContainsString("\$type==='datetime'", $new);
			self::assertStringContainsString("\$subtype?['questionType'=>\$subtype]:[]", $new);
			self::assertStringContainsString('shiftQuestionsForInsert($questions,$position)', $new);
			$shift = FormsBehavior::body($api, 'shiftQuestionsForInsert');
			self::assertStringContainsString('if($q->getOrder()>=$position){$q->setOrder($q->getOrder()+1)', $shift);
			self::assertStringContainsString("\$this->formsService->areExtraSettingsValid(\$keyValuePairs['extraSettings'],\$question->getType())", FormsBehavior::body($api, 'updateQuestion'));
			$delete = FormsBehavior::body($api, 'deleteQuestion');
			self::assertStringContainsString('$question->setOrder(0)', $delete);
			self::assertStringContainsString('if($question->getType()===Constants::ANSWER_TYPE_FILE){$this->jobList->add(DeleteQuestionFoldersJob::class,', $delete);
			self::assertStringContainsString("'ownerId'=>\$form->getOwnerId()", $delete);
			$option = FormsBehavior::body($api, 'deleteOption');
			self::assertStringContainsString('$this->optionMapper->delete($option)', $option);
			self::assertStringContainsString('array_values($this->optionMapper->findByQuestion($questionId))', $option, 'renumbering ignores the partition');
			self::assertStringContainsString('$this->optionMapper->findByQuestion($questionId,$optionType)', FormsBehavior::body($api, 'newOption'));
			self::assertStringContainsString('catch(IMapperException', FormsBehavior::body($api, 'newOption'), 'a failed insert is skipped');
			self::assertStringContainsString('if($optionType){', FormsBehavior::body($f['sources']['Db/OptionMapper']['source'], 'findByQuestion'));
			self::assertMatchesRegularExpression("/getExtraSettings\(\)\['questionType'\]/", $f['sources']['Service/SubmissionService']['source'], 'grid reads its cell type without default');
			self::assertStringContainsString("'questionType'=>['string']", str_replace([' ', "\n", "\t"], '', $f['sources']['Constants']['source']));
		}
		$defaults = array_map(static fn (string $pin): string => self::newOptionDefault($pin), array_keys(FormsSourcePins::all()));
		self::assertSame(['null', "'choice'", "'choice'", "'choice'", "'choice'"], $defaults, 'option_type is always explicit because of this');
	}

	/**
	 * @param string $pin Forms version
	 * @return string default of newOption's optionType
	 */
	private static function newOptionDefault(string $pin): string {
		$f = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/nextcloud-api/forms/' . $pin . '.json'), true, 512, JSON_THROW_ON_ERROR);
		preg_match('/function newOption\([^)]*\?string \$optionType = ([^)]+)\)/', $f['sources']['Controller/ApiController']['source'], $match);
		return $match[1] ?? '';
	}
}
