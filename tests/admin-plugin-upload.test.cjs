const assert = require('node:assert/strict');
const { before, after, test } = require('node:test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'yzboard-plugin-upload-'));
const assets = path.join(temp, 'assets');
const relayPatch = path.join(repo, '.docker/patch-admin-relay.php');
const uploadPatch = path.join(repo, '.docker/patch-admin-upload.php');
let bundle;

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
    run('php', [relayPatch, assets]);
    run('php', [uploadPatch, assets]);
    run(process.execPath, ['--check', entry()]);
    bundle = fs.readFileSync(entry(), 'utf8');
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-plugin-upload-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function uploadApi() {
    const match = bundle.match(/uploadPlugin:(e=>\{[\s\S]*?\}),deletePlugin:/);
    assert.ok(match, '找不到实际插件上传方法');
    const calls = [];
    const upload = vm.runInNewContext('(' + match[1] + ')', {
        NT: '/api/v2/test',
        FormData: class {
            append(name, value) { this[name] = value; }
        },
        RL(url, data, options) {
            calls.push({ url, data, options });
            return Promise.resolve({ message: '插件上传成功' });
        },
    });
    return { upload, calls };
}

function responseInterceptor() {
    const start = bundle.indexOf('TL.interceptors.response.use(');
    const end = bundle.indexOf(';const IL=', start);
    assert.ok(start >= 0 && end > start, '找不到实际请求错误处理');
    let handler;
    const notices = [];
    vm.runInNewContext(bundle.slice(start, end), {
        TL: { interceptors: { response: { use(success, failure) { handler = failure; } } } },
        lL: { t(key) { return key; } },
        gE: { error(message) { notices.push(message); } },
        wE() {},
    });
    return { handler, notices };
}

test('实际上传方法允许 12 MiB 和恰好 64 MiB 的插件包', async () => {
    const { upload, calls } = uploadApi();
    for (const size of [12 * 1024 * 1024, 64 * 1024 * 1024]) {
        const file = { name: 'plugin.zip', size };
        await upload(file);
        assert.equal(calls.at(-1).data.file, file);
        assert.equal(calls.at(-1).url, '/api/v2/test/plugin/upload');
    }
    assert.equal(calls.length, 2);
});

test('超过 64 MiB 一字节时明确报错且不发送请求', async () => {
    const { upload, calls } = uploadApi();
    await assert.rejects(upload({ name: 'plugin.zip', size: 64 * 1024 * 1024 + 1 }), error => {
        assert.equal(error.message, '插件包大小不能超过64 MiB');
        return true;
    });
    assert.equal(calls.length, 0);
});

test('服务器返回 HTML 或空内容的 413 时保留明确提示', async () => {
    for (const data of ['<html>413 Request Entity Too Large</html>', '']) {
        const { handler, notices } = responseInterceptor();
        await assert.rejects(handler({ response: { status: 413, data } }), error => {
            assert.equal(error.code, 413);
            assert.equal(error.message, '上传文件过大，请检查服务器上传限制');
            return true;
        });
        assert.deepEqual(notices, ['上传文件过大，请检查服务器上传限制']);
    }
});

test('保留后端提供的 413 原因和原有表单验证错误', async () => {
    const { handler, notices } = responseInterceptor();
    const message = '入口允许的请求大小不足';
    await assert.rejects(handler({ response: { status: 413, data: { message } } }), error => error.message === message);
    const validation = { message: '文件格式不正确', errors: { file: ['必须上传 ZIP'] } };
    await assert.rejects(handler({ response: { status: 422, data: validation } }), error => error === validation);
    assert.deepEqual(notices, [message, validation.message]);
});

test('重新执行两份构建补丁后，内容、入口名称和引用保持一致', () => {
    const before = entry();
    run('php', [relayPatch, assets]);
    run('php', [uploadPatch, assets]);
    assert.equal(entry(), before);
    assert.equal(fs.readFileSync(entry(), 'utf8'), bundle);
    assert.ok(fs.readFileSync(path.join(temp, 'index.html'), 'utf8').includes(path.basename(entry())));
});

test('上游上传锚点变化时构建失败，不写入半成品', () => {
    const incompatible = path.join(temp, 'incompatible');
    fs.mkdirSync(incompatible);
    const source = bundle.replace('/* yz_plugin_upload_64m */', '').replace('const t=new FormData;', 'let t=new FormData;');
    const file = path.join(incompatible, 'index-changed.js');
    fs.writeFileSync(file, source, 'utf8');
    const result = spawnSync('php', [uploadPatch, incompatible], { encoding: 'utf8' });
    assert.notEqual(result.status, 0);
    assert.ok(result.stderr.includes('锚点已变化'));
    assert.equal(fs.readFileSync(file, 'utf8'), source);
});
