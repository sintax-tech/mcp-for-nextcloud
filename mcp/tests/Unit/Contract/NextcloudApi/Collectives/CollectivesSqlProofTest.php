<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Server/Doctrine fetch value proofs supplement, and never guess from, OCP's mixed declarations. */
final class CollectivesSqlProofTest extends CallGraphTreeTestCase {
	private const DIR = __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/';

	/** @return void each pin independently reconstructs all driver branches and checks every followed body for writes */
	public function testEveryServerPinProvesItsFetchDelegations(): void {
		require_once self::DIR . 'CollectivesSqlProof.php';
		$pins = json_decode(file_get_contents(self::DIR . 'collectives-sql-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$chains = json_decode(file_get_contents(self::DIR . 'collectives-read-chains.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($pins as $major => $pin) {
			$record = $chains[$major]['sqlProof'];
			$rebuilt = \CollectivesSqlProof::build($pin, $record['sources']);
			$this->assertSame($record['returns'], $rebuilt['returns']);
			$this->assertSame($record['methods'], $rebuilt['methods']);
			$this->assertSame($record['wiring'], $rebuilt['wiring']);
			$this->assertSame($record['leaves'], $rebuilt['leaves']);
			$this->assertCount(21, $rebuilt['methods']);
			$this->assertSame([], NativeWrites::in($rebuilt['methods']));
			$this->assertSame('scalar[][]', $rebuilt['returns']['fetchAll']['PDO::FETCH_ASSOC']);
		}
		$r = new \ReflectionClass(\PDOStatement::class);
		$this->assertTrue($r->isInternal());
		$this->assertFalse($r->hasMethod('__toString'));
	}

	/** @return void OCI without proved OCI_RETURN_LOBS must retain the possible object/unknown result */
	public function testOciWithoutReturnLobsOrWithUnprovedFlagsIsUnknown(): void {
		require_once self::DIR . 'CollectivesSqlTypes.php';
		foreach ([null, 'OCI_ASSOC|OCI_RETURN_NULLS', 'OCI_NUM', 'OCI_RETURN_LOBS'] as $flags) {
			$this->assertNull(\CollectivesSqlTypes::oci($flags, false));
			$this->assertNull(\CollectivesSqlTypes::oci($flags, true));
		}
		$this->assertSame('@scalar[]', \CollectivesSqlTypes::oci('OCI_NUM|OCI_RETURN_LOBS', false));
		file_put_contents($this->lib . '/Service/Oci.php', '<?php namespace Example;
			class Oci { public function row($mode) { return oci_fetch_array($statement, $mode | OCI_RETURN_NULLS); }
			public function dynamic($mode, $flags) { return oci_fetch_array($statement, $mode | $flags); } }');
		$index = new \CallGraphIndex(['' => $this->lib], null);
		$proof = new \CollectivesSqlTypes($index, 'Example\\Oci');
		$this->assertNull($proof->call('Example\\Oci', 'row', ['OCI_ASSOC']));
		$this->assertNull($proof->call('Example\\Oci', 'dynamic', ['OCI_ASSOC', null]));
	}

	/** @return void mode evidence is compulsory and object-producing modes are never admitted */
	public function testUnknownAndObjectFetchModesRemainUnknown(): void {
		foreach (['$mode', '\\PDO::FETCH_OBJ', '\\PDO::FETCH_CLASS'] as $mode) {
			$this->assertNotSame([], $this->fetch($mode)['unknown'], $mode);
		}
		$this->assertSame([], $this->fetch('\\PDO::FETCH_ASSOC')['unknown']);
		$this->assertSame([], $this->fetch('\\PDO::FETCH_NUM')['unknown']);
	}

	/** @return void a new source body cannot inherit the audited source's return proof */
	public function testChangedSqlSourceFailsBeforeTypeInference(): void {
		require_once self::DIR . 'CollectivesSqlProof.php';
		$pin = ['files' => ['Result.php' => hash('sha256', '<?php')]];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('SQL source SHA mismatch');
		\CollectivesSqlProof::build($pin, ['Result.php' => '<?php /* changed */']);
	}

	/** @return void even a deliberately repinned OCI body cannot prove a fetch when its LOB conversion flag disappears */
	public function testRemovingOciReturnLobsInvalidatesTheWholeDriverProof(): void {
		require_once self::DIR . 'CollectivesSqlProof.php';
		$pins = json_decode(file_get_contents(self::DIR . 'collectives-sql-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$chains = json_decode(file_get_contents(self::DIR . 'collectives-read-chains.json'), true, 512, JSON_THROW_ON_ERROR);
		$pin = $pins['33'];
		$sources = $chains['33']['sqlProof']['sources'];
		$file = '3rdparty/doctrine/dbal/src/Driver/OCI8/Result.php';
		$sources[$file] = str_replace(' | OCI_RETURN_LOBS', '', $sources[$file]);
		$pin['files'][$file] = hash('sha256', $sources[$file]);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unproved SQL return');
		\CollectivesSqlProof::build($pin, $sources);
	}

	/**
	 * @param string $mode supplied fetch mode expression
	 * @return array reached chain with an independently proved SQL model
	 */
	private function fetch(string $mode): array {
		@mkdir($this->lib . '/types');
		file_put_contents($this->lib . '/types/IResult.php', '<?php namespace OCP\\DB; interface IResult { public function fetchAll(int $mode = 2): array; }');
		file_put_contents($this->lib . '/Service/Entry.php', '<?php namespace Example; class Entry {
			public function read(\\OCP\\DB\\IResult $r, int $mode): void {
				array_unique(array_column($r->fetchAll(' . $mode . '), "id"));
			} }');
		$returns = ['fetchAll' => ['PDO::FETCH_ASSOC' => 'scalar[][]', 'PDO::FETCH_NUM' => 'scalar[][]']];
		return (new \CollectivesCallGraph($this->lib, $this->lib . '/types', $returns))->reach(['Example\\Entry::read']);
	}
}
