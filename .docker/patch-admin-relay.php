<?php
/**
 * 给管理端构建产物注入 YZboard 的节点管理补充界面。
 *
 * 管理端源码不在本仓库（public/assets/admin 是 cedar2025/xboard-admin-dist 的构建产物），
 * 需要在构建阶段对压缩产物做定点补丁，补上上游没有的界面：
 *
 * 1. 节点编辑表单里的前置入口下拉。原有的「父级节点」下拉写死了同协议过滤，
 *    选不到跨协议的 VLESS 入口，因此新增一个独立的 relay_entry_id 字段，
 *    候选为「没有前置入口的 VLESS 节点」，不改动原有的父级节点字段。
 * 2. 节点列表表头的「前置入口」列，显示每个节点走哪个入口，未设置时显示占位符。
 *    列的显隐跟随排序模式，与地址、部署方式等数据列保持一致。
 * 3. VLESS 落地表单按当前 YZ-Xray-core 能力修正传输和 Flow，并在切换传输时
 *    自动纠正不合法的 Reality/Hysteria 组合。H2 不再展示，新增 Hysteria 传输。
 * 4. 在 VLESS Encryption 的 decryption 输入框加入与 Reality 私钥一致的钥匙按钮，
 *    复用产物现有的 X25519 生成器，一次填入配对的 decryption/encryption。
 * 5. 节点批量操作增加按权限组添加、移除的增量动作。
 * 6. Shadowsocks 新建表单优先使用 SS2022 128 位 AES-GCM。
 * 7. 节点编辑表单在协议选择左侧增加 Xray/sing-box 内核下拉，默认 Xray。
 *
 * 任一锚点匹配不到就直接失败退出，让构建显式报错，而不是静默产出一个缺少这些界面的管理端。
 * 重复执行是幂等的。
 */

const LABEL = '前置入口';
const PLACEHOLDER = '选择前置入口节点';
const NONE = '无';
const COLUMN_TITLE = '前置入口';
const COLUMN_TOOLTIP = '客户端实际连接的入口节点，未设置表示该节点直接对外提供服务';
const COLUMN_EMPTY = '--';

/**
 * 徽标样式，与权限组列保持一致：淡底、浅描边、悬停加深。
 *
 * 类名抄自权限组列，只是拼成一个字符串而不再调用产物里的类名合并函数——
 * 这些类之间没有需要仲裁的冲突，结果相同，还少一个会随上游变动的锚点。
 * 不跟倍率列的实心底色，是因为单值列用实心块在整行里显得过重。
 *
 * 权限组那边还带 `flex items-center gap-1.5`，这里不要：它的徽标装在一个
 * flex 容器里，而这一列的徽标是单元格的直接子元素，加 flex 会把徽标从
 * inline-flex 变成块级并撑满整列宽度。
 */
const BADGE_CLASS = 'px-2 py-0.5 font-medium bg-secondary/50 hover:bg-secondary/70 border border-border/50 transition-all duration-200 cursor-default select-none';

