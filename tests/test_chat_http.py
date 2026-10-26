"""Real local HTTP OAuth/MCP exchanges; fictitious account and zero provider calls."""
import base64
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
from urllib.parse import urlencode, urlsplit, parse_qs
from urllib.request import Request, build_opener, HTTPCookieProcessor, HTTPRedirectHandler

ROOT = Path(__file__).resolve().parents[1]
CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect'


class NoExternalRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


@unittest.skipUnless(shutil.which('php'), 'PHP unavailable in local environment')
class ChatHTTPTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        release = self.root / 'releases' / ('a'*40) / 'app'
        release.mkdir(parents=True)
        for module in (ROOT/'app').glob('*.php'):
            shutil.copy(module, release/module.name)
        self.public = self.root/'public'
        shutil.copytree(ROOT/'public', self.public)
        index = self.public/'index.php'
        index.write_text(index.read_text().replace('declare(strict_types=1);', "declare(strict_types=1); define('STUDIO_CHAT_TEST_HTTP',true);"))
        (self.root/'current.json').write_text(json.dumps({'release':'a'*40}))
        self.storage = self.root/'storage'
        self.storage.mkdir(mode=0o700)
        sessions = self.root/'sessions'
        sessions.mkdir(mode=0o700)
        fixture_hash = subprocess.check_output(['php','-r',"echo password_hash('fixture-password-only', PASSWORD_DEFAULT);"]).decode()
        (self.storage/'account.json').write_text(json.dumps({'id':'owner','password_hash':fixture_hash}))
        (self.storage/'worker-status.json').write_text(json.dumps({'last_seen':int(time.time()),'status':'idle'}))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0))
            port=sock.getsockname()[1]
        self.base=f'http://127.0.0.1:{port}'
        (self.root/'.env').write_text(f'APP_URL={self.base}\nHIGGSFIELD_API_KEY=fixture:key\nELEVENLABS_API_KEY=fixture-key\nELEVENLABS_DEFAULT_VOICE_ID=pZv6Kbgq62dtlvkJTupr\n')
        self.client=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),NoExternalRedirect())
        self.proc=subprocess.Popen(['php','-d','session.save_path='+str(sessions),'-S',f'127.0.0.1:{port}','-t',str(self.public)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                if self.call('/?health=1')[0]==200:
                    break
            except OSError:
                time.sleep(.02)
        else:
            self.fail('PHP server failed to start')

    def tearDown(self):
        self.proc.terminate()
        self.proc.wait(timeout=5)
        self.tmp.cleanup()

    def call(self,path,data=None,token=None,method=None,headers=None,form=False):
        headers=dict(headers or {})
        if token:
            headers['Authorization']='Bearer '+token
        if data is not None:
            headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
            data=(urlencode(data) if form else json.dumps(data)).encode()
        req=Request(self.base+path,data=data,headers=headers,method=method)
        try:
            res=self.client.open(req,timeout=5)
        except HTTPError as err:
            res=err
        with res:
            body=res.read()
            value=json.loads(body) if 'application/json' in res.headers.get('Content-Type','') else body.decode()
            return res.code,dict(res.headers),value

    def authorize(self,scope='studio:read studio:generate',decision='allow'):
        status,_,client=self.call('/oauth.php?route=register',{'redirect_uris':[CALLBACK],'client_name':'ChatGPT test','token_endpoint_auth_method':'none'})
        self.assertEqual(status,201)
        verifier='v'*43
        challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=')
        params={'client_id':client['client_id'],'redirect_uri':CALLBACK,'state':'fixture-state','response_type':'code','scope':scope,'resource':self.base+'/mcp.php','code_challenge_method':'S256','code_challenge':challenge}
        status,headers,page=self.call('/oauth.php?'+urlencode({'route':'authorize',**params}))
        self.assertEqual(status,200)
        self.assertIn("form-action 'self' https://chatgpt.com https://chat.openai.com",headers['Content-Security-Policy'])
        self.assertEqual(headers['Referrer-Policy'],'same-origin')
        if 'Set-Cookie' in headers:
            self.assertIn('HttpOnly',headers['Set-Cookie'])
            self.assertIn('SameSite=Lax',headers['Set-Cookie'])
        csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
        # Browser form submissions must retain their origin, not bypass origin or CSRF validation.
        for origin in ('null','https://evil.test'):
            status,_,result=self.call('/oauth.php?route=authorize',{'csrf':csrf,'password':'fixture-password-only','decision':decision},form=True,headers={'Origin':origin})
            self.assertEqual(status,403)
            self.assertEqual(result['error'],'Origen no autorizado.')
        status,_,result=self.call('/oauth.php?route=authorize',{'csrf':'bad','password':'fixture-password-only','decision':decision},form=True,headers={'Origin':self.base})
        self.assertEqual(status,403)
        status,error_headers,error_page=self.call('/oauth.php?route=authorize',{'csrf':csrf,'password':'invalid-fixture-password','decision':'allow'},form=True,headers={'Origin':self.base})
        self.assertEqual(status,401)
        self.assertEqual(error_headers['Referrer-Policy'],'same-origin')
        self.assertIn('Contraseña incorrecta.',error_page)
        status,headers,result=self.call('/oauth.php?route=authorize',{'csrf':csrf,'password':'fixture-password-only','decision':decision},form=True,headers={'Origin':self.base})
        self.assertEqual(status,303,str(result))
        redirect=urlsplit(headers['Location'])
        self.assertEqual(redirect.scheme+'://'+redirect.netloc+redirect.path,CALLBACK)
        query=parse_qs(redirect.query)
        self.assertEqual(query['state'],['fixture-state'])
        self.assertEqual(query['iss'],[self.base+'/oauth.php'])
        if decision=='deny':
            self.assertEqual(query['error'],['access_denied'])
            self.assertNotIn('code',query)
            return None
        body={'grant_type':'authorization_code','client_id':client['client_id'],'redirect_uri':CALLBACK,'code_verifier':verifier,'resource':self.base+'/mcp.php','code':query['code'][0]}
        status,headers,tokens=self.call('/oauth.php?route=token',body,form=True)
        self.assertEqual(status,200)
        self.assertEqual(headers['Cache-Control'],'no-store, max-age=0')
        return tokens,client

    def rpc(self,token,method,params=None,identifier=1,headers=None):
        return self.call('/mcp.php',{'jsonrpc':'2.0','id':identifier,'method':method,'params':params or {}},token,headers=headers)

    def test_discovery_and_unauthorized_challenge(self):
        status,headers,body=self.call('/mcp.php',{'jsonrpc':'2.0','id':1,'method':'initialize'})
        self.assertEqual(status,401)
        self.assertIn('resource_metadata="'+self.base+'/oauth.php?route=resource"',headers['WWW-Authenticate'])
        status,_,resource=self.call('/.well-known/oauth-protected-resource/mcp.php')
        self.assertEqual(status,200)
        self.assertEqual(resource['resource'],self.base+'/mcp.php')
        self.assertEqual(resource['authorization_servers'],[self.base+'/oauth.php'])
        status,_,metadata=self.call('/.well-known/oauth-authorization-server/oauth.php')
        self.assertEqual(status,200)
        self.assertEqual(metadata['issuer'],resource['authorization_servers'][0])
        self.assertEqual(metadata['token_endpoint_auth_methods_supported'],['none'])
        self.assertEqual(self.call('/worker.php')[0],404)
        self.assertEqual(self.call('/studio-api.php?op=status')[0],401)

    def test_consent_mcp_queue_duplicate_and_scopes(self):
        tokens,_=self.authorize()
        token=tokens['access_token']
        status,_,response=self.rpc(token,'initialize',{'protocolVersion':'2025-11-25','capabilities':{},'clientInfo':{'name':'fixture','version':'1'}})
        self.assertEqual(status,200)
        self.assertEqual(response['result']['protocolVersion'],'2025-11-25')
        self.assertEqual(self.call('/mcp.php',{'jsonrpc':'2.0','method':'notifications/initialized'},token)[0],202)
        status,_,response=self.rpc(token,'tools/list')
        self.assertEqual(status,200)
        tools={t['name']:t for t in response['result']['tools']}
        self.assertEqual(len(tools),7)
        self.assertEqual(tools['studio_import_image']['_meta']['openai/fileParams'],['source_image'])
        file_schema=tools['studio_import_image']['inputSchema']['properties']['source_image']
        self.assertEqual(set(file_schema['properties']),{'download_url','file_id','mime_type','file_name'})
        self.assertEqual(set(file_schema['required']),{'download_url','file_id'})
        self.assertIn('image_id',tools['studio_create_generation']['inputSchema']['properties'])
        self.assertEqual(self.rpc(token,'tools/call',{'name':'studio_import_image','arguments':{'request_key':'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'}})[2]['result']['isError'],True)
        self.assertEqual(tools['studio_create_generation']['securitySchemes'][0]['scopes'],['studio:generate'])
        self.assertFalse(tools['studio_create_generation']['annotations']['readOnlyHint'])
        status,_,result=self.rpc(token,'tools/call',{'name':'studio_connection_status','arguments':{}})
        self.assertTrue(result['result']['structuredContent']['worker']['ready'])
        job={'request_key':'11111111-1111-4111-8111-111111111111','kind':'speech','prompt':'Prueba local.','accept_cost':True}
        for _ in range(2):
            status,_,result=self.rpc(token,'tools/call',{'name':'studio_create_generation','arguments':job})
            self.assertEqual(status,200)
            self.assertFalse(result['result']['isError'])
            self.assertEqual(result['result']['structuredContent']['job']['status'],'prepared')
        persisted=json.loads((self.storage/'jobs.json').read_text())
        self.assertEqual(len(persisted),1)
        self.assertEqual(persisted[job['request_key']]['attempts'],0)
        self.assertNotIn('fixture-key',json.dumps(result))
        self.assertNotIn('grant_id',json.dumps(result))
        self.assertEqual(self.rpc(token,'ping',headers={'Origin':'https://evil.test'})[0],403)
        self.assertEqual(self.call('/mcp.php',token=token)[0],405)
        self.assertEqual(self.rpc(token,'ping',identifier={})[0],400)
        self.assertEqual(self.rpc(token,'ping',headers={'MCP-Protocol-Version':'2000-01-01'})[0],400)
        read_tokens,_=self.authorize('studio:read')
        status,headers,_=self.rpc(read_tokens['access_token'],'tools/call',{'name':'studio_create_generation','arguments':job})
        self.assertEqual(status,403)
        self.assertIn('insufficient_scope',headers['WWW-Authenticate'])

    def test_rest_refresh_revoke_and_expiring_audio(self):
        tokens,client=self.authorize()
        token=tokens['access_token']
        job={'request_key':'22222222-2222-4222-8222-222222222222','kind':'speech','prompt':'Prueba local.','accept_cost':True}
        status,_,result=self.call('/studio-api.php?op=create',job,token)
        self.assertEqual(status,202)
        self.assertEqual(self.call('/studio-api.php?op=jobs',token=token)[2]['jobs'][0]['id'],job['request_key'])
        self.assertEqual(self.call('/studio-api.php?op=cancel',{'job_id':job['request_key']},token)[0],200)
        # Fake completed result; this fixture never calls a provider.
        jobs=json.loads((self.storage/'jobs.json').read_text())
        jobs[job['request_key']]['status']='completed'
        (self.storage/'jobs.json').write_text(json.dumps(jobs))
        (self.storage/(job['request_key']+'.mp3')).write_bytes(b'ID3fixture-audio')
        status,_,result=self.call('/studio-api.php?op=job&job_id='+job['request_key'],token=token)
        url=result['job']['media'][0]['url']
        status,headers,audio=self.call(url[len(self.base):])
        self.assertEqual(status,200)
        self.assertEqual(headers['Content-Type'],'audio/mpeg')
        self.assertEqual(audio,'ID3fixture-audio')
        links=json.loads((self.storage/'media-links.json').read_text())
        for link in links.values(): link['expires']=int(time.time())-1
        (self.storage/'media-links.json').write_text(json.dumps(links))
        self.assertEqual(self.call(url[len(self.base):])[0],404)
        refresh={'grant_type':'refresh_token','client_id':client['client_id'],'resource':self.base+'/mcp.php','refresh_token':tokens['refresh_token']}
        status,_,rotated=self.call('/oauth.php?route=token',refresh,form=True)
        self.assertEqual(status,200)
        self.assertEqual(self.call('/studio-api.php?op=status',token=rotated['access_token'])[0],200)
        self.assertEqual(self.call('/oauth.php?route=token',refresh,form=True)[0],400)
        self.assertEqual(self.call('/studio-api.php?op=status',token=rotated['access_token'])[0],401)
        self.assertEqual(self.call('/studio-api.php?op=status&access_token='+token)[0],401)

    def test_cancel_consent_and_reject_untrusted_callbacks(self):
        self.authorize(decision='deny')
        self.assertEqual(self.call('/oauth.php?route=register',{'redirect_uris':['https://evil.test/callback']})[0],400)
        self.assertEqual(self.call('/oauth.php?route=authorize',{'csrf':'bad','password':'fixture-password-only'},form=True)[0],403)
        self.assertEqual(self.call('/oauth.php?route=register',{'redirect_uris':[CALLBACK]},headers={'Origin':'https://evil.test'})[0],403)
        db=json.loads((self.storage/'oauth.json').read_text())
        self.assertFalse(db.get('grants'))


if __name__=='__main__':
    unittest.main()
