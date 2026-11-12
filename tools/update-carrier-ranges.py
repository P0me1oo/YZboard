#!/usr/bin/env python3
"""按固定上游提交更新运营商 CIDR；完整校验后原子替换，不改生产 DNS。"""
import argparse
import datetime
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import tempfile
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--revision', required=True, help='gaoyifan/china-operator-ip 的完整提交 SHA')
parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[1] / 'resources/dns/carriers.json')
args = parser.parse_args()
if not re.fullmatch('[a-f0-9]{40}', args.revision):
    parser.error('必须使用完整固定提交 SHA')
base = f'https://raw.githubusercontent.com/gaoyifan/china-operator-ip/{args.revision}/'
with urllib.request.urlopen(f'https://api.github.com/repos/gaoyifan/china-operator-ip/commits/{args.revision}', timeout=30) as response:
    committed = json.load(response)['commit']['committer']['date']
timestamp = int(datetime.datetime.fromisoformat(committed.replace('Z', '+00:00')).timestamp())
data = dict(source='https://github.com/gaoyifan/china-operator-ip', revision=args.revision,
            generated_at=timestamp, expires_at=timestamp + 30 * 86400, sha256={}, ranges={})
for carrier, filename in dict(telecom='chinanet', unicom='unicom', mobile='cmcc').items():
    ranges = []
    for suffix, version in [('.txt', 4), ('6.txt', 6)]:
        name = filename + suffix
        with urllib.request.urlopen(base + name, timeout=30) as response:
            raw = response.read(2 * 1024 * 1024 + 1)
        if len(raw) > 2 * 1024 * 1024:
            raise ValueError('网段文件过大')
        values = [ipaddress.ip_network(line.strip(), strict=True) for line in raw.decode('ascii').splitlines() if line.strip()]
        if not values or len(values) > 30000 or any(v.version != version or v.prefixlen == 0 or not v.is_global for v in values):
            raise ValueError('网段文件为空、过大或包含非公网地址')
        ranges.extend(str(v) for v in sorted(set(values)))
        data['sha256'][name] = hashlib.sha256(raw).hexdigest()
    data['ranges'][carrier] = ranges
args.output.parent.mkdir(parents=True, exist_ok=True)
with tempfile.NamedTemporaryFile('w', dir=args.output.parent, delete=False) as file:
    temporary = Path(file.name)
    os.fchmod(file.fileno(), 0o644)
    json.dump(data, file, ensure_ascii=True, indent=2)
    file.write('\n')
try:
    os.replace(temporary, args.output)
finally:
    temporary.unlink(missing_ok=True)
print(json.dumps({'revision': args.revision, 'counts': {k: len(v) for k, v in data['ranges'].items()}}))
