<?php

/**
 * 移除管理端套餐基础价格的默认折扣，按各周期月数直接填价。
 * 管理端使用固定上游产物，补丁随镜像构建执行；锚点变化时中止构建。
 */

const PLAN_PRICES_MARKER = 'yz_plan_prices_no_discount';

try {
    $assets = rtrim($argv[1] ?? '/www/public/assets/admin/assets', '/\\');
    $periods = 'o6t={monthly:{label:"月付",months:1,discount:1},'
        . 'quarterly:{label:"季付",months:3,discount:.95},'
        . 'half_yearly:{label:"半年付",months:6,discount:.9},'
        . 'yearly:{label:"年付",months:12,discount:.85},'
        . 'two_yearly:{label:"两年付",months:24,discount:.8},'
        . 'three_yearly:{label:"三年付",months:36,discount:.75},'
        . 'onetime:{label:"流量包",months:1,discount:1},'
        . 'reset_traffic:{label:"重置包",months:1,discount:1}}';
    $replacements = [
        $periods => preg_replace('/,discount:(?:1|\.\d+)/', '', $periods),
        'const r=e*i.months*i.discount;return{...t,[n]:r.toFixed(2)}' =>
            'const r=e*i.months;/* ' . PLAN_PRICES_MARKER . ' */return{...t,[n]:r.toFixed(2)}',
    ];

    $processed = 0;
    foreach (glob($assets . '/index-*.js') ?: [] as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('读取管理端文件失败');
        }
        if (!str_contains($source, 'plan.form.price.base_price')) {
            continue;
        }

        if (str_contains($source, PLAN_PRICES_MARKER)) {
            foreach ($replacements as $replacement) {
                if (substr_count($source, $replacement) !== 1) {
                    throw new RuntimeException('管理端只包含部分套餐价格补丁');
                }
            }
            $processed++;
            continue;
        }
        foreach ($replacements as $anchor => $replacement) {
            if (substr_count($source, $anchor) !== 1) {
                throw new RuntimeException('管理端套餐价格锚点已变化，请同步更新补丁');
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

        // 同步更新入口名称和引用，让浏览器重新获取移除折扣后的产物。
        if (file_put_contents($assets . '/' . $newName, $source) === false) {
            throw new RuntimeException('写入管理端套餐价格补丁失败');
        }
        foreach ($references as $reference => $content) {
            if (file_put_contents($reference, $content) === false) {
                throw new RuntimeException('更新管理端入口引用失败');
            }
        }
        if ($oldName !== $newName && !unlink($file)) {
            throw new RuntimeException('移除已替换的管理端入口失败');
        }
        fwrite(STDOUT, "patch-admin-plan-prices: 已移除套餐默认折扣 -> {$newName}\n");
        $processed++;
    }
    if ($processed === 0) {
        throw new RuntimeException('未找到包含套餐基础价格的管理端产物');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'patch-admin-plan-prices: ' . $error->getMessage() . "\n");
    exit(1);
}
