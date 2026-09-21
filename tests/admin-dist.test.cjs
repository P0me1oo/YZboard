const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

// 管理端产物由 YZboard-Dash 源码工程构建后同步到 public/assets/admin。
// 界面行为的回归在该工程内完成（源码检查、行为测试、29 页对照、浏览器用例）。
// 这里守住面板侧的交付契约：产物结构可被 Laravel 入口加载，且所有 YZ 定制都在产物里。
const repo = path.resolve(__dirname, '..');
const admin = path.join(repo, 'public/assets/admin');

function manifest() {
    return JSON.parse(fs.readFileSync(path.join(admin, 'manifest.json'), 'utf8'));
}

// 复现 resources/views/admin.blade.php 的清单解析，确保入口脚本和样式能被解析出来
function resolveEntryAssets() {
    const chunks = manifest();
    const entry = chunks['index.html'];
    const scripts = [];
    const styles = [];
    const visited = new Set();
    (function collect(name) {
        if (visited.has(name) || !chunks[name]) return;
        visited.add(name);
        const chunk = chunks[name];
        for (const css of chunk.css || []) styles.push(css);
        for (const dependency of chunk.imports || []) collect(dependency);
        if (chunk.isEntry && chunk.file) scripts.push(chunk.file);
    })('index.html');
    return { entry, scripts, styles };
}

test('构建清单提供 Laravel 入口所需的脚本与样式，且文件都存在', () => {
    const { entry, scripts, styles } = resolveEntryAssets();
    assert.ok(entry?.isEntry, '清单必须把 index.html 标为入口');
    assert.ok(scripts.length > 0, 'blade 依据 scripts 非空决定是否走清单分支');
    assert.ok(styles.length > 0, '清单必须包含样式');
    for (const name of [...scripts, ...styles]) {
        assert.ok(!name.includes('..'), `资源路径不得越级：${name}`);
        assert.ok(fs.existsSync(path.join(admin, name)), `资源不存在：${name}`);
    }
});

test('入口及其动态块语法正确，且不残留指向未发布源码映射的注释', () => {
    const chunks = manifest();
    const files = new Set();
    for (const chunk of Object.values(chunks)) {
        if (typeof chunk.file === 'string' && chunk.file.endsWith('.js')) files.add(chunk.file);
    }
    assert.ok(files.size >= 2, '应至少包含入口与管理端主块');
    for (const name of files) {
        const file = path.join(admin, name);
        assert.ok(fs.existsSync(file), `产物不存在：${name}`);
        const result = spawnSync(process.execPath, ['--check', file], { encoding: 'utf8' });
        assert.equal(result.status, 0, `${name} 语法检查失败：${result.stderr}`);
        // 面板不发布 .map，产物末尾不应再指向它，否则浏览器开发者工具会请求 404
        const tail = fs.readFileSync(file, 'utf8').slice(-400);
        assert.ok(!/^\/\/# sourceMappingURL=/m.test(tail), `${name} 仍带有源码映射注释`);
        assert.ok(!fs.existsSync(`${file}.map`), `不应发布源码映射：${name}.map`);
    }
});

test('三种语言资源齐全，并包含两步验证文案', () => {
    for (const locale of ['zh-CN', 'en-US', 'ru-RU']) {
        const file = path.join(admin, 'locales', `${locale}.js`);
        assert.ok(fs.existsSync(file), `缺少语言资源：${locale}`);
        const scope = { window: {} };
        // 语言文件是挂到 window 上的赋值脚本，放进最小沙箱执行即可读取
        new Function('window', fs.readFileSync(file, 'utf8')).call(null, scope.window);
        const translations = scope.window.XBOARD_TRANSLATIONS[locale];
        assert.ok(translations, `${locale} 未注册翻译`);
        assert.ok(translations.settings?.totp?.title, `${locale} 缺少安全设置两步验证文案`);
        assert.ok(translations.auth?.signIn?.totp?.title, `${locale} 缺少登录页两步验证文案`);
        for (const key of ['version', 'publicIp', 'batch_upgrade', 'batch_restart']) {
            assert.ok(translations.machine?.agent?.[key], `${locale} 缺少服务器 agent 文案 ${key}`);
        }
    }
});

test('编辑器工作线程与字体随产物发布', () => {
    const assets = fs.readdirSync(path.join(admin, 'assets'));
    for (const worker of ['ts.worker-', 'css.worker-', 'html.worker-', 'json.worker-']) {
        assert.ok(assets.some(name => name.startsWith(worker)), `缺少编辑器工作线程 ${worker}`);
    }
    assert.ok(assets.some(name => name.endsWith('.ttf')), '缺少编辑器字体');
});

test('产物包含全部 YZ 定制，避免误用未定制的上游管理端', () => {
    const chunks = manifest();
    const bundles = Object.values(chunks)
        .filter(chunk => typeof chunk.file === 'string' && chunk.file.endsWith('.js'))
        .map(chunk => fs.readFileSync(path.join(admin, chunk.file), 'utf8'))
        .join('\n');

    // 每项对应一组 YZ 定制；缺失说明同步了未经定制的产物
    const markers = {
        '节点前置入口': 'relay_entry_id',
        '节点内核选择': 'data-yz-node-kernel-selector',
        '单节点运行开关': 'data-yz-node-switch',
        '内部端口校验': 'yzCreateServerPortValidator',
        '节点批量权限组': 'group_action',
        '插件上传 64 MiB': '67108864',
        '套餐周期价格': 'three_year_price',
        '管理员两步验证': 'loginWithTotp',
        '服务器 agent 操作': '/server/machine/operate',
        '服务器运行版本': 'agent_runtime',
        '服务器任务状态': 'agent_operation',
        '服务器分页记忆': 'yzboard.machines.pageSize',
    };
    for (const [name, marker] of Object.entries(markers)) {
        assert.ok(bundles.includes(marker), `产物缺少「${name}」定制（标识 ${marker}）`);
    }
});
