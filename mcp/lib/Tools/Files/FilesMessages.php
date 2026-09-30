<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

/**
 * Every user-facing string of the Files module: tool descriptions, parameter descriptions and failure
 * messages. Handlers hold no text literal; this is the single entry point for the later IL10N migration.
 */
final class FilesMessages {
    /** Folder in the user's root that receives a copy of every file before an edit writes it. */
    public const BACKUP_FOLDER = FileBackup::FOLDER;

    // ---------------------------------------------------------------- tool descriptions

    /** @return string description of files_list */
    public static function listTool(): string {
        return 'Lista arquivos e pastas de um diretório do Nextcloud do usuário.';
    }

    /** @return string description of files_search */
    public static function searchTool(): string {
        return 'Busca arquivos pelo nome na pasta do usuário no Nextcloud.';
    }

    /** @return string description of files_read */
    public static function readTool(): string {
        return 'Lê o texto de um arquivo do Nextcloud (txt/md direto; PDF/DOCX/ODT com extração de texto).';
    }

    /** @return string description of files_tree */
    public static function treeTool(): string {
        return 'Lista a árvore de arquivos de uma pasta, com o dono de cada item e quantas entradas cabem. '
            . 'Use para planejar uma reorganização sem uma chamada por pasta. Tem limite de profundidade e de '
            . 'entradas, e avisa quando cortou.';
    }

    /** @return string description of files_mkdir */
    public static function mkdirTool(): string {
        return 'Cria uma pasta, com os níveis intermediários quando faltam. Recusa se já existir algo no '
            . 'destino e nunca apaga o que estiver lá. Se o destino estiver fora da sua pasta pessoal, '
            . 'pergunte ao usuário antes e repita com confirm_shared.';
    }

    /** @return string description of files_copy */
    public static function copyTool(): string {
        return 'Copia um arquivo ou uma pasta para outro caminho, criando um nó novo com id novo. Nunca '
            . 'sobrescreve o destino. A cópia é um nó independente: não promete manter id, versões nem '
            . 'compartilhamentos da origem. Tem teto de ' . ReorganizationLimits::NODES . ' itens e '
            . ReorganizationLimits::BYTES . ' bytes, medidos antes de copiar. Se a origem ou o destino '
            . 'estiverem fora da sua pasta pessoal, pergunte ao usuário antes e repita com confirm_shared.';
    }

    /** @return string description of files_move */
    public static function moveTool(): string {
        return 'Move ou renomeia um arquivo ou uma pasta dentro da mesma área de arquivos, sem sobrescrever o '
            . 'destino. Reporta o id antes e depois e as contagens de versões e compartilhamentos quando os '
            . 'apps correspondentes estão ligados. Não move entre storages diferentes: lá o Nextcloud trata '
            . 'como cópia e o id muda. Se a origem ou o destino estiverem fora da sua pasta pessoal, pergunte '
            . 'ao usuário antes e repita com confirm_shared.';
    }

    /** @return string description of files_edit */
    public static function editTool(): string {
        return 'Substitui o conteúdo de um arquivo de texto existente e devolve o diff. Antes de gravar, exige '
            . 'versionamento ativo e cria uma cópia em "/MCP backups". Se o arquivo estiver fora da sua pasta '
            . 'pessoal, grave apenas depois de perguntar ao usuário e repetir a chamada com confirm_shared. '
            . 'Nunca cria, move ou exclui arquivos.';
    }

    /** @return string description of files_replace */
    public static function replaceTool(): string {
        return 'Substitui um trecho único de um arquivo de texto existente e devolve o diff. O trecho informado '
            . 'precisa aparecer exatamente uma vez. Antes de gravar, exige versionamento ativo e cria uma cópia '
            . 'em "/MCP backups". Se o arquivo estiver fora da sua pasta pessoal, grave apenas depois de '
            . 'perguntar ao usuário e repetir a chamada com confirm_shared.';
    }

    /** @return string description of files_checkout */
    public static function checkoutTool(): string {
        return 'Entrega links temporários de download e upload para editar um arquivo com as ferramentas '
            . 'locais (curl) sem passar o conteúdo pelo modelo. Os links valem de 5 a 15 minutos, são de uso '
            . 'único e presos a você, ao arquivo e ao ETag atual: se o arquivo mudar antes do upload, nada é '
            . 'gravado. O upload faz o mesmo backup de files_edit. Se o arquivo estiver fora da sua pasta '
            . 'pessoal, emita os links apenas depois de perguntar ao usuário e repetir com confirm_shared.';
    }

    /** @return string description of files_versions_list */
    public static function versionsListTool(): string {
        return 'Lista as versões do app Versions para um arquivo, da mais recente para a mais antiga.';
    }

