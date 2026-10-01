<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;

/** Carries validation metadata built exclusively from declared fields and fixed rules. */
class ArgumentValidationException extends InvalidArgumentException {
    /**
     * @param string $message Legacy internal exception message.
     * @param string $field Declared argument path, never its submitted value.
     * @param string $rule Fixed validation rule, optionally containing schema bounds.
     */
    public function __construct(string $message, private string $field = '', private string $rule = '') {
        parent::__construct($message);
    }

    /** @return array{field: string, rule: string} Safe metadata for JSON-RPC error.data; the field is "arguments" when the whole call is meant. */
    public function details(): array {
        return ['field' => $this->field === '' ? 'arguments' : $this->field, 'rule' => $this->rule];
    }

    /** @return string Short localized explanation for the client. */
    public function clientMessage(): string {
        return Translator::t('%s: %s', [$this->field === '' ? 'arguments' : $this->field, $this->rule]);
    }
}
