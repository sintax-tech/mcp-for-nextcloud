<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/oauth.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/reference/oauth.md).

# OAuth

Los metadatos del recurso protegido están en `/apps/mcp/.well-known/oauth-protected-resource`. El desafío `401` identifica ese documento. Los metadatos del servidor de autorización están en `/apps/mcp/.well-known/openid-configuration` y `/apps/mcp/.well-known/oauth-authorization-server`, con emisor `https://<instance>/apps/mcp`.

`oauth/authorize` ofrece consentimiento en el navegador; `oauth/token` intercambia códigos de autorización con PKCE S256 y rota tokens de renovación. Los tokens de acceso duran una hora y los de renovación, 30 días. La base de datos almacena hashes de credenciales, no sus valores en claro. Es autenticación de cliente público (`none`); un ID de cliente es un identificador, no un secreto ni una concesión de permisos.

## Clientes publicados, nativos y registrados

Los administradores gestionan clientes en **Administración → MCP for Cloud → Clientes y conexiones**. Guardar exige administrador, protección CSRF de Nextcloud y la revisión de política actual.

| Tipo de cliente | Identidad y reglas de redirección |
| --- | --- |
| Documento de identidad de cliente publicado (CIMD) | Documento HTTPS en un host exacto permitido. Los predeterminados son `claude.ai,chatgpt.com`. Las redirecciones deben coincidir con el documento validado. |
| Cliente nativo incluido | `nextcloud-mcp-native`, desactivado de forma predeterminada. Sus callbacks HTTP de loopback registrados admiten puertos variables para programas locales. |
| Cliente personalizado registrado por el administrador | ID generado, nombre visible, estado habilitado y lista explícita de URI de redirección. La coincidencia es exacta, incluido el puerto en HTTP de loopback. |

La lista permitida admite hosts sin esquema, puerto, ruta ni comodines; debe quedar al menos uno. Guardar sustituye la lista. Retirar un host bloquea la admisión aunque su documento esté en caché. Los documentos se descargan mediante HTTPS sin redirecciones, con límite de 64 KiB y caché de una hora.

Se admiten como máximo 20 clientes personalizados, nombres de hasta 64 caracteres y hasta 20 URI de redirección por cliente. Los nombres no pueden estar vacíos y deben ser únicos sin distinguir mayúsculas. Cada URI admite hasta 2000 bytes, usa HTTPS o HTTP en loopback y no contiene información de usuario, fragmento, comodín, espacios en blanco, caracteres de control ASCII ni barra inversa. Una lista de redirecciones vacía no puede autorizar un cliente. Copia el ID generado en su configuración y registra el callback exacto; los clientes personalizados no heredan la excepción de puerto variable del cliente nativo.

Tras la migración, la tabla de clientes gestionados es la fuente autoritativa. Deshabilitar/eliminar un cliente gestionado o retirar uno de sus URI de redirección retira las concesiones existentes y avanza su generación de cliente. Volver a habilitarlo no reactiva una página de consentimiento, código o token anterior. Un fallo de lectura de esa tabla rechaza temporalmente la autorización en vez de mostrar consentimiento suponiendo que está permitido.

## Conectar mediante ChatGPT y Gemini CLI (OAuth)

Consulta los ejemplos en la [guía de clientes](../guides/clients.md). ChatGPT usa su identidad publicada en `chatgpt.com`. Gemini CLI puede usar el cliente nativo incluido una vez habilitado por un administrador. Los callbacks nativos son `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` y `http://[::1]/oauth/callback`; el puerto de loopback seleccionado puede variar.

Cada redirección validada incluye el parámetro de emisor `iss`, también las denegaciones y errores redirigibles. Los errores anteriores a validar la redirección se muestran en una página local. Las interfaces y la compatibilidad con clientes alojados siguen requiriendo revisión con clientes reales; una prueba local del protocolo no certifica su interfaz actual.

## Revocación e incertidumbre

Cada solicitud vuelve a comprobar identidad, servicio, elegibilidad, conexión personal, aplicación/rol de registros, permiso de operación, restricción de recursos, etiquetas de exclusión y ACL nativas cuando corresponda. Un permiso de grupo no evita estas comprobaciones. Una política desconocida significa indisponibilidad temporal, no permiso implícito ni revocación destructiva.

Desconectar personalmente, perder elegibilidad, apagar el servicio y deshabilitar/eliminar la cuenta invalidan credenciales OAuth mediante generaciones por usuario o una época global. La invalidación se confirma junto con el cambio de política; una limpieza persistente elimina después las filas obsoletas y debe conservar las credenciales posteriores. Restituir acceso o habilitar un cliente no revive credenciales anteriores. La detección de reutilización de tokens de renovación sigue aplicándose al linaje de su propietario/cliente.

Cambiar el ámbito de recursos no revoca OAuth: la siguiente operación evalúa la nueva restricción. Las contraseñas de aplicación HTTP Basic pertenecen a Nextcloud, no a este almacén OAuth. Desconectar MCP bloquea su uso en MCP, pero borrarlas exige los controles de Seguridad de Nextcloud. Apagar el servicio conserva la preferencia de conexión personal; la recuperación también puede exigir desconexión explícita y revisión de contraseñas de aplicación. Consulta [instalación y recuperación](../installation.md).

## Propiedades de seguridad

- El consentimiento está protegido por CSRF y no admite marcos. Inicio de sesión, elegibilidad, conexión, permisos, consentimiento y PKCE siguen siendo obligatorios para clientes nativos/personalizados.
- Las comprobaciones de generación e invalidación impiden que credenciales retiradas recuperen validez al restituir acceso o recrear identidad/cliente. Un estado incierto de base de datos o política produce un fallo reintentable sin suposiciones.
- Los cambios administrativos de clientes validan toda la solicitud antes de escribir. Los conflictos recargan el estado en vez de repetir silenciosamente una mutación.
- Los errores inesperados mostrados al cliente son genéricos. El diagnóstico de la aplicación no registra credenciales en claro ni contenido de archivos.
- Comunica vulnerabilidades en privado a los responsables. No desactives la protección contra fuerza bruta ni permitas un rango de red no verificado para resolver un fallo de conexión; investiga la denegación y los registros del servidor.

[Guías operativas](../guides/README.md).

[English](../../en/reference/oauth.md) · Español · [Català](../../ca/reference/oauth.md) · [Português (Brasil)](../../pt-BR/reference/oauth.md) · [Português (Portugal)](../../pt-PT/reference/oauth.md)
