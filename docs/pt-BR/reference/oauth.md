<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/oauth.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/reference/oauth.md).

# OAuth

Os metadados do recurso protegido ficam em `/apps/mcp/.well-known/oauth-protected-resource`. O desafio `401` identifica esse documento. Os metadados do servidor de autorização ficam em `/apps/mcp/.well-known/openid-configuration` e `/apps/mcp/.well-known/oauth-authorization-server`, com emissor `https://<instance>/apps/mcp`.

`oauth/authorize` oferece consentimento no navegador; `oauth/token` troca códigos de autorização com PKCE S256 e rotaciona tokens de renovação. Tokens de acesso duram uma hora e os de renovação, 30 dias. O banco de dados armazena hashes de credenciais, não os valores em texto puro. É autenticação de cliente público (`none`); um ID de cliente é um identificador, não um segredo nem uma concessão de permissões.

## Clientes publicados, nativos e registrados

Administradores gerenciam clientes em **Administração → MCP for Cloud → Clientes e conexões**. Salvar exige administrador, proteção CSRF do Nextcloud e a revisão atual da política.

| Tipo de cliente | Identidade e regras de redirecionamento |
| --- | --- |
| Documento de identidade de cliente publicado (CIMD) | Documento HTTPS em um host exato permitido. Os padrões são `claude.ai,chatgpt.com`. Redirecionamentos devem corresponder ao documento validado. |
| Cliente nativo incluído | `nextcloud-mcp-native`, desativado por padrão. Seus callbacks HTTP de loopback registrados permitem portas variáveis para programas locais. |
| Cliente personalizado registrado pelo administrador | ID gerado, nome de exibição, estado habilitado e lista explícita de URIs de redirecionamento. A correspondência é exata, incluindo a porta em HTTP de loopback. |

A lista permitida aceita hosts sem esquema, porta, caminho nem curingas; pelo menos um deve permanecer. Salvar substitui a lista. Remover um host bloqueia a admissão mesmo com seu documento em cache. Os documentos são baixados por HTTPS sem redirecionamentos, com limite de 64 KiB e cache de uma hora.

São permitidos no máximo 20 clientes personalizados, nomes de até 64 caracteres e até 20 URIs de redirecionamento por cliente. Nomes não podem estar vazios e devem ser únicos sem diferenciar maiúsculas. Cada URI admite até 2000 bytes, usa HTTPS ou HTTP em loopback e não contém informações de usuário, fragmento, curinga, espaços em branco, caracteres de controle ASCII nem barra invertida. Uma lista de redirecionamentos vazia não pode autorizar um cliente. Copie o ID gerado para a configuração e registre o callback exato; clientes personalizados não herdam a exceção de porta variável do cliente nativo.

Após a migração, a tabela de clientes gerenciados é a fonte autoritativa. Desabilitar/remover um cliente gerenciado ou remover um de seus URIs de redirecionamento retira as concessões existentes e avança sua geração de cliente. Reabilitá-lo não reativa uma página de consentimento, código ou token anterior. Uma falha na leitura dessa tabela recusa temporariamente a autorização em vez de mostrar consentimento supondo permissão.

## Conectar pelo ChatGPT e Gemini CLI (OAuth)

Consulte os exemplos no [guia de clientes](../guides/clients.md). ChatGPT usa sua identidade publicada em `chatgpt.com`. Gemini CLI pode usar o cliente nativo incluído após um administrador habilitá-lo. Os callbacks nativos são `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` e `http://[::1]/oauth/callback`; a porta de loopback selecionada pode variar.

Todo redirecionamento validado inclui o parâmetro de emissor `iss`, inclusive recusas e erros redirecionáveis. Erros anteriores à validação do redirecionamento ficam em uma página local. Interfaces e compatibilidade com clientes hospedados ainda exigem revisão com clientes reais; um teste local do protocolo não certifica sua interface atual.

## Revogação e incerteza

Cada solicitação verifica novamente identidade, serviço, elegibilidade, conexão pessoal, aplicativo/papel de logs, permissão de operação, restrição de recursos, etiquetas de exclusão e ACLs nativas quando aplicável. Uma permissão de grupo não ignora essas verificações. Política desconhecida significa indisponibilidade temporária, não permissão implícita nem revogação destrutiva.

Desconexão pessoal, perda de elegibilidade, desligamento do serviço e desabilitação/exclusão da conta invalidam credenciais OAuth por gerações por usuário ou uma época global. A invalidação é confirmada com a alteração da política; uma limpeza persistente remove depois as linhas obsoletas e deve preservar credenciais posteriores. Restituir acesso ou reabilitar um cliente não revive credenciais anteriores. A detecção de reutilização de tokens de renovação continua aplicável à linhagem do proprietário/cliente.

Alterar o escopo de recursos não revoga OAuth: a próxima operação avalia a nova restrição. Senhas de aplicativo HTTP Basic pertencem ao Nextcloud, não a este armazenamento OAuth. Desconectar MCP bloqueia seu uso em MCP, mas excluí-las exige os controles de Segurança do Nextcloud. Desligar o serviço preserva a preferência de conexão pessoal; a recuperação também pode exigir desconexão explícita e revisão de senhas de aplicativo. Consulte [instalação e recuperação](../installation.md).

## Propriedades de segurança

- O consentimento tem proteção CSRF e não pode ser exibido em frames. Login, elegibilidade, conexão, permissões, consentimento e PKCE continuam obrigatórios para clientes nativos/personalizados.
- Verificações de geração e invalidação impedem que credenciais retiradas recuperem validade após restituir acesso ou recriar identidade/cliente. Estado incerto do banco de dados ou da política produz uma falha que permite nova tentativa, sem suposições.
- Alterações administrativas de clientes validam toda a solicitação antes de gravar. Conflitos recarregam o estado em vez de repetir silenciosamente uma mutação.
- Erros inesperados apresentados ao cliente são genéricos. O diagnóstico do aplicativo não registra credenciais em texto puro nem conteúdo de arquivos.
- Comunique vulnerabilidades aos mantenedores em particular. Não desative a proteção contra força bruta nem permita uma faixa de rede não verificada para contornar uma conexão que falhou; investigue a recusa e os logs do servidor.

[Guias de operação](../guides/README.md).

[English](../../en/reference/oauth.md) · [Español](../../es/reference/oauth.md) · [Català](../../ca/reference/oauth.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/reference/oauth.md)
