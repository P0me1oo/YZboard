import argparse,gzip,hashlib,http.client,http.server,json,os,pathlib,shutil,socket,subprocess,tempfile,threading,time
# 仅在本机临时端口验证代理与静态文件，不读取生产配置。
parser=argparse.ArgumentParser()
parser.add_argument('--nginx', required=True, help='本机 Nginx 可执行文件')
args=parser.parse_args()
workspace=tempfile.TemporaryDirectory(prefix='yzboard-nginx-test-')
root=pathlib.Path(workspace.name)
repo=pathlib.Path(__file__).resolve().parents[2]
worker_user='user root;' if os.geteuid()==0 else ''
class Backend(http.server.ThreadingHTTPServer):
    daemon_threads=True
    accepted=0
    def get_request(self):
        self.accepted+=1
        return super().get_request()
class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def do_GET(self):
        if self.path=='/ws':
            assert self.headers.get('Upgrade','').lower()=='websocket'
            assert self.headers.get('Connection','').lower()=='upgrade'
            import base64
            accept=base64.b64encode(hashlib.sha1((self.headers['Sec-WebSocket-Key']+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode()
            self.send_response(101);self.send_header('Upgrade','websocket');self.send_header('Connection','Upgrade');self.send_header('Sec-WebSocket-Accept',accept);self.end_headers();self.close_connection=True;return
        data=json.dumps({'path':self.path,'connection':self.headers.get('Connection'),'host':self.headers.get('Host')}).encode()
        self.send_response(200);self.send_header('Content-Length',str(len(data)));self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(data)
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
backend=Backend(('127.0.0.1',0),Handler)
threading.Thread(target=backend.serve_forever,daemon=True).start()
outer,inner=port(),port();back=backend.server_address[1]
pool=(repo/'docs/examples/nginx-yzboard-keepalive.conf').read_text().replace('127.0.0.1:7001',f'127.0.0.1:{inner}').replace('127.0.0.1:7002',f'127.0.0.1:{back}')
assets=(repo/'docs/examples/nginx-yzboard-admin-static.conf').read_text().replace('/root/yzboard/public',str(repo/'public'))
config=f'''worker_processes 1;
{worker_user}
pid {root}/nginx.pid;
error_log {root}/nginx-error.log notice;
events {{ worker_connections 256; }}
http {{
 access_log off;
 log_format pool '$connection $request_uri';
 client_body_temp_path {root}/body;
 proxy_temp_path {root}/proxy;
 fastcgi_temp_path {root}/fastcgi;
 uwsgi_temp_path {root}/uwsgi;
 scgi_temp_path {root}/scgi;
 types {{ application/javascript js; text/css css; image/svg+xml svg; }}
 {pool}
 server {{ listen 127.0.0.1:{inner};
  access_log {root}/nginx-entry.log pool;
  {assets}
  location = /ws {{ proxy_pass http://127.0.0.1:{back}; proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection $yzboard_keepalive_connection; }}
  location / {{ proxy_pass http://yzboard_http_worker; proxy_http_version 1.1; proxy_set_header Connection ""; proxy_set_header Host $http_host; }}
 }}
 server {{ listen 127.0.0.1:{outer};
  location / {{ proxy_pass http://yzboard_http_entry; proxy_http_version 1.1; proxy_set_header Connection $yzboard_keepalive_connection; proxy_set_header Upgrade $http_upgrade; proxy_set_header Host $http_host; }}
 }}
}}'''
(root/'nginx-test.conf').write_text(config)
cmd=[args.nginx,'-p',str(root),'-c',str(root/'nginx-test.conf')]
subprocess.run(cmd+['-t'],check=True,capture_output=True)
proc=subprocess.Popen(cmd+['-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
results={}
try:
    for i in range(30):
        try:
            with socket.create_connection(('127.0.0.1',outer),timeout=.2):break
        except OSError:time.sleep(.1)
    def request(path,headers={}):
        c=http.client.HTTPConnection('127.0.0.1',outer,timeout=5)
        try:
            c.request('GET',path,headers={'Host':'panel.example.test',**headers});r=c.getresponse();return r.status,dict(r.getheaders()),r.read()
        finally:c.close()
    before=backend.accepted
    for i in range(20):
        s,h,b=request('/probe');assert s==200
        d=json.loads(b);assert d['connection'] is None and d['host']=='panel.example.test'
    results['requests']=20;results['backend_connections']=backend.accepted-before
    entries=(root/'nginx-entry.log').read_text().splitlines()[-20:]
    results['entry_connections']=len({l.split()[0] for l in entries if l.endswith(' /probe')})
    assert results['entry_connections']==1,results
    assert results['backend_connections']<=2,results
    manifest=json.loads((repo/'public/assets/admin/manifest.json').read_text())
    js=next(v['file'] for k,v in manifest.items() if v['file'].startswith('assets/admin-'))
    raw=(repo/'public/assets/admin'/js).read_bytes();url='/assets/admin/'+js
    s,h,b=request(url,{'Accept-Encoding':'gzip'});assert s==200 and h.get('Content-Encoding')=='gzip' and gzip.decompress(b)==raw;assert 'Accept-Encoding' in h.get('Vary','')
    results['raw_bytes']=len(raw);results['gzip_bytes']=len(b)
    for enc in ['identity','gzip;q=0','br']:
        s,h,b=request(url,{'Accept-Encoding':enc});assert s==200 and 'Content-Encoding' not in h and b==raw
    s,h,b=request(url,{'Accept-Encoding':'identity','Range':'bytes=0-31'});assert s==206 and b==raw[:32]
    s,h,b=request('/assets/admin/missing.js',{'Accept-Encoding':'gzip'});assert s==404
    c=http.client.HTTPConnection('127.0.0.1',outer,timeout=5)
    c.request('GET','/ws',headers={'Connection':'Upgrade','Upgrade':'websocket','Sec-WebSocket-Version':'13','Sec-WebSocket-Key':'dGhlIHNhbXBsZSBub25jZQ=='});r=c.getresponse();assert r.status==101 and r.getheader('Sec-WebSocket-Accept')=='s3pPLMBiTxaQ9kYGzzhZRbK+xOo=';c.close()
    results['websocket_status']=101;results['encoding_fallbacks']=3;results['range_status']=206;results['missing_status']=404
    (root/'nginx-verification.json').write_text(json.dumps(results,indent=2));print(json.dumps(results))
finally:
    proc.terminate()
    try:proc.wait(timeout=5)
    except subprocess.TimeoutExpired:proc.kill();proc.wait()
    backend.shutdown();backend.server_close()
    workspace.cleanup()
