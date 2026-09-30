<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

/**
 * The MCP prompts this server offers, in the shape both protocol eras expect.
 *
 * A prompt is a workflow the client can pull on demand. `edit_nextcloud_file_locally` teaches the
 * checkout → download → edit locally → upload round trip to an agent that has a disk and a shell, and
 * names the in-context tools to fall back on when it has none.
 */
final class PromptCatalog {
    /** Name of the only prompt, as the client sees it. */
    public const EDIT_LOCALLY = 'edit_nextcloud_file_locally';
    /** Grant the prompt's workflow needs; without it the tools it names are not listed either. */
    private const MODULE = 'files';
    private const OPERATION = 'edit';

    /**
     * @return list<array{name:string, title:string, description:string, arguments:list<array{name:string, description:string, required:bool}>}>
     *   the prompts the caller may use now
     */
    public function list(GrantPolicy $policy, string $userId): array {
        if (!$policy->granted($userId, self::MODULE, self::OPERATION)) {
            return [];
        }
        return [[
            'name' => self::EDIT_LOCALLY,
            'title' => 'Editar um arquivo do Nextcloud localmente',
            'description' => 'Ensina a baixar um arquivo do Nextcloud, editá-lo com as ferramentas locais e '
                . 'devolvê-lo, sem passar o conteúdo pelo modelo.',
            'arguments' => [],
        ]];
    }

    /**
     * @param string $name prompt name asked for
     * @param GrantPolicy $policy grants of the caller
     * @param string $userId authenticated user
     * @return array{description:string, messages:list<array{role:string, content:array{type:string, text:string}}>}|null
     *   the rendered prompt, or null when the name is unknown or not granted
     */
    public function get(string $name, GrantPolicy $policy, string $userId): ?array {
        if ($name !== self::EDIT_LOCALLY || $this->list($policy, $userId) === []) {
            return null;
        }
        return [
            'description' => 'Fluxo de edição local de um arquivo do Nextcloud.',
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => self::text()]]],
        ];
    }

    /** @return string the workflow handed to the model, in Portuguese like every other user-facing string */
    private static function text(): string {
        return implode("\n", [
            'Para editar um arquivo do Nextcloud sem que o conteúdo passe pelo modelo, use o fluxo de checkout:',
            '',
            '1. Chame files_checkout com o caminho. Ele devolve download_url, upload_url, o ETag atual e expires_at.',
            '2. Baixe o arquivo com a sua shell: curl -sS -o /tmp/arquivo "$DOWNLOAD_URL".',
            '   O link vale poucos minutos e é de uso único: se falhar, faça o checkout de novo.',
            '3. Edite /tmp/arquivo com as suas ferramentas locais. O usuário vê o diff; não escreva nada fora do arquivo.',
            '4. Devolva o arquivo com curl -sS -T /tmp/arquivo -X PUT "$UPLOAD_URL".',
            '   O servidor confere o token e o ETag, faz o backup em "/MCP backups", grava e devolve o novo ETag.',
            '   Se o arquivo mudou no meio, a resposta é um conflito e nada foi gravado: releia e repita.',
            '',
            'Se você não tem shell para baixar e enviar o arquivo, não use o checkout: aplique a mudança com',
            'files_replace (troca um trecho único e devolve o diff) ou com files_edit (conteúdo completo).',
            'files_edit e files_replace devolvem o diff unificado do antes e do depois, truncado em 20 000 caracteres.',
            '',
            'Escopo compartilhado: qualquer resposta com "requiresConfirmation": true significa que o arquivo está',
            'fora da sua pasta pessoal (compartilhado, pasta de time ou armazenamento externo). Nesse caso NÃO repita',
            'a chamada de imediato: mostre ao usuário a mensagem recebida, pergunte se quer continuar e só então',
            'pergunte de novo com confirm_shared: true. Sem essa confirmação nada é gravado.',
            '',
            'Para desfazer, use files_versions_list e files_version_restore; a restauração também faz backup antes.',
        ]);
    }
}
