"""Controlled TLS/S3 fixture. SigV4 authenticated PUT/GET/DELETE with private objects."""
from http.server import HTTPServer, BaseHTTPRequestHandler
import ssl, os, hashlib, hmac, urllib.parse, re, json, uuid, time
TOKEN="pharos-fixture-probe-token-0000000000000000000000000000000000000000"
remaining=list(range(5)); issued={}; accepted=[]
ROOT='/backups/s3'; os.makedirs(ROOT,exist_ok=True)
class Handler(BaseHTTPRequestHandler):
 def log_message(self,*args): pass
 def authenticated(self):
  auth=self.headers.get('Authorization',''); m=re.fullmatch(r'AWS4-HMAC-SHA256 Credential=pharosfixture/([^,]+), SignedHeaders=([^,]+), Signature=([0-9a-f]{64})',auth)
  if not m:return False
  scope,headers,signature=m.groups();date,region,service,ending=scope.split('/'); url=urllib.parse.urlsplit(self.path)
  canonical_headers=''.join(k+':'+re.sub(r'\s+',' ',self.headers.get(k,'').strip())+'\n' for k in headers.split(';'))
  query='&'.join(urllib.parse.quote(k,safe='-_.~')+'='+urllib.parse.quote(v,safe='-_.~') for k,v in sorted(urllib.parse.parse_qsl(url.query,keep_blank_values=True)))
  canonical='\n'.join([self.command,urllib.parse.quote(urllib.parse.unquote(url.path),safe='/-_.~'),query,canonical_headers,headers,self.headers.get('X-Amz-Content-Sha256','UNSIGNED-PAYLOAD')])
  stamp=self.headers.get('X-Amz-Date','');to_sign='AWS4-HMAC-SHA256\n'+stamp+'\n'+scope+'\n'+hashlib.sha256(canonical.encode()).hexdigest()
  key=('AWS4'+'Fixture-s3-secret-123').encode()
  for value in [date,region,service,ending]:key=hmac.new(key,value.encode(),hashlib.sha256).digest()
  return hmac.compare_digest(hmac.new(key,to_sign.encode(),hashlib.sha256).hexdigest(),signature)
 def location(self):
  path=urllib.parse.unquote(urllib.parse.urlsplit(self.path).path)
  if not path.startswith('/fixturebackup/') or '..' in path:return None
  return ROOT+'/'+path[len('/fixturebackup/'):].replace('/','_')
 def respond(self,code,body=b'',headers={}):
  self.send_response(code)
  for k,v in headers.items():self.send_header(k,str(v))
  self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
 def do_GET(self):
  if self.path.startswith('/slow'):
   time.sleep(float(os.environ.get('PHAROS_FIXTURE_JOB_DELAY','25')))
   return self.respond(200,b'fixture ready')
  if self.path=='/oversize':return self.respond(200,b'x'*1048576)
  if self.path=='/api/v1/probe/jobs':
   if self.headers.get('Authorization')!='Bearer '+TOKEN:return self.respond(401)
   if not remaining:return self.respond(200,b'{"jobs":[]}')
   index=remaining.pop(0);nonce=str(uuid.uuid4());issued[nonce]=time.monotonic()+120
   job={'id':nonce,'type':'http','target':'https://172.17.0.4:9443/slow?check='+str(index),'timeout_seconds':30}
   return self.respond(200,json.dumps({'jobs':[job]}).encode())
  if self.path=='/api/v1/probe/evidence':return self.respond(200,json.dumps({'accepted':len(accepted),'remaining':len(remaining)}).encode())
  if self.path=='/up':return self.respond(200,b'fixture ready')
  if self.path=='/redirect':return self.respond(302,b'',{'Location':'http://169.254.169.254/latest/meta-data/'})
  path=self.location()
  if not path or not self.authenticated():return self.respond(403)
  if not os.path.isfile(path):return self.respond(404)
  data=open(path,'rb').read();self.respond(200,data,{'ETag':'"'+hashlib.md5(data).hexdigest()+'"','Content-Type':'application/octet-stream'})
 def do_POST(self):
  if self.path!='/api/v1/probe/results' or self.headers.get('Authorization')!='Bearer '+TOKEN:return self.respond(401)
  data=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))));nonce=data['job']
  if nonce not in issued or issued.pop(nonce)<time.monotonic():return self.respond(409)
  if not data.get('ok'):return self.respond(422)
  accepted.append(nonce);return self.respond(200,b'{"ok":true}')
 def do_PUT(self):
  path=self.location()
  if not path or not self.authenticated():return self.respond(403)
  data=self.rfile.read(int(self.headers.get('Content-Length','0')))
  open(path,'wb').write(data);self.respond(200,b'',{'ETag':'"'+hashlib.md5(data).hexdigest()+'"'})
 def do_DELETE(self):
  path=self.location()
  if not path or not self.authenticated():return self.respond(403)
  if os.path.isfile(path):os.unlink(path)
  self.respond(204)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain('/tmp/fixture.pem','/tmp/fixture.key')
server=HTTPServer(('0.0.0.0',9443),Handler);server.socket=context.wrap_socket(server.socket,server_side=True);server.serve_forever()
