<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCP\IConfig;

/**
 * The fingerprint of what a plan showed, so the confirmed call executes exactly what was approved.
 *
 * A write that re-reads state between plan and confirm (a share that appeared, changed or went away in the meantime)
 * puts {@see self::of()} of the state it showed in its plan, under {@see self::ARGUMENT}. The confirmed call computes
 * it again from the state it is about to change and requires the value back: a different one means the person
 * approved something else, so nothing is written and the answer is the new plan ({@see PlanChanged}).
 *
 * General rule: `$shown` holds every argument that changes the effect of the write (the node, the recipient, each field
 * the call asks for) next to the state it found, so the value of one plan never confirms a call with other arguments,
 * even when both would find the same state. An argument that does not change the effect (`confirm`, this one) stays out.
 *
 * The value is an HMAC-SHA256 with the instance secret: stable for the same state, opaque, and never readable back
 * into the fields. Without the secret it cannot be computed either, so a confirmed call carries it only after a plan
 * was produced for that state. Hand it nothing secret anyway, never a password. Stateless: one instance serves every
 * request.
 */
final class PlanState {
    /** Argument of the confirmed call and key of the plan that carries the value. */
    public const ARGUMENT = 'plan_state';
    /** Longest value the schema accepts; {@see self::of()} gives 64 characters. */
    private const MAX_LENGTH = 128;

    public function __construct(private IConfig $config) {}

    /**
     * @param string $tool tool whose plan this is, so the state of one tool never confirms another
     * @param string $uid authenticated user the plan was made for, so a plan_state never confirms for another account
     * @param array<string, mixed> $shown what the plan showed and the write depends on, every argument that changes the
     *   effect included, as scalars and arrays of them
     * @return string 64 lowercase hex characters
     */
    public function of(string $tool, string $uid, array $shown): string {
        $payload = json_encode([$tool, $uid, $shown], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return hash_hmac('sha256', $payload, 'mcp-plan-state|' . $this->config->getSystemValueString('secret', ''));
    }

    /**
     * The confirmed call has to give the value back, as the G4 argument errors say: field and fixed rule, no value.
     *
     * @param array<string, mixed> $arguments validated arguments of the confirmed call
     * @throws ArgumentValidationException when the value is missing or is not a non-empty string
     */
    public static function require(array $arguments): void {
        $given = $arguments[self::ARGUMENT] ?? null;
        if (!is_string($given) || $given === '') {
            throw new ArgumentValidationException('Invalid argument: ' . self::ARGUMENT, self::ARGUMENT,
                Translator::t('required with confirm: true; send the plan_state of the plan'));
        }
    }

    /**
     * @param string $expected {@see self::of()} of the state the confirmed call found
     * @param array<string, mixed> $arguments validated arguments of the confirmed call
     * @return bool whether the call gives back exactly that value, compared in constant time
     */
    public static function matches(string $expected, array $arguments): bool {
        $given = $arguments[self::ARGUMENT] ?? null;
        return is_string($given) && hash_equals($expected, $given);
    }

    /** @return array{type:string, minLength:int, maxLength:int, description:string} the schema property of {@see self::ARGUMENT} */
    public static function property(): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_LENGTH, 'description' => CommonMessages::planStateParameter()];
    }
}