/** 表单字段与列表列各自的注入标记，用于判断产物已经打过哪一部分补丁。 */
const FIELD_MARKER = 'relay_entry_id';
const COLUMN_MARKER = 'relay_entry_name';
const VLESS_MATRIX_MARKER = 'relay_vless_matrix';
const VLESS_KEYGEN_MARKER = 'relay_vless_keygen';
const BATCH_GROUP_MARKER = 'batch_group_membership';
const SS2022_DEFAULT_MARKER = 'yz_ss2022_default';
const KERNEL_MARKER = 'data-yz-node-kernel-selector';

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

    $hasField = str_contains($src, FIELD_MARKER);
    $hasColumn = str_contains($src, COLUMN_MARKER);
    $hasVlessMatrix = str_contains($src, VLESS_MATRIX_MARKER);
    $hasVlessKeygen = str_contains($src, VLESS_KEYGEN_MARKER);
    $hasBatchGroup = str_contains($src, BATCH_GROUP_MARKER);
    $hasSs2022Default = str_contains($src, SS2022_DEFAULT_MARKER);
    $hasKernel = str_contains($src, KERNEL_MARKER);

    // 早期版本曾把 kernel_type 错误地套用了 machine_id 的数字校验器。
    // 对已经打过补丁的现有产物做一次可重复修复，避免必须手工恢复整个管理端包。
    if (str_contains($src, 'kernel_type:dy().optional().nullable().default("xray")')) {
        $src = patchKernelSchemaType($src, $file);
        if (file_put_contents($file, $src) === false) {
            fwrite(STDERR, "patch-admin-relay: 写入失败 {$file}\n");
            exit(1);
        }
        $newBase = renameWithNewHash($file, $src, $root);
        fwrite(STDOUT, "patch-admin-relay: 已修复内核字段校验器 -> {$newBase}\n");
        $patched++;
        continue;
    }

    if ($hasField && $hasColumn && $hasVlessMatrix && $hasVlessKeygen && $hasBatchGroup && $hasSs2022Default && $hasKernel) {
        fwrite(STDOUT, "patch-admin-relay: 已完整打过补丁，跳过 " . basename($file) . "\n");
        $patched++;
        continue;
    }

    // 只有一半标记说明产物被旧版补丁改过。继续执行会把表单字段注入第二遍，
    // 因此直接失败，要求先取回干净的构建产物。
    if ($hasField !== $hasColumn
        || (($hasVlessMatrix || $hasVlessKeygen) && !$hasField)
        || ($hasVlessKeygen && !$hasVlessMatrix)
        || ($hasBatchGroup && !$hasField)
        || ($hasSs2022Default && !$hasField)
        || ($hasKernel && !$hasField)) {
        fwrite(STDERR, "patch-admin-relay: " . basename($file) . " 只包含部分补丁标记，需先还原干净的管理端产物\n");
        exit(1);
    }

    // 只处理确实含有节点编辑表单的产物，其余 chunk 直接跳过。
    if (!str_contains($src, 'name:"parent_id",render:')) {
        continue;
    }

    if (!$hasField) {
        $src = patchSchema($src, $file);
        $src = patchDefaults($src, $file);
        $src = patchKernelSchema($src, $file);
        $src = patchKernelDefaults($src, $file);
        $src = patchCandidates($src, $file);
        $src = patchField($src, $file);
        $src = patchListColumn($src, $file);
        $src = patchListVisibility($src, $file);
    }
    if (!$hasVlessMatrix) {
        $src = patchVlessMatrix($src, $file);
    }
    if (!$hasVlessKeygen) {
        $src = patchVlessEncryptionKeygen($src, $file);
    }
    if (!$hasBatchGroup) {
        $src = patchBatchGroupMembership($src, $file);
    }
    if (!$hasSs2022Default) {
        $src = patchShadowsocksDefault($src, $file);
    }
    if (!$hasKernel) {
        $src = patchKernelSelector($src, $file);
    }

    if (file_put_contents($file, $src) === false) {
        fwrite(STDERR, "patch-admin-relay: 写入失败 {$file}\n");
        exit(1);
    }

    // 文件名里的 hash 是构建时算的，改内容不改名会让浏览器继续用缓存里的旧产物。
    // 按补丁后的内容重新命名，并同步更新引用，才能真正让客户端取到新版本。
    $newBase = renameWithNewHash($file, $src, $root);
    fwrite(STDOUT, "patch-admin-relay: 已注入节点管理补充界面与 SS2022 默认值 -> {$newBase}\n");
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

/** 节点表单内核字段默认使用 Xray；null 仍由后端解释为 Xray。 */
function patchKernelSchema(string $src, string $file): string
{
    if (str_contains($src, 'kernel_type:')) {
        return $src;
    }
    $pattern = '/(machine_id:(\w+)\(\)\.optional\(\)\.nullable\(\),)/';
    if (!preg_match($pattern, $src, $m)) {
        fail('schema machine_id', $file);
    }
    // machine_id 使用数字校验器；内核名称必须使用字符串校验器。
    $insert = $m[1] . 'kernel_type:cy().optional().nullable().default("xray"),';
    return preg_replace($pattern, addcslashes($insert, '\\$'), $src, 1);
}

function patchKernelSchemaType(string $src, string $file): string
{
    $needle = 'kernel_type:dy().optional().nullable().default("xray")';
    if (substr_count($src, $needle) !== 1) {
        fail('kernel schema type', $file);
    }
    return str_replace($needle, 'kernel_type:cy().optional().nullable().default("xray")', $src);
}

