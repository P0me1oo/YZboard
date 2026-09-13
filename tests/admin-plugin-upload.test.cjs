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
let beforeUpload;

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
    beforeUpload = fs.readFileSync(entry(), 'utf8');
    run('php', [uploadPatch, assets]);
    run(process.execPath, ['--check', entry()]);
    bundle = fs.readFileSync(entry(), 'utf8');
});

after(() => {
    assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
    assert.ok(path.basename(temp).startsWith('yzboard-plugin-upload-'));
    fs.rmSync(temp, { recursive: true, force: true });
});

function uploadApi(respond = () => Promise.resolve({ message: '插件上传成功' })) {
    const match = bundle.match(/uploadPlugin:(e=>\{[\s\S]*?\}),deletePlugin:/);
    assert.ok(match, '找不到实际插件上传方法');
    const calls = [];
    const upload = vm.runInNewContext('(' + match[1] + ')', {
        NT: '/api/v2/test',
        FormData: class {
            append(name, value) { this[name] = value; }
        },
        RL(url, data, options) {
            const call = { url, data, options };
            calls.push(call);
            return respond(call);
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
    const logouts = [];
    vm.runInNewContext(bundle.slice(start, end), {
        TL: { interceptors: { response: { use(success, failure) { handler = failure; } } } },
        lL: { t(key) { return key; } },
        gE: { error(message) { notices.push(message); } },
        wE() { logouts.push(true); },
    });
    return { handler, notices, logouts };
}

function uploadPage(upload, notices) {
    const match = bundle.match(/F=(async n=>\{n\.name\.endsWith\("\.zip"\)[\s\S]*?\}),B=e=>/);
    assert.ok(match, '找不到实际插件上传页面处理函数');
    const state = { loading: [], closed: [], successes: [], refreshes: 0, invalidations: 0, input: { value: 'plugin.zip' } };
    const submit = vm.runInNewContext('(' + match[1] + ')', {
        ET: { uploadPlugin: upload },
        gE: { error: message => notices.push(message), success: message => state.successes.push(message) },
        e: key => key,
        m: loading => state.loading.push(loading),
        _: open => state.closed.push(open),
        D: () => state.refreshes++,
        t: { invalidateQueries: () => state.invalidations++ },
        Wlt: ['plugins'],
        y: { current: state.input },
    });
    return {
        state,
        async submit(file) {
            await submit(file);
            await new Promise(resolve => setImmediate(resolve));
        },
    };
}

function fixture(name, source) {
    const directory = path.join(temp, name);
    const fixtureAssets = path.join(directory, 'assets');
    fs.mkdirSync(fixtureAssets, { recursive: true });
    const file = path.join(fixtureAssets, 'index-original.js');
    const manifest = path.join(directory, 'manifest.json');
    const html = path.join(directory, 'index.html');
    fs.writeFileSync(file, source, 'utf8');
    fs.writeFileSync(manifest, JSON.stringify({ 'index.html': { file: 'assets/index-original.js' } }), 'utf8');
    fs.writeFileSync(html, '<script src="assets/index-original.js"></script>', 'utf8');
    return { directory, assets: fixtureAssets, file, manifest, html };
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

test('插件上传独立等待 5 分钟，普通请求继续使用 30 秒', async () => {
    const start = bundle.indexOf('const TL=BN.create(');
    const end = bundle.indexOf(';TL.interceptors.request.use(', start);
    assert.ok(start >= 0 && end > start, '找不到实际请求客户端配置');
    let defaults;
    vm.runInNewContext(bundle.slice(start, end), {
        BN: { create: config => { defaults = config; } },
        window: { settings: {} },
    });
    assert.equal(defaults.timeout, 30000);
    const { upload, calls } = uploadApi();
    await upload({ name: 'plugin.zip', size: 1024 });
    assert.equal(calls[0].options.timeout, 300000);
    assert.equal(calls[0].options.headers['Content-Type'], 'multipart/form-data');
    assert.equal(calls[0].options.skipErrorToast, true);
    assert.equal(calls[0].options.timeoutErrorMessage, '插件上传请求超时（5 分钟），请刷新插件列表确认结果后再重试');
});

test('超过 64 MiB 一字节时明确报错且不发送请求', async () => {
    const { upload, calls } = uploadApi();
    await assert.rejects(upload({ name: 'plugin.zip', size: 64 * 1024 * 1024 + 1 }), error => {
        assert.equal(error.message, '插件包大小不能超过64 MiB');
        return true;
    });
    assert.equal(calls.length, 0);
});

test('两种浏览器超时码都在上传页面显示一次原因，并恢复为可重新上传状态', async () => {
    for (const code of ['ECONNABORTED', 'ETIMEDOUT']) {
        const { handler, notices } = responseInterceptor();
        let fail = true;
        const { upload, calls } = uploadApi(({ url, options }) => fail
            ? handler({ code, message: 'timeout of 300000ms exceeded', config: { url, ...options } })
            : Promise.resolve({ message: '插件上传成功' }));
        const page = uploadPage(upload, notices);
        const file = { name: 'plugin.zip', size: 1024 };
        await page.submit(file);
        assert.deepEqual(notices, ['插件上传请求超时（5 分钟），请刷新插件列表确认结果后再重试']);
        assert.deepEqual(page.state.loading, [true, false]);
        assert.deepEqual(page.state.closed, []);
        assert.deepEqual(page.state.successes, []);
        assert.equal(page.state.refreshes, 0);
        assert.equal(page.state.input.value, '');
        assert.equal(calls.length, 1, '超时后不能自动重复发送上传请求');

        fail = false;
        await page.submit(file);
        assert.deepEqual(page.state.loading, [true, false, true, false]);
        assert.deepEqual(page.state.closed, [false]);
        assert.equal(page.state.successes.length, 1);
        assert.equal(page.state.refreshes, 1);
        assert.equal(page.state.invalidations, 1);
        assert.equal(calls.length, 2);
    }
});

test('本地文件校验失败仍在页面显示原因，并且不发送上传请求', async () => {
    const { upload, calls } = uploadApi();
    const notices = [];
    const page = uploadPage(upload, notices);
    await page.submit({ name: 'plugin.zip', size: 64 * 1024 * 1024 + 1 });
    assert.deepEqual(notices, ['插件包大小不能超过64 MiB']);
    assert.deepEqual(page.state.loading, [true, false]);
    assert.equal(page.state.input.value, '');
    await page.submit({ name: 'plugin.txt', size: 1024 });
    assert.deepEqual(notices, ['插件包大小不能超过64 MiB', 'upload.error.format']);
    assert.equal(calls.length, 0);
});

test('普通请求超时保留实际等待时间与错误码，不能误用插件的 5 分钟提示', async () => {
    for (const code of ['ECONNABORTED', 'ETIMEDOUT']) {
        const { handler, notices } = responseInterceptor();
        await assert.rejects(handler({ code, message: 'timeout of 30000ms exceeded', config: { timeout: 30000 } }), error => {
            assert.equal(error.code, code);
            assert.equal(error.message, '请求超时（30 秒），请检查网络或服务状态后重试');
            return true;
        });
        assert.deepEqual(notices, ['请求超时（30 秒），请检查网络或服务状态后重试']);
    }
});

test('断网、取消、中断和客户端错误均保留明确原因', async () => {
    for (const [input, expected] of [
        [{ code: 'ERR_NETWORK', message: 'Network Error' }, '网络连接失败，未收到服务器响应，请检查网络或服务状态'],
        [{ code: 'ERR_CANCELED', message: 'canceled' }, '请求已取消'],
        [{ code: 'ECONNABORTED', message: 'Request aborted' }, '请求已中断，请重试'],
        [{ code: 'CLIENT_ERROR', message: '文件读取失败' }, '文件读取失败'],
        [{ code: -1, message: '请先登录' }, '请先登录'],
    ]) {
        const { handler, notices } = responseInterceptor();
        await assert.rejects(handler(input), error => {
            assert.equal(error.code, input.code);
            assert.equal(error.message, expected);
            return true;
        });
        assert.deepEqual(notices, [expected]);
    }
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

test('网关和服务器返回 HTML、空内容或无效消息时，提示明确的 HTTP 原因', async () => {
    const messages = {
        408: '服务器接收请求超时（HTTP 408），请检查网络后重试',
        500: '服务器内部错误（HTTP 500），请查看服务端日志',
        502: '网关连接服务异常（HTTP 502），请检查服务状态',
        503: '服务暂时不可用（HTTP 503），请稍后重试',
        504: '网关等待服务器响应超时（HTTP 504），请检查网关和服务端超时设置',
        524: '网关与服务器通信超时（HTTP 524），请检查服务端处理耗时',
        521: '请求失败（HTTP 521）',
    };
    for (const [status, expected] of Object.entries(messages)) {
        for (const data of ['<!DOCTYPE html><html>Gateway error</html>', '', null, [], { message: { detail: '无效消息结构' } }]) {
            const { handler, notices } = responseInterceptor();
            await assert.rejects(handler({ response: { status: Number(status), data } }), error => {
                assert.equal(error.code, Number(status));
                assert.equal(error.message, expected);
                return true;
            });
            assert.deepEqual(notices, [expected]);
        }
    }
});

test('服务端原因优先于状态码提示，并支持纯文本和常见错误字段', async () => {
    const expected = '插件安装失败：缺少 manifest.json';
    for (const data of [{ message: expected }, { error: expected }, { error: { message: expected } }, expected]) {
        const { handler, notices } = responseInterceptor();
        await assert.rejects(handler({ response: { status: 500, data } }), error => error.message === expected);
        assert.deepEqual(notices, [expected]);
    }
    const { handler, notices } = responseInterceptor();
    const validation = { code: 'INVALID_FILE', message: '', errors: { file: ['必须上传 ZIP', '压缩包不能损坏'] } };
    await assert.rejects(handler({ response: { status: 422, data: validation } }), error => {
        assert.equal(error.code, 'INVALID_FILE');
        assert.equal(error.errors, validation.errors);
        assert.equal(error.message, '必须上传 ZIP；压缩包不能损坏');
        return true;
    });
    assert.deepEqual(notices, ['必须上传 ZIP；压缩包不能损坏']);
});

test('认证错误保留原有登录处理，完全缺少错误信息时仍有可读提示', async () => {
    for (const [status, message] of [[401, 'common:http.loginExpired'], [403, 'common:http.noPermission']]) {
        const { handler, notices, logouts } = responseInterceptor();
        await assert.rejects(handler({ response: { status, data: '' } }), error => error.message === message);
        assert.deepEqual(notices, [message]);
        assert.equal(logouts.length, 1);
    }
    for (const input of [{}, null, undefined]) {
        const { handler, notices, logouts } = responseInterceptor();
        await assert.rejects(handler(input), error => error.message === 'common:http.unknownError');
        assert.deepEqual(notices, ['common:http.unknownError']);
        assert.equal(logouts.length, 0);
    }
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
    const source = beforeUpload.replace('uploadPlugin:e=>{const t=new FormData;', 'uploadPlugin:e=>{let t=new FormData;');
    const incompatible = fixture('incompatible', source);
    const manifest = fs.readFileSync(incompatible.manifest, 'utf8');
    const html = fs.readFileSync(incompatible.html, 'utf8');
    const result = spawnSync('php', [uploadPatch, incompatible.assets], { encoding: 'utf8' });
    assert.notEqual(result.status, 0);
    assert.ok(result.stderr.includes('锚点已变化'));
    assert.equal(fs.readFileSync(incompatible.file, 'utf8'), source);
    assert.equal(fs.readFileSync(incompatible.manifest, 'utf8'), manifest);
    assert.equal(fs.readFileSync(incompatible.html, 'utf8'), html);
    assert.deepEqual(fs.readdirSync(incompatible.assets), ['index-original.js']);
});

test('旧版 64 MiB 上传补丁可以升级，结果与全新构建一致', () => {
    // 1.15.0 的三个固定替换结果，作为已有产物的兼容样本。
    const replacements = [
        ['uploadPlugin:e=>{const t=new FormData;', 'uploadPlugin:e=>{/* yz_plugin_upload_64m */if(e.size>64*1024*1024)return Promise.reject({message:"插件包大小不能超过64 MiB"});const t=new FormData;'],
        ['const i={401:lL.t("common:http.loginExpired"),', 'const i={413:"上传文件过大，请检查服务器上传限制",401:lL.t("common:http.loginExpired"),'],
        ['Promise.reject(e.response?.data||{data:null,code:-1,message:lL.t("common:http.unknownError")})', 'Promise.reject(413===t?{data:null,code:413,message:n||i[413]}:e.response?.data||{data:null,code:-1,message:lL.t("common:http.unknownError")})'],
    ];
    let source = beforeUpload;
    for (const [anchor, replacement] of replacements) {
        assert.equal(source.split(anchor).length, 2);
        source = source.replace(anchor, replacement);
    }
    const legacy = fixture('legacy', source);
    run('php', [uploadPatch, legacy.assets]);
    const manifest = JSON.parse(fs.readFileSync(legacy.manifest, 'utf8'));
    assert.equal(path.basename(manifest['index.html'].file), path.basename(entry()));
    assert.equal(fs.readFileSync(path.join(legacy.directory, manifest['index.html'].file), 'utf8'), bundle);
    assert.ok(fs.readFileSync(legacy.html, 'utf8').includes(path.basename(entry())));
    run('php', [uploadPatch, legacy.assets]);

    const partialSource = source.replace(replacements[1][1], replacements[1][0]);
    const partial = fixture('legacy-partial', partialSource);
    const result = spawnSync('php', [uploadPatch, partial.assets], { encoding: 'utf8' });
    assert.notEqual(result.status, 0);
    assert.ok(result.stderr.includes('部分旧版上传补丁'));
    assert.equal(fs.readFileSync(partial.file, 'utf8'), partialSource);
});

test('已有新补丁缺少超时配置时构建失败，不能把残缺产物当成已完成', () => {
    const source = bundle.replace('timeout:300000,', 'timeout:30000,');
    assert.notEqual(source, bundle);
    const partial = fixture('partial', source);
    const result = spawnSync('php', [uploadPatch, partial.assets], { encoding: 'utf8' });
    assert.notEqual(result.status, 0);
    assert.ok(result.stderr.includes('部分上传补丁'));
    assert.equal(fs.readFileSync(partial.file, 'utf8'), source);
});
