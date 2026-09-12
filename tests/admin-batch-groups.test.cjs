const assert = require('node:assert/strict');
const { before, after, test } = require('node:test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-batch-groups-'));
const groups = [{ id: 1, name: 'A' }, { id: 2, name: 'B' }, { id: 3, name: 'C' }];
let toolbar;

before(() => {
    const admin = path.join(repo, 'public/assets/admin');
    const manifest = JSON.parse(fs.readFileSync(path.join(admin, 'manifest.json'), 'utf8'));
    const assets = path.join(temp, 'assets');
    fs.mkdirSync(assets);
    for (const name of ['manifest.json', 'index.html']) {
        fs.copyFileSync(path.join(admin, name), path.join(temp, name));
    }
    fs.copyFileSync(path.join(admin, manifest['index.html'].file),
        path.join(assets, path.basename(manifest['index.html'].file)));
    const result = spawnSync('php', [path.join(repo, '.docker/patch-admin-relay.php'), assets], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.error?.message || result.stderr || result.stdout);
    const patchedManifest = JSON.parse(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'));
    const bundle = fs.readFileSync(path.join(temp, patchedManifest['index.html'].file), 'utf8');
    const start = bundle.indexOf('function x5t(');
    const end = bundle.indexOf('var w5t=', start);
    assert.ok(start > 0 && end > start, '节点操作栏锚点应存在');
    toolbar = bundle.slice(start, end);
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-batch-groups-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

// 执行实际生成的操作栏，通过菜单和点击回调验证行为。
function screen(nodes, selectedIds, options = {}) {
    let selected = selectedIds;
    let refreshes = 0;
    const calls = [];
    const errors = [];
    const context = {
        Q: {
            jsx: (type, props, key) => ({ type, props, key }),
            jsxs: (type, props, key) => ({ type, props, key }),
        },
        jy: () => ({ t: key => key }),
        p8e: () => options.mobile ?? false,
        gE: { success() {}, error: message => errors.push(message) },
        ZL: async payload => {
            calls.push(JSON.parse(JSON.stringify(payload)));
            return options.request ? options.request(payload) : { data: true };
        },
    };
    for (const name of ['Vst', 'Wst', 'Lf', 'Ust', '$st', 'Wat', 'Vat', 'clt', 'llt',
        'Zst', 'Gst', 'YXt', 'hQt', 'ult', 'Nlt', 'v5t', 'u8e', 'B7e']) {
        context[name] = name;
    }
    vm.createContext(context);
    vm.runInContext(toolbar, context);
    const table = {
        getState: () => ({ columnFilters: [] }),
        getFilteredSelectedRowModel: () => ({
            rows: nodes.filter(node => selected.includes(node.id)).map(original => ({ original })),
        }),
        getColumn() {},
        getRowCount: () => nodes.length,
        resetRowSelection: () => { selected = []; },
    };
    function visit(element, found = []) {
        if (Array.isArray(element)) {
            for (const child of element) visit(child, found);
        } else if (element && typeof element === 'object') {
            if (element.type === '$st') found.push(element);
            visit(element.props?.children, found);
        }
        return found;
    }
    function menu() {
        return visit(context.x5t({
            table, groups: options.groups ?? groups, machines: [], isSortMode: false,
            refetch: () => { refreshes++; options.refresh?.(); },
        }));
    }
    return {
        calls, errors, menu,
        refreshes: () => refreshes,
        select: ids => { selected = ids; },
        ids: action => menu().filter(item => item.key?.startsWith('batch-group-' + action + '-'))
            .map(item => item.key.split('-').at(-1)),
        choose: (action, id) => {
            const item = menu().find(item => item.key === `batch-group-${action}-${id}`);
            assert.ok(item, '应能找到目标权限组选项');
            assert.equal(item.props.disabled, false);
            return item.props.onSelect();
        },
        empty: () => menu().filter(item => !item.key && item.props.disabled && typeof item.props.children === 'string')
            .map(item => item.props.children),
    };
}

test('单选只添加缺少的组、只移除已有的组，桌面与窄屏一致', () => {
    for (const mobile of [false, true]) {
        const state = screen([{ id: 10, group_ids: ['1', '2'] }], [10], { mobile });
        assert.deepEqual(state.ids('add'), ['3']);
        assert.deepEqual(state.ids('remove'), ['1', '2']);
    }
});

test('所有选中节点都有的权限组不再显示添加选项', () => {
    const state = screen([
        { id: 10, group_ids: ['1', '2'] },
        { id: 20, group_ids: ['1', '2'] },
        { id: 30, group_ids: [] },
    ], [10, 20]);
    assert.deepEqual(state.ids('add'), ['3']);
    assert.deepEqual(state.ids('remove'), ['1', '2']);
});

test('部分节点缺少 A 时仍可添加 A，移除仅包含任一所选节点已有的组', () => {
    const state = screen([
        { id: 10, group_ids: ['1', '2'] },
        { id: 20, group_ids: ['2'] },
        { id: 30, group_ids: ['3'] },
    ], [10, 20]);
    assert.deepEqual(state.ids('add'), ['1', '3']);
    assert.deepEqual(state.ids('remove'), ['1', '2']);
});

test('权限组编号兼容数字和字符串，保留列表顺序且不改写节点数据', () => {
    const nodes = [{ id: 10, group_ids: [1, '2', 2] }, { id: 20, group_ids: ['1', 2] }];
    const before = JSON.stringify(nodes);
    const state = screen(nodes, [10, 20], { groups: [{ id: '2', name: 'B' }, groups[0], groups[2]] });
    assert.deepEqual(state.ids('add'), ['3']);
    assert.deepEqual(state.ids('remove'), ['2', '1']);
    assert.equal(JSON.stringify(nodes), before);
});

test('空值和缺失的节点权限组可添加全部组，不能移除任何组', () => {
    for (const group_ids of [[], null, undefined]) {
        const state = screen([{ id: 10, group_ids }], [10]);
        assert.deepEqual(state.ids('add'), ['1', '2', '3']);
        assert.deepEqual(state.ids('remove'), []);
        assert.deepEqual(state.empty(), ['暂无可移除的权限组']);
    }
});

test('未选择节点、没有权限组或已拥有全部组时给出禁用提示', () => {
    const nodes = [{ id: 10, group_ids: ['1', '2', '3'] }];
    const unselected = screen(nodes, []);
    assert.deepEqual(unselected.ids('add'), []);
    assert.deepEqual(unselected.ids('remove'), []);
    assert.deepEqual(unselected.empty(), ['请先选择节点', '请先选择节点']);
    const noGroups = screen(nodes, [10], { groups: [] });
    assert.deepEqual(noGroups.ids('add'), []);
    assert.deepEqual(noGroups.ids('remove'), []);
    assert.deepEqual(noGroups.empty(), ['暂无可添加的权限组', '暂无可移除的权限组']);
    const complete = screen(nodes, [10]);
    assert.deepEqual(complete.ids('add'), []);
    assert.deepEqual(complete.ids('remove'), ['1', '2', '3']);
    assert.deepEqual(complete.empty(), ['暂无可添加的权限组']);
});

test('切换选择和替换刷新后的节点数据会重新计算菜单', () => {
    const nodes = [{ id: 10, group_ids: ['1'] }, { id: 20, group_ids: ['2'] }];
    const state = screen(nodes, [10]);
    assert.deepEqual(state.ids('add'), ['2', '3']);
    state.select([20]);
    assert.deepEqual(state.ids('add'), ['1', '3']);
    assert.deepEqual(state.ids('remove'), ['2']);
    nodes[1] = { id: 20, group_ids: ['1', '2', '3'] };
    assert.deepEqual(state.ids('add'), []);
    assert.deepEqual(state.ids('remove'), ['1', '2', '3']);
});

test('添加成功沿用当前所选节点请求，刷新后补齐的组不再显示添加', async () => {
    const nodes = [{ id: 10, group_ids: ['1', '2'] }, { id: 20, group_ids: ['2'] }];
    const state = screen(nodes, [10, 20], {
        refresh: () => { nodes[1] = { id: 20, group_ids: ['1', '2'] }; },
    });
    await state.choose('add', 1);
    assert.deepEqual(state.calls, [{ ids: [10, 20], group_action: 'add', group_id: 1 }]);
    assert.equal(state.refreshes(), 1);
    assert.deepEqual(state.ids('add'), []);
    state.select([10, 20]);
    assert.deepEqual(state.ids('add'), ['3']);
    assert.deepEqual(state.ids('remove'), ['1', '2']);
    assert.deepEqual(state.errors, []);
});

test('移除成功刷新后消失的组不再显示移除，并可重新添加', async () => {
    const nodes = [{ id: 10, group_ids: ['1', '2'] }, { id: 20, group_ids: ['2'] }];
    const state = screen(nodes, [10, 20], {
        refresh: () => { nodes[0] = { id: 10, group_ids: ['2'] }; },
    });
    await state.choose('remove', 1);
    assert.deepEqual(state.calls, [{ ids: [10, 20], group_action: 'remove', group_id: 1 }]);
    assert.equal(state.refreshes(), 1);
    state.select([10, 20]);
    assert.deepEqual(state.ids('add'), ['1', '3']);
    assert.deepEqual(state.ids('remove'), ['2']);
    assert.deepEqual(state.errors, []);
});

test('请求失败保留当前选择和可操作项，允许重试', async () => {
    for (const action of ['add', 'remove']) {
        let fail = true;
        const state = screen([{ id: 10, group_ids: ['1'] }, { id: 20, group_ids: [] }], [10, 20], {
            request: async () => {
                if (fail) throw new Error('测试请求失败');
                return { data: true };
            },
        });
        await state.choose(action, 1);
        assert.equal(state.refreshes(), 0);
        assert.deepEqual(state.ids('add'), ['1', '2', '3']);
        assert.deepEqual(state.ids('remove'), ['1']);
        assert.deepEqual(state.errors, [action === 'add' ? '批量添加权限组失败' : '批量移除权限组失败']);
        fail = false;
        await state.choose(action, 1);
        assert.equal(state.refreshes(), 1);
        assert.equal(state.calls.length, 2);
    }
});
