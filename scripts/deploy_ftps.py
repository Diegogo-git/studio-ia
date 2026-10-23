"""Manual FTPS release deployment. Standard library only; never uploads .env."""

from __future__ import annotations

import argparse
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path
import re
import secrets
import ssl
import sys
import time
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


class DeploymentError(Exception):
    pass


PUBLIC_FILES = ("public/index.php",)
PRIVATE_FILES = ("app/entry.php",)
MARKER = b"STUDIO_IA_FRONT_CONTROLLER_V1"
MAX_REMOTE_BYTES = 1024 * 1024


def build_package(root: Path, release: str) -> dict[str, bytes]:
    if not re.fullmatch(r"[a-f0-9]{40}", release):
        raise DeploymentError("La version debe ser un SHA Git completo de 40 caracteres.")
    package = {}
    for relative in PUBLIC_FILES + PRIVATE_FILES:
        path = root / relative
        if path.is_symlink() or not path.is_file():
            raise DeploymentError("Falta un archivo de la lista de despliegue o es un enlace simbolico.")
        package[relative] = path.read_bytes()
    return package


def payload(value: dict) -> bytes:
    return (json.dumps(value, sort_keys=True) + "\n").encode()


class Remote:
    def __init__(self, ftp):
        self.ftp = ftp

    def directory(self, path: str):
        self.ftp.cwd("/")
        for component in path.strip("/").split("/"):
            if not component or component in (".", ".."):
                raise DeploymentError("Directorio remoto invalido.")
            try:
                self.ftp.cwd(component)
            except ftplib.error_perm:
                self.ftp.mkd(component)
                self.ftp.cwd(component)
        self.ftp.cwd("/")

    def read(self, path: str) -> bytes | None:
        # Check the parent listing before RETR. An unreadable existing file must
        # not be confused with a missing file and then overwritten.
        path = path.lstrip("/")
        parent, _, name = path.rpartition("/")
        self.ftp.cwd("/" + parent)
        try:
            names = {item.rstrip("/").rsplit("/", 1)[-1] for item in self.ftp.nlst()}
        except ftplib.error_perm:
            # Empty NLST responses differ across FTP servers. MLSD provides a
            # second read-only check; any failure aborts instead of overwriting.
            names = {item[0] for item in self.ftp.mlsd()}
        finally:
            self.ftp.cwd("/")
        if name not in names:
            return None
        data = bytearray()

        def collect(chunk):
            data.extend(chunk)
            if len(data) > MAX_REMOTE_BYTES:
                raise DeploymentError("Archivo remoto demasiado grande para comprobarlo.")

        self.ftp.retrbinary("RETR " + path, collect)
        return bytes(data)

    def write(self, path: str, data: bytes):
        self.ftp.storbinary("STOR " + path, io.BytesIO(data))
        if self.read(path) != data:
            raise DeploymentError("La verificacion de un archivo subido ha fallado.")

    def replace(self, path: str, data: bytes, staging: str, name: str):
        temporary = staging + "/" + name
        self.write(temporary, data)
        # RNFR/RNTO on the same filesystem; never delete the live destination.
        self.ftp.rename(temporary, path)
        if self.read(path) != data:
            raise DeploymentError("La comprobacion del archivo activado ha fallado.")


def verify_web(base_url: str, release: str):
    parsed = urlsplit(base_url)
    if (parsed.scheme != "https" or parsed.hostname != "studio.eskalamos.com"
            or parsed.username or parsed.password or parsed.port not in (None, 443)
            or parsed.path not in ("", "/") or parsed.query or parsed.fragment):
        raise DeploymentError("STUDIO_URL debe ser https://studio.eskalamos.com sin rutas ni parametros.")
    url = base_url.rstrip("/") + "/?health=1&check=" + secrets.token_hex(8)
    for attempt in range(3):
        try:
            request = Request(url, headers={"Cache-Control": "no-cache"})
            with urlopen(request, timeout=20, context=ssl.create_default_context()) as response:
                if urlsplit(response.url).hostname != parsed.hostname:
                    raise DeploymentError("La comprobacion web se ha redirigido a otro dominio.")
                result = json.loads(response.read(65536))
            if result.get("app") == "studio-ia" and result.get("status") == "ok" and result.get("release") == release:
                return
        except Exception:
            pass
        if attempt < 2:
            time.sleep(2)
    raise DeploymentError("La web no confirma la version subida mediante HTTPS.")


