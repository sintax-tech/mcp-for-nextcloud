# App MCP para Nextcloud — Sprint 01

Versão de teste interno para **Nextcloud 33**. Expõe MCP `2025-06-18` via Streamable HTTP sem sessão e sem SSE, na rota do próprio app:

- `https://<instância>/apps/mcp/` com URLs limpas;
- `https://<instância>/index.php/apps/mcp/` sem URLs limpas.

A URL exata da instância aparece nas páginas de administração e pessoal. Nesta versão existe apenas a tool `mcp_status`, que informa se o endpoint está disponível e não lê dados do usuário. Os grants de Files, Notes, Deck, Calendar e Talk já são configuráveis e persistidos, mas nenhuma tool de dados está disponível.

## Empacotar

No diretório `mcp/`:

```sh
./scripts/package.sh
```

Gera `build/mcp-<versão>.tar.gz` com raiz `mcp/` contendo apenas `appinfo/`, `lib/`, `templates/` e este README. O app não tem dependências de runtime além do Nextcloud; `composer.json` e `vendor/` servem só aos testes.

## Instalar via SSH

Substitua `<servidor>`, `<nextcloud>` (raiz da instalação), `<apps>` (diretório de apps gravável, por exemplo `custom_apps` ou `apps`, conforme `apps_paths` em `config/config.php`) e `<www>` (usuário do servidor web, por exemplo `www-data`).

```sh
scp build/mcp-0.1.0.tar.gz <servidor>:/tmp/
ssh <servidor>
sudo tar -xzf /tmp/mcp-0.1.0.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

Em Docker, rode os comandos `occ` dentro do container como o usuário do servidor web. Se o PHP usa OPcache com `validate_timestamps=0`, reinicie o PHP-FPM após copiar os arquivos.

Para atualizar: `occ app:disable mcp`, substituir `<apps>/mcp` pelo novo conteúdo e `occ app:enable mcp`. Para remover: `occ app:remove mcp`.

## Configurar

1. **Administração → Configurações adicionais → MCP**: ativar o serviço, carregar o ID do usuário e clicar em *Allow connection*. A matriz de grants por módulo e operação fica na mesma tela. Serviço, elegibilidade e conexão pessoal começam desligados para todos, inclusive administradores. Leitura começa permitida e escrita, exclusão e transferência começam negadas.
2. **Configurações pessoais → Informações pessoais → MCP connection**: o próprio usuário clica em *Connect*.
3. Em **Configurações pessoais → Segurança**, o usuário cria uma senha de app. O cliente MCP usa autenticação HTTP Basic com o ID do usuário e essa senha de app. O app nunca pede nem guarda a senha principal.

Login pelo navegador (Login Flow v2/OAuth) ainda não está disponível.

## Revogar

*Disconnect* na página pessoal bloqueia a próxima requisição MCP, inclusive com a mesma senha de app. A senha de app continua válida no Nextcloud e deve ser revogada em **Segurança**. O administrador também pode remover a elegibilidade do usuário ou desativar o serviço; ambos valem na próxima requisição.

## Teste manual após a instalação

```sh
URL=https://<instância>/apps/mcp/
AUTH='<usuário>:<senha-de-app>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Respostas esperadas:

| Situação | HTTP |
| --- | --- |
| Sem credencial, credencial inválida ou usuário desativado | 401 com `WWW-Authenticate: Basic` |
| Serviço desligado, usuário inelegível ou desconectado | 403 |
| `Origin` de outro host | 403 |
| Faltando `Accept: application/json, text/event-stream` ou `Content-Type: application/json` | 406 |
| Falta `MCP-Protocol-Version: 2025-06-18` depois do `initialize`, ou versão diferente | 400 |
| JSON inválido (`-32700`), lote ou envelope inválido (`-32600`) | 400 |
| Método desconhecido (`-32601`) ou parâmetros inválidos (`-32602`) | 200 com erro JSON-RPC |
| GET ou DELETE | 405 |

## Desenvolvimento

```sh
composer install
vendor/bin/phpunit
```

Os testes são unitários, com mocks das interfaces OCP (`nextcloud/ocp` stable33). Eles não substituem a instalação num Nextcloud real.
