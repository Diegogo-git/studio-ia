# Conexión de ChatGPT con Studio IA

Este cambio añade un servidor MCP privado para solicitar imágenes, vídeos desde texto y locuciones desde una conversación. Usa el saldo y las claves de Higgsfield y ElevenLabs guardadas en el hosting. El servidor no llama a la API de OpenAI. ChatGPT puede exigir un plan con acceso a plugins/conexiones MCP personalizadas; la disponibilidad de esta función en la cuenta se comprueba al instalar la conexión.

**Estado de esta entrega:** código y pruebas locales preparados. No implica que esté desplegado ni que la conexión esté instalada en ChatGPT. La cuenta del Studio debe tener contraseña propia antes de conectar. No enviar esa contraseña ni claves de proveedores en el chat.

## 1. Desplegar

Actualizar el repositorio con los archivos de esta entrega, revisar el cambio y ejecutar **Actions → Desplegar Studio IA → Run workflow** sobre `main`. El flujo valida PHP, ejecuta las pruebas sin gasto, publica la versión privada y comprueba salud, descubrimiento OAuth y rechazo de MCP sin autenticación. Si falla, restaura la versión anterior. Las claves del `.env` existente no se vuelven a cargar.

Se añaden controladores públicos fijos. El despliegue se detiene si ya existe un archivo distinto en esas rutas. `public/index.php` conserva exactamente su contenido anterior. La lógica versionada vive fuera del directorio web, en `releases/<sha>/app/`.

## 2. Activar la cola en cPanel

En **Tareas cron**, programar **una ejecución cada minuto** (`* * * * *`):

```sh
php /home5/cywqgkxj/studio.eskalamos.com/public/worker.php >/dev/null 2>&1
```

`php` debe ser el ejecutable CLI de PHP 8.1 o posterior, con cURL. Si el entorno cron no lo encuentra, sustituir `php` por la ruta que devuelve `command -v php` en Terminal de cPanel y comprobar su versión con `php -v`. No asumir una ruta específica de MultiPHP sin verificarla en el servidor.

Para comprobarlo la primera vez, ejecutar el comando sin redirección. Debe imprimir `Studio worker: completed`. El archivo `storage/worker-status.json` se actualiza en cada ejecución. El acceso HTTP a `worker.php` devuelve 404; solo se ejecuta por CLI. Un bloqueo impide ejecutar dos procesadores a la vez.

Cada ejecución envía como máximo tres trabajos. Si no hay actividad del procesador durante tres minutos, el servidor rechaza nuevas generaciones del chat con 503 y no llama al proveedor. El panel conserva su flujo existente de envío directo; las solicitudes del chat usan la cola.

## 3. Instalar una vez en ChatGPT

En ChatGPT web, abrir **Plugins** y usar **+ → Add custom MCP server**. Si la interfaz lo muestra como aplicaciones/conectores, localizar la opción equivalente para añadir un servidor MCP personalizado.

| Campo | Valor |
|---|---|
| Nombre | Studio IA |
| URL MCP | `https://studio.eskalamos.com/mcp.php` |
| Autenticación | OAuth; registro dinámico del cliente (DCR), si se ofrece la opción |
| Permisos | `studio:read studio:generate` |

No introducir las claves de Higgsfield ni ElevenLabs. La aplicación descubre los endpoints OAuth y registra un cliente público con PKCE S256. No hay `client_secret` que copiar. Al conectar, se abre una página del propio dominio `studio.eskalamos.com`: introducir personalmente la contraseña del Studio, revisar los permisos y autorizar. Instalar la conexión y seleccionarla o mencionarla como **@Studio IA** en las conversaciones donde se quiera usar.

La instalación en la cuenta y el consentimiento son pasos del propietario; el despliegue por sí solo no los completa. Si la cuenta no permite servidores MCP personalizados, este servidor no habilita esa función por su cuenta.

## 4. Usar desde una conversación

No hace falta memorizar comandos. Ejemplos una vez instalada y seleccionada la conexión:

> @Studio IA, comprueba la conexión y el estado del procesador.

> @Studio IA, genera una imagen vertical de una calle de Alicante al atardecer. Autorizo el consumo de créditos.

> @Studio IA, crea un vídeo vertical de 5 segundos de una vivienda mediterránea, sin audio. Autorizo el consumo de créditos.

> @Studio IA, genera una locución con mi voz predeterminada y este texto: «¿Has visto que cada vez cuesta más comprar una vivienda en España?». Autorizo el consumo de créditos.

> @Studio IA, usa la voz con ID [ID DE LA VOZ] para esta locución.

> @Studio IA, consulta el estado del último trabajo y dame el enlace cuando esté listo.

