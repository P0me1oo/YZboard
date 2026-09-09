<?php

/**
 * 给固定版本的管理端产物补上插件上传大小校验和 HTTP 413 提示。
 * 管理端源码由上游子模块提供，补丁随镜像构建执行；锚点变化时中止构建。
 */

const UPLOAD_MARKER = 'yz_plugin_upload_64m';

try {
    $assets = rtrim($argv[1] ?? '/www/public/assets/admin/assets', '/\\');
    $processed = 0;
    foreach (glob($assets . '/index-*.js') ?: [] as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('读取管理端文件失败');
        }
        if (!str_contains($source, 'uploadPlugin:')) {
            continue;
        }

        $replacements = [
            'uploadPlugin:e=>{const t=new FormData;' =>
                'uploadPlugin:e=>{/* ' . UPLOAD_MARKER . ' */if(e.size>64*1024*1024)return Promise.reject({message:"插件包大小不能超过64 MiB"});const t=new FormData;',
            'const i={401:lL.t("common:http.loginExpired"),' =>
                'const i={413:"上传文件过大，请检查服务器上传限制",401:lL.t("common:http.loginExpired"),',
            'Promise.reject(e.response?.data||{data:null,code:-1,message:lL.t("common:http.unknownError")})' =>
                'Promise.reject(413===t?{data:null,code:413,message:n||i[413]}:e.response?.data||{data:null,code:-1,message:lL.t("common:http.unknownError")})',
        ];

        if (str_contains($source, UPLOAD_MARKER)) {
            foreach ($replacements as $replacement) {
                if (substr_count($source, $replacement) !== 1) {
                    throw new RuntimeException('管理端只包含部分上传补丁');
                }
            }
            $processed++;
            continue;
        }
        foreach ($replacements as $anchor => $replacement) {
            if (substr_count($source, $anchor) !== 1) {
                throw new RuntimeException('管理端上传锚点已变化，请同步更新补丁');
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

        // 内容变化时同步更新文件名，避免浏览器继续使用旧缓存。
        if (file_put_contents($assets . '/' . $newName, $source) === false) {
            throw new RuntimeException('写入管理端上传补丁失败');
        }
        foreach ($references as $reference => $content) {
            if (file_put_contents($reference, $content) === false) {
                throw new RuntimeException('更新管理端入口引用失败');
            }
        }
        if ($oldName !== $newName && !unlink($file)) {
            throw new RuntimeException('移除已替换的管理端入口失败');
        }
        fwrite(STDOUT, "patch-admin-upload: 已更新插件上传校验和提示 -> {$newName}\n");
        $processed++;
    }
    if ($processed === 0) {
        throw new RuntimeException('未找到包含插件上传的管理端产物');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'patch-admin-upload: ' . $error->getMessage() . "\n");
    exit(1);
}
