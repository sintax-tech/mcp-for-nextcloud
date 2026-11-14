<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Cómo contribuir a MCP for Cloud

[English](CONTRIBUTING.md) · [Português (Brasil)](CONTRIBUTING.pt-BR.md) · [Español](CONTRIBUTING.es.md) · [Deutsch](CONTRIBUTING.de.md)

Gracias por contribuir. Las correcciones, traducciones, mejoras de documentación y nuevas funcionalidades son bienvenidas. Esta guía explica las decisiones de producto que orientan el código para que puedas valorar una idea antes de invertir en una implementación grande.

## Qué estamos construyendo

MCP for Cloud es una aplicación PHP nativa de Nextcloud que permite a una persona autenticada usar Nextcloud mediante un cliente MCP. Se ejecuta dentro de su instancia, con su identidad y sus permisos. Nuestra prioridad es ofrecer acceso útil a las aplicaciones existentes, con controles claros, efectos previsibles y una instalación que funcione desde la interfaz de Nextcloud.

## Nuestra filosofía de desarrollo

### Instalación sencilla e implementación nativa

La aplicación distribuida debe funcionar sin comandos obligatorios de terminal, SQL manual, edición de archivos de configuración ni un servicio separado. Los diagnósticos opcionales deben seguir siendo opcionales. Usa las migraciones y los ajustes de Nextcloud para la instalación y la configuración.

Usa PHP nativo y las API de Nextcloud. Mantén las dependencias de producción reducidas y justificadas. El frontend usa JavaScript sencillo, con bundles generados por el constructor PHP existente. No introduzcas un requisito de compilación o pruebas con Node/npm, otro framework ni un servicio externo sin acuerdo previo. Composer se usa durante el desarrollo y el empaquetado; los usuarios instalan un paquete que incluye sus dependencias de ejecución.

### Respetar la política MCP y los permisos nativos

Toda operación usa la identidad del actor autenticado y debe cumplir los grants MCP, restricciones de grupo, reglas de Guests, ámbito de recursos, controles de visibilidad y ACL nativas aplicables. Un grant no sustituye un permiso nativo. Una política o servicio no disponible debe dar lugar a un rechazo controlado, sin ampliar el acceso.

Conserva las decisiones de acceso guardadas durante las actualizaciones. Las nuevas escrituras y las lecturas sensibles recién introducidas comienzan denegadas. Algunos grants de lectura existentes se permiten por defecto; esto no autoriza a exponer automáticamente cualquier nueva lectura. Una operación nueva o la ampliación de un grant existente requiere una revisión explícita de acceso. Por ejemplo, poder editar una tarjeta no establece automáticamente permiso para eliminar las etiquetas de un tablero.

### Explicar las escrituras antes de ejecutarlas

Una escritura sin confirmar devuelve un plan con el destino, los cambios, las personas afectadas y los límites de recuperación. No debe ejecutar la escritura solicitada ni crear por adelantado su copia de seguridad, carpeta o token de checkout. La ejecución exige `confirm: true`, además de `confirm_shared` y `plan_state` cuando el flujo lo requiera. Vuelve a validar el acceso, la identidad y el estado relevante antes del efecto.

La confirmación es conversacional: el servidor no puede demostrar que una persona dijo sí. `plan_state` vincula una llamada al estado mostrado en un plan; no concede permisos ni representa una aprobación almacenada. Conserva los controles de concurrencia de cada flujo. Documenta los efectos de la inicialización nativa donde existan; no prometas que todas las vistas previas carecen de cualquier efecto de plataforma.

### Preservar el comportamiento nativo y proteger los datos

Usa el servicio nativo o el flujo DAV adecuado para la operación, preservando los controles de permisos, eventos, versiones, papelera y programación necesarios. No modifiques tablas de otra aplicación ni eludas su flujo para que una funcionalidad parezca funcionar. La persistencia propia de MCP sigue usando los recursos de base de datos y migraciones de Nextcloud.

No elimines archivos del usuario como limpieza, reversión o retención. La eliminación de archivos es un flujo explícito y restringido mediante `files_delete`; `notes_delete` tiene sus propias reglas. Mantén el alcance limitado de la excepción existente para deshacer carpetas vacías. Nunca depures copias de seguridad o versiones de forma silenciosa, ni describas un intento nativo de envío a la papelera como recuperación garantizada.

Una lectura no debe marcar mensajes como leídos, hacer caducar estados ni ejecutar mantenimiento inesperado. Comprueba los efectos reales de la llamada nativa. Si no hay una vía segura, rechaza la capacidad y explica la limitación en lugar de añadir un atajo.

