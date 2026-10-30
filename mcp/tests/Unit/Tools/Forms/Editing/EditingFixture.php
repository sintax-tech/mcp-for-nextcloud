<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

require_once dirname(__DIR__) . '/NativeFormsFixture.php';

/** Explicit unit form double; runtime cells use the installed native entity. */
class EditingForm extends \OCA\Forms\Db\Form {
	/** Captures only the small metadata shape used by editing, not results or email bodies.
	 * @param array<string,mixed> $fields Native-shaped fields.
	 */
	public function __construct(public array $fields) { $this->setId(1); $this->setOwnerId('alice'); $this->setAccess(['permitAllUsers' => false,'showToAllUsers' => false]); }
	/** Supplies dynamic native-shaped field getters without adding methods to the shared legacy fixture.
	 * @param string $name Getter.
	 * @param array<mixed> $args Arguments.
	 * @return mixed Synthetic stored value.
	 */
	public function __call(string $name, array $args): mixed { $key = lcfirst(substr($name,3)); return array_key_exists($key,$this->fields) ? $this->fields[$key] : parent::__call($name,$args); }
}

/** Unit-only question/option field reader; never advertised as execution of pinned Forms. */
class EditingRow {
	/** Receives mutable native-shaped fields.
	 * @param array<string,mixed> $data Stored fields.
	 */
	public function __construct(public array $data) { }
	/** Returns only getters; an accidental setter or foreign lookup fails loudly.
	 * @param string $name Getter name.
	 * @param array<mixed> $args No setter input permitted.
	 * @return mixed Field.
	 */
	public function __call(string $name, array $args): mixed { if (!str_starts_with($name,'get')) { throw new \RuntimeException('unexpected write'); } return $this->data[lcfirst(substr($name,3))]; }
}

/** Separates question, option and share ports so active-map tests detect lookup scope. */
class EditingReader {
	/** Selects the single reader role.
	 * @param EditingFixture $state Mutable synthetic state.
	 * @param string $kind Reader role.
	 */
	public function __construct(private EditingFixture $state, private string $kind) { }
	/** Counts complete active-map reads; no findById fallback exists.
	 * @param int $id Authorized form ID.
	 * @return array<object> Native-shaped entities.
	 */
	public function findByForm(int $id): array {
		$this->state->reads[] = $this->kind . ':' . $id;
		if ($this->state->readFails) { throw new \RuntimeException('private mapper detail'); }
		if ($this->kind === 'shares') { return $this->state->shares; }
		return $this->state->deleted ? [] : [$this->state->question];
	}
	/** Reads options only after the parent active question was admitted.
	 * @param int $id Admitted question ID.
	 * @return list<EditingRow> Options.
	 */
	public function findByQuestion(int $id): array { $this->state->reads[] = 'options:' . $id; return [$this->state->option]; }
}