function patchKernelDefaults(string $src, string $file): string
{
    if (str_contains($src, 'kernel_type:"xray"')) {
        return $src;
    }
    $needle = 'machine_id:null,enabled:null';
    if (!str_contains($src, $needle)) {
        fail('defaults machine_id', $file);
    }
    return str_replace($needle, 'machine_id:null,kernel_type:"xray",enabled:null', $src);
}

/** 在协议选择左侧加入节点级内核下拉，保存仍走原有表单状态。 */
function patchKernelSelector(string $src, string $file): string
{
    $anchor = 'Q.jsxs(yzt,{value:l||"",onValueChange:';
    $start = strpos($src, $anchor);
    if ($start === false) {
        fail('protocol selector', $file);
    }

    // 协议选择器位于弹窗标题区域，不在 FormProvider 内；这里直接读写当前表单实例，
    // 避免 Controller/FormItem 访问空的 useFormContext() 导致编辑节点时整页报 500。
    $prefix = 'Q.jsxs("div",{className:"flex items-center gap-2",' .
        '"data-yz-node-kernel-selector":!0,children:[' .
        'Q.jsx("span",{className:"sr-only",children:e("form.kernel.label","内核")}),Q.jsxs(yzt,{onValueChange:t=>x.setValue("kernel_type",t,{shouldDirty:!0,shouldTouch:!0,shouldValidate:!0}),value:x.watch("kernel_type")||"xray",children:[' .
        'Q.jsx(Czt,{className:"h-8 w-[130px] border-2 font-mono text-xs",children:Q.jsx(wzt,{placeholder:e("form.kernel.label","内核")})}),' .
        'Q.jsxs(Nzt,{children:[Q.jsx(Lzt,{value:"xray",children:"Xray"}),Q.jsx(Lzt,{value:"singbox",children:"sing-box"})]})]})]}),' .
        $anchor;
    $src = substr($src, 0, $start) . $prefix . substr($src, $start + strlen($anchor));

    $close = '})]})]}),Q.jsx(vtt,{className';
    $closePos = strpos($src, $close, $start);
    if ($closePos === false) {
        fail('protocol selector close', $file);
    }
    $src = substr($src, 0, $closePos) . '})]})]})]}),Q.jsx(vtt,{className' . substr($src, $closePos + strlen($close));

    return $src;
}

/**
 * 新增候选列表：没有前置入口的 VLESS 节点，且不是自己。
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

/** 在父级节点字段之后插入一个结构相同的前置入口字段。 */
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

/**
 * 在节点列表的「地址」列之前插入「前置入口」列。
 *
 * 入口名称由面板接口以 relay_entry_name 下发，前端不再自己回查节点列表，
 * 因此入口节点被搜索或筛选排除时，逻辑节点这一列仍然显示得出来。
 */
function patchListColumn(string $src, string $file): string
{
    // 从地址列的定义里取出 JSX 命名空间与表头组件，避免把压缩后的变量名写死在补丁里。
    $addressPattern = '/\{accessorKey:"host",header:\(\{column:(\w+)\}\)=>(\w+)\.jsx\((\w+),\{column:\1,title:\w+\("columns\.address"\)\}\),/';
    if (!preg_match($addressPattern, $src, $m)) {
        fail('address column', $file);
    }
    $anchor = $m[0];
    $jsx = $m[2];
    $headerComp = $m[3];

    // 徽标组件取自倍率列。样式不跟倍率，改用权限组那一套（淡底、描边、悬停），见 BADGE_CLASS。
    $badgePattern = '/cell:\(\{row:(\w+)\}\)=>\w+\.jsxs\((\w+),\{variant:"secondary",className:"font-medium",children:\[\1\.getValue\("rate"\)," x"\]\}\)/';
    if (!preg_match($badgePattern, $src, $bm)) {
        fail('rate column badge', $file);
    }
    $badgeComp = $bm[2];

    $title = jsString(COLUMN_TITLE);
    $tooltip = jsString(COLUMN_TOOLTIP);
    $empty = jsString(COLUMN_EMPTY);
    $badgeClass = jsString(BADGE_CLASS);

    // 参数一律加前缀，避免与压缩产物里的同名变量相互遮蔽。
    $column = '{id:"relay_entry",accessorFn:__reRow=>__reRow.' . COLUMN_MARKER . '||"",'
        . 'header:({column:__reCol})=>' . $jsx . '.jsx(' . $headerComp . ',{column:__reCol,title:' . $title . ',tooltip:' . $tooltip . '}),'
        . 'cell:({row:__reRow})=>{const __reName=__reRow.original.' . COLUMN_MARKER . ';'
        . 'return __reName?' . $jsx . '.jsx(' . $badgeComp . ',{variant:"secondary",className:' . $badgeClass . ',children:__reName})'
        . ':' . $jsx . '.jsx("span",{className:"text-sm text-muted-foreground",children:' . $empty . '})},'
        . 'enableSorting:!1,enableHiding:!0,size:140},';

    return str_replace($anchor, $column . $anchor, $src);
}

