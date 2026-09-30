<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Node;

/**
 * Shared-write guard shared by every module that changes a file the user does not solely own. A write
 * outside the personal scope needs the caller to pass confirm_shared, which the agent may only send
 * after asking the user. Without the flag nothing is written and a NON-error result asks for the
 * confirmation; a node Nextcloud refuses to update is denied whatever the confirmation says.
 *
 * The payload shape and the message wording live here so that Files, Notes, Deck and Calendar return
 * byte-identical JSON.
 */
final class SharedWriteGuard {
    /** Suffix shared by every confirmation message, in every module. */
    private const ADVICE = 'Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.';

    public function __construct(private NodeAccessInfo $access) {}

    /**
     * @param Node $node node about to be changed
     * @param string $viewerUid authenticated user
     * @param string $resource how the tool names the node (a path in Files, an id elsewhere)
     * @param bool $confirmed whether the caller passed confirm_shared
     * @return array<string, mixed>|null the confirmation payload, or null when the write may proceed
     * @throws ToolFailure when Nextcloud denies the update, with or without the confirmation
     */
    public function guard(Node $node, string $viewerUid, string $resource, bool $confirmed): ?array {
        $info = $this->access->describe($node, $viewerUid);
        if ($info['permissions']['update'] === false) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        if ($confirmed || $info['scope'] === NodeAccessInfo::PERSONAL) {
            return null;
        }
        return $this->request($info, $resource);
    }

    /**
     * Builds the non-error payload for a scope that needs the user's confirmation.
     *
     * @param array<string, mixed> $info result of NodeAccessInfo::describe()
     * @param string $resource how the tool names the node
     * @return array{requiresConfirmation:bool, scope:string, owner:string, ownerDisplayName:string, teamFolder?:string, sharedBy?:string, resource:string, message:string}
     */
    public function request(array $info, string $resource): array {
        $payload = [
            'requiresConfirmation' => true,
            'scope' => $info['scope'],
            'owner' => $info['owner'],
            'ownerDisplayName' => $info['ownerDisplayName'],
        ];
        if (isset($info['teamFolder'])) {
            $payload['teamFolder'] = $info['teamFolder'];
        }
        if (isset($info['sharedBy'])) {
            $payload['sharedBy'] = $info['sharedBy'];
        }
        return $payload + [
            'resource' => $resource,
            'message' => $this->message($info) . ' ' . self::ADVICE,
        ];
    }

    /**
     * @param array<string, mixed> $info result of NodeAccessInfo::describe()
     * @return string the sentence naming why the change reaches other people
     */
    private function message(array $info): string {
        return match ($info['scope']) {
            NodeAccessInfo::TEAM => sprintf('Este arquivo está na pasta de time "%s" (Team Folder).', (string)$info['teamFolder']),
            NodeAccessInfo::SHARED => sprintf('Este arquivo foi compartilhado por %s.', (string)$info['sharedBy']),
            default => 'Este arquivo está em um armazenamento externo.',
        };
    }
}
