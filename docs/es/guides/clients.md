<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/clients.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/guides/clients.md).

# Conectar clientes

Todos los clientes usan la misma URL, `https://cloud.example.com/apps/mcp/`, y se autentican como la persona que los utiliza, con sus permisos. Todos necesitan primero el servicio MCP activo, un usuario elegible y una conexión personal activa. La configuración específica del cliente cambia:

| Cliente | Autenticación | Acción previa del administrador |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | OAuth **Iniciar sesión**, identidad publicada de Claude (CIMD) | Ninguna: `claude.ai` está permitido de forma predeterminada |
| **ChatGPT** | OAuth **Iniciar sesión**, identidad publicada de OpenAI (CIMD) | Ninguna: `chatgpt.com` está permitido de forma predeterminada |
| **Gemini CLI** | OAuth con el cliente nativo incluido en la aplicación | Marcar **Permitir programas locales** y copiar `client_id` |
| **Cliente autoalojado registrado** | OAuth público con PKCE | Registrar nombre y URI de callback exacto, después copiar el ID generado |
| **Cualquier otro cliente MCP** | Contraseña de aplicación (HTTP Basic) | Sin registro de cliente adicional a los requisitos de acceso comunes |

### Claude (claude.ai, Claude Desktop, Cowork y Claude Code: recomendado)

1. *Ajustes → Conectores → Añadir conector personalizado*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticación: **Iniciar sesión** (OAuth), cliente: **Identidad publicada de Claude** (CIMD). No se necesitan encabezados.
4. Pulsa **Conectar**: se abre Nextcloud; inicia sesión y pulsa **Permitir**.

`claude.ai` está incluido de fábrica en los hosts de cliente permitidos, así que no hay que cambiar nada en el servidor. Si el administrador restringió la lista, Claude recibe `invalid_client` antes de que se abra la página de inicio de sesión. Pídele que vuelva a incluir `claude.ai` en *Ajustes de administración → MCP for Cloud → Clientes OAuth → Hosts de cliente permitidos*.

### ChatGPT

1. En ChatGPT, añade un conector nuevo para un servidor MCP y pega `https://cloud.example.com/apps/mcp/` como URL.
2. Autenticación: **Iniciar sesión** (OAuth). ChatGPT se identifica mediante el documento de identidad de cliente publicado en `chatgpt.com`; la aplicación descarga ese documento para conocer los URI de redirección registrados. No se necesitan encabezados ni contraseña de aplicación.
3. Pulsa **Conectar**: se abre Nextcloud; inicia sesión y pulsa **Permitir**.

`chatgpt.com` está permitido de forma predeterminada, así que una instalación que nunca se haya configurado ya acepta ChatGPT. Si el administrador limitó la lista a otros hosts, ChatGPT recibe `invalid_client` antes de que se abra la página de inicio de sesión. Pídele que vuelva a añadir `chatgpt.com` en *Ajustes de administración → MCP for Cloud → Clientes OAuth → Hosts de cliente permitidos*.

### Gemini CLI

Son dos pasos, uno del administrador y otro del usuario; no se necesita acceder al terminal del servidor.

**Administrador** — *Ajustes de administración → MCP for Cloud → Clientes OAuth*:

1. Marca **Permitir programas locales (cliente nativo)**. Está desactivado de forma predeterminada.
2. La sección muestra entonces el `client_id` que hay que copiar —`nextcloud-mcp-native`— y los URI de redirección que acepta.

**Usuario** — crea o edita `~/.gemini/settings.json` en tu propio equipo:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. En Gemini CLI, ejecuta `/mcp auth` (o `/mcp auth nextcloud`).
4. Se abre Nextcloud en el navegador: inicia sesión y pulsa **Permitir**. Gemini CLI escucha en un puerto libre que elige por su cuenta, por lo que el navegador vuelve a `http://localhost:<port>/oauth/callback`. La aplicación acepta `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` y `http://[::1]/oauth/callback`; para direcciones de loopback ignora el puerto (RFC 8252 7.3), por lo que también coincide el puerto aleatorio.

