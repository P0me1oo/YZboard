const assert = require('node:assert/strict');
const { before, after, test } = require('node:test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-kernel-filter-'));
let bundle;

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
    const patched = JSON.parse(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'));
    bundle = fs.readFileSync(path.join(temp, patched['index.html'].file), 'utf8');
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-kernel-filter-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function section(start, end) {
    const begin = bundle.indexOf(start);
    const finish = bundle.indexOf(end, begin + start.length);
    assert.ok(begin > 0 && finish > begin, `应能找到构建产物片段：${start}`);
    return bundle.slice(begin, finish);
}

function elements(element, found = []) {
    if (Array.isArray(element)) {
        for (const child of element) elements(child, found);
    } else if (element && typeof element === 'object') {
        found.push(element);
        elements(element.props?.children, found);
    }
    return found;
}

// 使用产物自带的表格库、列定义和筛选组件，不另写一套过滤逻辑。
function screen(options = {}) {
    const nodes = options.nodes ?? [
        { id: 10, name: '节点 A', type: 'vless', kernel_type: 'singbox', machine_id: 7, group_ids: ['1'] },
        { id: 20, name: '节点 B', type: 'vless', kernel_type: 'xray', machine_id: 7, group_ids: ['1'] },
        { id: 30, name: '节点 C', type: 'vless', kernel_type: null, machine_id: 8, group_ids: ['2'] },
        { id: 40, name: '节点 D', type: 'hysteria', kernel_type: 'sing-box', machine_id: 8, group_ids: ['2'] },
        { id: 50, name: '节点 E', type: 'trojan', kernel_type: 'SINGBOX', machine_id: 7, group_ids: ['1'] },
    ];
    const calls = [];
    const context = {
        bKt: {}, _Kt: {},
        Q: {
            jsx: (type, props, key) => ({ type, props, key }),
            jsxs: (type, props, key) => ({ type, props, key }),
            Fragment: 'fragment',
        },
        Im: (...names) => names.filter(name => typeof name === 'string').join(' '),
        jy: () => ({ t: (key, fallback) => fallback || key }),
        p8e: () => options.mobile ?? false,
        y5t: [],
        gE: { success() {}, error: message => assert.fail(message) },
        ZL: async payload => { calls.push(JSON.parse(JSON.stringify(payload))); return { data: true }; },
    };
    for (const name of ['Vst', 'Wst', 'Lf', 'Ust', '$st', 'Wat', 'Vat', 'clt', 'llt', 'Zst', 'Gst',
        'YXt', 'hQt', 'ult', 'Nlt', 'v5t', 'u8e', 'B7e', 'P$t', 'j$t', 'B$t', 'but', 'xut', 'wut',
        'Cut', 'Sut', 'Nut', 'kut', '$f', 'nKt', 'iet', 'k7e']) {
        context[name] = name;
    }
    vm.createContext(context);
    vm.runInContext(section('function yKt(', 'function kGt('), context);
    vm.runInContext(section('const Z5t=', ';function Y5t') + ';', context);
    vm.runInContext(section('function b5t(', 'const y5t='), context);
    vm.runInContext(section('function x5t(', 'var w5t='), context);
    const visibility = bundle.match(/\[s,o\]=H\.useState\((\{[^}]+\})\)/);
    assert.ok(visibility, '应能找到节点表格初始列显隐');
    let state;
    const table = context._Gt({
        data: nodes,
        columns: vm.runInContext('Z5t(()=>{},(key,fallback)=>fallback||key,[])', context),
        state: {},
        onStateChange: update => {
            state = typeof update === 'function' ? update(state) : update;
            table.setOptions(current => ({ ...current, state }));
        },
        getCoreRowModel: context.vGt(),
        getFilteredRowModel: context.wGt(),
        getFacetedRowModel: context.yGt(),
        getFacetedUniqueValues: context.xGt(),
    });
    state = { ...table.initialState, columnVisibility: vm.runInContext('(' + visibility[1] + ')', context) };
    table.setOptions(current => ({ ...current, state }));
    function toolbar() {
        return elements(context.x5t({
            table, groups: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }], machines: [],
            isSortMode: false, refetch() {}, saveOrder() {},
        }));
    }
    function filter() {
        const component = toolbar().find(element => element.type === context.b5t && element.props.column.id === 'kernel_type');
        assert.ok(component, '操作栏应包含内核筛选');
        return elements(context.b5t(component.props));
    }
    return {
        table, calls, toolbar, filter,
        rows: () => Array.from(table.getFilteredRowModel().rows, row => row.original.id),
        filters: () => toolbar().filter(element => element.type === context.b5t),
        choose: value => {
            const option = filter().find(element => element.type === 'Nut' && element.key === value);
            assert.ok(option, '应能找到内核选项');
            option.props.onSelect();
        },
        refresh: data => table.setOptions(current => ({ ...current, data })),
    };
}

