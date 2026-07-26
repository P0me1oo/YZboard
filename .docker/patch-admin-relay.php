<?php
/**
 * 给管理端构建产物注入「中转入口」选择框。
 *
 * 管理端源码不在本仓库（public/assets/admin 是 cedar2025/xboard-admin-dist 的构建产物），
 * 而节点编辑表单里的「父级节点」下拉写死了同协议过滤，选不到跨协议的 VLESS 入口。
 * 这里在构建阶段对压缩产物做定点补丁，新增一个独立的 relay_entry_id 选择框，
 * 候选为「没有中转入口的 VLESS 节点」，不改动原有的父级节点字段。
 *
 * 任一锚点匹配不到就直接失败退出，让构建显式报错，而不是静默产出一个缺少该字段的管理端。
 * 重复执行是幂等的。
 */

const LABEL = '中转入口';
const PLACEHOLDER = '选择中转入口节点';
const NONE = '无';

$root = $argv[1] ?? '/www/public/assets/admin/assets';
if (!is_dir($root)) {
    fwrite(STDERR, "patch-admin-relay: 目录不存在 {$root}\n");
    exit(1);
}

$targets = glob($root . '/index-*.js');
if (!$targets) {
    fwrite(STDERR, "patch-admin-relay: 未找到 index-*.js\n");
    exit(1);
}

$patched = 0;
foreach ($targets as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        fwrite(STDERR, "patch-admin-relay: 读取失败 {$file}\n");
        exit(1);
    }

    if (str_contains($src, 'relay_entry_id')) {
        fwrite(STDOUT, "patch-admin-relay: 已包含 relay_entry_id，跳过 " . basename($file) . "\n");
        $patched++;
        continue;
    }

    // 只处理确实含有节点编辑表单的产物，其余 chunk 直接跳过。
    if (!str_contains($src, 'name:"parent_id",render:')) {
        continue;
    }

    $src = patchSchema($src, $file);
    $src = patchDefaults($src, $file);
    $src = patchCandidates($src, $file);
    $src = patchField($src, $file);

    if (file_put_contents($file, $src) === false) {
        fwrite(STDERR, "patch-admin-relay: 写入失败 {$file}\n");
        exit(1);
    }

    // 文件名里的 hash 是构建时算的，改内容不改名会让浏览器继续用缓存里的旧产物。
    // 按补丁后的内容重新命名，并同步更新引用，才能真正让客户端取到新版本。
    $newBase = renameWithNewHash($file, $src, $root);
    fwrite(STDOUT, "patch-admin-relay: 已注入 relay_entry_id -> {$newBase}\n");
    $patched++;
}

if ($patched === 0) {
    fwrite(STDERR, "patch-admin-relay: 没有任何产物被处理，锚点可能已随上游变更\n");
    exit(1);
}
exit(0);

/**
 * 用补丁后的内容重新计算文件名，并更新 manifest.json、index.html 等处的引用。
 *
 * 返回新的文件名。找不到任何引用时视为产物结构异常，直接失败。
 */
function renameWithNewHash(string $file, string $content, string $assetsDir): string
{
    $oldBase = basename($file);
    // 与 Vite 的 hash 无关，只要求随内容变化且可重复推导，便于重复构建产出相同结果。
    $newBase = 'index-' . substr(sha1($content), 0, 8) . '.js';
    if ($newBase === $oldBase) {
        return $oldBase;
    }

    if (!rename($file, dirname($file) . '/' . $newBase)) {
        fwrite(STDERR, "patch-admin-relay: 重命名失败 {$oldBase}\n");
        exit(1);
    }

    // 引用出现在 admin 根目录（manifest.json、index.html），assets 的上一级。
    $adminRoot = dirname(rtrim($assetsDir, '/\\'));
    $updated = 0;
    foreach (['json', 'html', 'js'] as $ext) {
        foreach (glob($adminRoot . '/*.' . $ext) ?: [] as $ref) {
            $body = file_get_contents($ref);
            if ($body === false || !str_contains($body, $oldBase)) {
                continue;
            }
            if (file_put_contents($ref, str_replace($oldBase, $newBase, $body)) === false) {
                fwrite(STDERR, "patch-admin-relay: 更新引用失败 {$ref}\n");
                exit(1);
            }
            fwrite(STDOUT, "patch-admin-relay: 更新引用 " . basename($ref) . "\n");
            $updated++;
        }
    }

    if ($updated === 0) {
        fwrite(STDERR, "patch-admin-relay: 未找到任何指向 {$oldBase} 的引用，产物结构异常\n");
        exit(1);
    }

    return $newBase;
}