    /** @return string description of files_version_read */
    public static function versionReadTool(): string {
        return 'Lê o texto de uma versão anterior de um arquivo, com o mesmo limite e a mesma extração de '
            . 'files_read.';
    }

    /** @return string description of files_version_restore */
    public static function versionRestoreTool(): string {
        return 'Restaura uma versão anterior de um arquivo, criando antes uma cópia do conteúdo atual em '
            . '"/MCP backups" e uma nova versão no Nextcloud. Exige confirm=true. Se o arquivo estiver fora da '
            . 'sua pasta pessoal, restaure apenas depois de perguntar ao usuário e repetir com confirm_shared.';
    }

    // ---------------------------------------------------------------- parameter descriptions

    /** @return string description of the path parameter */
    public static function path(): string {
        return 'Caminho do arquivo ou da pasta, ex.: /Documentos/relatorio.pdf';
    }

    /** @return string description of the etag parameter */
    public static function etag(): string {
        return 'ETag lido antes; se divergir, nada é gravado';
    }

    /** @return string description of the confirm_shared parameter */
    public static function confirmShared(): string {
        return 'Obrigatório só quando o arquivo está fora da sua pasta pessoal (compartilhado, pasta de time '
            . 'ou armazenamento externo). Só envie depois de confirmar com o usuário.';
    }

    /** @return string description of the content parameter of files_edit */
    public static function content(): string {
        return 'Novo conteúdo completo (UTF-8)';
    }

    /** @return string description of the old parameter of files_replace */
    public static function oldSnippet(): string {
        return 'Trecho a substituir; precisa aparecer exatamente uma vez no arquivo';
    }

    /** @return string description of the new parameter of files_replace */
    public static function newSnippet(): string {
        return 'Texto que substitui o trecho informado';
    }

    /** @return string description of the version parameter */
    public static function version(): string {
        return 'Identificador da versão, como devolvido por files_versions_list';
    }

    /** @return string description of the confirm parameter of files_version_restore */
    public static function confirm(): string {
        return 'Precisa ser true para confirmar a restauração';
    }

    // ---------------------------------------------------------------- failures

    /** @return string a path that is not a folder */
    public static function notAFolder(): string {
        return 'O caminho informado não é uma pasta.';
    }

    /** @return string a path that is not a file */
    public static function notAFile(): string {
        return 'O caminho informado não é um arquivo.';
    }

    /** @return string a non-text file */
    public static function notText(): string {
        return 'Somente arquivos de texto podem ser editados pelo MCP.';
    }

    /** @return string an edit inside the backup folder */
    public static function backupPath(): string {
        return 'Arquivos em "/' . self::BACKUP_FOLDER . '" não podem ser editados pelo MCP.';
    }

    /**
     * @param int $limit maximum accepted size in bytes
     * @return string a content over the edit limit
     */
    public static function editTooLarge(int $limit): string {
        return 'Conteúdo excede o limite de edição de ' . $limit . ' bytes.';
    }

    /**
     * @param string $backup path of the verified copy of the original
     * @return string a failed write, which always leaves the original recoverable
     */
    public static function writeFailed(string $backup): string {
        return 'Falha ao gravar o arquivo; o original foi preservado em ' . $backup . '.';
    }

    /** @return string versioning is off for the user */
    public static function versioningOff(): string {
        return 'Edição bloqueada: o versionamento de arquivos (files_versions) não está ativo.';
    }

    /** @return string the backup copy could not be created or verified */
    public static function backupFailed(): string {
        return 'Edição bloqueada: não foi possível criar a cópia de segurança do original.';
    }

    /** @return string the snippet given to files_replace does not occur */
    public static function snippetMissing(): string {
        return 'O trecho informado não aparece no arquivo.';
    }

    /** @return string the snippet given to files_replace is not text we can locate in the file */
    public static function snippetNotUtf8(): string {
        return 'O trecho informado não é UTF-8 válido; envie-o exatamente como aparece no arquivo.';
    }

    /** @return string something already sits where a folder would go */
    public static function crossStorage(): string {
        return 'Mover entre storages diferentes não é suportado nesta versão; mova dentro da mesma área de arquivos.';
    }

    /** @return string a folder moved into itself or into one of its own subfolders */
    public static function folderIntoItself(): string {
        return 'Não é possível mover uma pasta para dentro dela mesma.';
    }

    /**
     * @param int $limit ceiling in nodes
     * @return string a copy whose source is larger than the node ceiling
     */
    public static function copyTooManyNodes(int $limit): string {
        return 'A cópia passaria de ' . $limit . ' itens; nada foi copiado. Escolha uma pasta menor.';
    }

    /**
     * @param int $limit ceiling in bytes
     * @return string a copy whose source is larger than the byte ceiling
     */
    public static function copyTooLarge(int $limit): string {
        return 'A cópia passaria de ' . $limit . ' bytes; nada foi copiado. Escolha uma pasta menor.';
    }

