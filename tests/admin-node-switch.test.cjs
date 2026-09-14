const assert = require('node:assert/strict');
const { before, after, test } = require('node:test');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-node-switch-'));
const assets = path.join(temp, 'assets');
const patches = ['relay', 'upload', 'plan-prices', 'server-port', 'node-switch']
    .map(name => path.join(repo, '.docker/patch-admin-' + name + '.php'));
let bundle;
let beforeSwitch;
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
    fs.copyFileSync(path.join(admin, manifest['index.html'].file),
        path.join(assets, path.basename(manifest['index.html'].file)));
    for (const patch of patches.slice(0, -1)) run('php', [patch, assets]);
    beforeSwitch = fs.readFileSync(entry(), 'utf8');
    run('php', [patches.at(-1), assets]);
    run(process.execPath, ['--check', entry()]);
    bundle = fs.readFileSync(entry(), 'utf8');
    const start = bundle.indexOf('/* yz_node_runtime_switch:start */');
    const end = bundle.indexOf('/* yz_node_runtime_switch:end */');
    assert.ok(start > 0 && end > start);
    helper = bundle.slice(start, end);
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-node-switch-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function component(overrides = {}, options = {}) {
    const node = {
        id: 19, machine_id: 7, name: '测试节点', type: 'vless', enabled: true, show: false,
        ...overrides,
    };
    const slots = [];
    const calls = [];
    const errors = [];
    const resources = {};
    const language = options.language || 'zh-CN';
    const translate = key => resources[language]?.[key] || key;
    let cursor = 0;
    let serverEnabled = node.enabled;
    let serverShow = node.show;
    let refreshes = 0;
    const context = {
        jy: () => ({ i18n: { resolvedLanguage: language }, t: translate }),
        lL: {
            addResource(locale, namespace, key, value) {
                assert.equal(namespace, 'server');
                (resources[locale] ??= {})[key] = value;
            },
        },
        Q: { jsx: (type, props, key) => ({ type, props, key }) },
        H: {
            useState(initial) {
                const index = cursor++;
                if (!(index in slots)) slots[index] = initial;
                return [slots[index], value => { slots[index] = value; }];
            },
            useRef(initial) {
                const index = cursor++;
                if (!(index in slots)) slots[index] = { current: initial };
                return slots[index];
            },
        },
        eQt: 'header', oZt: 'switch', sqt: { vless: '#123456' },
        gE: { error: message => errors.push(message) },
        UL: '/_tests/admin',
        RL: async (url, payload) => {
            calls.push({ url, payload: JSON.parse(JSON.stringify(payload)) });
            const response = await (options.request?.(payload) ?? { data: true });
            if (response?.data === true) {
                serverEnabled = payload.enabled;
                serverShow = payload.enabled;
            }
            return response;
        },
    };
    const endpoint = bundle.match(/XL=(e=>RL\(UL\+"\/server\/manage\/update",e\))/);
    assert.ok(endpoint);
    vm.createContext(context);
    vm.runInContext('var XL = ' + endpoint[1] + ';' + helper, context);
    async function refetch() {
        refreshes++;
        const result = await options.refresh?.();
        if (!result?.isError) {
            node.enabled = serverEnabled;
            node.show = serverShow;
        }
        return result;
    }
    function columns() {
        const start = bundle.indexOf('const Z5t=') + 'const Z5t='.length;
        const end = bundle.indexOf(';function Y5t', start);
        assert.ok(end > start);
        return vm.runInContext('(' + bundle.slice(start, end) + ')', context)(refetch, translate, []);
    }
    return {
        node, calls, errors, context, refetch, columns,
        refreshes: () => refreshes,
        render() {
            cursor = 0;
            return context.F5t({ node, refetch });
        },
    };
}

test('列表开关反映运行启用状态，订阅显隐不影响开关', () => {
    assert.equal(component({ enabled: true, show: false }).render().props.checked, true);
    assert.equal(component({ enabled: false, show: true }).render().props.checked, false);
    assert.equal(component({ enabled: null, show: true }).render().props.checked, false);
});

test('关闭和重新开启只提交当前节点的运行状态，刷新后显隐跟随', async () => {
    const state = component();
    await state.render().props.onCheckedChange(false);
    assert.equal(state.render().props.checked, false);
    assert.equal(state.node.show, false);
    await state.render().props.onCheckedChange(true);
    assert.equal(state.render().props.checked, true);
    assert.equal(state.node.show, true);
    assert.deepEqual(state.calls, [
        { url: '/_tests/admin/server/manage/update', payload: { id: 19, enabled: false } },
        { url: '/_tests/admin/server/manage/update', payload: { id: 19, enabled: true } },
    ]);
    assert.equal(state.node.machine_id, 7);
    assert.equal(state.refreshes(), 2);
    assert.deepEqual(state.errors, []);
});

test('请求未完成时禁用开关，重新渲染前的连续点击也只发出一次请求', async () => {
    let resolve;
    const state = component({}, { request: () => new Promise(done => { resolve = done; }) });
    const toggle = state.render().props.onCheckedChange;
    const pending = toggle(false);
    await toggle(false);
    await toggle(true);
    assert.equal(state.calls.length, 1);
    assert.equal(state.render().props.disabled, true);
    assert.equal(state.render().props['aria-busy'], true);
    assert.equal(state.render().props.checked, true);
    resolve({ data: true });
    await pending;
    assert.equal(state.render().props.disabled, false);
    assert.equal(state.render().props.checked, false);
});