def deploy(remote: Remote, package: dict[str, bytes], release: str, deployment_id: str, check_web):
    # FTP / is already jailed to /home5/cywqgkxj/studio.eskalamos.com.
    remote.ftp.cwd("/")
    remote.ftp.cwd("public")
    remote.ftp.cwd("/")
    existing_index = remote.read("public/index.php")
    if existing_index is not None and existing_index != package["public/index.php"]:
        raise DeploymentError("public/index.php ya existe y difiere del controlador previsto. Revisar antes de sobrescribirlo.")
    for alternate in ("public/index.html", "public/index.htm"):
        if remote.read(alternate) is not None:
            raise DeploymentError("Hay una pagina de inicio previa. Revisar su prioridad antes de desplegar.")

    remote.directory("releases")
    remote.directory("deployments")
    # / is the account root, not the server filesystem root.
    previous = remote.read("/current.json")
    if previous is not None:
        try:
            previous_data = json.loads(previous)
            previous_release = previous_data.get("release")
            if previous_release is not None and not re.fullmatch(r"[a-f0-9]{40}", previous_release):
                raise ValueError
        except (ValueError, TypeError, AttributeError):
            raise DeploymentError("current.json ya existe con un formato inesperado.") from None

    staging = "deployments/" + deployment_id
    remote.directory(staging)
    remote.write(staging + "/previous-current.json", previous if previous is not None else payload({"release": None}))
    release_root = "releases/" + release
    for relative in PRIVATE_FILES:
        destination = release_root + "/" + relative
        remote.directory(destination.rsplit("/", 1)[0])
        old = remote.read(destination)
        if old is not None and old != package[relative]:
            raise DeploymentError("La version remota ya existe con otro contenido; se conserva intacta.")
        if old is None:
            remote.write(destination, package[relative])
    manifest = payload({"release": release, "files": {path: hashlib.sha256(data).hexdigest() for path, data in package.items()}})
    remote.write(staging + "/manifest.json", manifest)
    if existing_index is None:
        remote.replace("public/index.php", package["public/index.php"], staging, "index.php")
    current = payload({"release": release})
    try:
        remote.replace("current.json", current, staging, "next-current.json")
        check_web(release)
    except Exception:
        # A lost connection can occur after RNTO succeeded. Always attempt to
        # restore the saved pointer; do not assume that a failed command means
        # the live version did not change.
        try:
            remote.replace("current.json", previous if previous is not None else payload({"release": None}), staging, "restore-current.json")
        except Exception:
            raise DeploymentError("Fallo de activacion y restauracion no confirmada. Revisar current.json con cPanel y la copia previous-current.json de este despliegue.") from None
        raise DeploymentError("La comprobacion web fallo; se restauro el puntero anterior. En la primera instalacion el Studio queda pendiente de activacion.") from None
    print("Version activada y comprobada por HTTPS.")
    print("No se han modificado .env, storage/, .htaccess ni los ajustes PHP del hosting.")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--release", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--validate-only", action="store_true")
    args = parser.parse_args()
    package = build_package(args.root, args.release)
    if args.validate_only:
        print("Paquete validado: " + ", ".join(sorted(package)))
        return
    host = os.environ.get("FTP_SERVER", "afrodita.dns-es.com").strip()
    username = os.environ.get("FTP_USERNAME", "deploy-studio@eskalamos.com").strip()
    password = os.environ.get("FTP_PASSWORD", "")
    if not host or not username or not password:
        raise DeploymentError("Falta configurar el acceso FTPS en los secretos de GitHub.")
    try:
        port = int(os.environ.get("FTP_PORT", "21"))
        if not 1 <= port <= 65535:
            raise ValueError
    except ValueError:
        raise DeploymentError("Puerto FTPS invalido.") from None
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    ftp = ftplib.FTP_TLS(context=context, timeout=30)
    try:
        ftp.connect(host, port)
        ftp.login(username, password)
        ftp.prot_p()
        ftp.set_pasv(True)
        deploy(remote=Remote(ftp), package=package, release=args.release,
               deployment_id=time.strftime("%Y%m%dT%H%M%S", time.gmtime()) + "-" + secrets.token_hex(6),
               check_web=lambda release: verify_web(os.environ.get("STUDIO_URL", "https://studio.eskalamos.com"), release))
    finally:
        ftp.close()


if __name__ == "__main__":
    try:
        main()
    except DeploymentError as exc:
        print("ERROR: " + str(exc), file=sys.stderr)
        sys.exit(1)
    except Exception:
        # Do not log raw FTP exceptions, server responses or credentials.
        print("ERROR: no se ha completado el despliegue. Revisar conexion FTPS, certificado y permisos del Studio.", file=sys.stderr)
        sys.exit(1)
