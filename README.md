# Studio IA · Eskalamos

Panel privado en PHP 8.1+ para generar imágenes (Soul Standard), vídeos desde texto (Seedance 2.0, 4–15 segundos) y locuciones MP3 (ElevenLabs Multilingual v2). Las solicitudes salen del servidor; no usa la API de OpenAI. Incluye un servidor MCP con OAuth para solicitar contenido desde ChatGPT; instrucciones completas en [Conectar ChatGPT](docs/chatgpt-connection.md).

## Configuración privada

La raíz FTP es `/home5/cywqgkxj/studio.eskalamos.com`; la raíz web debe ser su carpeta `public/`. El `.env` real vive en la raíz FTP, fuera de `public/`. Copiar las variables de `.env.example`; nunca subir credenciales a GitHub. Se admiten las dos variables Higgsfield ID/secreto o una `HIGGSFIELD_API_KEY` completa. ElevenLabs usa `ELEVENLABS_API_KEY` y el ID de voz opcional `ELEVENLABS_DEFAULT_VOICE_ID`; el panel permite cambiar de voz sin cambiar la clave.

Los campos del panel indican si falta una clave, pero guardarla no demuestra acceso a todos los modelos. El proveedor determina saldo, disponibilidad, moderación y tarifas. Cada generación requiere confirmar consumo de créditos. No se promete un coste exacto antes de la factura del proveedor.

## Primer acceso

El panel permanece cerrado mientras no haya contraseña. Desde Terminal de cPanel, en la raíz del Studio, generar un código de configuración con PHP: crear `storage/` con permisos 0700 y guardar en `storage/setup.json` un JSON con `token_hash` (SHA-256 de 32 bytes aleatorios representados como 64 caracteres hexadecimales) y `expires` (Unix, como máximo una hora). El archivo debe tener permisos 0600. Este paso no modifica el `.env`.

El propietario abre `https://studio.eskalamos.com/#setup=CODIGO` y elige personalmente una contraseña de al menos 12 caracteres. El fragmento no viaja al servidor ni a los registros HTTP. El código se envía solo al guardar el formulario, se valida con CSRF y caducidad, y se invalida después del uso. Se almacena únicamente un hash de contraseña en `storage/account.json`. También se admite `STUDIO_PASSWORD_HASH` para un hash configurado manualmente por el propietario. No hay contraseñas por defecto.

## Producción

- Imágenes: una pieza por solicitud, formato y resolución 720p/1080p.
- Vídeos: escena desde texto, 4–15 segundos, formato, 720p/1080p y audio nativo opcional. No incluye avatares sincronizados ni montaje automático con ElevenLabs.
- Locuciones: hasta 2.500 caracteres, ID de voz editable, búsqueda en las voces de la cuenta y velocidad de 0,7 a 1,2. No clona ni crea voces.
- Historial persistente en `storage/jobs.json`, privado y separado de las versiones. La consulta de trabajos pendientes se reanuda al abrir el panel. No necesita cron para seguir generando en Higgsfield, aunque la pestaña se cierre.
- Los archivos de imagen/vídeo permanecen en el proveedor y sus enlaces pueden caducar: descargar al terminar. Los MP3 se guardan en el hosting: acceso con sesión autenticada en el panel o enlaces temporales de diez minutos emitidos para el chat autorizado.
- Higgsfield: `Idempotency-Key` persistida antes de enviar, recuperación del mismo envío tras respuesta incierta, consulta de estado y solicitud de cancelación. Una cancelación solo se marca definitiva cuando el proveedor la confirma en estado.
- ElevenLabs: un envío incierto nunca se repite automáticamente. Revisar el historial del proveedor antes de crear otro.
- Un propietario por instalación, sesiones Secure/HttpOnly/SameSite, CSRF, comprobación de origen, límites de intentos y de 30 generaciones/hora, validación de parámetros y rutas fijas al proveedor.

## Despliegue

GitHub Actions → **Desplegar Studio IA** → **Run workflow**, rama `main`. Usa `FTP_PASSWORD` y los ajustes FTPS existentes. El cliente verifica TLS, sube solo los archivos de la lista explícita de `scripts/deploy_ftps.py` (controladores públicos y módulos privados), crea versiones inmutables en `releases/<sha>/`, activa `current.json` de forma atómica y exige que `https://studio.eskalamos.com/?health=1` confirme el mismo SHA. También exige metadatos OAuth coherentes y una respuesta 401 de MCP sin token. Si falla, restaura el puntero anterior. Nunca sube `.env` ni reemplaza `storage/`, `.htaccess` o configuración PHP. No sobrescribe páginas de inicio ajenas.

La carpeta de documentos debe ser `public/` y Cloudflare debe tener el registro A `studio` hacia `92.60.32.32`. La verificación HTTPS requiere un certificado válido del origen cuando se usa TLS estricto. No se relajan comprobaciones TLS.

## Generación desde ChatGPT

Desplegar el código, activar el cron de `public/worker.php` cada minuto y añadir el servidor `https://studio.eskalamos.com/mcp.php` como conexión MCP personalizada con OAuth. El propietario autoriza con la contraseña del Studio y selecciona **@Studio IA** en sus conversaciones. No requiere copiar claves de proveedores al chat ni añadir claves al `.env`. El panel permite revocar todas las conexiones. La instalación en ChatGPT y la activación del cron no se completan automáticamente al publicar el código. Ver [pasos y limitaciones](docs/chatgpt-connection.md).

## Pruebas sin gasto

```sh
php -l app/entry.php
php tests/test_studio.php
php tests/test_chat.php
python3 -m unittest discover -s tests -v
```

Las pruebas de aplicación usan transporte simulado; las HTTP levantan un PHP aislado con credenciales ficticias y comprueban acceso, CSRF, configuración de una sola vez, cierre de sesión, consentimiento OAuth, PKCE, renovación/revocación, descubrimiento MCP, permisos, cola y enlaces de audio temporales. Ninguna prueba llama a endpoints de generación reales. La autenticación real de Higgsfield se puede verificar consultando un UUID de solicitud inexistente: clave ficticia 401 y clave configurada 404, sin generar contenido. Esto no comprueba una generación completa ni el acceso a un modelo concreto.

Documentación: [Higgsfield](https://docs.higgsfield.ai/docs/llms.txt), [Soul Standard](https://open.higgsfield.ai/models/higgsfield-ai/soul/standard/api-reference), [Seedance 2.0](https://open.higgsfield.ai/models/bytedance/seedance-2.0/text-to-video/api-reference), [ElevenLabs TTS](https://elevenlabs.io/docs/api-reference/text-to-speech/convert).
