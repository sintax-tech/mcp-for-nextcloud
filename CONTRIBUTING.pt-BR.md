<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Como contribuir com o MCP for Cloud

[English](CONTRIBUTING.md) · [Português (Brasil)](CONTRIBUTING.pt-BR.md) · [Español](CONTRIBUTING.es.md) · [Deutsch](CONTRIBUTING.de.md)

Obrigado por contribuir. Correções, traduções, documentação e novas funcionalidades são bem-vindas. Este guia explica as decisões de produto por trás do código para que você possa avaliar uma ideia antes de investir em uma implementação grande.

## O que estamos construindo

O MCP for Cloud é um app PHP nativo do Nextcloud que permite a uma pessoa autenticada usar o Nextcloud por um cliente MCP. Ele roda dentro da instância, com a identidade e as permissões dessa pessoa. Nossa prioridade é oferecer acesso útil aos apps existentes, com controles claros, efeitos previsíveis e uma instalação que funcione pela interface do Nextcloud.

## Nossa filosofia de desenvolvimento

### Instalação simples e implementação nativa

O app distribuído deve funcionar sem comandos obrigatórios no terminal, SQL manual, edição de arquivos de configuração ou um serviço separado. Diagnósticos opcionais devem continuar opcionais. Use as migrations e configurações do Nextcloud para instalação e ajustes.

Use PHP nativo e as APIs do Nextcloud. Mantenha as dependências de produção pequenas e justificadas. O frontend usa JavaScript simples, com bundles montados pelo builder PHP existente. Não introduza um pré-requisito de build ou testes com Node/npm, outro framework ou serviço externo sem acordo prévio. O Composer é usado no desenvolvimento e no empacotamento; o usuário instala um pacote que já contém as dependências de execução.

### Respeitar a política MCP e as permissões nativas

Toda operação usa a identidade do ator autenticado e deve cumprir os grants MCP, restrições de grupo, regras de Guests, escopo de recursos, verificações de visibilidade e ACLs nativas aplicáveis. Um grant não substitui uma permissão nativa. Política ou serviço indisponível deve resultar em recusa controlada, sem ampliar o acesso.

Preserve as decisões de acesso salvas nas atualizações. Escritas novas e leituras sensíveis recém-introduzidas começam negadas. Alguns grants de leitura já existentes são permitidos por padrão; isso não autoriza expor automaticamente toda leitura nova. Uma operação nova ou ampliação de um grant existente exige análise explícita de acesso. Por exemplo, poder editar um card não estabelece automaticamente permissão para apagar etiquetas de um board.

### Explicar as escritas antes de executá-las

Uma escrita sem confirmação devolve um plano com o destino, mudanças, pessoas afetadas e limites de recuperação. Ela não deve executar a escrita solicitada nem criar antecipadamente seu backup, pasta ou token de checkout. A execução exige `confirm: true`, além de `confirm_shared` e `plan_state` quando o fluxo exigir. Revalide acesso, identidade e estado relevante antes do efeito.

A confirmação é conversacional: o servidor não consegue provar que uma pessoa disse sim. O `plan_state` vincula uma chamada ao estado mostrado no plano; ele não concede permissão nem representa uma aprovação armazenada. Preserve as verificações de concorrência de cada fluxo. Documente efeitos da inicialização nativa onde existirem; não prometa que todo preview é livre de qualquer efeito da plataforma.

### Preservar o comportamento nativo e proteger os dados

Use o serviço nativo ou pipeline DAV adequado à operação, preservando as verificações de permissão, eventos, versões, lixeira e agendamento necessários. Não altere tabelas de outro app nem contorne seu fluxo para fazer uma funcionalidade parecer que funciona. A persistência própria do MCP continua usando os recursos de banco e migrations do Nextcloud.

Não apague arquivos do usuário como limpeza, rollback ou retenção. A exclusão de arquivos é um fluxo explícito e restrito pela `files_delete`; a `notes_delete` tem regras próprias. Preserve o alcance limitado da exceção existente de desfazer pastas vazias. Nunca elimine backups ou versões silenciosamente, nem descreva uma tentativa nativa de envio à lixeira como recuperação garantida.

