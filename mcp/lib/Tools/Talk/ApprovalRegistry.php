<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Remembers the drafts already shown to a user and decides whether one of them may still be published.
 *
 * A boolean on the call is not a proof of anything: an agent could set confirm: true on its first try and the
 * message would go out without the user ever seeing it. So the draft call stores what it showed, hands back an
 * opaque id, and the confirmed call has to present that id with the very same content: same account, same
 * conversation, same action, same payload. The id is consumed by the first successful check, so an approved
 * message cannot be sent twice from a stale answer, and it expires, so a draft forgotten on a screen is not a
 * standing permission.
 *
 * State lives in the app config, one key per pending approval, because each MCP call is its own request: a value
 * kept in memory would be gone by the time the user answers. Config rows have no compare-and-delete, so two
 * simultaneous confirmations of the same id could both pass; that needs two parallel publishes of a message the
 * user already approved, and a real table with a unique constraint is the fix if it ever matters.
 */
class ApprovalRegistry {
    /** App config key prefix of a pending approval; never a reserved key. */
    public const KEY_PREFIX = 'talk_approval_';
    /** App id under which the pending approvals are stored. */
    public const APP = 'mcp';
    /**
     * How long a draft stays approvable. Long enough for a user to read a message and answer, short enough that a
     * draft left on screen overnight is not a permission.
     */
    public const TTL_SECONDS = 600;
    /** Length of the generated id, in characters. */
    public const ID_LENGTH = 32;
    /** Shortest interval between two prunes of expired approvals. */
    private const PRUNE_INTERVAL_SECONDS = 60;
    /** Most keys a single prune reads. */
    private const PRUNE_SCAN_LIMIT = 200;

    public function __construct(
        private IAppConfig $config,
        private ISecureRandom $random,
    ) {}

    /**
     * Records a draft and returns the id the confirmed call has to present.
     *
     * @param string $userId Authenticated user who is shown the draft
     * @param array<string, mixed> $binding Action, conversation and payload the draft will publish
     * @return string Opaque id, valid once and for the TTL
     */
    public function issue(string $userId, array $binding): string {
        $this->pruneExpired();

        $id = $this->random->generate(self::ID_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->config->setValueString(self::APP, self::KEY_PREFIX . $id, (string)json_encode([
            'userId' => $userId,
            'binding' => self::fingerprint($binding),
            'expiresAt' => time() + self::TTL_SECONDS,
        ]));

        return $id;
    }

    /**
     * Checks the id against the call about to run and burns it, so the same draft cannot publish twice.
     *
     * The row is deleted before it is compared: an id that does not match what it was issued for is spent too,
     * because the only way to get a usable id is to show the matching draft again.
     *
     * @param string $userId Authenticated user publishing
     * @param string|null $approvalId Id returned by the draft call, null when the caller did not pass one
     * @param array<string, mixed> $binding Action, conversation and payload this call would publish
     * @throws ApprovalException When there is no usable approval for exactly this call
     */
    public function consume(string $userId, ?string $approvalId, array $binding): void {
        if ($approvalId === null || $approvalId === '') {
            throw new ApprovalException(Messages::approvalMissing());
        }

        $key = self::KEY_PREFIX . $approvalId;
        $stored = $this->config->getValueString(self::APP, $key);
        $this->config->deleteKey(self::APP, $key);

        $entry = json_decode($stored, true);
        if (!is_array($entry) || !isset($entry['userId'], $entry['binding'], $entry['expiresAt'])) {
            throw new ApprovalException(Messages::approvalInvalid());
        }
        if (!is_int($entry['expiresAt']) || $entry['expiresAt'] <= time()) {
            throw new ApprovalException(Messages::approvalInvalid());
        }
        // An id of another user is compared without a word about it: the answer is the same for every mismatch.
        if ($entry['userId'] !== $userId || !hash_equals((string)$entry['binding'], self::fingerprint($binding))) {
            throw new ApprovalException(Messages::approvalInvalid());
        }
    }

    /**
     * One line that identifies the content an approval was granted for, without keeping the content itself:
     * the message text never reaches the config, only its digest.
     *
     * Both sides build the binding through the same private helpers of DraftApproval, so the field order is the
     * same on both and the digest is comparable.
     *
     * @param array<string, mixed> $binding Action, conversation and payload
     * @return string Hex digest of the binding
     */
    public static function fingerprint(array $binding): string {
        return hash('sha256', (string)json_encode($binding, JSON_THROW_ON_ERROR));
    }

    /**
     * Drops approvals nobody will use anymore. Runs at most once per PRUNE_INTERVAL_SECONDS and inspects at most
     * PRUNE_SCAN_LIMIT keys, so a burst of drafts cannot turn every call into a full config scan.
     */
    private function pruneExpired(): void {
        $marker = self::KEY_PREFIX . 'last_prune';
        $lastPrune = (int)$this->config->getValueString(self::APP, $marker, '0');
        if ($lastPrune > time() - self::PRUNE_INTERVAL_SECONDS) {
            return;
        }
        $this->config->setValueString(self::APP, $marker, (string)time());

        $checked = 0;
        foreach ($this->config->getKeys(self::APP) as $key) {
            if (!str_starts_with($key, self::KEY_PREFIX) || $key === $marker) {
                continue;
            }
            $entry = json_decode($this->config->getValueString(self::APP, $key), true);
            if (!is_array($entry) || !isset($entry['expiresAt']) || !is_int($entry['expiresAt'])
                || $entry['expiresAt'] <= time()) {
                $this->config->deleteKey(self::APP, $key);
            }
            if (++$checked >= self::PRUNE_SCAN_LIMIT) {
                return;
            }
        }
    }
}