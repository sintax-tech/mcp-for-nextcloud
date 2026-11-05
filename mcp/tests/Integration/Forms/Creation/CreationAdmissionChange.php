<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Creation;
use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;
use OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory;

/** Test-only barrier calls a second real PHP process to change the synthetic actor's policy. */
final class CreationAdmissionChange extends FormsCreateNativeFactory {
	/** @var int Admission calls for the real writer's stage boundaries. */
	private int $checks = 0;
	/** @var list<int> Existing synthetic form IDs before this writer starts. */
	private array $existing = [];
	/** Receives the unchanged product factory; this fixture never constructs a replacement native graph.
	 * @param FormsCreateNativeFactory $real Actual product admission.
	 * @param CreateFormContext|null $editContext Optional graph for the native concurrent-edit control.
	 */
	public function __construct(private FormsCreateNativeFactory $real, private ?CreateFormContext $editContext = null) {
		if ($editContext !== null) { $this->existing = array_map(static fn (object $form): int => $form->getId(), $editContext->forms->findAllByOwnerId(\OCA\Mcp\Tests\Integration\Forms\FormsScene::READER)); }
	}
	/** Changes native metadata or revokes externally after metadata readback, before the first question.
	 * @param string $uid Disposable actor.
	 * @param CreateFormContext $context Actual pinned graph.
	 * @return void
	 */
	public function assertAllowed(string $uid, CreateFormContext $context): void {
		if (++$this->checks === 6) {
			if ($this->editContext !== null) {
				$new = array_values(array_filter($context->forms->findAllByOwnerId($uid), fn (object $form): bool => !in_array($form->getId(), $this->existing, true)));
				if (count($new) !== 1) { throw new \RuntimeException('Synthetic new identity not unique'); }
				$response = $context->controller->updateForm($new[0]->getId(), ['title' => 'Changed by native edit fixture']);
				if ($response->getStatus() !== 200) { throw new \RuntimeException('Native edit fixture failed'); }
			} else {
			$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/ci/forms/creation-revoke.php');
			$lines = []; exec($command . ' 2>&1', $lines, $exit);
			if ($exit !== 0 || implode('', $lines) !== 'revoked') { throw new \RuntimeException('External fixture writer failed'); }
			}
		}
		$this->real->assertAllowed($uid, $context);
	}
}
