<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Results;

use OCA\Mcp\Modules\Forms\ResultFileAccess;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\Files\{File,Folder,IRootFolder};
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** Real D6 gate with independent grant/scope and all actor-relative aliases, never owner-root access. */
final class ResultFileAccessTest extends TestCase {
	/** Verifies uniform omission, all aliases and native viewer metadata without reading file content.
	 * @return void
	 */
	public function testAllAliasesAndExactActorViewerWhitelist(): void {
		$grant=true;$hidden=false;$missing=false;
		$policy=$this->createMock(GrantPolicy::class);$policy->method('granted')->willReturnCallback(static function($uid,$app,$op)use(&$grant):bool{self::assertSame(['alice','files','read'],[$uid,$app,$op]);return $grant;});
		$guard=$this->createMock(FilesAccessGuard::class);$guard->method('isVisible')->willReturnCallback(static function($node)use(&$hidden):bool{return !$hidden||$node->getPath()==='/alice/files/a';});
		$a=$this->createMock(File::class);$b=$this->createMock(File::class);
		foreach([$a,$b] as $i=>$file){$file->method('getId')->willReturn(77);$file->method('isReadable')->willReturn(true);$file->method('getPath')->willReturn('/alice/files/'.($i===0?'a':'b'));$file->method('getName')->willReturn('current.txt');$file->method('getSize')->willReturn(3);$file->method('getMimetype')->willReturn('text/plain');foreach(['getContent','delete','move','copy']as$method){$file->expects(self::never())->method($method);}}
		$folder=$this->createMock(Folder::class);$folder->method('getById')->with(77)->willReturnCallback(static fn():array=>$missing?[]:[$b,$a]);
		$root=$this->createMock(IRootFolder::class);$root->method('getUserFolder')->with('alice')->willReturn($folder);
		$urls=$this->createMock(IURLGenerator::class);$urls->method('linkToRouteAbsolute')->with('files.View.showFile',['fileid'=>77])->willReturn('https://example.test/f/77');
		$files=new ResultFileAccess($policy,$guard,$root,$urls);self::assertTrue($files->begin('alice'));self::assertSame(['fileId'=>77,'name'=>'current.txt','size'=>3,'mimeType'=>'text/plain','url'=>'https://example.test/f/77'],$files->resolve('alice',77));
		$hidden=true;self::assertNull($files->resolve('alice',77));$hidden=false;$grant=false;self::assertFalse($files->begin('alice'));
	}
	/** A Files policy failure must propagate rather than become an omitted association.
	 * @return void
	 */
	public function testScopeFailureCannotBecomeEmpty(): void {
		$policy=$this->createMock(GrantPolicy::class);$policy->method('granted')->willReturn(true);$guard=$this->createMock(FilesAccessGuard::class);$guard->method('assertScopeReadable')->willThrowException(new PolicyUnavailable('private scope error'));
		$root=$this->createMock(IRootFolder::class);$root->expects(self::never())->method('getUserFolder');
		$this->expectException(PolicyUnavailable::class);(new ResultFileAccess($policy,$guard,$root,$this->createMock(IURLGenerator::class)))->begin('alice');
	}
}
