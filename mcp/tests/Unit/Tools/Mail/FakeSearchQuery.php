<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

/**
 * Test shape of OCA\Mail\Service\Search\SearchQuery with the setters the message tools call, read back by
 * {@see FakeMailMessageApp} to filter its messages as the Mail mapper does.
 */
final class FakeSearchQuery {
	/** @var list<string> sender fragments */
	public array $from = [];
	/** @var list<string> recipient fragments */
	public array $to = [];
	/** @var list<string> copy recipient fragments */
	public array $cc = [];
	/** @var list<string> subject fragments */
	public array $subjects = [];
	/** @var list<string> body texts */
	public array $bodies = [];
	/** Lower send date bound, as a numeric string. */
	public ?string $start = null;
	/** Upper send date bound, as a numeric string. */
	public ?string $end = null;
	/** Cursor: messages sent strictly before. */
	public ?int $cursor = null;
	/** Whether only messages with attachments match. */
	public bool $hasAttachments = false;
	/** @var list<array{flag:string, set:bool}> flag conditions */
	public array $flags = [];
	/** Whether the query groups by thread, as SearchQuery does by default since Mail 5.5. */
	public bool $threaded = true;

	/** @param bool $threaded thread grouping @return void */
	public function setThreaded(bool $threaded): void {
		$this->threaded = $threaded;
	}

	/** @param string $value sender fragment @return void */
	public function addFrom(string $value): void {
		$this->from[] = $value;
	}

	/** @param string $value recipient fragment @return void */
	public function addTo(string $value): void {
		$this->to[] = $value;
	}

	/** @param string $value copy recipient fragment @return void */
	public function addCc(string $value): void {
		$this->cc[] = $value;
	}

	/** @param string $value subject fragment @return void */
	public function addSubject(string $value): void {
		$this->subjects[] = $value;
	}

	/** @param string $value body text @return void */
	public function addBody(string $value): void {
		$this->bodies[] = $value;
	}

	/** @param string $value lower bound @return void */
	public function setStart(string $value): void {
		$this->start = $value;
	}

	/** @param string $value upper bound @return void */
	public function setEnd(string $value): void {
		$this->end = $value;
	}

	/** @param int $value cursor @return void */
	public function setCursor(int $value): void {
		$this->cursor = $value;
	}

	/** @param bool $value attachments only @return void */
	public function setHasAttachments(bool $value): void {
		$this->hasAttachments = $value;
	}

	/** @param \ArrayObject<string, mixed> $flag condition from FakeMailMessageApp::flag() @return void */
	public function addFlag(\ArrayObject $flag): void {
		$this->flags[] = $flag->getArrayCopy();
	}

	/** @return list<string> body texts, as the Mail provider reads them */
	public function getBodies(): array {
		return $this->bodies;
	}
}