test('桌面与窄屏均在类型后显示内核筛选，使用 sing-box 和 Xray 选项', () => {
    for (const mobile of [false, true]) {
        const state = screen({ mobile });
        const filters = state.filters();
        assert.deepEqual(filters.map(item => item.props.column.id), ['type', 'kernel_type', 'machine', 'group_ids']);
        assert.equal(filters[1].props.title, '内核');
        assert.deepEqual(JSON.parse(JSON.stringify(filters[1].props.options)), [
            { label: 'sing-box', value: 'singbox' }, { label: 'Xray', value: 'xray' },
        ]);
    }
});

test('内核分类兼容别名、大小写和历史空值，不按节点协议推断', () => {
    const kernels = ['singbox', 'sing-box', ' SING-BOX ', 'xray', 'XRAY', null, '', undefined, 'unknown'];
    const state = screen({ nodes: kernels.map((kernel_type, id) => ({ id, type: 'vless', kernel_type })) });
    state.choose('singbox');
    assert.deepEqual(state.rows(), [0, 1, 2]);
    state.choose('singbox');
    state.choose('xray');
    assert.deepEqual(state.rows(), [3, 4, 5, 6, 7, 8]);
});

test('两个内核可同时勾选，清空只取消内核筛选，重置取消全部筛选', () => {
    const state = screen();
    state.choose('singbox');
    assert.deepEqual(state.rows(), [10, 40, 50]);
    state.choose('xray');
    assert.deepEqual(state.rows(), [10, 20, 30, 40, 50]);
    state.choose('singbox');
    assert.deepEqual(state.rows(), [20, 30]);
    state.table.getColumn('type').setFilterValue(['vless']);
    const clear = state.filter().find(element => element.type === 'Nut' && element.props.children === 'Clear filters');
    assert.ok(clear);
    clear.props.onSelect();
    assert.deepEqual(state.rows(), [10, 20, 30]);
    assert.equal(state.table.getColumn('kernel_type').getFilterValue(), undefined);
    state.choose('singbox');
    const reset = state.toolbar().find(element => element.type === 'Lf' && element.props.children?.[0] === 'toolbar.reset');
    assert.ok(reset);
    reset.props.onClick();
    assert.deepEqual(state.rows(), [10, 20, 30, 40, 50]);
    assert.equal(state.table.getState().columnFilters.length, 0);
});

test('与类型、服务器、权限组和名称组合过滤，数量统计随其他条件变化', () => {
    const state = screen();
    state.table.getColumn('type').setFilterValue(['vless']);
    state.table.getColumn('machine').setFilterValue(['7']);
    state.table.getColumn('group_ids').setFilterValue(['1']);
    state.choose('singbox');
    assert.deepEqual(state.rows(), [10]);
    assert.deepEqual(Array.from(state.table.getColumn('kernel_type').getFacetedUniqueValues(), ([key, count]) => [key, count]),
        [['singbox', 1], ['xray', 1]]);
    state.table.getColumn('name').setFilterValue('节点 A');
    assert.deepEqual(state.rows(), [10]);
    assert.deepEqual(Array.from(state.table.getColumn('kernel_type').getFacetedUniqueValues(), ([key, count]) => [key, count]),
        [['singbox', 1]]);
});

test('刷新节点数据后保留筛选并重新计算结果，空列表也可清空选择', () => {
    const state = screen();
    state.choose('singbox');
    state.refresh([{ id: 10, type: 'vless', kernel_type: 'xray' }, { id: 20, type: 'trojan', kernel_type: 'singbox' }]);
    assert.deepEqual(state.rows(), [20]);
    state.refresh([]);
    assert.deepEqual(state.rows(), []);
    state.choose('singbox');
    assert.equal(state.table.getColumn('kernel_type').getFilterValue(), undefined);
});

test('内核筛选列在初始状态和拖拽排序模式中保持隐藏', () => {
    const state = screen();
    const kernel = state.table.getColumn('kernel_type');
    assert.equal(kernel.getIsVisible(), false);
    assert.equal(kernel.getCanHide(), false);
    const visibility = bundle.match(/o\((\{"drag-handle":g,[^}]+\})\),f\(\{name:g\?2e3:200/);
    assert.ok(visibility);
    for (const g of [true, false]) {
        state.table.setColumnVisibility(vm.runInNewContext('(' + visibility[1] + ')', { g }));
        assert.equal(kernel.getIsVisible(), false);
        assert.equal(state.table.getVisibleLeafColumns().some(column => column.id === 'kernel_type'), false);
    }
});

test('内核筛选后批量权限组菜单和请求只作用于筛选内的已选节点', async () => {
    const state = screen();
    for (const row of state.table.getCoreRowModel().rows) row.toggleSelected(true);
    state.choose('singbox');
    const remove = state.toolbar().find(element => element.key === 'batch-group-remove-1');
    assert.ok(remove);
    await remove.props.onSelect();
    assert.deepEqual(state.calls, [{ ids: [10, 40, 50], group_action: 'remove', group_id: 1 }]);
});