test('端口冲突或请求失败时保持原状态、显示原因，并允许重试', async () => {
    let fail = true;
    const state = component({ enabled: false }, {
        request: async () => {
            if (fail) throw { errors: { server_port: ['内部端口被其他节点占用'] } };
            return { data: true };
        },
    });
    await state.render().props.onCheckedChange(true);
    assert.equal(state.render().props.checked, false);
    assert.equal(state.render().props.disabled, false);
    assert.equal(state.refreshes(), 0);
    assert.deepEqual(state.errors, ['内部端口被其他节点占用']);
    fail = false;
    await state.render().props.onCheckedChange(true);
    assert.equal(state.render().props.checked, true);
    assert.equal(state.refreshes(), 1);
});

test('没有明确成功响应时不切换状态', async () => {
    for (const response of [{ data: false }, { data: null }, {}]) {
        const state = component({}, { request: async () => response });
        await state.render().props.onCheckedChange(false);
        assert.equal(state.render().props.checked, true);
        assert.equal(state.refreshes(), 0);
        assert.deepEqual(state.errors, ['节点开关更新失败，请重试']);
    }
});

test('写入成功但刷新失败时提示刷新，不误报节点未保存', async () => {
    const state = component({}, { refresh: async () => ({ isError: true }) });
    await state.render().props.onCheckedChange(false);
    assert.deepEqual(state.errors, ['节点状态已更新，请刷新列表确认']);
    assert.equal(state.calls.length, 1);
    assert.equal(state.render().props.disabled, false);
});

test('轮询和批量操作返回的新状态直接反映在开关上', () => {
    const state = component();
    assert.equal(state.render().props.checked, true);
    state.node.enabled = false;
    assert.equal(state.render().props.checked, false);
    state.node.enabled = true;
    assert.equal(state.render().props.checked, true);
});

test('独立部署节点显示不可操作，不发送虚假的远程启停请求', () => {
    for (const machine_id of [null, undefined, 0]) {
        const state = component({ machine_id });
        const rendered = state.render();
        assert.equal(rendered.type, 'span');
        assert.equal(rendered.props.children, '--');
        assert.equal(rendered.props.title, '独立部署节点需在部署端启停');
        assert.equal(rendered.props.onCheckedChange, undefined);
        assert.equal(state.calls.length, 0);
    }
});

test('桌面表头和移动端卡片都显示开关，并随管理端语言切换', () => {
    for (const [language, expected] of [['zh-CN', '开关'], ['en-US', 'On / off'], ['ru-RU', 'Вкл. / выкл.']]) {
        const state = component({}, { language });
        const column = state.columns().find(column => column.accessorKey === 'enabled');
        const header = column.header({ column: {} });
        assert.equal(header.props.title, expected);
        const start = bundle.indexOf('function PGt(');
        const end = bundle.indexOf('MGt.displayName=', start);
        assert.ok(start > 0 && end > start);
        vm.runInContext(bundle.slice(start, end), state.context);
        assert.equal(state.context.jGt(column.header, 'enabled'), expected);
    }
});

test('真实节点表格绑定运行列，按节点编号保持组件身份，排序模式隐藏运行列', () => {
    const state = component();
    const columns = state.columns();
    assert.equal(columns.some(column => column.accessorKey === 'show'), false);
    const column = columns.find(column => column.accessorKey === 'enabled');
    assert.ok(column);
    const cell = column.cell({ row: { original: state.node } });
    assert.equal(cell.type, state.context.F5t);
    assert.equal(cell.key, state.node.id);
    assert.equal(cell.props.node, state.node);
    assert.ok(bundle.includes('o({"drag-handle":g,enabled:!g,host:!g,'));
});

test('完整补丁重复执行保持入口摘要、引用与已有功能一致', () => {
    const previous = entry();
    const manifest = fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8');
    const html = fs.readFileSync(path.join(temp, 'index.html'), 'utf8');
    assert.equal(path.basename(previous), 'index-' + createHash('sha1').update(bundle).digest('hex').slice(0, 8) + '.js');
    assert.ok(html.includes(path.basename(previous)));
    for (const patch of patches) run('php', [patch, assets]);
    assert.equal(entry(), previous);
    assert.equal(fs.readFileSync(entry(), 'utf8'), bundle);
    assert.equal(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'), manifest);
    assert.equal(fs.readFileSync(path.join(temp, 'index.html'), 'utf8'), html);
    assert.ok(bundle.includes('yzPort=yzUseServerPortValidation(x,r,l)'));
});

test('上游锚点变化或只有部分开关补丁时构建失败，不改写原文件', () => {
    for (const [name, source, expected] of [
        ['incompatible', beforeSwitch.replace('function F5t(', 'function changedSwitch('), '锚点已变化'],
        ['partial', bundle.replace('enabled:!g,host:!g,', 'show:!g,host:!g,'), '部分节点开关补丁'],
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
