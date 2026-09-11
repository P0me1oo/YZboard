const assert = require('node:assert/strict');
const { after, before, test } = require('node:test');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-server-port-'));
const assets = path.join(temp, 'assets');
const patches = ['relay', 'upload', 'plan-prices', 'server-port'].map(name => path.join(repo, '.docker/patch-admin-' + name + '.php'));
let bundle;
let beforePort;
let helper;

function run(command, args) {
    const result = spawnSync(command, args, { encoding: 'utf8' });
    assert.equal(result.status, 0, result.error?.message || result.stderr || result.stdout);
}

function entry() {
    const manifest = JSON.parse(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'));
    return path.join(temp, manifest['index.html'].file);
}

before(() => {
    const admin = path.join(repo, 'public/assets/admin');
    const manifest = JSON.parse(fs.readFileSync(path.join(admin, 'manifest.json'), 'utf8'));
    fs.mkdirSync(assets);
    for (const name of ['manifest.json', 'index.html']) {
        fs.copyFileSync(path.join(admin, name), path.join(temp, name));
    }
    fs.copyFileSync(path.join(admin, manifest['index.html'].file), path.join(assets, path.basename(manifest['index.html'].file)));
    for (const patch of patches.slice(0, -1)) run('php', [patch, assets]);
    beforePort = fs.readFileSync(entry(), 'utf8');
    run('php', [patches.at(-1), assets]);
    run(process.execPath, ['--check', entry()]);
    bundle = fs.readFileSync(entry(), 'utf8');
    const start = bundle.indexOf('/* yz_server_port_validation:start */');
    const end = bundle.indexOf('/* yz_server_port_validation:end */');
    assert.ok(start > 0 && end > start);
    helper = bundle.slice(start, end);
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-server-port-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function form(overrides = {}, request = async () => ({ data: { valid: true, message: null } })) {
    const values = {
        id: null, machine_id: 7, server_port: '24443', kernel_type: 'xray', enabled: true,
        protocol_settings: { network: 'tcp', tls: 0 }, ...overrides,
    };
    let error;
    let focused = false;
    const context = { open: true, type: 'vless' };
    const calls = [];
    const api = {
        control: {},
        getValues() { return values; },
        getFieldState() { return { error }; },
        clearErrors() { error = undefined; },
        setError(field, value, options) {
            assert.equal(field, 'server_port');
            error = { ...value };
            focused = !!options.shouldFocus;
        },
    };
    const sandbox = {};
    vm.runInNewContext(helper, sandbox);
    const validator = sandbox.yzCreateServerPortValidator(api, () => context, async payload => {
        calls.push(JSON.parse(JSON.stringify(payload)));
        return request(payload);
    });
    return {
        values, context, api, validator, calls,
        error: () => error, focused: () => focused,
        existingError(value) { error = value; },
    };
}

test('填写内部端口时显示后端冲突原因，改为可用端口后清除提示', async () => {
    const state = form({}, async payload => ({
        data: { valid: payload.server_port === '24444', message: '24443/TCP 已被测试节点占用' },
    }));
    assert.equal(await state.validator.validate(), false);
    assert.equal(state.error().message, '24443/TCP 已被测试节点占用');
    assert.equal(state.focused(), false);
    state.values.server_port = '24444';
    assert.equal(await state.validator.validate(), true);
    assert.equal(state.error(), undefined);
    assert.equal(state.calls.length, 2);
});

test('预检查只发送监听判断字段，并保留编辑节点编号供后端排除自身', async () => {
    const state = form({
        id: 19, port: '443', host: 'port.example.invalid', name: '测试节点',
        protocol_settings: { network: 'kcp', transport: 'UDP', tls: 1, unrelated: '额外配置' },
    });
    assert.equal(await state.validator.validate(true), true);
    assert.deepEqual(state.calls[0], {
        id: 19, machine_id: 7, server_port: '24443', kernel_type: 'xray', enabled: true, type: 'vless',
        protocol_settings: { network: 'kcp', transport: 'UDP', tls: 1 },
    });
    state.context.type = 'hysteria';
    state.values.protocol_settings = { version: 2, tls: { server_name: 'port.example.invalid' } };
    assert.equal(await state.validator.validate(), true);
    assert.deepEqual(state.calls[1].protocol_settings, {});
});

test('未绑定服务器时允许同端口，取消绑定会清除原冲突', async () => {
    const state = form({}, async () => ({ data: { valid: false, message: '端口重复' } }));
    await state.validator.validate();
    for (const value of [null, 0, undefined]) {
        state.values.machine_id = value;
        assert.equal(await state.validator.validate(true), true);
        assert.equal(state.error(), undefined);
    }
    assert.equal(state.calls.length, 1);
});

test('空输入不自动报错，保存时以及无效端口输入时明确提醒', async () => {
    const state = form({ server_port: '' });
    assert.equal(await state.validator.validate(), false);
    assert.equal(state.error(), undefined);
    assert.equal(await state.validator.validate(true), false);
    assert.equal(state.error().message, '内部端口不能为空');
    assert.equal(state.focused(), true);
    for (const port of ['0', '-1', '65536', '24443-24444', '24443,24444', '443.5', 'abc']) {
        state.values.server_port = port;
        assert.equal(await state.validator.validate(), false);
        assert.match(state.error().message, /1 到 65535/);
    }
    assert.equal(state.calls.length, 0);
});

test('切换端口后忽略旧请求的迟到结果', async () => {
    const pending = [];
    const state = form({}, () => new Promise(resolve => pending.push(resolve)));
    const old = state.validator.validate();
    state.values.server_port = '24444';
    const current = state.validator.validate();
    pending[1]({ data: { valid: true } });
    assert.equal(await current, true);
    pending[0]({ data: { valid: false, message: '旧端口重复' } });
    assert.equal(await old, false);
    assert.equal(state.error(), undefined);
});

test('切换服务器、协议或内核时旧结果失效，改名称不影响监听检查', async () => {
    for (const change of [
        state => { state.values.machine_id = 8; },
        state => { state.context.type = 'hysteria'; },
        state => { state.values.kernel_type = 'singbox'; },
        state => { state.values.protocol_settings.network = 'hysteria'; },
    ]) {
        let resolve;
        const state = form({}, () => new Promise(done => { resolve = done; }));
        const pending = state.validator.validate();
        change(state);
        resolve({ data: { valid: false, message: '旧输入重复' } });
        assert.equal(await pending, false);
        assert.equal(state.error(), undefined);
    }
    let resolve;
    const state = form({}, () => new Promise(done => { resolve = done; }));
    const pending = state.validator.validate();
    state.values.name = '修改名称';
    resolve({ data: { valid: false, message: '当前端口重复' } });
    assert.equal(await pending, false);
    assert.equal(state.error().message, '当前端口重复');
});

test('关闭、重新打开或重置表单后，不恢复上一次的错误', async () => {
    let resolve;
    const state = form({}, () => new Promise(done => { resolve = done; }));
    const pending = state.validator.validate();
    state.context.open = false;
    state.validator.cancel();
    state.context.open = true;
    resolve({ data: { valid: false, message: '已经关闭的表单结果' } });
    assert.equal(await pending, false);
    assert.equal(state.error(), undefined);
});

test('检查请求失败时阻止保存，重试成功后恢复；不清除其他字段校验错误', async () => {
    let fail = true;
    const state = form({}, async () => {
        if (fail) throw new Error('测试请求失败');
        return { data: { valid: true } };
    });
    assert.equal(await state.validator.validate(true), false);
    assert.equal(state.error().message, '内部端口检查失败，请重试');
    fail = false;
    assert.equal(await state.validator.validate(true), true);
    assert.equal(state.error(), undefined);
    state.existingError({ type: 'required', message: '其他校验错误' });
    state.validator.cancel();
    assert.equal(state.error().message, '其他校验错误');

    const invalid = form({}, async () => ({ data: {} }));
    assert.equal(await invalid.validator.validate(true), false);
    assert.equal(invalid.error().message, '内部端口检查失败，请重试');
});

test('检查结果已过期时，正式保存返回的端口冲突仍显示在字段旁', () => {
    const state = form();
    const submitted = { ...state.values };
    state.validator.reportSaveError({ errors: { server_port: ['保存时发现端口重复'] } }, submitted);
    assert.equal(state.error().message, '保存时发现端口重复');
    assert.equal(state.focused(), true);
    state.values.server_port = '24444';
    state.validator.cancel();
    state.validator.reportSaveError({ errors: { server_port: ['旧提交端口重复'] } }, submitted);
    assert.equal(state.error(), undefined);
});

function submitCallback(context) {
    const start = 'onClick:async()=>{if(!l)return void gE.error(e("form.type.select_error"));';
    const begin = bundle.indexOf(start);
    assert.ok(begin > 0);
    const end = bundle.indexOf('},className:', begin);
    assert.ok(end > begin);
    return vm.runInNewContext('(async()=>{' + bundle.slice(begin + 'onClick:async()=>{'.length, end) + '})', context);
}

test('实际提交回调在检查失败时不保存，通过后保留原有保存和刷新操作', async () => {
    const state = form();
    let allowed = false;
    const events = [];
    const context = {
        l: 'vless', e: key => key, x: state.api,
        yzPort: {
            validate: async submitting => { assert.equal(submitting, true); events.push('check'); return allowed; },
            reportSaveError() { assert.fail('不应产生保存错误'); },
        },
        qL: async payload => { events.push('save'); assert.equal(payload.server_port, '24443'); return { data: true }; },
        D() { events.push('close'); }, h() { events.push('refresh'); },
        gE: { error() { assert.fail('不应缺少协议'); }, success() { events.push('success'); } },
    };
    const submit = submitCallback(context);
    await submit();
    assert.deepEqual(events, ['check']);
    allowed = true;
    await submit();
    assert.deepEqual(events, ['check', 'check', 'save', 'close', 'success', 'refresh']);
});

test('实际复制请求仍直接调用复制接口，保留原节点编号', async () => {
    const start = bundle.indexOf('YL=e=>RL(UL+"/server/manage/copy",e)');
    assert.ok(start > 0);
    const calls = [];
    const copy = vm.runInNewContext('(e=>RL(UL+"/server/manage/copy",e))', {
        UL: '/_tests/admin', RL: async (url, payload) => { calls.push({ url, payload }); return { data: true }; },
    });
    await copy({ id: 19 });
    assert.deepEqual(calls, [{ url: '/_tests/admin/server/manage/copy', payload: { id: 19 } }]);
});

test('表单按输入变化延迟 300 毫秒检查，关闭时取消定时和迟到响应', async () => {
    const state = form();
    let effect;
    let timer;
    let delay;
    let cancelled;
    let resolve;
    const calls = [];
    const sandbox = {
        I_: ({ name }) => name.map(key => state.values[key]),
        H: { useRef: value => ({ current: value }), useMemo: callback => callback(), useEffect: callback => { effect = callback; } },
        setTimeout(callback, milliseconds) { timer = callback; delay = milliseconds; return 1; },
        clearTimeout(id) { cancelled = id; },
        UL: '/_tests/admin',
        RL: (url, payload) => { calls.push({ url, payload }); return new Promise(done => { resolve = done; }); },
    };
    vm.runInNewContext(helper, sandbox);
    sandbox.yzUseServerPortValidation(state.api, true, 'vless');
    const cleanup = effect();
    assert.equal(delay, 300);
    assert.equal(calls.length, 0);
    timer();
    assert.equal(calls.length, 1);
    cleanup();
    assert.equal(cancelled, 1);
    resolve({ data: { valid: false, message: '迟到的端口检查' } });
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(state.error(), undefined);
});

test('完整补丁重复执行保持文件内容、摘要名称和入口引用一致', () => {
    const previous = entry();
    const manifest = fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8');
    const html = fs.readFileSync(path.join(temp, 'index.html'), 'utf8');
    const hash = createHash('sha1').update(bundle).digest('hex').slice(0, 8);
    assert.equal(path.basename(previous), 'index-' + hash + '.js');
    assert.ok(html.includes(path.basename(previous)));
    assert.ok(bundle.includes('yzPort=yzUseServerPortValidation(x,r,l)'));
    for (const patch of patches) run('php', [patch, assets]);
    assert.equal(entry(), previous);
    assert.equal(fs.readFileSync(entry(), 'utf8'), bundle);
    assert.equal(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'), manifest);
    assert.equal(fs.readFileSync(path.join(temp, 'index.html'), 'utf8'), html);
});

test('上游锚点变化或补丁不完整时构建失败，原文件不被改写', () => {
    for (const [name, source, expected] of [
        ['incompatible', beforePort.replace('function v5t(){', 'function changedNodeForm(){'), '锚点已变化'],
        ['partial', bundle.replace('if(!await yzPort.validate(true))return;', ''), '部分内部端口检查补丁'],
    ]) {
        const directory = path.join(temp, name);
        fs.mkdirSync(directory);
        const file = path.join(directory, 'index-test.js');
        fs.writeFileSync(file, source, 'utf8');
        const result = spawnSync('php', [patches.at(-1), directory], { encoding: 'utf8' });
        assert.equal(result.status, 1, result.stderr);
        assert.ok(result.stderr.includes(expected));
        assert.equal(fs.readFileSync(file, 'utf8'), source);
        assert.deepEqual(fs.readdirSync(directory), ['index-test.js']);
    }
});
