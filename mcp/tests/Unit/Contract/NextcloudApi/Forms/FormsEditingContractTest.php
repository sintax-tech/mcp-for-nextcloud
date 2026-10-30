<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use OCA\Mcp\Tools\Forms\Editing\EditSettings;
use PHPUnit\Framework\TestCase;

/** Binds the closed MCP edit domain and native PATCH effects to four hashed source fixtures, not runtime. */
final class FormsEditingContractTest extends TestCase {
	/** Checks ACL before lookup, non-atomic writes, B1 predicate, native lock duration and constructor reuse.
	 * @return void
	 */
	public function testFourPinnedNativeEditingContracts(): void {
		foreach (FormsSourcePins::all() as $pin => $sha) {
			$f = json_decode(file_get_contents(__DIR__.'/../../../../fixtures/nextcloud-api/forms/'.$pin.'.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($sha, $f['sha256']);
			foreach (['Controller/ApiController','Service/FormsService','Db/Question','Db/FormMapper'] as $name) {
				self::assertSame($f['sources'][$name]['sha256'], hash('sha256', $f['sources'][$name]['source']));
			}
			$methods=$f['classes']['OCA\\Forms\\Controller\\ApiController']['methods'];
			self::assertSame(['formId','questionId','keyValuePairs'],$methods['updateQuestion']);
			self::assertSame(['formId','questionId','optionId','keyValuePairs'],$methods['updateOption']);
			foreach (['updateQuestion'=>'questionMapper','updateOption'=>'optionMapper'] as $method=>$mapper) {
				$body=FormsBehavior::body($f['sources']['Controller/ApiController']['source'],$method);
				self::assertLessThan(strpos($body,'->findById('),strpos($body,'getFormIfAllowed($formId,Constants::PERMISSION_EDIT)'));
				self::assertStringContainsString('obtainFormLock($form)',$body);
				self::assertLessThan(strpos($body,'$this->formMapper->update($form)'),strpos($body,'$this->'.$mapper.'->update('));
				self::assertStringNotContainsString('delete',$body);
				self::assertStringNotContainsString('send(',$body);
				self::assertStringNotContainsString('canCreateForms',$body);
			}
			$body=FormsBehavior::body($f['sources']['Controller/ApiController']['source'],'updateQuestion');
			self::assertStringContainsString('$form->getConfirmationEmailQuestionId()===$question->getId()',$body);
			self::assertStringContainsString('$form->setConfirmationEmailQuestionId(null)',$body);
			self::assertStringContainsString("!\$question->isEmailType(\$keyValuePairs['type']??null,\$keyValuePairs['extraSettings']??null)",$body);
			self::assertStringContainsString('time()+15*60',FormsBehavior::body($f['sources']['Service/FormsService']['source'],'obtainFormLock'));
			self::assertStringContainsString('$value===false',FormsBehavior::body($f['sources']['Db/Question']['source'],'setExtraSettings'));
			$native=self::nativeTypes($f['sources']['Constants']['source']);
			$ours=EditSettings::TYPES;sort($ours);sort($native);self::assertSame($native,$ours,$pin);
		}
		$source=file_get_contents(__DIR__.'/../../../../../lib/Tools/Forms/Editing/EditNativeFactory.php');
		self::assertStringContainsString("new \\OCA\\Forms\\Service\\ConfigService('forms',",$source);
		self::assertStringContainsString('CreateNativeArguments::submission',$source);
		self::assertStringContainsString('new \\OCA\\Forms\\Controller\\ApiController(...$args)',$source);
		self::assertStringNotContainsString('->canCreateForms(',$source);
	}
	/** Resolves the pinned literal-string/self-reference declaration without executing source or guessing values.
	 * @param string $source SHA-checked official Constants source.
	 * @return list<string> Values actually referenced by ANSWER_TYPES.
	 */
	private static function nativeTypes(string $source): array {
		self::assertSame(1, preg_match('/public\s+const\s+ANSWER_TYPES\s*=\s*\[([^\]]*)\]\s*;/s', $source, $match));
		$members = array_values(array_filter(array_map('trim', explode(',', $match[1])), static fn (string $member): bool => $member !== ''));
		$values = [];
		foreach ($members as $member) {
			self::assertSame(1, preg_match('/^self::(ANSWER_TYPE_[A-Z_]+)$/', $member, $reference), 'Unreviewed native type expression');
			$pattern = "/public\\s+const\\s+" . preg_quote($reference[1], '/') . "\\s*=\\s*'([a-z_]+)'\\s*;/";
			self::assertSame(1, preg_match($pattern, $source, $literal), $reference[1] . ': no reviewed string literal');
			$values[] = $literal[1];
		}
		return $values;
	}
}
