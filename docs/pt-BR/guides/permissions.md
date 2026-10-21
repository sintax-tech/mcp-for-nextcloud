<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/guides/permissions.md).

# Permissões e administração

Abra **Administração → MCP for Cloud**. As cinco abas são **Visão geral**, **Acesso e permissões**, **Clientes e conexões**, **Proteção de dados** e **Avançado**. Os endereços (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) e a navegação pelo teclado abrem a seção correspondente.

## Habilitar o acesso

O serviço, a elegibilidade do usuário (**Pode conectar**) e a conexão pessoal começam desativados, inclusive para administradores. Ative o serviço, permita que o usuário se conecte e deixe que ele ative sua conexão em **Configurações pessoais → MCP for Cloud**. A autenticação sozinha não concede acesso: a conta, o serviço, a elegibilidade, a conexão, o aplicativo opcional, a permissão de operação e as permissões nativas do Nextcloud sobre o recurso devem permitir a solicitação.

A leitura começa permitida, exceto para os logs do servidor. As demais operações começam negadas. Compartilhar arquivos com pessoas/grupos (`files.share`) e criar links públicos (`files.link`) são permissões independentes. Listar compartilhamentos é uma operação de leitura.

Visão geral mostra o endpoint, a versão, o número de conexões e o limite de upload de checkout. Esse limite é editado em MiB e não pode exceder `post_max_size` do PHP. Avançado contém os papéis e a privacidade dos logs e OCR. Proteção de dados contém as etiquetas de exclusão; consulte [proteção de arquivos](file-protection.md).

## Escolhas individuais e herança de grupos

Acesso e permissões oferece níveis por módulo e uma visualização **Operações** para permissões individuais. A busca usa nome, ID ou e-mail; o e-mail não é exibido. Os filtros de grupo e elegibilidade/conexão são aplicados no servidor, com 50 usuários por página. Contas desabilitadas não podem ser editadas, e aplicativos ou papéis de logs indisponíveis continuam indisponíveis sem apagar as escolhas salvas.

A resolução segue esta ordem:

1. Prevalece **Permitir** ou **Negar** explícito do indivíduo.
2. Sem escolha individual, qualquer concessão de um grupo confiável permite a operação.
3. Caso contrário, aplica-se o padrão. Grupos concedem acesso; não o negam nem restringem.

Somente grupos do backend de banco de dados próprio do Nextcloud podem conceder permissões. LDAP, SAML, outros backends de aplicativos e identidades de backend ambíguas não podem fornecer herança segura. Conceda permissões individuais aos seus usuários. Um grupo recriado ou incerto não recupera silenciosamente sua autoridade anterior: o editor explica o estado e oferece confirmação da política atual quando aplicável.

Em **Permissões de grupo**, selecione um grupo, edite as operações e visualize antes de salvar. Contagens de membros e estimativas de encerramento de sessão são informações de planejamento, não uma decisão de autorização por membro. Remover **Pode conectar** encerra as sessões dos membros que realmente perdem a elegibilidade. Grupos grandes podem levar tempo para processar; esta integração não promete uma nova garantia de desempenho.

**Herdar** e as ações de redefinir linha/seleção removem as exceções individuais. A prévia identifica escolhas individuais, grupos que concedem permissões, padrões e resultados desconhecidos. Um grupo ou política ilegível é **Desconhecido**, nunca uma permissão implícita nem um motivo para apagar credenciais. Solicitações que dependem dessa incerteza falham temporariamente; ferramentas com autorização desconhecida são omitidas da descoberta, enquanto as permitidas independentemente permanecem disponíveis.

## Salvar, conflitos e desfazer

As gravações de política incluem a revisão exibida na interface. Uma prévia desatualizada ou alteração simultânea produz um conflito e uma recarga; o navegador não repete silenciosamente a gravação. Uma resposta incerta não é apresentada como salvamento bem-sucedido. Rascunhos de grupos, recursos e clientes continuam sendo rascunhos até a respectiva ação Salvar.

Solicitações de redefinição/lote se limitam a 50 usuários e 2500 células. A prévia vale 120 segundos; recibos do servidor expiram após 600 segundos. A interface oferece **Desfazer** por 10 segundos enquanto o controle estiver disponível e pausa o temporizador com o ponteiro sobre ele ou o foco do teclado. Desfazer exige a revisão salva e um recibo válido: não pode sobrescrever alterações posteriores. Restaurar uma permissão não restaura credenciais revogadas nem inicia sessões novamente.

## Restrições de recursos e inspeção

O administrador pode restringir os recursos de **Files**, **Calendar** e **Tasks** de um usuário pelo painel de acesso. Cada módulo é independente: **Todos os recursos** significa sem restrição; **Somente selecionados**, com seleção vazia, não permite nenhum. O limite é de 200 recursos por usuário e módulo. As restrições apenas reduzem as concessões e permissões nativas existentes; alterá-las não revoga a conexão. Aplicam-se à próxima operação.

Notes, Contacts, Deck e Talk não têm restrição de recursos por módulo em 1.1.0. Referências estruturadas a arquivos e operações com anexos podem exigir verificações do escopo de Files; consulte [proteção de arquivos](file-protection.md). Restringir uma pasta não restringe genericamente tudo que outros módulos armazenam abaixo dela.

O inspetor, exclusivo de administradores, informa **Permitido**, **Bloqueado** ou **Desconhecido**, a verificação que bloqueia e a origem da permissão. Pode explicar um caminho excluído ao administrador; erros MCP mantêm recusas genéricas. Consultas e alterações podem gerar mensagens de auditoria de nível informativo sem nomes nem caminhos de recursos. Dependem do nível de log do servidor e não garantem uma trilha de auditoria.

## Clientes e configurações pessoais

Gerencie os hosts de clientes publicados, o cliente nativo e os clientes personalizados registrados em **Clientes e conexões**; consulte [OAuth](../reference/oauth.md). APIs administrativas exigem administrador e proteção CSRF do Nextcloud. APIs pessoais de conexão/escopo obtêm a identidade da sessão e expõem apenas o estado daquele usuário. Respostas nunca contêm hashes de tokens.

As configurações pessoais mostram o estado da conexão, os clientes conectados, seus controles de revogação e as restrições de recursos somente leitura do usuário. Para HTTP Basic, crie uma senha de aplicativo em **Configurações pessoais → Segurança**. MCP não pede nem armazena a senha principal. Desconectar MCP não exclui essa senha de aplicativo do Nextcloud; consulte [revogar o acesso](clients.md).

Permanece um risco residual de gravação: uma recusa genérica pode distinguir um destino indisponível de outro onde a criação teria sucesso, embora não revele o motivo.

[Guias de operação](README.md).

[English](../../en/guides/permissions.md) · [Español](../../es/guides/permissions.md) · [Català](../../ca/guides/permissions.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
