# Studio IA · Eskalamos

Base de instalacion del Studio y despliegue manual por FTPS a Nicalia. La pagina inicial indica que Higgsfield y ElevenLabs estan pendientes: todavia no genera contenido, no carga el .env y no contiene un panel de administracion ni autenticacion de usuarios.

## Hosting

| Elemento | Configuracion |
| --- | --- |
| Subdominio | `https://studio.eskalamos.com` |
| Raiz web | `/home5/cywqgkxj/studio.eskalamos.com/public` |
| Raiz de la cuenta FTP | `/home5/cywqgkxj/studio.eskalamos.com` |
| Servidor FTPS certificado | `afrodita.dns-es.com` |
| Puerto | `21`, FTPS explicito y modo pasivo |
| Usuario FTP | `deploy-studio@eskalamos.com` |
| PHP objetivo | 8.5 actualizado, aplicado solo al subdominio |

La instalacion inicial es una comprobacion publica sin informacion privada. Los futuros modulos de generacion necesitaran autenticacion propia antes de habilitarse.

## Secretos de GitHub

En **Settings > Secrets and variables > Actions**, guardar `FTP_PASSWORD`. Los secretos opcionales `FTP_SERVER`, `FTP_USERNAME` y `FTP_PORT` reemplazan los valores predeterminados del cuadro anterior. No almacenar claves API como archivos del repositorio.

## Activar el workflow de despliegue

Para cargar el paquete manualmente, extraer el ZIP en el ordenador y abrir el repositorio en GitHub. En **Add file > Upload files**, arrastrar las carpetas `app`, `public`, `scripts` y `tests`, junto con `README.md`, `.gitignore` y `.env.example`, y guardar el commit en `main`. No subir el ZIP como un archivo: GitHub no lo descomprime. Si el explorador oculta los nombres que empiezan por punto, activar su visualizacion.

El archivo de automatizacion debe estar en `.github/workflows/deploy-ftps.yml` en `main`. La integracion de GitHub ha rechazado anteriormente escribir workflows (403). Si ese permiso sigue restringido, el propietario debe anadir el archivo preparado desde la interfaz de GitHub. No reemplazar el workflow de comprobacion FTPS existente.

Para anadirlo desde la web: **Add file > Create new file**, escribir `.github/workflows/deploy-ftps.yml`, pegar el contenido del archivo del paquete y guardar en `main` con **Commit changes**.

Si los archivos llegan mediante una pull request, revisar e incorporar primero esa propuesta. Una vez el workflow y el codigo esten en `main`, abrir **Actions > Desplegar Studio IA > Run workflow > main > Run workflow**. Solo se ejecuta manualmente y solo despliega desde `main`; subir un commit no publica nada por si solo.

## Que hace el despliegue

1. Valida la sintaxis de los dos archivos PHP y ejecuta las pruebas del proceso de despliegue.
2. Verifica FTPS, el certificado y el acceso a `public/`.
3. Comprueba si ya existe una pagina de inicio. Rechaza sobrescribir un `index.php` distinto o convivir con `index.html`/`index.htm` sin revision.
4. Guarda el puntero previo y el manifiesto en `deployments/<id>/` y sube el codigo a `releases/<sha>/` fuera de la raiz web.
5. Instala el controlador publico en la primera instalacion y activa la version mediante el renombrado de `current.json`.
6. Consulta `https://studio.eskalamos.com/?health=1` mediante HTTPS y exige el mismo SHA del commit.
7. Si la activacion o la comprobacion web falla, intenta restaurar el puntero anterior. Si tambien falla la conexion durante la restauracion, informa de que no se ha podido confirmar y conserva la copia para recuperacion manual.

El proceso no borra archivos remotos. No modifica `.env`, `storage/`, `.htaccess`, `.user.ini`, `php.ini`, `.well-known/` ni `cgi-bin/`. El renombrado se comprueba mediante lectura; depende de que el servidor permita RNFR/RNTO en los directorios de esta cuenta FTP.

La URL HTTPS y su certificado deben funcionar antes de desplegar. La comprobacion confirma ejecucion PHP y la version servida, pero no valida todavia las APIs, la base de datos ni el cron.

## Recuperacion

Cada despliegue guarda `deployments/<id>/previous-current.json` antes de activar la nueva version. Para recuperar manualmente, inspeccionar esa copia y su version; restaurar su contenido en `current.json` en la raiz privada del Studio y comprobar `/?health=1`. Conservar las carpetas de versiones mientras puedan ser necesarias. En una primera instalacion fallida, el puntero queda con `release: null` y se muestra HTTP 503, pendiente de activacion.

## Desarrollo y comprobaciones

```sh
php -l public/index.php
php -l app/entry.php
python3 -m unittest discover -s tests -v
python3 scripts/deploy_ftps.py --release 0000000000000000000000000000000000000000 --validate-only
```

`scripts/deploy_ftps.py` usa solo la biblioteca estandar de Python. Las pruebas simulan el sistema FTP y no utilizan credenciales ni conexiones de produccion.

Los archivos desplegables se enumeran expresamente en el script. Al ampliar la aplicacion, actualizar esa lista junto con sus pruebas; no sustituirla por una subida indiscriminada del repositorio.

## Configuracion futura de APIs

Crear `.env` en la raiz privada del Studio tomando `.env.example` como referencia. Las claves permaneceran fuera de `public/` y de Git. No se necesita una clave de la API de OpenAI para esta base de instalacion.