/**
 * 让新列跟随排序模式显隐。
 *
 * 列表进入拖拽排序模式时会显式隐藏所有数据列，只留节点名和拖拽手柄。
 * 该可见性映射没有列出的列默认可见，不同步登记新列就会在排序模式下孤零零地留在表格里。
 */
function patchListVisibility(string $src, string $file): string
{
    $pattern = '/\w+\(\{"drag-handle":(\w+),show:!\1,host:!\1,machine:!\1,/';
    if (!preg_match($pattern, $src, $m)) {
        fail('column visibility map', $file);
    }
    $flagVar = $m[1];
    $replacement = $m[0] . "relay_entry:!{$flagVar},";

    return str_replace($m[0], $replacement, $src);
}

/**
 * 修正 VLESS 表单为当前 Xray 的有效集合，并在传输变化后纠正非法安全模式。
 *
 * 后端仍会做完整校验；这里的作用是避免用户在下拉框里直接选出已知无效组合。
 */
function patchVlessMatrix(string $src, string $file): string
{
    $oldOptions = 'P4t={networkOptions:[{value:"tcp",label:"TCP"},{value:"ws",label:"Websocket"},{value:"grpc",label:"gRPC"},{value:"h2",label:"HTTP/2"},{value:"kcp",label:"mKCP"},{value:"httpupgrade",label:"HttpUpgrade"},{value:"xhttp",label:"XHTTP"}],flowOptions:["none","xtls-rprx-direct","xtls-rprx-splice","xtls-rprx-vision"]}';
    if (!str_contains($src, $oldOptions)) {
        fail('VLESS network/flow options', $file);
    }
    $newOptions = 'P4t={relay_vless_matrix:"xray-2026",networkOptions:[{value:"tcp",label:"RAW / TCP"},{value:"ws",label:"WebSocket"},{value:"grpc",label:"gRPC"},{value:"kcp",label:"mKCP"},{value:"httpupgrade",label:"HTTPUpgrade"},{value:"xhttp",label:"XHTTP"},{value:"hysteria",label:"Hysteria"}],flowOptions:["none","xtls-rprx-vision"]}';
    $src = str_replace($oldOptions, $newOptions, $src);

    $componentStart = 'j4t=({form:e,t:t})=>Q.jsxs(';
    if (!str_contains($src, $componentStart)) {
        fail('VLESS form component start', $file);
    }
    $componentReplacement = 'j4t=({form:e,t:t})=>{const __relayNetwork=e.watch("network");H.useEffect(()=>{const __relayTLS=Number(e.getValues("tls"));"hysteria"===__relayNetwork&&1!==__relayTLS?e.setValue("tls",1):2===__relayTLS&&!['
        . '"tcp","grpc","xhttp"].includes(__relayNetwork)&&e.setValue("tls",1)},[__relayNetwork]);return Q.jsxs(';
    $src = str_replace($componentStart, $componentReplacement, $src);

    $nextComponent = strpos($src, '),F4t=', strpos($src, $componentReplacement));
    if ($nextComponent === false) {
        fail('VLESS form component end', $file);
    }
    $src = substr($src, 0, $nextComponent + 1) . '}' . substr($src, $nextComponent + 1);

    $tlsOptions = 'Q.jsx(Lzt,{value:"0",children:t("dynamic_form.vless.tls.none")}),Q.jsx(Lzt,{value:"1",children:t("dynamic_form.vless.tls.tls")}),Q.jsx(Lzt,{value:"2",children:t("dynamic_form.vless.tls.reality")})';
    if (!str_contains($src, $tlsOptions)) {
        fail('VLESS TLS options', $file);
    }
    $tlsReplacement = 'Q.jsx(Lzt,{value:"0",disabled:"hysteria"===__relayNetwork,children:t("dynamic_form.vless.tls.none")}),Q.jsx(Lzt,{value:"1",children:t("dynamic_form.vless.tls.tls")}),Q.jsx(Lzt,{value:"2",disabled:!["tcp","grpc","xhttp"].includes(__relayNetwork),children:t("dynamic_form.vless.tls.reality")})';

    return str_replace($tlsOptions, $tlsReplacement, $src);
}

