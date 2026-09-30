<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/** A tool failure whose message is safe to show to the MCP client (no paths, content or internals). */
class ToolFailure extends \RuntimeException {
    /** Missing node, or node outside the scope the tool may see. */
    public const NOT_FOUND = 'Recurso não encontrado no Nextcloud.';
    /** Nextcloud denied the operation (ACL, share permissions). */
    public const FORBIDDEN = 'Sem acesso a este recurso no Nextcloud.';
    /** The node is locked by another operation. */
    public const LOCKED = 'Recurso bloqueado por outra operação; tente novamente.';
    /** The caller's etag no longer matches. */
    public const CONFLICT = 'O recurso foi alterado desde a última leitura (etag divergente); nada foi gravado.';
}
