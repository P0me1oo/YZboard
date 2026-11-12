#!/usr/bin/env python3
"""使用临时目录和回环端口验证真实 PowerDNS；不会读取或修改生产配置。"""
import json
import ipaddress
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import secrets
import urllib.request

root = Path(__file__).resolve().parents[1]
prefix = Path(os.environ['PDNS_TEST_PREFIX']).resolve()
binary = prefix / 'usr/sbin/pdns_server'
env = dict(os.environ, LD_LIBRARY_PATH=str(prefix / 'usr/lib/x86_64-linux-gnu'))
schema = prefix / 'usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql'
domain = 'carrier.example.test'
zone_name = 'example.test'


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def query(number, name='user', ecs=None, tcp=False):
    cmd = ['dig', '@127.0.0.1', '-p', str(number), name + '.' + domain, 'CNAME', '+short', '+time=1', '+tries=1']
    if ecs is not None:
        cmd.append('+subnet=' + ecs)
    if tcp:
        cmd.append('+tcp')
    return subprocess.check_output(cmd, text=True).strip()


with tempfile.TemporaryDirectory(prefix='yz-carrier-dns-') as directory:
    base = Path(directory)
    data = {'expires_at': int(time.time()) + 3600, 'ranges': {
        'telecom': ['192.0.2.0/24', '203.0.113.0/24'],
        'unicom': ['198.51.100.0/24', '203.0.113.0/24'],
        'mobile': ['2001:db8:3::/48'],
    }}
    cases = [(None, 'default'), ('192.0.2.42/24', 'telecom'), ('198.51.100.4/24', 'unicom'),
             ('2001:db8:3::1/56', 'mobile'), ('203.0.113.7/24', 'default'),
             ('192.0.0.0/8', 'default'), ('0.0.0.0/0', 'default'), ('10.0.0.0/24', 'default')]
    if os.environ.get('PDNS_CARRIER_DATASET'):
        data = json.loads(Path(os.environ['PDNS_CARRIER_DATASET']).read_text())
        networks = {k: [ipaddress.ip_network(v) for v in values] for k, values in data['ranges'].items()}
        cases = [(None, 'default'), ('0.0.0.0/0', 'default'), ('10.0.0.0/24', 'default')]
        for carrier, values in networks.items():
            for version in [4, 6]:
                network = next(v for v in values if v.version == version and
                    not any(v.overlaps(other) for key, group in networks.items() if key != carrier for other in group if other.version == version))
                cases.append((str(network), carrier))
    dataset = base / 'ranges.json'
    dataset.write_text(json.dumps(data))
    records = json.loads(subprocess.check_output(['php', str(root / 'tests/fixtures/carrier-records.php'), str(dataset)], text=True))
    processes = []
    logs = []
    try:
        for index in range(2):
            path = base / str(index)
            path.mkdir()
            number = port()
            api_port = port()
            api_key = secrets.token_hex(20)
            db = path / 'dns.sqlite3'
            connection = sqlite3.connect(db)
            connection.executescript(schema.read_text())
            if index == 0:
                connection.execute('INSERT INTO domains(id,name,type) VALUES(1,?,?)', (zone_name, 'MASTER'))
                entries = [(zone_name, 'SOA', 'ns.' + domain + ' hostmaster.' + domain + ' 1 60 60 3600 30'),
                           (zone_name, 'NS', 'ns.' + domain)]
                connection.executemany('INSERT INTO records(domain_id,name,type,content,ttl,auth) VALUES(1,?,?,?,30,1)', entries)
            else:
                connection.execute('INSERT INTO domains(id,name,type,master) VALUES(1,?,?,?)', (zone_name, 'SLAVE', '127.0.0.1:' + str(primary_port)))
            connection.commit()
            connection.close()
            config = '\n'.join([
                'launch=gsqlite3', 'module-dir=' + str(prefix / 'usr/lib/x86_64-linux-gnu/pdns'),
                'gsqlite3-database=' + str(db), 'local-address=127.0.0.1', 'local-port=' + str(number),
                'socket-dir=' + str(path), 'enable-lua-records=yes', 'edns-subnet-processing=yes',
                'primary=' + ('yes' if index == 0 else 'no'), 'secondary=' + ('yes' if index else 'no'),
                'xfr-cycle-interval=1', 'query-cache-ttl=0', 'negquery-cache-ttl=0', 'security-poll-suffix=',
                'workaround-11804=yes',
                'allow-axfr-ips=127.0.0.1', 'daemon=no', 'guardian=no', 'disable-syslog=yes',
                'loglevel=9', 'log-dns-queries=yes',
                'api=yes', 'api-key=' + api_key, 'webserver=yes', 'webserver-address=127.0.0.1', 'webserver-port=' + str(api_port),
            ])
            (path / 'pdns.conf').write_text(config + '\n')
            log = open(path / 'pdns.log', 'w+')
            logs.append(log)
            process = subprocess.Popen([str(binary), '--config-dir=' + str(path)], env=env, stdout=log, stderr=log)
            processes.append(process)
            if index == 0:
                url = f'http://127.0.0.1:{api_port}/api/v1/servers/localhost/zones/{zone_name}.'
                payload = {'rrsets': [dict(name=r['name'] + '.', type=r['type'], ttl=30, changetype='REPLACE',
                    records=[dict(content=c, disabled=False) for c in r['contents']]) for r in records]}
                for attempt in range(50):
                    try:
                        with urllib.request.urlopen(urllib.request.Request(url, data=json.dumps(payload).encode(), method='PATCH',
                            headers={'X-API-Key': api_key, 'Content-Type': 'application/json'}), timeout=2) as response:
                            assert response.status == 204
                        break
                    except urllib.error.URLError:
                        time.sleep(0.1)
                else:
                    raise AssertionError('临时 PowerDNS API 未就绪')
                with urllib.request.urlopen(urllib.request.Request(url, headers={'X-API-Key': api_key}), timeout=2) as response:
                    actual = {r['name'].rstrip('.'): r for r in json.load(response)['rrsets'] if r['type'] == 'LUA'}
                for record in records:
                    if record['type'] == 'LUA':
                        assert [v['content'] for v in actual[record['name']]['records']] == record['contents'], record['name']
            for attempt in range(100):
                if process.poll() is not None:
                    log.seek(0)
                    raise RuntimeError(log.read())
                try:
                    if query(number) == 'default.' + domain + '.':
                        break
                except subprocess.CalledProcessError:
                    pass
                time.sleep(0.1)
            else:
                log.seek(0)
                answer = subprocess.check_output(['dig', '@127.0.0.1', '-p', str(number), 'user.' + domain, 'CNAME'], text=True)
                logs[0].seek(0)
                primary_errors = '\n'.join(line for line in logs[0].read().splitlines() if any(word in line.lower() for word in ['axfr', 'exception', 'error', 'failed', 'large']))
                raise AssertionError(answer + '\nPRIMARY:\n' + primary_errors + '\nSECONDARY:\n' + log.read()[-4000:])
            primary_port = number if index == 0 else primary_port
            for tcp in [False, True]:
                for ecs, expected in cases:
                    actual = query(number, ecs=ecs, tcp=tcp)
                    assert actual == expected + '.' + domain + '.', (index, ecs, tcp, actual)
                assert query(number, 'fallback', '192.0.2.0/24', tcp) == 'default.' + domain + '.'
                assert query(number, 'expired', '192.0.2.0/24', tcp) == 'default.' + domain + '.'
                for name in ['missing', 'stale', 'partial']:
                    assert query(number, name, '198.51.100.0/24', tcp) == 'default.' + domain + '.'
        print(f'PowerDNS: {(len(cases) + 5) * 4} UDP/TCP checks passed, including API round-trip, ECS, IPv6, unknown/expired/partial data and AXFR')
    finally:
        for process in processes:
            process.terminate()
        for process in processes:
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        for log in logs:
            log.close()