¿Por qué se incluye un cliente? Gemini CLI no publica un documento de identidad de cliente, por lo que la aplicación proporciona un único cliente público fijo para programas locales. Su ID es el mismo para toda la instancia, lo cual es seguro porque por sí solo no concede nada: siguen siendo necesarios el inicio de sesión, la elegibilidad, la conexión personal, los permisos del administrador, la pantalla de consentimiento y PKCE. Al desactivar el interruptor también se impide que el cliente intercambie un código de autorización o renueve un token.

### Administradores: clientes OAuth

La sección *Ajustes de administración → MCP for Cloud → Clientes OAuth* determina qué clientes pueden conectarse; no es necesario usar el terminal:

- **Hosts de cliente permitidos**: hosts cuyos documentos de identidad de cliente publicados puede descargar la aplicación, separados por comas; de forma predeterminada, `claude.ai` y `chatgpt.com`. Solo se permiten nombres de host exactos: sin `https://`, puerto, ruta ni `*`, y debe quedar al menos uno. El host es el que aparece en la URL que usa el cliente como su `client_id`. Al guardar se sustituye toda la lista; retirar un host rechaza ese cliente de inmediato, aunque su documento ya esté en caché.
- **Permitir programas locales (cliente nativo)**: habilita el cliente `nextcloud-mcp-native` descrito arriba y muestra el `client_id` y los URI de redirección aceptados para copiarlos.

- **Clientes autoalojados (URI de redirección personalizados)**: registra nombre, estado habilitado y URI exactos de callback, y copia el ID generado en el cliente. Usan HTTPS o HTTP de loopback; el puerto debe coincidir exactamente, a diferencia del cliente nativo incluido. Consulta límites y validación en [OAuth](../reference/oauth.md#clientes-publicados-nativos-y-registrados).

Los controles de clientes exigen administrador, protección CSRF y revisión de política vigente, e informan de guardados rechazados. Una configuración propia no se sustituye por los valores predeterminados: un valor de `oauth_client_hosts` guardado antes de 0.8.0 sigue en uso.

### Otros clientes MCP (contraseña de aplicación)

Crea una contraseña de aplicación en *Ajustes personales → Seguridad*, habilita la conexión en *Ajustes personales → MCP for Cloud* y configura el cliente con la URL anterior y autenticación HTTP Basic (`usuario:contraseña-de-aplicación`). Comprobación rápida:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revocar el acceso

*Desconectar* en la página personal bloquea la siguiente solicitud MCP, incluso con la misma contraseña de aplicación. La contraseña sigue siendo válida en Nextcloud y debe revocarse en **Seguridad**. En esa misma página, *Revocar* corta una conexión OAuth concreta del propio usuario. El administrador también puede retirar la elegibilidad del usuario o desactivar el servicio; ambas acciones se aplican en la siguiente solicitud. En el bloque **Conexiones activas**, el administrador puede revocar inmediatamente una conexión OAuth concreta o todas las conexiones de un usuario; el usuario sigue habilitado y puede volver a iniciar sesión.

La revocación invalida generaciones de credenciales OAuth o la época global antes de la limpieza diferida. Restituir acceso, deshacer un cambio de política o habilitar de nuevo un cliente gestionado no restaura códigos/tokens antiguos. Retirar un URI de redirección personalizado también retira las concesiones existentes de ese cliente. Apagar el servicio conserva la preferencia de conexión personal; el ciclo de vida de las contraseñas de aplicación y la revisión de recuperación son independientes. Consulta [OAuth](../reference/oauth.md#revocación-e-incertidumbre).

[Guías operativas](README.md).

[English](../../en/guides/clients.md) · Español · [Català](../../ca/guides/clients.md) · [Português (Brasil)](../../pt-BR/guides/clients.md) · [Português (Portugal)](../../pt-PT/guides/clients.md)
