<?php

/**
 * 在固定管理端产物中注入内部端口预检查和保存拦截。
 * 使用现有表单错误位置提示；复制接口不经过表单保存校验。
 */

const SERVER_PORT_MARKER = 'yz_server_port_validation';

try {
    $assets = rtrim($argv[1] ?? '/www/public/assets/admin/assets', '/\\');
    $helper = file_get_contents(__DIR__ . '/admin-server-port.js');
    if ($helper === false) {
        throw new RuntimeException('读取内部端口检查脚本失败');
    }
    $helper = rtrim(str_replace("\r\n", "\n", $helper)) . "\n";
    $save = 'if(!l)return void gE.error(e("form.type.select_error"));const t=x.getValues(),'
        . 'n=parseFloat(t.transfer_enable_gb||"0"),i=n>0?Math.round(1024*n*1024*1024):0,'
        . '{transfer_enable_gb:r,...s}=t;(await qL({...s,type:l,transfer_enable:i})).data'
        . '&&(D(),gE.success(e("form.success")),h())';
    $guardedSave = 'if(!l)return void gE.error(e("form.type.select_error"));'
        . 'if(!await yzPort.validate(true))return;const t=x.getValues(),'
        . 'n=parseFloat(t.transfer_enable_gb||"0"),i=n>0?Math.round(1024*n*1024*1024):0,'
        . '{transfer_enable_gb:r,...s}=t;try{(await qL({...s,type:l,transfer_enable:i})).data'
        . '&&(D(),gE.success(e("form.success")),h())}catch(error){yzPort.reportSaveError(error,t)}';
    $replacements = [
        'function v5t(){' => $helper . 'function v5t(){',
        'w=I_({control:x.control,name:"protocol_settings"}),C=H.useRef(new Map)' =>
            'w=I_({control:x.control,name:"protocol_settings"}),yzPort=yzUseServerPortValidation(x,r,l),C=H.useRef(new Map)',
        $save => $guardedSave,
    ];

    $processed = 0;
    foreach (glob($assets . '/index-*.js') ?: [] as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('读取管理端文件失败');
        }
        if (!str_contains($source, 'form.server_port.label')) {
            continue;
        }
        if (str_contains($source, SERVER_PORT_MARKER)) {
            foreach ($replacements as $replacement) {
                if (substr_count($source, $replacement) !== 1) {
                    throw new RuntimeException('管理端只包含部分内部端口检查补丁');
                }
            }
            $processed++;
            continue;
        }
        foreach ($replacements as $anchor => $replacement) {
            if (substr_count($source, $anchor) !== 1) {
                throw new RuntimeException('管理端内部端口检查锚点已变化，请同步更新补丁');
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
            throw new RuntimeException('写入内部端口检查补丁失败');
        }
        foreach ($references as $reference => $content) {
            if (file_put_contents($reference, $content) === false) {
                throw new RuntimeException('更新管理端入口引用失败');
            }
        }
        if ($oldName !== $newName && !unlink($file)) {
            throw new RuntimeException('移除已替换的管理端入口失败');
        }
        fwrite(STDOUT, "patch-admin-server-port: 已添加内部端口检查 -> {$newName}\n");
        $processed++;
    }
    if ($processed === 0) {
        throw new RuntimeException('未找到包含内部端口表单的管理端产物');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'patch-admin-server-port: ' . $error->getMessage() . "\n");
    exit(1);
}