El chat recibe inmediatamente un ID del trabajo en estado `prepared`. El cron lo envía al proveedor aproximadamente en un minuto, dependiendo de la cola. Para resultados pendientes, consultar el mismo ID; no pedir otra generación como recuperación. No se promete una notificación autónoma cuando termine ni ejecución sin seleccionar la conexión.

Los enlaces de imagen y vídeo son del proveedor y pueden caducar. El audio se sirve mediante un enlace temporal de diez minutos; consultar de nuevo el trabajo genera otro enlace. El historial también se ve en el panel privado.

**Alcance:** imágenes Soul Standard; vídeo desde texto Seedance 2.0 de 4 a 15 segundos; voz ElevenLabs Multilingual v2 con ID editable, búsqueda y velocidad. No incluye sincronización labial de un avatar ni montaje automático del vídeo con la locución. Son trabajos separados.

## Herramientas disponibles

| Herramienta | Función | Permiso |
|---|---|---|
| `studio_connection_status` | Proveedores configurados, modelos, voz predeterminada y cron | `studio:read` |
| `studio_create_generation` | Guardar una generación autorizada en la cola | `studio:generate` |
| `studio_get_generation` | Consultar estado y resultado de un trabajo propio | `studio:read` |
| `studio_list_generations` | Consultar historial propio | `studio:read` |
| `studio_list_voices` | Buscar voces de la cuenta | `studio:read` |
| `studio_cancel_generation` | Cancelar antes del envío o pedir cancelación a Higgsfield | `studio:generate` |

`studio_create_generation` requiere `request_key` UUID, `kind` (`image`, `video` o `speech`), `prompt` y `accept_cost: true`. El UUID se mantiene al recuperar la misma solicitud. Otro UUID representa otro envío y puede generar otro cargo. La ausencia de `accept_cost: true` impide crear el trabajo.

## Seguridad y desconexión

OAuth usa códigos de un solo uso, PKCE S256, `state`, emisor, audiencia y callbacks restringidos a ChatGPT. El consentimiento usa un formulario del propio dominio, CSRF, contraseña existente y límites de intentos. Las claves de proveedor nunca se envían a ChatGPT. No se admite la cookie del panel como sustituto del token Bearer.

Los tokens se guardan solo mediante SHA-256 en `storage/oauth.json`. El acceso dura una hora; el token de renovación rota en cada uso y la conexión caduca a los 30 días. Reutilizar un token de renovación ya usado revoca toda su conexión. Reconectar cuando caduque.

En el panel, **Desconectar ChatGPT** revoca todas las conexiones del propietario. Los trabajos pendientes de enviar se cancelan al procesarse. Los ya enviados al proveedor pueden continuar y generar cargos. Los enlaces de audio ya emitidos conservan su caducidad de diez minutos. Quitar también la conexión en ChatGPT si ya no se va a utilizar.

Higgsfield recibe una clave de idempotencia persistente; se recupera el mismo envío tras un fallo ambiguo como máximo tres intentos del procesador. ElevenLabs no se reintenta automáticamente tras una respuesta incierta. Antes de crear otra locución en ese caso, comprobar el historial del proveedor.

## Diagnóstico sin gastar

- MCP sin token debe responder **401**, con `WWW-Authenticate` y `resource_metadata`.
- `https://studio.eskalamos.com/.well-known/oauth-protected-resource/mcp.php` debe devolver el recurso MCP y el emisor.
- `https://studio.eskalamos.com/.well-known/oauth-authorization-server/oauth.php` debe devolver los endpoints OAuth y `S256`.
- `studio_connection_status` confirma configuración y actividad del cron. Indicar que una clave existe no demuestra saldo, permisos ni generación completa.
- Un 401 tras renovar la conexión puede indicar que Apache/PHP no recibe la cabecera `Authorization`; comprobar su paso en la configuración del hosting, sin exponer tokens en diagnósticos.
- Las pruebas incluidas usan credenciales ficticias y transporte simulado. No validan una generación pagada real ni sustituyen la prueba de instalación en la cuenta de ChatGPT.

## API HTTP adicional

`/studio-api.php?op=...` comparte el OAuth del MCP. GET: `status`, `job&job_id=UUID`, `jobs&limit=10`, `voices&search=...`. POST JSON: `create` con los campos anteriores; `cancel` con `job_id`. Usar `Authorization: Bearer TOKEN` y la audiencia canónica `https://studio.eskalamos.com/mcp.php`. No se aceptan tokens de acceso en la URL. La excepción son los enlaces efímeros de descarga de audio, que solo permiten descargar ese archivo.

## Referencias de implementación

- [Servidores MCP personalizados en ChatGPT](https://developers.openai.com/api/docs/guides/custom-mcp-server)
- [Autenticación de plugins](https://developers.openai.com/plugins/build/auth)
- [Servidor MCP](https://developers.openai.com/plugins/build/mcp-server)
- [Transporte MCP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
- [Autorización MCP](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)