function fail(string $what, string $file): never
{
    fwrite(STDERR, "patch-admin-relay: 在 " . basename($file) . " 中找不到锚点：{$what}\n");
    fwrite(STDERR, "管理端构建产物结构已变化，需要同步更新本补丁。\n");
    exit(1);
}

/** 表单校验 schema 中登记新字段。 */
function patchSchema(string $src, string $file): string
{
    $pattern = '/(parent_id:(\w+)\(\)\.default\("0"\)\.nullable\(\),)/';
    if (!preg_match($pattern, $src, $m)) {
        fail('schema parent_id', $file);
    }
    $zodString = $m[2];
    $insert = $m[1] . "relay_entry_id:{$zodString}().default(\"0\").nullable(),";
    return preg_replace($pattern, addcslashes($insert, '\\$'), $src, 1);
}

/** 表单默认值中登记新字段。 */
function patchDefaults(string $src, string $file): string
{
    $needle = 'parent_id:"0",route_ids:';
    if (!str_contains($src, $needle)) {
        fail('defaults parent_id', $file);
    }
    return str_replace($needle, 'parent_id:"0",relay_entry_id:"0",route_ids:', $src);
}

/**
 * 新增候选列表：没有中转入口的 VLESS 节点，且不是自己。
 * 与原有的父级节点候选（同协议过滤）并列声明，互不影响。
 */
function patchCandidates(string $src, string $file): string
{
    $pattern = '/(const (\w+)=(\w+)\.useMemo\(\(\)=>(\w+)\?\.filter\(\w+=>\(0===\w+\.parent_id\|\|null===\w+\.parent_id\)&&\w+\.type===(\w+)&&\w+\.id!==(\w+)\.watch\("id"\)\),\[\w+,\w+,\w+\]\))/';
    if (!preg_match($pattern, $src, $m)) {
        fail('parent candidates useMemo', $file);
    }
    $whole = $m[1];
    $useMemoHost = $m[3];   // React 命名空间
    $serversVar = $m[4];    // 节点列表
    $formVar = $m[6];       // react-hook-form 实例

    $added = $whole
        . ",__relayEntryOpts={$useMemoHost}.useMemo(()=>{$serversVar}?.filter(e=>(0===e.relay_entry_id||null===e.relay_entry_id)&&\"vless\"===e.type&&e.id!=={$formVar}.watch(\"id\")),[{$serversVar},{$formVar}])";

    return str_replace($whole, $added, $src);
}

/** 在父级节点字段之后插入一个结构相同的中转入口字段。 */
function patchField(string $src, string $file): string
{
    $start = strpos($src, 'Q.jsx($y,{control:x.control,name:"parent_id",render:');
    if ($start === false) {
        fail('parent_id form field', $file);
    }
    $endNeedle = 'Q.jsx($y,{control:x.control,name:"route_ids",render:';
    $end = strpos($src, $endNeedle, $start);
    if ($end === false) {
        fail('route_ids form field', $file);
    }

    $label = jsString(LABEL);
    $placeholder = jsString(PLACEHOLDER);
    $none = jsString(NONE);

    // 结构照搬父级节点字段，只替换字段名、候选列表和文案。
    $field = 'Q.jsx($y,{control:x.control,name:"relay_entry_id",render:({field:t})=>Q.jsxs(Gy,{children:['
        . 'Q.jsx(Zy,{className:"font-mono text-[12px] text-foreground/80",children:' . $label . '}),'
        . 'Q.jsxs(yzt,{onValueChange:t.onChange,value:t.value?.toString()||"0",children:['
        . 'Q.jsx(Yy,{children:Q.jsx(Czt,{className:"h-9 font-mono text-xs",children:Q.jsx(wzt,{placeholder:' . $placeholder . '})})}),'
        . 'Q.jsxs(Nzt,{className:"font-mono text-xs",children:['
        . 'Q.jsx(Lzt,{value:"0",className:"text-xs",children:' . $none . '}),'
        . '__relayEntryOpts?.map(e=>Q.jsx(Lzt,{value:e.id.toString(),className:"cursor-pointer text-xs",children:e.name},e.id))'
        . ']})]}),Q.jsx(Qy,{})]})}),';

    return substr($src, 0, $end) . $field . substr($src, $end);
}

/** 输出为 \u 转义的 JS 字符串字面量，避免产物编码问题。 */
function jsString(string $text): string
{
    return json_encode($text, JSON_UNESCAPED_SLASHES);
}