### Preservar la privacidad entre aplicaciones y comunicar la incertidumbre

Los enlaces, adjuntos, resultados de búsqueda y referencias deben respetar las protecciones del recurso que exponen. Pasar por otro módulo no puede revelar un recurso oculto, su nombre ni su existencia. Devuelve únicamente los datos necesarios para la operación y mantén los secretos y el contenido de los recursos fuera de los diagnósticos.

Limita las exploraciones y las respuestas; indica explícitamente los resultados parciales y la continuación. Distingue el éxito observado, la ejecución parcial y un resultado incierto. No prometas atomicidad, entrega de invitaciones ni recuperación que la API nativa no pueda demostrar. No añadas reintentos automáticos ni compensaciones destructivas después de una posible escritura sin un diseño revisado por separado.

### Cambios acotados y controles familiares

Usa clases centradas en una responsabilidad y los límites de módulos existentes. Conserva nombres de herramientas, esquemas, orden e identidades persistidas, salvo cuando el cambio requiera explícitamente una actualización de contrato revisada. El código heredado conservado y las excepciones de pruebas no son modelos para código nuevo.

Preserva la disposición de la pantalla de permisos, sus tres estados distinguibles sin color, los cambios en lote explícitos, los recuentos y Deshacer. Un rediseño visual necesita un acuerdo separado.

## Antes de una contribución importante

Describe el problema del usuario, el comportamiento esperado, la rama de destino, los grants, la API nativa y las versiones compatibles. Explica los efectos compartidos o destructivos y los casos de fallo. Acuerda con los mantenedores una funcionalidad grande, un cambio de arquitectura, una dependencia nueva o una ampliación de acceso antes de implementarlos. Las correcciones pequeñas y mejoras de documentación pueden ir directamente a una PR acotada.

La compatibilidad corresponde a la versión de destino. La versión 1.3.5 publicada documenta NC32–35. Para trabajo destinado a la 1.4.0, las nuevas garantías y pruebas cubren solo NC34/35; se conservan el código existente de NC32/33 y el intervalo de instalación declarado. No añadas alternativas ni fixtures específicos de NC32/33, ni interpretes el intervalo de instalación como garantía de cobertura de funcionalidades nuevas.

## Implementación y evidencias

Lee [Arquitectura](docs/en/architecture.md), [Añadir un módulo o herramienta](docs/en/adding-a-module.md), [Pruebas](docs/en/testing.md) y [Empaquetado](docs/en/packaging.md). El PHP nuevo y reescrito usa tabulaciones, `strict_types`, avisos SPDX adecuados y PHPDoc completo. Conserva la atribución. El código, los comentarios, los mensajes de commit y los textos fuente están en inglés; mantén traducciones compatibles equivalentes para los cambios visibles al usuario.

Para cambios de código, ejecuta las pruebas focales y afectadas, Architecture, MapCoverageTest, la suite Contract y REUSE sobre una copia limpia. Las pruebas nuevas deben entrar en el inventario y las particiones del CI existente; la cobertura de integración nativa se incorpora a las celdas de integración existentes. Los mocks por sí solos no demuestran el comportamiento nativo. Las suites completas se ejecutan en la validación de versiones existente en GitHub, en vez de repetirse localmente con cada parche. Los cambios exclusivamente en Markdown necesitan revisión del contenido y los enlaces, no pruebas de la aplicación ni CI.

En la PR, indica qué cambió, el impacto sobre acceso y compatibilidad, comandos y resultados, y qué no se probó. Revisa deliberadamente los cambios de goldens; no regeneres las expectativas para ocultar regresiones. Los cambios en la integración nativa requieren una nueva validación de integración en CI.

## Revisión, crédito y publicación

Una implementación funcional todavía puede necesitar ajustes para seguir estos principios. Las contribuciones pequeñas y adecuadas suelen incorporarse mediante un merge normal que preserva la autoría; los ajustes de los mantenedores quedan separados. Las contribuciones grandes o que necesiten otro diseño pueden adaptarse antes de su integración, reconociendo la contribución original y distinguiendo nuestros cambios.

Integrar una contribución y publicar una versión son pasos separados. Conserva la licencia y los avisos de atribución del proyecto. Consulta la [licencia](LICENSES/AGPL-3.0-or-later.txt), [NOTICE](NOTICE) y la [referencia técnica](mcp/README.md).