/** Synthetic native controller/ACL state for discriminating sinks, B1, readback and races; not an integration replacement. */
class EditingFixture {
	public EditingForm $form;
	public EditingRow $question;
	public EditingRow $option;
	public array $shares = [];
	public array $reads = [];
	public array $calls = [];
	public bool $allow = true;
	/** @var list<string> Native permissions; this is independent of the MCP grant. */
	public array $nativePermissions = ['edit'];
	public bool $readFails = false;
	public bool $deleted = false;
	public bool $throwAfterTarget = false;
	public bool $voidWithoutWrite = false;
	public bool $statusWithoutWrite = false;
	public bool $deleteAfterTarget = false;
	public bool $validSettings = true;
	public bool $allowConfirmation = true;
	/** Creates a selected email question and a persisted option without writing any real service.
	 * @return void
	 */
	public function __construct() {
		$this->form = new EditingForm(['title'=>'Form','state'=>0,'lockedBy'=>null,'lockedUntil'=>0,'lastUpdated'=>1,'confirmationEmailEnabled'=>true,'confirmationEmailQuestionId'=>2]);
		$this->question = new EditingRow(['id'=>2,'formId'=>1,'order'=>1,'type'=>'short','text'=>'Email','description'=>'','isRequired'=>false,'extraSettings'=>['validationType'=>'email']]);
		$this->option = new EditingRow(['id'=>3,'questionId'=>2,'order'=>1,'text'=>'Choice','optionType'=>'choice']);
	}
	/** Checks native-style EDIT before any question/options read.
	 * @param int $id Form ID.
	 * @param string $permission Required permission.
	 * @return EditingForm Admitted entity.
	 */
	public function getFormIfAllowed(int $id, string $permission): EditingForm { if (!$this->allow || $id!==1 || !in_array($permission,$this->nativePermissions,true)) { throw new \RuntimeException('private native denial'); } return $this->form; }
	/** Publishes the admitted actor's effective native permission set.
	 * @param object $form Authorized form.
	 * @return list<string> Permission values.
	 */
	public function getPermissions(object $form): array { return $this->allow ? $this->nativePermissions : []; }
	/** Returns only the app confirmation switch, never canCreateForms.
	 * @return bool Native configuration.
	 */
	public function getAllowConfirmationEmail(): bool { return $this->allowConfirmation; }
	/** Makes requiring native create in an edit an explicit test failure.
	 * @return never
	 */
	public function canCreateForms(): never { throw new \RuntimeException('editing must not require creation'); }
	/** Supplies a controlled validator outcome; source/runtime tests cover actual native validation.
	 * @param array<string,mixed> $settings Complete map.
	 * @param string $type Immutable type.
	 * @return bool Controlled result.
	 */
	public function areExtraSettingsValid(array $settings,string $type): bool { return $this->validSettings; }
	/** Performs one native-shaped question patch, including optional post-target failure and selection cleanup.
	 * @param int $form Form ID.
	 * @param int $question Question ID.
	 * @param array<string,mixed> $patch Closed native patch.
	 * @return mixed Native response or veto-like void.
	 */
	public function updateQuestion(int $form,int $question,array $patch): mixed { return $this->apply(false,$patch,$question); }
	/** Performs only a native-shaped option patch.
	 * @param int $form Form ID.
	 * @param int $question Parent question.
	 * @param int $option Option ID.
	 * @param array<string,mixed> $patch Point patch.
	 * @return mixed Native response.
	 */
	public function updateOption(int $form,int $question,int $option,array $patch): mixed { return $this->apply(true,$patch,$option); }
	/** Applies the synthetic native sink once; deliberately leaves the target after an injected final-write failure.
	 * @param bool $option Option versus question.
	 * @param array<string,mixed> $patch Native patch.
	 * @param int $id Response ID.
	 * @return mixed Response.
	 */
	private function apply(bool $option,array $patch,int $id): mixed {
		$this->calls[] = $patch; if ($this->voidWithoutWrite) { return null; } if ($this->statusWithoutWrite) { return new \OCP\AppFramework\Http\DataResponse($id); }
		$target = $option ? $this->option : $this->question;
		if (isset($patch['extraSettings'])) { $patch['extraSettings'] = \OCA\Mcp\Tools\Forms\Editing\EditSettings::stored($patch['extraSettings']); }
		$target->data = array_replace($target->data,$patch);
		if ($this->throwAfterTarget) { throw new \RuntimeException('private post-target detail'); }
		if (!$option && $this->form->fields['confirmationEmailQuestionId']===2 && !\OCA\Mcp\Tools\Forms\Editing\EditState::email($this->question->data)) { $this->form->fields['confirmationEmailQuestionId']=null; }
		$this->form->fields['lockedBy']='alice';$this->form->fields['lockedUntil']=time()+900;$this->form->fields['lastUpdated']=time();
		$this->deleted=$this->deleteAfterTarget; return new \OCP\AppFramework\Http\DataResponse($id);
	}
	/** Supplies separate mapper identities in a per-operation native-shaped graph.
	 * @return \OCA\Mcp\Tools\Forms\Editing\EditContext Synthetic graph.
	 */
	public function context(): \OCA\Mcp\Tools\Forms\Editing\EditContext { return new \OCA\Mcp\Tools\Forms\Editing\EditContext('5.4.1',$this,$this,$this,new EditingReader($this,'questions'),new EditingReader($this,'options'),new EditingReader($this,'shares')); }
}
