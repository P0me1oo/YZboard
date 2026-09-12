<?php

/**
 * 将节点列表的显隐开关改为当前节点的运行开关。
 * 保留固定管理端子模块，通过构建补丁同步列、组件和排序模式。
 */

const NODE_SWITCH_MARKER = 'yz_node_runtime_switch';

try {
    $assets = rtrim($argv[1] ?? '/www/public/assets/admin/assets', '/\\');
    $helper = file_get_contents(__DIR__ . '/admin-node-switch.js');
    if ($helper === false) {
        throw new RuntimeException('读取节点开关脚本失败');
    }
    $helper = rtrim(str_replace("\r\n", "\n", $helper)) . "\n";
    $component = 'function F5t({node:e,refetch:t}){const[n,i]=H.useState(Boolean(e.show));'
        . 'return Q.jsx(oZt,{checked:n,onCheckedChange:async n=>{i(n),'
        . 'XL({id:e.id,type:e.type,show:n?1:0}).catch(()=>{i(!n),t()})},'
        . 'style:{backgroundColor:n?sqt[e.type]:void 0}})}';
    $column = '{accessorKey:"show",header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.show")}),'
        . 'cell:({row:t})=>Q.jsx(F5t,{node:t.original,refetch:e}),size:50,enableSorting:!1},';
    $replacements = [
        $component => $helper,
        $column => '{accessorKey:"enabled",header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.enabled")}),'
            . 'cell:({row:t})=>Q.jsx(F5t,{node:t.original,refetch:e},t.original.id),size:50,enableSorting:!1},',
        'o({"drag-handle":g,show:!g,host:!g,' => 'o({"drag-handle":g,enabled:!g,host:!g,',
    ];

    $processed = 0;
    foreach (glob($assets . '/index-*.js') ?: [] as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('读取管理端文件失败');
        }
        if (!str_contains($source, 'const Z5t=')) {
            continue;
        }
        if (str_contains($source, NODE_SWITCH_MARKER)) {
            foreach ($replacements as $replacement) {
                if (substr_count($source, $replacement) !== 1) {
                    throw new RuntimeException('管理端只包含部分节点开关补丁');
                }
            }
            $processed++;
            continue;
        }
        foreach ($replacements as $anchor => $replacement) {
            if (substr_count($source, $anchor) !== 1) {
                throw new RuntimeException('管理端节点开关锚点已变化，请同步更新补丁');
            }
            $source = str_replace($anchor, $replacement, $source);
        }

        $oldName = basename($file);
        $newName = 'index-' . substr(sha1($source), 0, 8) . '.js';
        $references = [];
        foreach (['json', 'html', 'js'] as $extension) {
            foreach (glob(dirname($assets) . '/*.' . $extension) ?: [] as $reference) {
                $content = file_get_contents($reference);
                if ($content === false) {
                    throw new RuntimeException('读取管理端入口引用失败');
                }
                if (str_contains($content, $oldName)) {
                    $references[$reference] = str_replace($oldName, $newName, $content);
                }
            }
        }
        if (!$references) {
            throw new RuntimeException('找不到管理端入口引用');
        }

        if (file_put_contents($assets . '/' . $newName, $source) === false) {
            throw new RuntimeException('写入节点开关补丁失败');
        }
        foreach ($references as $reference => $content) {
            if (file_put_contents($reference, $content) === false) {
                throw new RuntimeException('更新管理端入口引用失败');
            }
        }
        if ($oldName !== $newName && !unlink($file)) {
            throw new RuntimeException('移除已替换的管理端入口失败');
        }
        fwrite(STDOUT, "patch-admin-node-switch: 已添加节点运行开关 -> {$newName}\n");
        $processed++;
    }
    if ($processed === 0) {
        throw new RuntimeException('未找到包含节点列表的管理端产物');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'patch-admin-node-switch: ' . $error->getMessage() . "\n");
    exit(1);
}
