<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

/** Every write/delegation or sink-name literal in all lib directories has an explicit, honest classification. */
final class FileWriteGuardCoverageTest extends TestCase {
	/**
	 * Pending is an integration task, never an exemption or a claim that this slice already enforces the policy.
	 * Later tickets must replace pending with verified guard references and behavior tests, not expand D3.
	 *
	 * @return array<string, string> file#method:operation:occurrence to status and owner/rationale.
	 */
	private function classified(): array {
		return [
			'Access/Grants/GrantCatalog.php#<global>:literal-move:1' => 'label: files operation catalog entry',
			'Access/Grants/GrantCatalog.php#<global>:literal-move:2' => 'label: notes operation catalog entry',
			'Access/Grants/GrantCatalog.php#<global>:literal-move:3' => 'label: deck operation catalog entry',
			'Access/Grants/GrantCatalog.php#<global>:literal-move:4' => 'label: calendar operation catalog entry',
			'Access/Grants/GrantCatalog.php#<global>:literal-move:5' => 'label: mail operation catalog entry',
			'Modules/Calendar/Selftest/CalendarSelftestService.php#run:literal-move:1' => 'label: self-test operation grant name',
			'Modules/Calendar/Selftest/CalendarSelftestService.php#run:literal-move:2' => 'label: self-test stage identifier',
			'Modules/Calendar/Selftest/CalendarSelftestService.php#run:literal-move:3' => 'label: self-test report detail key',
			'Modules/Calendar/Selftest/CalendarSelftestMessages.php#detail:literal-move:1' => 'label: report message lookup key',
			'Mcp/Tools/ToolPresentation.php#<global>:literal-move:1' => 'label: destructive-operation presentation catalog',
			'Modules/Collectives/Integration/CollectivesSurface.php#<global>:literal-move:1' => 'label: proven native PageService::move shape',
			'Access/Grants/GrantCatalog.php#<global>:literal-move:6' => 'label: collectives operation catalog entry',
			'Modules/Collectives/Integration/CollectivesGateway.php#move:literal-move:1' => 'label: collectives.move grant the admission re-reads',
			'Modules/Collectives/Integration/CollectivesGateway.php#move:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Modules\\Collectives\\CollectivesMovingTest::testRefusesWhatCollectivesWouldBreakOrNotDo',
			'Modules/Collectives/Moving/MovePageHandler.php#handle:literal-move:1' => 'label: collectives.move grant the edit permission re-reads',
			'Modules/Collectives/Moving/MovePageHandler.php#handle:literal-move:2' => 'label: collectives.move grant of the read-back',
			'Modules/Collectives/Moving/MovePageHandler.php#handle:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Modules\\Collectives\\CollectivesMovingTest::testRevocationAndChangedTreeMoveNothing',
			'Modules/Collectives/Moving/MovePagePlan.php#build:literal-move:1' => 'label: collectives.move grant of the plan permission check',
			'Modules/Collectives/Moving/MovePagePlan.php#build:literal-move:2' => 'label: collectives.move grant of the plan tree read',
			'Modules/Collectives/Tools/MovePage.php#definition:literal-move:1' => 'label: collectives_move operation metadata',
			'Modules/Collectives/Editing/EditPageHandler.php#handle:putcontent:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Modules\\Collectives\\CollectivesFileTypeBoundaryTest::testFilesKeepsTheAllowlistWhileCollectivesWritesMarkdown',
			'Modules/Notes/Tools/MoveNote.php#definition:literal-move:1' => 'label: notes_move operation metadata',
			'Modules/Files/Tools/MoveFile.php#definition:literal-move:1' => 'label: files_move operation metadata',
			'Modules/Files/Tools/MoveBatch.php#definition:literal-move:1' => 'label: files_move_batch operation metadata',
			'Modules/Files/Tools/UndoBatch.php#definition:literal-move:1' => 'label: files_undo_batch operation metadata',
			'Modules/Logs/Presentation/LogRedactor.php#<global>:literal-copy:1' => 'label: allowed HTTP verb for redacted log rendering',
			'Modules/Logs/Presentation/LogRedactor.php#<global>:literal-move:1' => 'label: allowed HTTP verb for redacted log rendering',
			'Modules/Deck/Tools/MoveCard.php#definition:literal-move:1' => 'label: move-card operation metadata',
			'Modules/Calendar/Tools/MoveEvent.php#definition:literal-move:1' => 'label: calendar_move_event operation metadata',
			'Modules/Mail/Moving/MailMoveTools.php#<global>:literal-move:1' => 'label: mail_move_message operation metadata',
			'Modules/Mail/Moving/MailMoveCapability.php#assertMoveTouchesOnlyTheMessage:literal-move:1' => 'label: IMAP capability name queried, no write',
			'Modules/Calendar/Writing/EmbeddedDavDispatcher.php#move:literal-move:1' => 'label: DAV HTTP verb; actual dispatcher is classified separately',

			'Modules/Files/Checkout/CheckoutDownload.php#send:fopen:1' => 'read: existing file download stream',
			'Modules/Files/Checkout/CheckoutWriter.php#create:fopen:1' => 'read: temporary upload body',
			'Modules/Files/Checkout/BodyReader.php#storeBody:fopen:1' => 'temporary: ITempManager upload body, not a Files node',
			'Controller/CheckoutController.php#inputStream:fopen:1' => 'read: php input stream',
			'Modules/Files/Presentation/FilesPlanRenderer.php#render:copy:1' => 'render: static plan text, no Files mutation',
			'Modules/Files/Presentation/FilesPlanRenderer.php#render:move:1' => 'render: static plan text, no Files mutation',
			'Modules/Files/Creation/ProtectedFileDestination.php#create:fopen:1' => 'read: read-back of the just-created file for the SHA-256 check, read-only mode; the write itself is FileCreationWriter, classified above',
			'Modules/Files/Images/ImageMetadata.php#originalDimensions:fopen:1' => 'read: original image bytes',
			'Modules/Files/Backups/FileBackupCopy.php#copy:fopen:1' => 'read: original bytes for internal recovery',
			'Modules/Files/Integration/VersionRestore.php#writeVersion:fopen:1' => 'temporary: php temp stream buffer, not a Files node',
			'Modules/Files/Extraction/TextExtractor.php#readLimited:fopen:1' => 'read: bounded file content, read-only mode',
			'Modules/Files/Integration/VersionStream.php#open:fopen:1' => 'read: current file bytes of the listed current version, read-only mode',
			'Modules/Logs/Reading/ReverseLogFile.php#__construct:fopen:1' => 'read: current server log published by IFileBased::getLogFilePath, read-only mode',
			'Modules/Files/Extraction/TextExtractor.php#zipXml:file_put_contents:1' => 'temporary: ITempManager local zip, not a Files node',
			'Modules/Mail/Extraction/MailAttachmentDocument.php#text:file_put_contents:1' => 'temporary: ITempManager local zip of a mail attachment, not a Files node',
			'Modules/Mail/Integration/MailMoveStore.php#move:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Mail\\MailMoveToolsTest::testAnotherGenerationNeverMovesAMessage',
			'Modules/Mail/Moving/MoveMessageHandler.php#handle:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Mail\\MailMoveToolsTest::testAConfirmedCallMovesOnTheCheckedSelection',
			'Modules/Calendar/Editing/UpdateEventDraft.php#prepare:touch:1' => 'DAV: update an in-memory event timestamp, not Files touch',
			'Access/FileTypes/FileWritePolicy.php#recoverStringType:rollback:1' => 'database: rollback owned configuration repair, not Files',

			'Modules/Files/Checkout/CheckoutWriter.php#write:putcontent:1' => 'guarded: OCA\Mcp\Tests\Unit\Tools\Files\FileWritePolicyBoundariesTest::testPolicyChangeDuringBackupRefusesContentSinkAndKeepsOriginalAndBackup',
			'Modules/Files/Creation/FileCreationWriter.php#write:newfile:1' => 'guarded: OCA\Mcp\Tests\Unit\Tools\Files\FileWritePolicyBoundariesTest::testDirectCreationSinkRefusesBeforeNewFile',
			'Modules/Files/Creation/FileCreationWriter.php#write:putcontent:1' => 'guarded: OCA\Mcp\Tests\Unit\Tools\Files\FileWritePolicyBoundariesTest::testCreationRechecksBeforePutContentWithoutDeletingEmptyFile',
			'Modules/Files/Creation/CopyFileHandler.php#handle:copy:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testForbiddenDescendantDeniesBothToolsBeforeEffects',
			'Modules/Files/Moving/MoveFileHandler.php#handle:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testForbiddenDescendantDeniesBothToolsBeforeEffects',
			'Modules/Files/Reorganization/Reorganization.php#copy:copy:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testForbiddenDescendantDeniesBothToolsBeforeEffects',
			'Modules/Files/Reorganization/Reorganization.php#move:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testForbiddenDescendantDeniesBothToolsBeforeEffects',
			'Modules/Files/Reorganization/MoveHandler.php#handle:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testForbiddenDescendantDeniesBothToolsBeforeEffects',
			'Modules/Files/Reorganization/MoveBatchHandler.php#batch:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testBatchRechecksEverySinkAndRecordsPartialProgress',
			'Modules/Files/Reorganization/UndoBatchHandler.php#undoBatch:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testUndoRechecksEverySinkAndKeepsOnlyRemainingMoves',
			'Modules/Files/Reorganization/ListedTreeCopy.php#native:copy:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\TreeBatchPolicyTest::testListedCopyExcludesLateChildrenAndReportsRenamedSource',
			'Modules/Files/Reorganization/ListedTreeCopy.php#execute:newfolder:1' => 'directory: only entries of the wholly prevalidated listed tree, never a file',
			'Modules/Files/Writing/ContentCommit.php#commit:putcontent:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\FileContentTypePolicyTest::testSinkDenialAfterBackupKeepsTheOriginalAndTheBackup',
			'Modules/Files/Writing/ContentCommit.php#commit:putcontent:2' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\FileContentTypePolicyTest::testSinkDenialAfterBackupKeepsTheOriginalAndTheBackup',
			'Modules/Files/Integration/VersionRestore.php#restore:rollback:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\FileContentTypePolicyTest::testSinkDenialAfterBackupOnRestoreKeepsTheOriginalAndTheBackup',
			'Modules/Files/Integration/VersionRestore.php#writeVersion:putcontent:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Files\\FileContentTypePolicyTest::testSinkDenialAfterBackupOnRestoreKeepsTheOriginalAndTheBackup',
			'Modules/Files/Reorganization/FolderCreator.php#createFolders:newfolder:1' => 'directory: type not applicable; T15-6 must preflight associated files first',
			'Platform/FileAccess/NodeAccess.php#ensureFolder:newfolder:1' => 'directory: auxiliary folder, not a file; callers preflight associated files',
			'Modules/Notes/Creation/CreateNoteHandler.php#handle:newfile:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Notes\\NoteTypePolicyTest::testCreateRefusesBeforeCreatingACategory',
			'Modules/Notes/Editing/EditNoteHandler.php#handle:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Notes\\NoteTypePolicyTest::testWholeEditRefusesADeniedTitleBeforeWritingContent',
			'Modules/Notes/Moving/MoveNoteHandler.php#handle:move:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Notes\\NoteTypePolicyTest::testMoveRefusesBeforeCreatingACategory',
			'Modules/Notes/Editing/NoteContentSink.php#whole:putcontent:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Notes\\NoteTypePolicyTest::testWholeEditRefusesADeniedTitleBeforeWritingContent',
			'Modules/Notes/Editing/NoteContentSink.php#partial:putcontent:1' => 'guarded: OCA\\Mcp\\Tests\\Unit\\Tools\\Notes\\NoteTypePolicyTest::testPartialWriterRefusesBeforePutContent',
			'Modules/Files/Backups/FileBackup.php#copy:copy:1' => 'D3: delegates to the verified internal recovery copier; never reads file type policy',
			'Modules/Files/Backups/FileBackup.php#prepare:copy:1' => 'D3: internal verified backup delegation; never reads policy',
			'Modules/Files/Backups/FileBackupCopy.php#copy:newfile:1' => 'D3: mandatory internal backup creation; never reads policy',
			'Modules/Files/Backups/FileBackupCopy.php#copy:putcontent:1' => 'D3: mandatory internal backup bytes; never reads policy',
			'Modules/Contacts/Backup/ContactBackup.php#save:newfile:1' => 'D3: mandatory internal vCard backup; never reads policy',
			'Modules/Calendar/Moving/EventRelocator.php#prepare:move:1' => 'DAV: not Files, official CalDAV move',
			'Access/Policy/Transactions/PolicyTransaction.php#failure:rollback:1' => 'database: not Files, application transaction rollback',
			'Modules/Calendar/Selftest/CalendarSelftestService.php#run:move:1' => 'DAV: not Files, diagnostic CalDAV move',
		];
	}

