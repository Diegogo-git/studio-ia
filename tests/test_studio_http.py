"""HTTP authorization and CSRF checks against an isolated PHP fixture (no live APIs)."""
import hashlib
import http.cookiejar
import json
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.error import HTTPError
from urllib.request import Request, build_opener, HTTPCookieProcessor


@unittest.skipUnless(shutil.which('php'), 'PHP unavailable in local environment')
class StudioHTTPTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        release = root / 'releases' / ('a' * 40) / 'app'
        release.mkdir(parents=True)
        shutil.copy(Path(__file__).resolve().parents[1] / 'app/entry.php', release / 'entry.php')
        public = root / 'public'
        public.mkdir()
        (public/'index.php').write_text("<?php define('STUDIO_RELEASE', '"+'a'*40+"'); require __DIR__.'/../releases/"+'a'*40+"/app/entry.php';")
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.base = f'http://127.0.0.1:{port}/'
        (root/'.env').write_text('APP_URL='+self.base.rstrip('/')+'\n')
        self.proc = subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(public)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        for _ in range(100):
            try:
                self.page = self.client.open(self.base).read().decode()
                break
            except OSError:
                time.sleep(.02)
        else:
            self.fail('PHP server did not start')
        self.root = root
        self.csrf = re.search(r'const boot=(.*?);', self.page).group(1)
        self.csrf = json.loads(self.csrf)['csrf']
        # Production cookies remain Secure; the isolated HTTP fixture adapts its jar.
        for handler in self.client.handlers:
            if isinstance(handler, HTTPCookieProcessor):
                for cookie in handler.cookiejar:
                    cookie.secure = False  # local fixture is HTTP only

    def tearDown(self):
        self.proc.terminate()
        self.proc.wait(timeout=5)
        self.tmp.cleanup()

    def call(self, action, payload=None, csrf=True, origin=None):
        headers = {}
        if payload is not None:
            headers['Content-Type'] = 'application/json'
            if csrf:
                headers['X-CSRF-Token'] = self.csrf
            if origin:
                headers['Origin'] = origin
        req = Request(self.base+'?action='+action, data=json.dumps(payload).encode() if payload is not None else None, headers=headers)
        try:
            with self.client.open(req) as res:
                return res.status, json.load(res)
        except HTTPError as err:
            return err.code, json.load(err)

    def test_unauthenticated_cannot_spend_or_read(self):
        for action in ['jobs','config','voices','audio&id=11111111-1111-4111-8111-111111111111']:
            self.assertEqual(self.call(action)[0], 401)
        self.assertEqual(self.call('generate', {'kind':'image','prompt':'test','accept_cost':True})[0], 401)
        self.assertNotIn('HIGGSFIELD_API_KEY_SECRET=', self.page)

    def test_setup_is_one_time_and_csrf_protected(self):
        token='b'*64
        storage=self.root/'storage'
        (storage/'setup.json').write_text(json.dumps({'token_hash':hashlib.sha256(token.encode()).hexdigest(),'expires':int(time.time())+600}))
        data={'token':token,'password':'fixture-password-only-123'}
        self.assertEqual(self.call('setup',data,csrf=False)[0],403)
        self.assertEqual(self.call('setup',data,origin='https://evil.example')[0],403)
        self.assertEqual(self.call('setup',data)[0],200)
        for handler in self.client.handlers:
            if isinstance(handler, HTTPCookieProcessor):
                for cookie in handler.cookiejar:
                    cookie.secure=False
        self.assertEqual(self.call('setup',data)[0],409)
        self.assertEqual(self.call('jobs')[0],200)
        self.assertEqual(self.call('generate',{'kind':'image','prompt':'test','accept_cost':False})[0],400)
        self.assertNotIn('password_hash',json.dumps(self.call('config')[1]))
        self.assertEqual(self.call('logout',{})[0],200)
        self.assertEqual(self.call('jobs')[0],401)


if __name__ == '__main__':
    unittest.main()
