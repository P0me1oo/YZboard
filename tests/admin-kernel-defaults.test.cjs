const assert = require('node:assert/strict');
const { after, before, test } = require('node:test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-kernel-defaults-'));
const assets = path.join(temp, 'assets');
const patch = path.join(repo, '.docker/patch-admin-relay.php');
let bundle;

function run(command, args) {
    const result = spawnSync(command, args, { encoding: 'utf8' });
    assert.equal(result.status, 0, result.error?.message || result.stderr || result.stdout);
}

function readBundle() {
    const manifest = JSON.parse(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'));
    return path.join(temp, manifest['index.html'].file);
}

before(() => {
    const admin = path.join(repo, 'public/assets/admin');
    const manifest = JSON.parse(fs.readFileSync(path.join(admin, 'manifest.json'), 'utf8'));
    fs.mkdirSync(assets);
    fs.copyFileSync(path.join(admin, 'manifest.json'), path.join(temp, 'manifest.json'));
    fs.copyFileSync(path.join(admin, manifest['index.html'].file), path.join(assets, path.basename(manifest['index.html'].file)));
    run('php', [patch, assets]);
    run(process.execPath, ['--check', readBundle()]);
    bundle = fs.readFileSync(readBundle(), 'utf8');
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-kernel-defaults-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function between(start, end, from = 0) {
    const begin = bundle.indexOf(start, from);
    assert.ok(begin >= 0, `找不到表单锚点：${start}`);
    const finish = bundle.indexOf(end, begin + start.length);
    assert.ok(finish >= 0, `找不到表单结束锚点：${end}`);
    return bundle.slice(begin + start.length, finish);
}

// 执行实际补丁产物中的重置与选择回调，避免测试另写一套默认值逻辑。
function openForm(protocol, editing = null) {
    let values = {};
    let touched = {};
    const formStart = bundle.indexOf('i=H.useMemo(()=>({id:null,');
    const context = {
        o: editing, l: protocol, d: null, C: { current: new Map() },
        i: vm.runInNewContext('({id:null,' + between('i=H.useMemo(()=>({id:null,', '}),[])') + '})'),
        N() {}, E() { return {}; }, s5t: {},
        c(value) { context.l = value; },
        x: {
            reset(value) { values = { ...value }; touched = {}; },
            setValue(key, value, options = {}) {
                values[key] = value;
                if (options.shouldTouch) touched[key] = true;
            },
            watch(key) { return values[key]; },
            getFieldState(key) { return { isTouched: !!touched[key] }; },
        },
    };
    const reset = '()=>{if(o){' + between('H.useEffect(()=>{if(o){', ',[o,x,i,c,d]);', formStart);
    vm.runInNewContext('(' + reset + ')', context)();
    const selector = bundle.indexOf('"data-yz-node-kernel-selector":!0');
    const kernelChange = between('onValueChange:', ',value:x.watch("kernel_type")', selector);
    const protocolChange = between('Q.jsxs(yzt,{value:l||"",onValueChange:', ',children:[');
    const selectedValue = 'x.watch("kernel_type")' + between('value:x.watch("kernel_type")', ',children:[', selector);
    return {
        chooseProtocol: vm.runInNewContext('(' + protocolChange + ')', context),
        chooseKernel: vm.runInNewContext('(' + kernelChange + ')', context),
        selected: () => vm.runInNewContext(selectedValue, context),
        saved: () => values.kernel_type,
    };
}

test('新建表单默认 sing-box，VLESS 默认 Xray，并随协议切换更新', () => {
    const form = openForm('shadowsocks');
    assert.equal(form.selected(), 'singbox');
    form.chooseProtocol('vless');
    assert.equal(form.saved(), 'xray');
    for (const protocol of ['vmess', 'trojan', 'hysteria', 'tuic', 'anytls', 'socks', 'http', 'naive', 'mieru']) {
        form.chooseProtocol(protocol);
        assert.equal(form.selected(), 'singbox');
        assert.equal(form.saved(), 'singbox');
    }
    assert.equal(openForm('vless').saved(), 'xray');
});

test('手动选择内核后切换协议仍保留选择', () => {
    const form = openForm('shadowsocks');
    form.chooseKernel('singbox');
    form.chooseProtocol('vless');
    assert.equal(form.saved(), 'singbox');
    form.chooseKernel('xray');
    form.chooseProtocol('trojan');
    assert.equal(form.saved(), 'xray');
});

test('编辑历史节点保留空值和显式内核，切换协议不改内核', () => {
    for (const kernel of [undefined, null, 'xray', 'singbox']) {
        const form = openForm('shadowsocks', { id: 7, type: 'shadowsocks', kernel_type: kernel });
        assert.equal(form.selected(), kernel || 'xray');
        form.chooseProtocol('vless');
        form.chooseProtocol('vmess');
        assert.equal(form.saved(), kernel ?? null);
        assert.equal(form.selected(), kernel || 'xray');
    }
});

test('关闭后再次新建不会沿用上一次的手动内核选择', () => {
    const previous = openForm('shadowsocks');
    previous.chooseKernel('xray');
    assert.equal(openForm('shadowsocks').saved(), 'singbox');
});

test('重复应用构建补丁保持内容和入口文件名不变', () => {
    const entry = readBundle();
    run('php', [patch, assets]);
    assert.equal(readBundle(), entry);
    assert.equal(fs.readFileSync(entry, 'utf8'), bundle);
});
