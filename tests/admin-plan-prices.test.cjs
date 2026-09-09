const assert = require('node:assert/strict');
const { after, before, test } = require('node:test');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-plan-prices-'));
const assets = path.join(temp, 'assets');
const patches = ['relay', 'upload', 'plan-prices'].map(name => path.join(repo, `.docker/patch-admin-${name}.php`));
let bundle;
let beforePricing;

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
    beforePricing = fs.readFileSync(entry(), 'utf8');
    run('php', [patches.at(-1), assets]);
    run(process.execPath, ['--check', entry()]);
    bundle = fs.readFileSync(entry(), 'utf8');
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-plan-prices-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function between(start, end, from = 0) {
    const begin = bundle.indexOf(start, from);
    assert.ok(begin >= 0, `找不到套餐表单锚点：${start}`);
    const finish = bundle.indexOf(end, begin + start.length);
    assert.ok(finish >= 0, `找不到套餐表单结束锚点：${end}`);
    return bundle.slice(begin + start.length, finish);
}

// 执行最终产物中的表单加载和基础价格回调，直接核对实际填入的金额。
function openForm(plan = null) {
    let values;
    const context = {
        n: plan,
        s6t: vm.runInNewContext('(' + between('s6t=', ',o6t=') + ')'),
        o6t: vm.runInNewContext('(' + between('o6t=', ',a6t=') + ')'),
        d: {
            reset(value) { values = JSON.parse(JSON.stringify(value)); },
            setValue(key, value) { values[key] = JSON.parse(JSON.stringify(value)); },
        },
    };
    const formStart = bundle.indexOf('function l6t()');
    const reset = between('H.useEffect(()=>{', '},[n,d])', formStart);
    vm.runInNewContext('(()=>{' + reset + '})()', context);
    const basePrice = bundle.indexOf('placeholder:c("plan.form.price.base_price")', formStart);
    const change = vm.runInNewContext('(' + between('onChange:', '}),Q.jsx("span",', basePrice) + ')', context);
    return {
        change(value) { change({ target: { value } }); },
        values() { return values; },
    };
}

test('基础价格 10 按完整周期填价，流量包和重置包保留基础价格', () => {
    const form = openForm();
    form.change('10');
    assert.deepEqual(form.values().prices, {
        monthly: '10.00', quarterly: '30.00', half_yearly: '60.00', yearly: '120.00',
        two_yearly: '240.00', three_yearly: '360.00', onetime: '10.00', reset_traffic: '10.00',
    });
});

test('小数基础价格保留两位小数，不再套用周期折扣', () => {
    const form = openForm();
    form.change('9.99');
    assert.deepEqual(form.values().prices, {
        monthly: '9.99', quarterly: '29.97', half_yearly: '59.94', yearly: '119.88',
        two_yearly: '239.76', three_yearly: '359.64', onetime: '9.99', reset_traffic: '9.99',
    });
    form.change('0.01');
    assert.deepEqual(form.values().prices, {
        monthly: '0.01', quarterly: '0.03', half_yearly: '0.06', yearly: '0.12',
        two_yearly: '0.24', three_yearly: '0.36', onetime: '0.01', reset_traffic: '0.01',
    });
});

test('重复输入基础价格重新填价，其他套餐字段保持原值', () => {
    const form = openForm({ name: '测试套餐', transfer_enable: 100 });
    form.change('10');
    form.change('20');
    assert.deepEqual(form.values().prices, {
        monthly: '20.00', quarterly: '60.00', half_yearly: '120.00', yearly: '240.00',
        two_yearly: '480.00', three_yearly: '720.00', onetime: '20.00', reset_traffic: '20.00',
    });
    const prices = { ...form.values().prices };
    form.change('20');
    assert.deepEqual(form.values().prices, prices);
    assert.equal(form.values().name, '测试套餐');
    assert.equal(form.values().transfer_enable, 100);
});

test('空输入和无效输入保留当前价格，零值仍按原规则填入', () => {
    const form = openForm();
    form.change('10');
    const prices = { ...form.values().prices };
    for (const input of ['', 'abc']) {
        form.change(input);
        assert.deepEqual(form.values().prices, prices);
    }
    form.change('0');
    assert.ok(Object.values(form.values().prices).every(price => price === '0.00'));
});

test('重新打开已有套餐保留各周期独立价格，不自动调价', () => {
    const plan = { id: 7, name: '测试套餐', prices: { monthly: 10, quarterly: 28.5, yearly: 115.8, onetime: 50 } };
    assert.deepEqual(openForm(plan).values().prices, plan.prices);
    const saved = { ...plan, prices: { ...plan.prices, quarterly: 31.8 } };
    assert.deepEqual(openForm(saved).values().prices, saved.prices);
});

test('完整构建补丁重复执行保持内容、文件名和入口引用一致', () => {
    const previous = entry();
    const manifest = fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8');
    const html = fs.readFileSync(path.join(temp, 'index.html'), 'utf8');
    const hash = createHash('sha1').update(bundle).digest('hex').slice(0, 8);
    assert.equal(path.basename(previous), `index-${hash}.js`);
    assert.ok(html.includes(path.basename(previous)));
    for (const patch of patches) run('php', [patch, assets]);
    assert.equal(entry(), previous);
    assert.equal(fs.readFileSync(entry(), 'utf8'), bundle);
    assert.equal(fs.readFileSync(path.join(temp, 'manifest.json'), 'utf8'), manifest);
    assert.equal(fs.readFileSync(path.join(temp, 'index.html'), 'utf8'), html);
});

test('上游计算锚点变化时构建失败，原产物保持完整', () => {
    const incompatible = path.join(temp, 'incompatible');
    fs.mkdirSync(incompatible);
    const source = beforePricing.replace('e*i.months*i.discount', 'i.discount*e*i.months');
    assert.notEqual(source, beforePricing);
    const file = path.join(incompatible, 'index-changed.js');
    fs.writeFileSync(file, source, 'utf8');
    const result = spawnSync('php', [patches.at(-1), incompatible], { encoding: 'utf8' });
    assert.equal(result.status, 1, result.stderr);
    assert.ok(result.stderr.includes('锚点已变化'));
    assert.equal(fs.readFileSync(file, 'utf8'), source);
    assert.deepEqual(fs.readdirSync(incompatible), ['index-changed.js']);
});

test('部分套餐价格补丁导致构建失败，不重复修改产物', () => {
    const partial = path.join(temp, 'partial');
    fs.mkdirSync(partial);
    const source = bundle.replace('const r=e*i.months;/* yz_plan_prices_no_discount */', 'const r=e*i.months*i.discount;/* yz_plan_prices_no_discount */');
    assert.notEqual(source, bundle);
    const file = path.join(partial, 'index-partial.js');
    fs.writeFileSync(file, source, 'utf8');
    const result = spawnSync('php', [patches.at(-1), partial], { encoding: 'utf8' });
    assert.equal(result.status, 1, result.stderr);
    assert.ok(result.stderr.includes('部分套餐价格补丁'));
    assert.equal(fs.readFileSync(file, 'utf8'), source);
    assert.deepEqual(fs.readdirSync(partial), ['index-partial.js']);
});