    /** @return string something already sits where a folder would go */
    public static function destinationExists(): string {
        return 'Já existe um arquivo ou pasta neste destino.';
    }

    /**
     * @param int $occurrences how many times the snippet occurs
     * @return string the snippet given to files_replace is ambiguous
     */
    public static function snippetAmbiguous(int $occurrences): string {
        return 'O trecho informado aparece ' . $occurrences . ' vezes no arquivo; informe um trecho único.';
    }

    /** @return string the file could not be opened */
    public static function openFailed(): string {
        return 'Não foi possível abrir o arquivo.';
    }

    /** @return string the file could not be read */
    public static function readFailed(): string {
        return 'Não foi possível ler o arquivo.';
    }

    /** @return string an unsupported format for text extraction */
    public static function unsupportedFormat(): string {
        return 'Formato de arquivo não suportado para leitura de texto.';
    }

    /**
     * @param int $limit read limit in bytes
     * @return string a file over the read limit
     */
    public static function readTooLarge(int $limit): string {
        return 'Arquivo excede o limite de leitura de ' . $limit . ' bytes.';
    }

    /**
     * @param string $name file name
     * @return string a corrupt document whose text could not be extracted
     */
    public static function notExtracted(string $name): string {
        return '[não foi possível extrair o texto de ' . $name . ']';
    }

    /** @return string appends to text cut by files_read */
    public static function textTruncated(): string {
        return '[conteúdo truncado]';
    }

    // ---------------------------------------------------------------- versions

    /** @return string versioning is required and not active */
    public static function versionsOff(): string {
        return 'O versionamento de arquivos (files_versions) não está ativo nesta conta.';
    }

    /**
     * @param string $requested version identifier asked for
     * @return string no version with that identifier
     */
    public static function versionMissing(string $requested): string {
        return 'Versão não encontrada para este arquivo: ' . $requested . '.';
    }

    /** @return string the version could not be read */
    public static function versionUnreadable(): string {
        return 'Não foi possível ler o conteúdo desta versão.';
    }

    /** @return string the rollback did not report success */
    public static function versionRestoreFailed(): string {
        return 'Não foi possível restaurar esta versão.';
    }

    // ---------------------------------------------------------------- checkout

    /**
     * @param string $path normalized path refused for checkout
     * @return string a folder or a non-text file
     */
    public static function checkoutNotEditable(string $path): string {
        return 'Não é possível preparar a edição deste arquivo.';
    }

    /** @return string app service, eligibility or connection is off right now */
    public static function checkoutRevoked(): string {
        return 'A conexão com o MCP foi desligada ou o acesso a esta pasta não está mais concedido.';
    }

    /** @return string the checkout token is unknown */
    public static function tokenInvalid(): string {
        return 'Link de edição inválido.';
    }

    /** @return string the checkout token expired or was already used */
    public static function tokenSpent(): string {
        return 'Este link de edição expirou ou já foi usado. Faça o checkout de novo.';
    }

    /** @return string the checkout token is of the wrong kind for this route */
    public static function tokenWrongKind(): string {
        return 'Link de edição inválido.';
    }

    /**
     * @param int $limit effective limit in bytes
     * @return string an upload over the limit
     */
    public static function uploadTooLarge(int $limit): string {
        return 'Conteúdo excede o limite de upload de ' . $limit . ' bytes.';
    }

    /**
     * The ETag no longer matches. The token is already spent, so the agent cannot retry it: it has to read
     * the file again and make a new checkout, which is what this message asks for.
     *
     * @return string the client-safe reason for a 409 on the upload
     */
    public static function uploadConflict(): string {
        return 'O arquivo mudou depois do checkout; nada foi gravado. Releia o arquivo e faça um novo files_checkout antes de enviar de novo.';
    }

    /** @return string a multipart body, the shape `curl -F` produces instead of raw bytes */
    public static function uploadMultipart(): string {
        return 'Envie os bytes do arquivo como corpo bruto (curl -T), não como formulário multipart.';
    }

    /** @return string a body the upload route does not interpret */
    public static function uploadWrongType(): string {
        return 'Envie o corpo como application/octet-stream com os bytes do arquivo.';
    }

    /** @return string a body that carried nothing at all */
    public static function uploadEmpty(): string {
        return 'O corpo enviado está vazio; nada foi gravado.';
    }

    /**
     * @param string $backup path of the verified copy of the original, '' when the failure came before it
     * @return string a failed write, naming where the original still is
     */
    public static function uploadFailed(string $backup): string {
        return $backup === ''
            ? 'Falha ao gravar o arquivo.'
            : 'Falha ao gravar o arquivo; o original foi preservado em ' . $backup . '.';
    }
}