/**
 * 给 VLESS Encryption 的 decryption 输入框加入钥匙按钮。
 *
 * O4t() 是当前管理端给 Reality 私钥按钮使用的 X25519 生成器，返回 Base64URL 编码的
 * privateKey/publicKey。私钥用于服务端 decryption，公钥用于客户端 encryption。
 */
function patchVlessEncryptionKeygen(string $src, string $file): string
{
    $componentAnchor = 'j4t=({form:e,t:t})=>{const __relayNetwork=e.watch("network");';
    if (!str_contains($src, $componentAnchor)) {
        fail('VLESS Encryption component', $file);
    }

    $success = jsString('VLESS Encryption 密钥对已生成');
    $error = jsString('VLESS Encryption 密钥对生成失败');
    $tooltip = jsString('生成 X25519 VLESS Encryption 密钥对');
    $keygen = 'const relay_vless_keygen=()=>{try{const __vlessKeys=O4t();'
        . 'e.setValue("encryption.decryption","mlkem768x25519plus.native.600s."+__vlessKeys.privateKey),'
        . 'e.setValue("encryption.encryption","mlkem768x25519plus.native.0rtt."+__vlessKeys.publicKey),'
        . 'gE.success(' . $success . ')}catch(__vlessKeyError){gE.error(' . $error . ')}};';
    $src = str_replace($componentAnchor, $componentAnchor . $keygen, $src);

    $input = 'Q.jsx(Yy,{children:Q.jsx(u8e,{className:"font-mono text-xs",placeholder:t("dynamic_form.vless.encryption.server_placeholder"),...e})})';
    if (substr_count($src, $input) !== 1) {
        fail('VLESS Encryption decryption input', $file);
    }

    $button = 'Q.jsxs("div",{className:"relative",children:['
        . 'Q.jsx(Yy,{children:Q.jsx(u8e,{className:"pr-9 font-mono text-xs",placeholder:t("dynamic_form.vless.encryption.server_placeholder"),...e})}),'
        . 'Q.jsx(Zot,{children:Q.jsxs(Yot,{children:['
        . 'Q.jsx(Xot,{asChild:!0,children:Q.jsx(xtt,{type:"button",variant:"ghost",size:"icon",onClick:relay_vless_keygen,'
        . 'className:"absolute right-0 top-0 h-full px-2 transition-transform duration-150 active:scale-90",'
        . 'children:Q.jsx(YXt,{icon:"ion:key-outline",className:"h-4 w-4 transition-transform duration-300 hover:rotate-180"})})}),'
        . 'Q.jsx(Kot,{children:Q.jsx(Qot,{children:Q.jsx("p",{children:' . $tooltip . '})})})'
        . ']})})]})';

    return str_replace($input, $button, $src);
}

/**
 * 给节点批量操作菜单增加按权限组增量添加、移除动作。
 *
 * 权限组列表已经由节点管理页加载，动作直接复用现有 batchUpdate 接口，
 * 每次只提交一个目标权限组，不覆盖节点已有的其它权限组。
 */
