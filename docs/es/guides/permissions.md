<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/guides/permissions.md).

# Permisos y administración

Abre **Administración → MCP for Cloud**. Las cinco pestañas son **Resumen**, **Acceso y permisos**, **Clientes y conexiones**, **Protección de datos** y **Avanzado**. Sus direcciones (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) y la navegación por teclado abren la sección correspondiente.

## Habilitar el acceso

El servicio, la elegibilidad del usuario (**Puede conectarse**) y la conexión personal empiezan desactivados, también para administradores. Activa el servicio, permite al usuario conectarse y deja que este active su conexión en **Ajustes personales → MCP for Cloud**. La autenticación por sí sola no concede acceso: deben permitir la solicitud la cuenta, el servicio, la elegibilidad, la conexión, la aplicación opcional, el permiso de operación y los permisos nativos de Nextcloud sobre el recurso.

La lectura empieza permitida salvo para los registros del servidor. Las demás operaciones empiezan denegadas. Compartir archivos con personas/grupos (`files.share`) y crear enlaces públicos (`files.link`) son permisos independientes. Listar recursos compartidos es una operación de lectura.

Resumen muestra el endpoint, la versión, el número de conexiones y el límite de subida de checkout. Este límite se edita en MiB y no puede superar `post_max_size` de PHP. Avanzado contiene los roles y la privacidad de registros y OCR. Protección de datos contiene las etiquetas de exclusión; consulta [protección de archivos](file-protection.md).

## Opciones individuales y herencia de grupos

Acceso y permisos ofrece niveles por módulo y una vista **Operaciones** para los permisos individuales. La búsqueda usa nombre, ID o correo; el correo no se muestra. Los filtros de grupo y elegibilidad/conexión se aplican en el servidor, con 50 usuarios por página. Las cuentas deshabilitadas no se pueden editar, y las aplicaciones o roles de registros no disponibles siguen sin estar disponibles sin borrar las opciones guardadas.

La resolución sigue este orden:

1. Prevalece **Permitir** o **Denegar** explícito del individuo.
2. Sin una opción individual, cualquier concesión de un grupo de confianza permite la operación.
3. En otro caso se aplica el valor predeterminado. Los grupos conceden acceso; no lo deniegan ni lo restringen.

Solo pueden conceder permisos los grupos del backend de base de datos propio de Nextcloud. LDAP, SAML, otros backends de aplicaciones e identidades de backend ambiguas no pueden proporcionar herencia segura. Usa permisos individuales para sus usuarios. Un grupo recreado o incierto no recupera silenciosamente su autoridad anterior: el editor explica su estado y permite confirmar la política actual cuando corresponde.

En **Permisos de grupo**, selecciona un grupo, edita sus operaciones y previsualiza antes de guardar. Los recuentos de miembros y las estimaciones de cierre de sesión sirven para planificar, no son una decisión de autorización por miembro. Retirar **Puede conectarse** cierra las sesiones de quienes realmente pierden la elegibilidad. Procesar grupos grandes puede llevar tiempo; esta integración no promete nuevas garantías de rendimiento.

**Heredar** y las acciones de restablecer fila/selección eliminan las excepciones individuales. La vista previa identifica opciones individuales, grupos que conceden permisos, valores predeterminados y resultados desconocidos. Un grupo o política ilegible es **Desconocido**, nunca un permiso implícito ni un motivo para borrar credenciales. Las solicitudes que dependan de esa incertidumbre fallan temporalmente; las herramientas con autorización desconocida se omiten del descubrimiento mientras siguen disponibles las permitidas de forma independiente.

## Guardar, conflictos y deshacer

Las escrituras de política incluyen la revisión mostrada en la interfaz. Una vista previa obsoleta o un cambio concurrente produce un conflicto y una recarga; el navegador no repite silenciosamente la escritura. Una respuesta incierta no se presenta como un guardado correcto. Los borradores de grupos, recursos y clientes siguen siendo borradores hasta su acción Guardar.

Las solicitudes de restablecimiento/lote admiten como máximo 50 usuarios y 2500 celdas. La vista previa vale 120 segundos; los recibos del servidor caducan a los 600 segundos. La interfaz ofrece **Deshacer** durante 10 segundos mientras el control esté disponible y pausa el temporizador al mantener encima el puntero o el foco del teclado. Deshacer exige la revisión guardada y un recibo válido: no puede sobrescribir cambios posteriores. Restaurar un permiso no restaura credenciales revocadas ni vuelve a iniciar sesiones.

## Restricciones de recursos e inspección

El administrador puede restringir los recursos de **Files**, **Calendar** y **Tasks** de un usuario desde el panel de acceso. Cada módulo es independiente: **Todos los recursos** significa sin restricción; **Solo los seleccionados**, con selección vacía, no permite ninguno. El límite es de 200 recursos por usuario y módulo. Las restricciones solo reducen los permisos existentes y nativos; cambiarlas no revoca la conexión. Se aplican a la siguiente operación.

Notes, Contacts, Deck y Talk no tienen restricción de recursos por módulo en 1.1.0. Las referencias estructuradas a archivos y las operaciones con adjuntos pueden requerir comprobaciones del ámbito de Files; consulta [protección de archivos](file-protection.md). Restringir una carpeta no restringe de forma general todo lo que otros módulos guardan debajo de ella.

El inspector, exclusivo de administradores, informa **Permitido**, **Bloqueado** o **Desconocido**, la comprobación que bloquea y el origen del permiso. Puede explicar una ruta excluida al administrador; los errores MCP mantienen denegaciones genéricas. Las consultas y cambios pueden generar mensajes de auditoría de nivel informativo sin nombres ni rutas de recursos. Dependen del nivel de registro del servidor y no garantizan una pista de auditoría.

## Clientes y ajustes personales

Gestiona los hosts de clientes publicados, el cliente nativo y los clientes personalizados registrados en **Clientes y conexiones**; consulta [OAuth](../reference/oauth.md). Las API administrativas exigen administrador y protección CSRF de Nextcloud. Las API personales de conexión/ámbito obtienen la identidad de la sesión y solo muestran el estado de ese usuario. Las respuestas nunca contienen hashes de tokens.

Los ajustes personales muestran el estado de conexión, los clientes conectados, los controles de revocación y las restricciones de recursos de solo lectura del usuario. Para HTTP Basic, crea una contraseña de aplicación en **Ajustes personales → Seguridad**. MCP no pide ni almacena la contraseña principal. Desconectar MCP no elimina esa contraseña de aplicación de Nextcloud; consulta [revocar el acceso](clients.md#revocar-el-acceso).

Persiste un riesgo residual de escritura: una denegación genérica puede distinguir un destino no disponible de otro donde la creación tendría éxito, aunque no revele el motivo.

[Guías operativas](README.md).

[English](../../en/guides/permissions.md) · Español · [Català](../../ca/guides/permissions.md) · [Português (Brasil)](../../pt-BR/guides/permissions.md) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