Uma leitura não deve marcar mensagens como lidas, expirar status ou executar manutenção inesperada. Confira os efeitos reais da chamada nativa. Se não houver caminho seguro, recuse a capacidade e explique a limitação, em vez de criar um desvio.

### Preservar a privacidade entre apps e comunicar incerteza

Links, anexos, resultados de busca e referências devem respeitar as proteções do recurso que expõem. Passar por outro módulo não pode revelar um recurso oculto, seu nome ou sua existência. Devolva apenas os dados necessários à operação e mantenha segredos e conteúdo dos recursos fora dos diagnósticos.

Limite varreduras e respostas; indique resultados parciais e continuação explicitamente. Diferencie sucesso observado, conclusão parcial e resultado incerto. Não prometa atomicidade, entrega de convites ou recuperação que a API nativa não consiga comprovar. Não acrescente repetição automática ou compensação destrutiva após uma possível escrita sem um desenho revisado separadamente.

### Mudanças focadas e controles familiares

Use classes focadas e as fronteiras de módulos existentes. Preserve nomes de ferramentas, schemas, ordem e identidades persistidas, salvo quando a mudança exigir explicitamente uma atualização de contrato revisada. Código legado preservado e exceções de testes não são modelos para código novo.

Preserve o layout da tela de permissões, os três estados distinguíveis sem cor, as mudanças em lote explícitas, contagens e Desfazer. Uma reformulação visual precisa de acordo separado.

## Antes de uma contribuição maior

Descreva o problema do usuário, comportamento esperado, branch de destino, grants, API nativa e versões suportadas. Explique efeitos compartilhados ou destrutivos e casos de falha. Alinhe com os mantenedores uma funcionalidade grande, mudança de arquitetura, nova dependência ou ampliação de acesso antes de implementar. Correções pequenas e melhorias na documentação podem ir diretamente para uma PR focada.

A compatibilidade pertence à release de destino. A 1.3.5 publicada documenta NC32–35. Em trabalho para a 1.4.0, garantias e testes novos cobrem apenas NC34/35; o código existente de NC32/33 e a faixa de instalação declarada permanecem. Não acrescente fallbacks ou fixtures específicos de NC32/33, nem trate a faixa de instalação como promessa de cobertura das novidades.

## Implementação e evidências

Leia [Arquitetura](docs/en/architecture.md), [Adicionar um módulo ou ferramenta](docs/en/adding-a-module.md), [Testes](docs/en/testing.md) e [Empacotamento](docs/en/packaging.md). PHP novo ou reescrito usa tabs, `strict_types`, avisos SPDX apropriados e PHPDoc completo. Preserve a atribuição. Código, comentários, mensagens de commit e textos-fonte ficam em inglês; mantenha traduções suportadas equivalentes para mudanças visíveis ao usuário.

Para mudanças de código, execute testes focais e afetados, Architecture, MapCoverageTest, a suíte Contract e REUSE em uma cópia limpa. Testes novos devem entrar no inventário e nas partições do CI existente; cobertura de integração nativa entra nas células de integração existentes. Mocks sozinhos não comprovam comportamento nativo. As suítes completas rodam na validação de release existente no GitHub, em vez de serem repetidas localmente a cada patch. Mudanças só em Markdown precisam de revisão do conteúdo e dos links, não de testes do app ou CI.

Na PR, informe o que mudou, impacto de acesso e compatibilidade, comandos e resultados, e o que não foi testado. Revise conscientemente mudanças nos goldens; não regenere expectativas para esconder regressões. Alterações na integração nativa exigem nova validação de integração no CI.

## Revisão, crédito e release

Uma implementação funcional ainda pode precisar de ajustes para seguir esses princípios. Contribuições pequenas e adequadas normalmente entram por merge normal, preservando a autoria; ajustes dos mantenedores ficam separados. Contribuições grandes ou que precisem de outro desenho podem ser adaptadas antes da integração, com crédito à contribuição original e distinção das nossas alterações.

Integrar uma contribuição e publicar uma release são etapas separadas. Preserve a licença e os avisos de atribuição do projeto. Veja a [licença](LICENSES/AGPL-3.0-or-later.txt), o [NOTICE](NOTICE) e a [referência técnica](mcp/README.md).