function patchBatchGroupMembership(string $src, string $file): string
{
    $handlerAnchor = '}},p=Q.jsxs("div"';
    if (substr_count($src, $handlerAnchor) !== 1) {
        fail('batch action handler', $file);
    }

    $handler = 'batch_group_membership=async(__bgAction,__bgGroup)=>{const __bgIds=a.map(e=>e.original.id);try{const{data:__bgOk}=await ZL({ids:__bgIds,group_action:__bgAction,group_id:__bgGroup.id});__bgOk&&(gE.success("add"===__bgAction?`已将 ${__bgIds.length} 个节点添加为「${__bgGroup.name}」权限组`:`已将 ${__bgIds.length} 个节点移除「${__bgGroup.name}」权限组`),e.resetRowSelection(),t())}catch{gE.error("add"===__bgAction?"批量添加权限组失败":"批量移除权限组失败")}}';
    $src = str_replace($handlerAnchor, '}},' . $handler . ',p=Q.jsxs("div"', $src);

    $menuContainer = 'Q.jsxs(Ust,{align:"start",className:"w-48",children:[';
    if (substr_count($src, $menuContainer) !== 1) {
        fail('batch action menu container', $file);
    }
    $src = str_replace(
        $menuContainer,
        'Q.jsxs(Ust,{align:"start",className:"w-72 overflow-y-auto",style:{maxHeight:"70vh"},children:[',
        $src
    );

    $menuAnchor = 'Q.jsx(Zst,{}),Q.jsx(hQt,{title:c("toolbar.batch_reset_traffic.title")';
    if (substr_count($src, $menuAnchor) !== 1) {
        fail('batch group menu anchor', $file);
    }

    $menu = 'Q.jsx(Zst,{}),Q.jsx(Gst,{children:"权限组操作"}),'
        . 'r.length?r.map(__bgGroup=>Q.jsxs($st,{disabled:!l,style:{whiteSpace:"normal"},onSelect:()=>batch_group_membership("add",__bgGroup),children:['
        . 'Q.jsx(YXt,{icon:"ion:add-circle-outline",className:"mr-2 size-4"}),`添加为「${__bgGroup.name}」权限组`,]},`batch-group-add-${__bgGroup.id}`)):'
        . 'Q.jsx($st,{disabled:!0,children:"暂无权限组"}),'
        . 'Q.jsx(Zst,{}),Q.jsx(Gst,{children:"移除权限组"}),'
        . 'r.length?r.map(__bgGroup=>Q.jsxs($st,{disabled:!l,style:{whiteSpace:"normal"},onSelect:()=>batch_group_membership("remove",__bgGroup),children:['
        . 'Q.jsx(YXt,{icon:"ion:remove-circle-outline",className:"mr-2 size-4"}),`移除「${__bgGroup.name}」权限组`,]},`batch-group-remove-${__bgGroup.id}`)):'
        . 'Q.jsx($st,{disabled:!0,children:"暂无权限组"}),'
        . 'Q.jsx(Zst,{}),Q.jsx(hQt,{title:c("toolbar.batch_reset_traffic.title")';

    return str_replace($menuAnchor, $menu, $src);
}

/** 将 Shadowsocks 新建表单的默认及首选算法调整为 SS2022 128 位 AES-GCM。 */
function patchShadowsocksDefault(string $src, string $file): string
{
    $schemaNeedle = 'const h4t=e=>py({cipher:cy().default("aes-128-gcm"),';
    if (substr_count($src, $schemaNeedle) !== 1) {
        fail('Shadowsocks cipher default', $file);
    }
    $src = str_replace(
        $schemaNeedle,
        'const h4t=e=>py({cipher:cy().default("2022-blake3-aes-128-gcm"),',
        $src
    );

    $listNeedle = 'g4t={ciphers:["aes-128-gcm","aes-192-gcm","aes-256-gcm","chacha20-ietf-poly1305","2022-blake3-aes-128-gcm","2022-blake3-aes-256-gcm","2022-blake3-chacha20-poly1305"]';
    if (substr_count($src, $listNeedle) !== 1) {
        fail('Shadowsocks cipher options', $file);
    }
    $listReplacement = 'g4t={yz_ss2022_default:"2022-blake3-aes-128-gcm",ciphers:["2022-blake3-aes-128-gcm","aes-128-gcm","aes-192-gcm","aes-256-gcm","chacha20-ietf-poly1305","2022-blake3-aes-256-gcm","2022-blake3-chacha20-poly1305"]';

    return str_replace($listNeedle, $listReplacement, $src);
}

/** 输出为 \u 转义的 JS 字符串字面量，避免产物编码问题。 */
function jsString(string $text): string
{
    return json_encode($text, JSON_UNESCAPED_SLASHES);
}
