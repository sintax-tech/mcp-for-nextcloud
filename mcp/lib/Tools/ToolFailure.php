<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/** A tool failure whose message is safe to show to the MCP client (no paths, content or internals). */
class ToolFailure extends \RuntimeException {
    public const NOT_FOUND = 'Recurso não encontrado no Nextcloud.';
    public const FORBIDDEN = 'Sem acesso a este recurso no Nextcloud.';
    public const LOCKED = 'Recurso bloqueado por outra operação; tente novamente.';
    public const CONFLICT = 'O recurso foi alterado desde a última leitura (etag divergente); nada foi gravado.';
}
