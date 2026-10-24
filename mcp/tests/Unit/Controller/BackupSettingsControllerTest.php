<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\BackupSettingsController;
use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupCopies;
use OCA\Mcp\Service\BackupSettingsWriter;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Db\PolicyStateMapper;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\App\IAppManager;
use OCP\Files\IFilenameValidator;
use PHPUnit\Framework\TestCase;

/** Validates HTTP adapter admission before any revisioned storage command runs. */
final class BackupSettingsControllerTest extends TestCase {
	/** @return void framework authentication, administrator and CSRF defaults remain enabled */
	public function testFrameworkSecurityExemptionsAreAbsent(): void {
		self::assertSame([], (new \ReflectionClass(BackupSettingsController::class))->getAttributes());
		foreach (['show', 'update'] as $method) {
			self::assertSame([], (new \ReflectionMethod(BackupSettingsController::class, $method))->getAttributes());
		}
	}

	/** @return void malformed or missing revisions and untyped settings cannot reach the transaction writer */
	public function testInvalidRequestsNeverReachPolicyWriter(): void {
		foreach ([[[], 428], [['expectedRevision' => '00', 'folder' => 'A', 'copies' => true], 400], [['expectedRevision' => '1', 'folder' => [], 'copies' => true], 400], [['expectedRevision' => '1', 'folder' => 'A', 'copies' => 'false'], 400]] as [$body, $status]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn ($key) => $body[$key] ?? null);
			$config = $this->createMock(IAppConfig::class);
			$location = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $config));
			$copies = new BackupCopies($location, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
			$writer = $this->createMock(PolicyWriter::class);
			$writer->expects(self::never())->method('runAdmin');
			$settings = new BackupSettingsWriter($location, $copies, $writer, $config, $this->createMock(IFilenameValidator::class));
			$controller = new BackupSettingsController('mcp', $request, $location, $copies, $settings, $this->createMock(PolicyStateMapper::class), $this->createMock(IUserSession::class));
			self::assertSame($status, $controller->update()->getStatus());
		}
	}
}
