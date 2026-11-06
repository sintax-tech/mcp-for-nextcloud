<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;
use OCA\Deck\Db\{Acl,Board,Card,CardMapper,Label,LabelMapper,Stack,StackMapper};
use OCA\Deck\Service\{BoardService,CardService,PermissionService};
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCA\Mcp\Modules\Deck\Editing\CardLabelChanges; use OCA\Mcp\Modules\Deck\Failures\DeckConflictException; use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException; use OCA\Mcp\Modules\Deck\Failures\DeckSessionException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
require_once __DIR__.'/Stubs/deck_stubs.php';
final class CardLabelsGatewayTest extends TestCase {
	private array $services;
	private DeckServiceGateway $gateway;
	private array $cardFields=[];
	private array $boardFields=[];
	protected function setUp():void {
		foreach([BoardService::class,CardService::class,PermissionService::class,CardMapper::class,LabelMapper::class,StackMapper::class,\OCP\IUserManager::class]as$c){
			$this->services[$c]=$this->createMock($c);
		}
		$this->services[CardMapper::class]->method('find')->willReturnCallback(fn()=>new Card($this->cardFields+
			['id'=>7,'stackId'=>10,'labels'=>[new Label(['id'=>1,'boardId'=>20])]]));
		$this->services[CardMapper::class]->method('findBoardId')->willReturn(20);
		$this->services[BoardService::class]->method('findAll')->with(-2,false,true)->willReturnCallback(fn()=>[
			new Board($this->boardFields+['id'=>20,'owner'=>'alice','title'=>'Board'])]);
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id'=>10,'boardId'=>20])]);
		$this->services[LabelMapper::class]->method('findAll')->with(20)->willReturn([
			new Label(['id'=>1,'boardId'=>20,'title'=>'Keep']),new Label(['id'=>2,'boardId'=>20,'title'=>'Add'])]);
		$container=$this->createMock(ContainerInterface::class);$container->method('get')->willReturnCallback(fn($id)=>$this->services[$id]);
		$session=$this->createMock(\OCP\ISession::class);$session->method('get')->with('user_id')->willReturn('alice');
		$this->gateway=new DeckServiceGateway($container,$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),null,$session);
	}
	public function testChecksBothReadAndEditBeforeLookingAtAnyLabel():void {
		$checks=[];
		$this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(
			function($mapper,$id,$permission)use(&$checks):bool{$checks[]=$permission;self::assertSame(7,$id);return true;});
		$s=$this->gateway->cardLabelState('alice',7,[2]);
		self::assertSame([Acl::PERMISSION_READ,Acl::PERMISSION_EDIT],$checks);
		self::assertSame([1],array_column($s['labels'],'id'));self::assertSame([2],array_column($s['requested'],'id'));
	}
	public function testNativeEditRefusalPrecedesMapperReads():void {
		$this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException());
		$this->services[CardMapper::class]->expects(self::never())->method('find');
		$this->expectException(NoPermissionException::class);$this->gateway->cardLabelState('alice',7,[1]);
	}
	public function testSessionMismatchDoesNotResolveDeck():void {
		$this->services[BoardService::class]->expects(self::never())->method('setUserId');
		$this->expectException(DeckSessionException::class);$this->gateway->cardLabelState('mallory',7,[1]);
	}
	public function testForeignLabelRejectedEvenWhenRemovingAbsentAssociation():void {
		$this->services[CardService::class]->expects(self::never())->method('removeLabel');
		$this->expectException(DeckRefusalException::class);$this->gateway->cardLabelState('alice',7,[999]);
	}
	public static function inactive():iterable {
		yield 'archived card'=>[['archived'=>true],[]];yield 'deleted card'=>[['deletedAt'=>1],[]];
		yield 'archived board'=>[[],['archived'=>true]];yield 'deleted board'=>[[],['deletedAt'=>1]];
	}
	#[DataProvider('inactive')]
	public function testRefusesInactiveCardOrBoard(array $card,array $board):void {
		$this->cardFields=$card;$this->boardFields=$board;
		$this->expectException(DeckRefusalException::class);$this->gateway->cardLabelState('alice',7,[1]);
	}
	public function testSnapshotMismatchNeverCallsNativeAssociation():void {
		$state=$this->gateway->cardLabelState('alice',7,[2]);$before=CardLabelChanges::snapshot($state);
		$this->boardFields=['title'=>'Renamed'];
		$this->services[CardService::class]->expects(self::never())->method('assignLabel');
		$this->expectException(DeckConflictException::class);$this->gateway->changeCardLabel('alice',7,2,true,[2],$before);
	}
	public function testInheritedForeignLabelsDoNotBlockLocalChangesOrExposeTheirMetadata():void {
		$this->cardFields=['labels'=>[new Label(['id'=>1,'boardId'=>20]),
			new Label(['id'=>999,'boardId'=>88,'title'=>'Private foreign label'])]];
		$state=$this->gateway->cardLabelState('alice',7,[2]);
		self::assertSame([1],array_column($state['labels'],'id'));
		self::assertSame([999],$state['foreignAssigned']);
		$this->services[CardService::class]->expects(self::once())->method('assignLabel')->with(7,2)->willReturn(new Card());
		$this->gateway->changeCardLabel('alice',7,2,true,[2],CardLabelChanges::snapshot($state));
	}
	public function testNativeIncrementalEndpointsOnly():void {
		$this->services[CardService::class]->expects(self::once())->method('assignLabel')->with(7,2)->willReturn(new Card());
		$this->services[CardService::class]->expects(self::once())->method('removeLabel')->with(7,1)->willReturn(new Card());
		$before=CardLabelChanges::snapshot($this->gateway->cardLabelState('alice',7,[1,2]));
		$this->gateway->changeCardLabel('alice',7,2,true,[1,2],$before);
		$this->gateway->changeCardLabel('alice',7,1,false,[1,2],$before);
	}
}
