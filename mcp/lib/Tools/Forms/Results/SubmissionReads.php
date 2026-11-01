<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Forms\Db\Submission;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;

/** Calls only the common native mapper read API after access admission; exceptions never become empty arrays. */
class SubmissionReads {
	public const MAX_HEADERS = 50000;
	/** Receives the native container without resolving any app service eagerly.
	 * @param ContainerInterface $container Official app container.
	 */
	public function __construct(private ContainerInterface $container) {
	}
	/** Resolves a native mapper lazily after callers established native ACL.
	 * @param string $name Mapper short name.
	 * @return object Native mapper.
	 */
	private function mapper(string $name): object { return $this->container->get('OCA\\Forms\\Db\\' . $name); }
	/** Loads one bounded header universe, not answers, checking every native identity.
	 * @param int $formId Authorized form.
	 * @param string $uid Actor.
	 * @param string $mode Native own/all.
	 * @return list<array{id:int,formId:int,userId:string,timestamp:int}> Complete admitted headers.
	 */
	public function headers(int $formId, string $uid, string $mode): array {
		$rows = $this->mapper('SubmissionMapper')->findByForm($formId, $mode === 'own' ? $uid : null, null, self::MAX_HEADERS + 1, 0);
		if (count($rows) > self::MAX_HEADERS) { throw new ToolFailure(ResultsMessages::universe()); }
		return array_map(fn ($row): array => $this->header($row, $formId, $uid, $mode), $rows);
	}
	/** Resolves one native header before its answers; missing and forbidden remain indistinguishable.
	 * @param int $id Submission id.
	 * @param int $formId Authorized form.
	 * @param string $uid Actor.
	 * @param string $mode Current own/all.
	 * @return array{id:int,formId:int,userId:string,timestamp:int} Native header.
	 */
	public function one(int $id, int $formId, string $uid, string $mode): array {
		try { $row = $this->mapper('SubmissionMapper')->findById($id); }
		catch (DoesNotExistException $error) { throw new ToolFailure(FormsMessages::notFound(), 0, $error); }
		$header = $this->header($row, $formId, $uid, $mode);
		if ($header['id'] !== $id) { throw new ToolFailure(FormsMessages::notFound()); }
		return $header;
	}
	/** Converts a native Submission into private bounded header fields, never public serialization.
	 * @param mixed $row Expected native entity.
	 * @param int $formId Authorized form.
	 * @param string $uid Actor.
	 * @param string $mode Current own/all.
	 * @return array{id:int,formId:int,userId:string,timestamp:int} Checked identity.
	 */
	private function header(mixed $row, int $formId, string $uid, string $mode): array {
		if (!$row instanceof Submission || (int)$row->getFormId() !== $formId || ($mode === 'own' && $row->getUserId() !== $uid)) {
			throw new ToolFailure(FormsMessages::notFound());
		}
		if (!is_string($row->getUserId()) || (int)$row->getId() <= 0) { throw new \UnexpectedValueException('Invalid native header'); }
		return ['id' => (int)$row->getId(), 'formId' => $formId, 'userId' => $row->getUserId(), 'timestamp' => (int)$row->getTimestamp()];
	}
	/** Loads active questions only; no deleted or foreign-id lookup is exposed.
	 * @param int $formId Authorized form.
	 * @return array Native Question entities.
	 */
	public function questions(int $formId): array { return $this->mapper('QuestionMapper')->findByForm($formId, false); }
	/** Reads native options of an already admitted active question.
	 * @param int $questionId Active question.
	 * @return array Native Option entities.
	 */
	public function options(int $questionId): array { return $this->mapper('OptionMapper')->findByQuestion($questionId, null); }
	/** Reads answers of one authorized header, not all submissions in the universe.
	 * @param int $submissionId Authorized selected header.
	 * @return array Native Answer entities.
	 */
	public function answers(int $submissionId): array { return $this->mapper('AnswerMapper')->findBySubmission($submissionId); }
}