	/** Unknown sinks and orphaned classifications fail against the entire production tree. */
	public function testAllProductionCallsAreClassified(): void {
		$actual = FileWriteSinkInventory::calls(FileWriteSinkInventory::sources(__DIR__ . '/../../../lib'));
		$expected = array_keys($this->classified());
		sort($expected, SORT_STRING);
		self::assertSame($expected, $actual, 'New/unclassified or removed/renamed sink: update the explicit inventory, never silently waive it.');
	}

	/**
	 * Keep all 48 calls, including the two reorganization facade delegations, and all 21 explicit literals visible.
	 *
	 * @return void
	 */
	public function testLiteralLabelsKeepTheOriginalCallInventory(): void {
		$calls = FileWriteSinkInventory::calls(FileWriteSinkInventory::sources(__DIR__ . '/../../../lib'));
		$literals = array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, ':literal-')));
		self::assertCount(29, $literals);
		self::assertCount(53, array_diff($calls, $literals));
		foreach ($calls as $call) {
			self::assertDoesNotMatchRegularExpression('/:(?:literal-)?(?:writestream|fputs):/', $call);
		}
	}

	/** Static copy/move helpers render plan text; the scanner still discovers and classifies both calls. */
	public function testPlanRendererCallsAreExplicitlyClassifiedAsRendering(): void {
		$path = 'Modules/Files/Presentation/FilesPlanRenderer.php';
		$calls = FileWriteSinkInventory::calls([$path => (string)file_get_contents(__DIR__ . '/../../../lib/' . $path)]);
		self::assertSame([$path . '#render:copy:1', $path . '#render:move:1'], $calls);
		foreach ($calls as $call) {
			self::assertSame('render: static plan text, no Files mutation', $this->classified()[$call]);
		}
	}

	/** D3 cannot spread outside its two trusted services, and pending remains explicitly distinct. */
	public function testOnlyRecoveryServicesHaveAnExemption(): void {
		foreach ($this->classified() as $key => $classification) {
			self::assertMatchesRegularExpression('/\A(pending T15-|guarded:|D3:|directory:|DAV:|database:|read:|temporary:|render:|label:)/', $classification);
			if (str_starts_with($classification, 'guarded:')) {
				self::assertTrue($this->hasBehaviorTest($classification), $key . ' needs guarded: Class::testMethod with a real public behavior test.');
			}
			if (str_starts_with($classification, 'label:')) {
				self::assertStringContainsString(':literal-', $key, 'A label classification cannot waive a detected call.');
			}
			if (str_starts_with($classification, 'D3:')) {
				$paths = ['Modules/Files/Backups/FileBackup.php', 'Modules/Files/Backups/FileBackupCopy.php', 'Modules/Contacts/Backup/ContactBackup.php'];
				self::assertMatchesRegularExpression('~\A(?:' . implode('|', array_map(static fn (string $path): string => preg_quote($path, '~'), $paths)) . ')#~', $key);
			}
		}
	}

	/** A future Mail directory is discovered too, without placing a fixture in production or deleting user data. */
	public function testNewSinkInAnotherDirectoryCannotHideFromTheInventory(): void {
		$base = tempnam(sys_get_temp_dir(), 'mcp-type-');
		self::assertNotFalse($base);
		unlink($base);
		$folder = $base . '/Tools/Mail';
		mkdir($folder, 0700, true);
		$file = $folder . '/FutureAttachment.php';
		try {
			file_put_contents($file, '<?php class FutureAttachment { function save($folder) { $folder->newFile("x.php"); } }');
			$calls = FileWriteSinkInventory::calls(FileWriteSinkInventory::sources($base));
			self::assertSame(['Tools/Mail/FutureAttachment.php#save:newfile:1'], $calls);
			self::assertSame($calls, array_values(array_diff($calls, array_keys($this->classified()))));
		} finally {
			unlink($file);
			rmdir($folder);
			rmdir($base . '/Tools');
			rmdir($base);
		}
		self::assertFileDoesNotExist($file);
	}

	/** Comments and quoted examples are ignored, but nullable/case-insensitive calls in nested closures are counted. */
	public function testScannerUsesTokensAndKeepsClosureOwnership(): void {
		$source = '<?php class Example { function save($f) { /* $f->newFile(); */ $s = "$f->copy()"; '
			. '$cb = function () use ($f) { $f?->putContent(""); }; $f->PUTCONTENT(""); } }';
		self::assertSame(['Example.php#save:putcontent:1', 'Example.php#save:putcontent:2'], FileWriteSinkInventory::calls(['Example.php' => $source]));
	}

	/** A guarded classification is evidence-linked, never arbitrary prose or a nonexistent method. */
	public function testGuardedStatusRequiresAnExistingBehaviorTest(): void {
		self::assertTrue($this->hasBehaviorTest('guarded: ' . self::class . '::testScannerUsesTokensAndKeepsClosureOwnership'));
		self::assertFalse($this->hasBehaviorTest('guarded: somehow protected'));
		self::assertFalse($this->hasBehaviorTest('guarded: MissingClass::testWhatever'));
		self::assertFalse($this->hasBehaviorTest('guarded: ' . self::class . '::testMissing'));
		self::assertFalse($this->hasBehaviorTest('guarded: ' . self::class . '::classified'));
	}

	/**
	 * @param string $classification A guarded inventory status.
	 * @return bool Whether it references an existing public behavior test, by Class::testMethod.
	 */
	private function hasBehaviorTest(string $classification): bool {
		$parts = explode('::', trim(substr($classification, strlen('guarded:'))));
		if (count($parts) !== 2 || !str_starts_with($parts[1], 'test') || !is_subclass_of($parts[0], TestCase::class)
			|| !method_exists($parts[0], $parts[1])) {
			return false;
		}
		return (new \ReflectionMethod($parts[0], $parts[1]))->isPublic();
	}

	/** Every form from the review mutation becomes an unclassified identity, in a real ephemeral Mail fixture. */
	public function testAllSevenEvasionFormsRequireClassification(): void {
		$base = tempnam(sys_get_temp_dir(), 'mcp-evasion-');
		self::assertNotFalse($base);
		unlink($base);
		$folder = $base . '/Tools/Mail';
		mkdir($folder, 0700, true);
		$file = $folder . '/EvasionProbe.php';
		try {
			$source = '<?php class EvasionProbe { '
				. 'function variableMethod($f) { $m="newFile"; $f->$m("a.php"); } '
				. 'function bracedMethod($f) { $f->{"putContent"}("x"); } '
				. 'function dispatch($f) { call_user_func([$f,"copy"],"/x"); } '
				. 'function arrayCallable($f) { [$f,"move"]("/y"); } '
				. 'function staticCall() { \OC\Files\Filesystem::file_put_contents("a.php","x"); } '
				. 'function streamWrite($f) { $f->fopen("w"); } '
				. 'function firstClass($f) { $f->newFolder(...); } }';
			file_put_contents($file, $source);
			$calls = FileWriteSinkInventory::calls(FileWriteSinkInventory::sources($base));
			$expected = [
				'Tools/Mail/EvasionProbe.php#variableMethod:dynamic-member:1',
				'Tools/Mail/EvasionProbe.php#variableMethod:literal-newfile:1',
				'Tools/Mail/EvasionProbe.php#bracedMethod:dynamic-member:1',
				'Tools/Mail/EvasionProbe.php#bracedMethod:literal-putcontent:1',
				'Tools/Mail/EvasionProbe.php#dispatch:literal-copy:1',
				'Tools/Mail/EvasionProbe.php#arrayCallable:literal-move:1',
				'Tools/Mail/EvasionProbe.php#staticCall:file_put_contents:1',
				'Tools/Mail/EvasionProbe.php#streamWrite:fopen:1',
				'Tools/Mail/EvasionProbe.php#firstClass:newfolder:1',
			];
			sort($expected, SORT_STRING);
			self::assertSame($expected, $calls);
			self::assertCount(9, array_diff($calls, array_keys($this->classified())), 'All seven forms and their literal names must require classification.');
		} finally {
			unlink($file);
			rmdir($folder);
			rmdir($base . '/Tools');
			rmdir($base);
		}
		self::assertDirectoryDoesNotExist($base);
	}

	/** Method ownership returns to the enclosing function after an anonymous-class method or nested function ends. */
	public function testNestedNamedFunctionRestoresTheOuterMethod(): void {
		$source = '<?php class Outer { function outer($f) { $x = new class { function inner($g) { $g->copy("x"); } }; '
			. 'function local($g) { $g->touch(); } $f->move("y"); } }';
		self::assertSame(['Nested.php#inner:copy:1', 'Nested.php#local:touch:1', 'Nested.php#outer:move:1'],
			FileWriteSinkInventory::calls(['Nested.php' => $source]));
	}

	/** Dynamic properties are not dynamic method calls; callable arrays and stream/static primitives cannot hide. */
	public function testAdditionalCallableAndPrimitiveShapesAreInventoried(): void {
		$source = '<?php function probe($f, $m) { $p = $f->{"RECURRENCE-ID"}; '
			. '$f?->$m(); Foo::$m(); Foo::{"touch"}(); $a=[Foo::class,"rename"]; '
			. 'call_user_func("file_put_contents","x","y"); "copy"("a","b"); '
			. '\call_user_func_array("rename",[]); $old=array($f,"touch"); '
			. 'fopen("x","w"); touch("x"); rename("x","y"); $f->copyFromStorage(); $f->moveFromStorage(); $f->writeStream("x", $h); fputs($h, "x"); }';
		$calls = FileWriteSinkInventory::calls(['Shapes.php' => $source]);
		self::assertCount(16, $calls);
		self::assertContains('Shapes.php#probe:dynamic-member:3', $calls);
		self::assertContains('Shapes.php#probe:literal-rename:1', $calls);
		self::assertContains('Shapes.php#probe:literal-file_put_contents:1', $calls);
		self::assertContains('Shapes.php#probe:copyfromstorage:1', $calls);
		self::assertContains('Shapes.php#probe:movefromstorage:1', $calls);
		self::assertContains('Shapes.php#probe:writestream:1', $calls);
		self::assertContains('Shapes.php#probe:fputs:1', $calls);
	}

	/** Review evasions, including qualified/static and multiline literal names, require classification in a disposable fixture. */
	public function testRoundThreeEvasionsRequireClassification(): void {
		$base = tempnam(sys_get_temp_dir(), 'mcp-evasion3-');
		self::assertNotFalse($base);
		unlink($base);
		$folder = $base . '/Tools/Mail';
		mkdir($folder, 0700, true);
		$file = $folder . '/EvasionProbe2.php';
		try {
			$source = '<?php class EvasionProbe2 { '
				. 'function mapped($items) { array_map("touch", $items); } '
				. 'function reflected($f) { (new \ReflectionMethod($f,"newFile"))->invoke("x"); } '
				. 'function variableFunction() { $fn="file_put_contents"; $fn("x","y"); } '
				. 'function variableArray($f) { $m="move"; [$f,$m]("x"); } '
				. 'function storageStream($s,$h) { $s->writeStream("x",$h); } '
				. 'function aliasStream($h) { fputs($h,"x"); } ';
			$source .= <<<'LITERAL_PROBES'
			function singleSlashTouch() { \call_user_func('\touch', 'x'); }
			function singleSlashRename() { \call_user_func('\rename', 'x', 'y'); }
			function qualifiedFunction() { \call_user_func('\\copy', 'x', 'y'); }
			function staticString() { \call_user_func('\\OC\\Files\\Filesystem::file_put_contents', 'x', 'y'); }
			function nowdocLiteral() { $fn = <<<'N'
			rename
			N;
			$fn('x', 'y'); }
			function heredocLiteral() { $fn = <<<H
			touch
			H;
			$fn('x'); }
			}
			LITERAL_PROBES;
			file_put_contents($file, $source);
			$calls = FileWriteSinkInventory::calls(FileWriteSinkInventory::sources($base));
			$expected = [
				'Tools/Mail/EvasionProbe2.php#mapped:literal-touch:1',
				'Tools/Mail/EvasionProbe2.php#reflected:literal-newfile:1',
				'Tools/Mail/EvasionProbe2.php#variableFunction:literal-file_put_contents:1',
				'Tools/Mail/EvasionProbe2.php#variableArray:literal-move:1',
				'Tools/Mail/EvasionProbe2.php#storageStream:writestream:1',
				'Tools/Mail/EvasionProbe2.php#aliasStream:fputs:1',
				'Tools/Mail/EvasionProbe2.php#singleSlashTouch:literal-touch:1',
				'Tools/Mail/EvasionProbe2.php#singleSlashRename:literal-rename:1',
				'Tools/Mail/EvasionProbe2.php#qualifiedFunction:literal-copy:1',
				'Tools/Mail/EvasionProbe2.php#staticString:literal-file_put_contents:1',
				'Tools/Mail/EvasionProbe2.php#nowdocLiteral:literal-rename:1',
				'Tools/Mail/EvasionProbe2.php#heredocLiteral:literal-touch:1',
			];
			sort($expected, SORT_STRING);
			self::assertSame($expected, $calls);
			self::assertCount(12, array_diff($calls, array_keys($this->classified())), 'Every review evasion must require explicit classification.');
		} finally {
			unlink($file);
			rmdir($folder);
			rmdir($base . '/Tools');
			rmdir($base);
		}
		self::assertDirectoryDoesNotExist($base);
	}

	/** Accepted limit: assembled names such as 'new' . 'File' are not evaluated by this token inventory. */
	public function testConcatenatedSinkNamesAreAnExplicitLimit(): void {
		$source = '<?php function assembled($f) { $m="new"."File"; [$f,$m]("x"); '
			. '$fn="file_put_"."contents"; $fn("x","y"); }';
		self::assertSame([], FileWriteSinkInventory::calls(['Concatenation.php' => $source]));
	}

}
