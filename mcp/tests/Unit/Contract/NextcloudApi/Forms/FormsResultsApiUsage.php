<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

/** Explicit FO2 native read surfaces; neither controller nor SubmissionService is a product dependency. */
final class FormsResultsApiUsage {
	public const EXTRA_CLASSES = ['OCA\\Forms\\Db\\SubmissionMapper','OCA\\Forms\\Db\\AnswerMapper','OCA\\Forms\\Db\\QuestionMapper','OCA\\Forms\\Db\\OptionMapper'];
	public const CLASSES = ['OCA\\Forms\\Db\\Submission','OCA\\Forms\\Db\\Answer', ...self::EXTRA_CLASSES];
	public const SOURCES = ['Db/Submission','Db/Answer','Db/SubmissionMapper','Db/AnswerMapper','Db/QuestionMapper','Db/OptionMapper','Service/SubmissionService','Controller/ApiController','Helper/FilePathHelper'];
	public const CONSTANT_NAMES = ['PERMISSION_RESULTS','ANSWER_TYPES'];
	public const POSITIONAL = [
		'OCA\\Forms\\Db\\SubmissionMapper::findByForm' => ['formId','userId','searchString','limit','offset'],
		'OCA\\Forms\\Db\\SubmissionMapper::findById' => ['id'],
		'OCA\\Forms\\Db\\AnswerMapper::findBySubmission' => ['submissionId'],
		'OCA\\Forms\\Db\\QuestionMapper::findByForm' => ['formId','loadDeleted'],
		'OCA\\Forms\\Db\\OptionMapper::findByQuestion' => ['questionId','optionType'],
		'OCA\\Forms\\Db\\Submission::getFormId' => [], 'OCA\\Forms\\Db\\Submission::getUserId' => [],
		'OCA\\Forms\\Db\\Submission::getId' => [], 'OCA\\Forms\\Db\\Submission::getTimestamp' => [],
		'OCA\\Forms\\Db\\Answer::getSubmissionId' => [], 'OCA\\Forms\\Db\\Answer::getQuestionId' => [],
		'OCA\\Forms\\Db\\Answer::getFileId' => [], 'OCA\\Forms\\Db\\Answer::getText' => [], 'OCA\\Forms\\Db\\Answer::getId' => [],
		'OCA\\Forms\\Db\\Question::getFormId' => [], 'OCA\\Forms\\Db\\Question::getId' => [], 'OCA\\Forms\\Db\\Question::getOrder' => [],
		'OCA\\Forms\\Db\\Question::getType' => [], 'OCA\\Forms\\Db\\Question::getName' => [], 'OCA\\Forms\\Db\\Question::getText' => [],
		'OCA\\Forms\\Db\\Option::getQuestionId' => [], 'OCA\\Forms\\Db\\Option::getId' => [], 'OCA\\Forms\\Db\\Option::getOrder' => [],
		'OCA\\Forms\\Db\\Option::getText' => [], 'OCA\\Forms\\Db\\Option::getOptionType' => [],
	];
}
