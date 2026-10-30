<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Forms;

/** Native constants plus an artificial future answer type only for isolated unit doubles; four source contracts verify their actual definitions. */
final class Constants {
	public const ANSWER_TYPES = ['short','long','multiple','multiple_unique','dropdown','file','grid','ranking','linearscale','color','date','time','datetime','future'];
	public const PERMISSION_EDIT = 'edit';
	public const PERMISSION_EMBED = 'embed';
	public const PERMISSION_RESULTS = 'results';
	public const PERMISSION_RESULTS_DELETE = 'results_delete';
	public const PERMISSION_SUBMIT = 'submit';
	public const PERMISSION_ALL = ['edit', 'embed', 'results', 'results_delete', 'submit'];
}

namespace OCA\Forms\Db;

/** Unit entity double; integration uses the real Form from each installed pinned app. */
class Form extends \OCP\AppFramework\Db\Entity {
	protected $ownerId;
	protected $access;
	protected $lastUpdated;
	protected $hash;
	/** @var array<string,mixed> Synthetic read projection. */
	public array $data = [];

	/** Sets native-style access for the transient identity probe.
	 * @param array<string,bool> $access Native access shape.
	 * @return void
	 */
	public function setAccess(array $access): void { $this->access = $access; }

	/** Returns synthetic entity data without providing native authorization.
	 * @return array<string,mixed> Test fields, including traps for leaked private keys.
	 */
	public function read(): array { return $this->data + ['id' => $this->getId(), 'lastUpdated' => $this->getLastUpdated(), 'hash' => $this->getHash()]; }
}

namespace OCA\Forms\Service;

/** Allows the optional class-existence gate in unit tests without any native service construction. */
class FormsService {
}
