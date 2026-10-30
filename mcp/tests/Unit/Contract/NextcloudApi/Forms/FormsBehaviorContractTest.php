<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use PHPUnit\Framework\TestCase;

/** Four SHA-checked source fixtures discriminate signatures, captured identity, discovery, field keys and native routes. */
final class FormsBehaviorContractTest extends TestCase {
	/** Checks each approved archive's signatures and the source-dependent branches without claiming runtime proof.
	 * @return void
	 */
	public function testFourPinsAndIdentityDiscoveryRoutesAndFieldContracts(): void {
		foreach (FormsSourcePins::all() as $version => $sha) {
			$fixture = json_decode(file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/forms/' . $version . '.json'), true);
			self::assertSame($sha, $fixture['sha256']);
			foreach ($fixture['sources'] as $file) { self::assertSame($file['sha256'], hash('sha256', $file['source'])); }
			$source = $fixture['sources']['Service/FormsService']['source'];
			self::assertTrue(FormsBehavior::identity($source), $version);
			self::assertFalse(FormsBehavior::identity(str_replace('return Constants::PERMISSION_ALL;', 'return [];', $source)), 'Owner-return mutation');
			self::assertFalse(FormsBehavior::identity(str_replace('if (!$this->currentUser)', 'if ($this->currentUser)', $source)), 'Null-branch mutation');
			self::assertStringContainsString('$this->currentUser=$userSession->getUser();', FormsBehavior::body($source, '__construct'));
			self::assertSame(['user', 'filterShown?'], $fixture['classes']['OCA\\Forms\\Service\\FormsService']['methods']['getSharedForms']);
			self::assertStringContainsString('$this->formMapper->findSharedForms(', FormsBehavior::body($source, 'getSharedForms'));
			self::assertStringContainsString('$this->hasUserAccess($form)', FormsBehavior::body($source, 'getFormIfAllowed'));
			self::assertStringNotContainsString('isFormArchived', FormsBehavior::body($source, 'canEditForm'));
			$entity = $fixture['sources']['Db/Form']['source'];
			self::assertStringContainsString('@method void setOwnerId(string $value)', $entity, 'Magic setter contract, not a declared positional method');
			self::assertContains('ownerId', $fixture['classes']['OCA\\Forms\\Db\\Form']['fields']);
			foreach (['submissionMessage', 'isAnonymous', 'access', 'confirmationEmailBody'] as $field) { self::assertStringContainsString("'".$field."'", FormsBehavior::body($entity, 'read')); }
			foreach (['/s/{hash}', '/embed/{hash}', '/{hash}/{view}'] as $route) { self::assertStringContainsString($route, $fixture['sources']['Controller/PageController']['source']); }
			self::assertStringContainsString('findByForm($formId)', FormsBehavior::body($source, 'getSharesWithUser'), 'Third actor does query sentinel shares');
		}
	}
}
