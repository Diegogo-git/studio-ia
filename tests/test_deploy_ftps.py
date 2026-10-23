import ftplib
import importlib.util
import json
from pathlib import Path
import posixpath
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("deploy_ftps", ROOT / "scripts/deploy_ftps.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
RELEASE = "a" * 40
OLD_RELEASE = "b" * 40


class MemoryFTP:
    """FTP filesystem double: no network, no real credentials or hosting files."""

    def __init__(self):
        self.current = "/"
        self.directories = {"/", "/public"}
        self.files = {}
        self.writes = []
        self.denied = set()
        self.corrupt_uploads = False

    def absolute(self, path):
        return posixpath.normpath(posixpath.join(self.current, path))

    def cwd(self, path):
        path = self.absolute(path)
        if path not in self.directories:
            raise ftplib.error_perm("550 Missing directory")
        self.current = path

    def mkd(self, path):
        target = self.absolute(path)
        if posixpath.dirname(target) not in self.directories:
            raise ftplib.error_perm("550 Missing parent")
        self.directories.add(target)

    def nlst(self):
        return sorted({posixpath.basename(path) for path in self.directories | set(self.files)
                       if path != self.current and posixpath.dirname(path) == self.current})

    def mlsd(self):
        return [(name, {}) for name in self.nlst()]

    def retrbinary(self, command, callback):
        target = self.absolute(command.removeprefix("RETR "))
        if target in self.denied or target not in self.files:
            raise ftplib.error_perm("550 Read denied")
        callback(self.files[target])

    def storbinary(self, command, stream):
        target = self.absolute(command.removeprefix("STOR "))
        if posixpath.dirname(target) not in self.directories:
            raise ftplib.error_perm("550 Missing parent")
        self.writes.append(target)
        self.files[target] = b"bad-upload" if self.corrupt_uploads else stream.read()

    def rename(self, source, target):
        source, target = self.absolute(source), self.absolute(target)
        self.files[target] = self.files.pop(source)


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.ftp = MemoryFTP()
        self.remote = MODULE.Remote(self.ftp)
        self.package = MODULE.build_package(ROOT, RELEASE)

    def run_deploy(self, check=lambda version: None):
        MODULE.deploy(self.remote, self.package, RELEASE, "test-release", check)

    def test_web_check_identifies_the_deployment_client(self):
        class Response:
            url = "https://studio.eskalamos.com/?health=1"
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self, size): return MODULE.payload({"status": "ok", "app": "studio-ia", "release": RELEASE})
        with patch.object(MODULE, "urlopen", return_value=Response()) as request:
            MODULE.verify_web("https://studio.eskalamos.com", RELEASE)
        self.assertEqual(request.call_args.args[0].get_header("User-agent"), "StudioIA-Deploy/1.0")
        self.assertIsNotNone(request.call_args.kwargs["context"])

    def test_web_check_reports_http_status_without_server_body(self):
        error = HTTPError("https://studio.eskalamos.com", 403, "private-server-detail", {}, None)
        with patch.object(MODULE, "urlopen", side_effect=error), patch.object(MODULE.time, "sleep"):
            with self.assertRaisesRegex(MODULE.DeploymentError, "HTTP 403") as raised:
                MODULE.verify_web("https://studio.eskalamos.com", RELEASE)
        self.assertNotIn("private-server-detail", str(raised.exception))

    def test_success_preserves_credentials_storage_and_cpanel_settings(self):
        protected = {"/.env": b"never-publish-this-secret", "/storage/audio.mp3": b"audio",
                     "/public/.htaccess": b"cpanel-config", "/public/.user.ini": b"php-config"}
        self.ftp.directories.add("/storage")
        self.ftp.files.update(protected)
        checked = []
        self.run_deploy(checked.append)
        self.assertEqual(checked, [RELEASE])
        self.assertEqual(json.loads(self.ftp.files["/current.json"])["release"], RELEASE)
        for path, data in protected.items():
            self.assertEqual(self.ftp.files[path], data)
            self.assertNotIn(path, self.ftp.writes)
        self.assertNotIn(b"never-publish-this-secret", b"".join(self.ftp.files[p] for p in self.ftp.writes if p in self.ftp.files))

    def test_health_failure_restores_previous_pointer(self):
        previous = MODULE.payload({"release": OLD_RELEASE})
        self.ftp.files["/current.json"] = previous
        def fail_health(version):
            raise MODULE.DeploymentError("Unavailable")
        with self.assertRaisesRegex(MODULE.DeploymentError, "restauro"):
            self.run_deploy(fail_health)
        self.assertEqual(self.ftp.files["/current.json"], previous)
        self.assertEqual(self.ftp.files["/deployments/test-release/previous-current.json"], previous)

    def test_first_install_health_failure_returns_to_pending(self):
        def fail_health(version):
            raise MODULE.DeploymentError("Unavailable")
        with self.assertRaises(MODULE.DeploymentError):
            self.run_deploy(fail_health)
        self.assertIsNone(json.loads(self.ftp.files["/current.json"])["release"])

    def test_refuses_to_overwrite_unrelated_index(self):
        self.ftp.files["/public/index.php"] = b"existing-application"
        with self.assertRaisesRegex(MODULE.DeploymentError, "ya existe"):
            self.run_deploy()
        self.assertEqual(self.ftp.writes, [])

    def test_refuses_an_existing_html_homepage(self):
        self.ftp.files["/public/index.html"] = b"existing-website"
        with self.assertRaisesRegex(MODULE.DeploymentError, "pagina de inicio previa"):
            self.run_deploy()
        self.assertEqual(self.ftp.writes, [])

    def test_refuses_to_overwrite_unrelated_chat_controller(self):
        self.ftp.files['/public/mcp.php'] = b'existing-mcp-server'
        with self.assertRaisesRegex(MODULE.DeploymentError, 'public/mcp.php ya existe'):
            self.run_deploy()
        self.assertEqual(self.ftp.writes, [])

    def test_discovery_preserves_existing_acme_files(self):
        self.ftp.directories.update({'/public/.well-known', '/public/.well-known/acme-challenge'})
        path='/public/.well-known/acme-challenge/existing-challenge'
        self.ftp.files[path]=b'certificate-challenge'
        self.run_deploy()
        self.assertEqual(self.ftp.files[path], b'certificate-challenge')
        self.assertNotIn(path, self.ftp.writes)
        self.assertEqual(self.ftp.files['/public/mcp.php'],self.package['public/mcp.php'])

    def test_chat_web_check_requires_discovery_and_oauth_challenge(self):
        base='https://studio.eskalamos.com'
        class Response:
            def __init__(self,path,data): self.url=base+path; self.data=data
            def __enter__(self): return self
            def __exit__(self,*args): return False
            def read(self,size): return json.dumps(self.data).encode()
        responses=[Response('/.well-known/oauth-authorization-server/oauth.php',{'issuer':base+'/oauth.php'}),
                   Response('/.well-known/oauth-protected-resource/mcp.php',{'resource':base+'/mcp.php'}),
                   HTTPError(base+'/mcp.php',401,'Unauthorized',{'WWW-Authenticate':'Bearer resource_metadata="'+base+'/oauth.php?route=resource"'},None)]
        with patch.object(MODULE,'urlopen',side_effect=responses) as requests:
            MODULE.verify_chat_web(base)
            self.assertEqual(requests.call_count,3)
        with patch.object(MODULE,'urlopen',return_value=Response('/wrong',{})):
            with self.assertRaisesRegex(MODULE.DeploymentError,'descubrimiento OAuth'):
                MODULE.verify_chat_web(base)

    def test_read_denied_is_not_treated_as_missing(self):
        self.ftp.files["/public/index.php"] = b"unreadable-existing-application"
        self.ftp.denied.add("/public/index.php")
        with self.assertRaises(ftplib.error_perm):
            self.run_deploy()
        self.assertEqual(self.ftp.writes, [])

    def test_release_is_immutable(self):
        self.ftp.directories.update({"/releases", "/releases/" + RELEASE, "/releases/" + RELEASE + "/app"})
        target = "/releases/" + RELEASE + "/app/entry.php"
        self.ftp.files[target] = b"different-code"
        with self.assertRaisesRegex(MODULE.DeploymentError, "otro contenido"):
            self.run_deploy()
        self.assertEqual(self.ftp.files[target], b"different-code")
        self.assertNotIn("/current.json", self.ftp.writes)

    def test_corrupt_upload_cannot_activate(self):
        self.ftp.corrupt_uploads = True
        with self.assertRaisesRegex(MODULE.DeploymentError, "verificacion"):
            self.run_deploy()
        self.assertNotIn("/current.json", self.ftp.files)

    def test_root_current_pointer_is_readable(self):
        self.ftp.files["/current.json"] = b"pointer"
        self.assertEqual(self.remote.read("current.json"), b"pointer")
        self.assertEqual(self.remote.read("/current.json"), b"pointer")

    def test_invalid_release_and_symlinks_are_rejected(self):
        with self.assertRaises(MODULE.DeploymentError):
            MODULE.build_package(ROOT, "../invalid")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "public").mkdir()
            (root / "public/index.php").symlink_to(ROOT / "public/index.php")
            with self.assertRaises(MODULE.DeploymentError):
                MODULE.build_package(root, RELEASE)


if __name__ == "__main__":
    unittest.main()
