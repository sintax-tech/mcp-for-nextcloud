<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

/**
 * Every user-facing string of the Deck module.
 *
 * Tool descriptions, parameter descriptions and error messages live here as constants so the app
 * can later be translated through IL10N without touching handlers. The values are Portuguese for
 * now; the keys and the code around them are language-neutral.
 */
final class DeckMessages {
	/* Tool descriptions, exposed to MCP clients in tools/list. */
	public const TOOL_LIST_BOARDS_DESCRIPTION
		= 'Lista os boards do Deck compartilhados com você, sem os arquivados.';
	public const TOOL_LIST_STACKS_DESCRIPTION
		= 'Lista as listas (stacks) de um board do Deck, com a quantidade de cards de cada uma.';
	public const TOOL_LIST_CARDS_DESCRIPTION
		= 'Lista os cards ativos de uma lista (stack) do Deck, com paginação.';
	public const TOOL_READ_CARD_DESCRIPTION
		= 'Lê um card do Deck com descrição, datas e contadores.';
	public const TOOL_CREATE_CARD_DESCRIPTION
		= 'Cria um card do Deck no fim de uma lista (stack). O card fica com você como responsável.';
	public const TOOL_EDIT_CARD_DESCRIPTION
		= 'Edita título, descrição e data prevista de um card do Deck. Use lastModified para não sobrescrever uma edição concorrente.';
	public const TOOL_MOVE_CARD_DESCRIPTION
		= 'Move um card do Deck para outra lista (stack), inclusive de outro board. Sem order, o card vai para o fim.';
	public const TOOL_DELETE_CARD_DESCRIPTION
		= 'Exclui um card do Deck, do mesmo modo que a interface web: a exclusão é reversível pelo Deck. Exige confirm.';

	/* Parameter descriptions. */
	public const PARAM_BOARD_ID = 'Id do board do Deck.';
	public const PARAM_STACK_ID = 'Id da lista (stack) do Deck.';
	public const PARAM_CARD_ID = 'Id do card do Deck.';
	public const PARAM_TITLE = 'Título do card.';
	public const PARAM_DESCRIPTION = 'Descrição do card, em texto simples.';
	public const PARAM_DUEDATE = 'Data prevista no formato AAAA-MM-DD, ou null para remover.';
	public const PARAM_LIMIT = 'Quantidade máxima de cards a devolver.';
	public const PARAM_OFFSET = 'Quantidade de cards a ignorar, para paginar.';
	public const PARAM_ORDER = 'Posição do card na lista, de 0 a 99999.';
	public const PARAM_LAST_MODIFIED = 'lastModified do card no momento da leitura; se tiver mudado, a edição é recusada.';
	public const PARAM_CONFIRM = 'Precisa ser true para confirmar a exclusão.';
	public const PARAM_CONFIRM_SHARED = 'Marque como true apenas depois que o usuário confirmar a alteração em um quadro de outra pessoa.';

	/* Shared-resource confirmation (confirm_shared). */
	/** Sentence appended to the description of every write tool of the module. */
	public const CONFIRM_SHARED_DESCRIPTION
		= ' Se o recurso for de outra pessoa, o agente DEVE perguntar ao usuário antes de enviar confirm_shared: true.';
	/** Message returned, without error, instead of writing on somebody else\'s board. */
	public const SHARED_CONFIRMATION
		= "O quadro '%s' pertence a %s e é compartilhado com você. Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.";

	/* Error messages. */
	/** Shared by "not found" and "no permission": the Deck answers both with the same exception. */
	public const ERROR_NOT_FOUND_OR_FORBIDDEN = 'Card, lista ou quadro não encontrado ou sem permissão.';
	/** Board or card archived. */
	public const ERROR_NOT_ALLOWED = 'Operação não permitida neste board ou card do Deck.';
	/** The Deck validator rejected the payload. */
	public const ERROR_INVALID = 'Dados inválidos para o Deck.';
	/** The card changed between the read and the write. */
	public const ERROR_CONFLICT = 'O item do Deck mudou durante a operação. Tente de novo.';
	/** Fallback for anything unexpected. */
	public const ERROR_GENERIC = 'Não foi possível concluir a operação no Deck.';

	/** Raised when deck_edit_card is called without a single editable field. */
	public const ERROR_NO_FIELD_TO_EDIT = 'Informe ao menos um campo para editar: title, description ou duedate.';

	/** Raised when a date is not a real calendar date in AAAA-MM-DD form. */
	public const ERROR_INVALID_DUEDATE = 'A data prevista deve ser uma data válida no formato AAAA-MM-DD.';

	/** Raised when a description is longer than the module accepts. */
	public const ERROR_DESCRIPTION_TOO_LONG = 'A descrição do card excede o tamanho máximo de 100000 caracteres.';

	/** Raised when the title is empty once trimmed. */
	public const ERROR_TITLE_REQUIRED = 'O título do card não pode ficar vazio.';

	/** Raised for a tool name this module does not serve. */
	public const ERROR_UNKNOWN_TOOL = 'Ferramenta do Deck desconhecida.';

	/** Raised when the Deck app is not available to the caller. */
	public const ERROR_DECK_APP_UNAVAILABLE = 'O app Deck não está disponível para este usuário.';
}
