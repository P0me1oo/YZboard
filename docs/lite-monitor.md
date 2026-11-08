# Lite 服务器监控接入

配套面板 2.7.1、管理端 0.92.1。无需修改 YZ-Agent 或代理核心。

## 使用

在“服务器管理 → 接入 Lite”填写 Lite 的 HTTPS 站点地址、站点 API 密钥，启用后点击“保存并读取”。密钥来自 Lite 的“API 密钥”窗口，保存后不会回显；后续留空保留原密钥。

- “按公网 IP 一键绑定”匹配两边已有服务器的公网 IPv4、IPv6。只处理两边都唯一的匹配；共享出口、多个候选或没有公网地址时，手动选择已有服务器并绑定。
- 勾选 Lite 服务器后可批量导入，一次最多 200 台。导入创建服务器记录，不安装程序、不绑定代理节点；重复导入跳过已经绑定的 Lite 编号。
- 绑定或解绑成功后立即清除旧列表；后续读取失败会单独提示刷新失败，可重新读取，不需要重复提交操作。
- 成功后使用 Lite 的服务器唯一编号保持关联，名称和 IP 变化不需要重新匹配。更换 Lite 站点前须解除原绑定；站点停用或不可达时，仍可从连接窗口解除绑定。
- 取消“启用 Lite”后，页面恢复 YZ-Agent 监控展示，绑定关系保留。

## 读取范围

服务器名称旁展示 Lite 的国家/地区国旗，优先使用 Lite 手动指定的地区，并以该地区参与列表默认排序。国旗使用随管理端打包的 SVG，不调用外部图片服务，也不向服务器名称或订阅名称插入 Emoji。

绑定服务器的负载、历史曲线和列表流量显示使用 Lite 数据；机器心跳、YZ-Agent 在线判断、运行版本和升级/重启仍使用原数据。Lite 在线状态单独显示，不能据此判定代理程序在线。

详情包括 Lite 名称、公网 IPv4/IPv6、国家/地区、操作系统、架构、处理器、核心数、内核、虚拟化、分组、标签、带宽、到期时间、公开备注，以及以下流量信息：

| 信息 | 口径 |
| --- | --- |
| 已用流量 | Lite 最新状态中的本周期上传、下载计数，沿用 Lite 的校准结果 |
| 配额 | Lite 当前周期有效配额，包含额外额度；0 表示不限 |
| 统计方式 | 上传、下载、合计、取较大值、取较小值 |
| 重置 | 原重置日、具体时间、服务商时区及 Lite 返回的下一次重置时间 |

缺失或无法识别的数据显示“—”；离线或过期状态不显示为当前负载。读取失败时显示失败，不把用量补零、不把上次读取结果当作当前数据；历史读取失败可单独重试。

现有服务器流量保护、超限自动停服、用户扣费和代理统计均沿用原逻辑。读取 Lite 的配额、用量和重置规则不写入本地流量保护字段，编辑服务器时的额度仍是原保护配置。Lite 流量不参与自动停服判断。

## 接口与存储

本实现对照 Lite 源码 `def441aa08a66fbd6bb979fbbce4c60f478b4803` 的 API：`POST /api/rpc2`，使用 `Authorization: Bearer` 站点密钥。只调用 `admin:listClients`、`common:getNodes`、`common:getNodesLatestStatus` 和 `common:getRecords` 四个读取方法，不接入远程命令、终端或文件操作。

YZboard 后端读取 Lite，浏览器只访问面板管理员接口。数据库新增 `v2_lite_connection` 和 `v2_lite_machine`，保存一套连接配置与绑定关系；密钥使用应用密钥加密。API 和缓存只返回白名单字段，不返回 Lite Agent 令牌。连接使用 HTTPS、公网解析、固定本次解析 IP、证书验证、超时和禁止重定向，只接受普通公网单播地址，排除共享、文档、多播及 IPv6 转换/隧道网段；当前不支持仅内网可达的 Lite 地址。

并发缓存未命中时，后续请求等待正在进行的读取：资料最多 22 秒，其余最多 12 秒；等待超时返回 503，可重新读取。

服务器资料缓存 60 秒，实时状态缓存 10 秒，历史查询缓存 30 秒；只在查看页面时读取，不建立后台同步任务，也不复制 Lite 的历史库。历史曲线按所选 1–24 小时范围读取，最多 500 个点。Lite 的实际采样、校准和历史保留时间以其设置为准。

管理端使用 `flag-icons` 7.5.0 的 4:3 国旗 SVG，保留 MIT 许可证于 `public/licenses/flag-icons.txt`。

## 验证

2.7.1 / 0.92.1 的最终验证与固定发布来源见 [发布记录](release-2.7.1.md)。以下保留 2.7.0 开发阶段的验证范围。

验证使用本地模拟 Lite 响应和临时数据库，不代表已经连接实际 Lite 站点或更新生产面板。

- PHP 8.4.27 / SQLite：Lite 接口、机器心跳、机器操作、流量保护、日志策略和历史清理共 60 项、641 条断言通过。入口使用独立的临时框架缓存，避免工作区旧缓存影响验证。
- Node 24.22.1：源码检查、Lite 与机器相关行为检查共 22 项、正式构建与资源检查通过。
- 浏览器覆盖 19 个不同场景：原机器操作/编辑/流量 13 项，Lite 6 项。Lite 在完整管理端 CSP 下覆盖 1440/390 像素，原流量保护包含 320 像素；失败读取、手动重试、自动/手动绑定、导入去重和关闭接入均通过。最后的手机布局与公网 IP 展示调整单独复验受影响场景。
- 274 份管理端产物逐项与面板集成资源核对，面板 5 项资源契约通过。未运行全站完整回归或 MariaDB 回归，未执行真实 Lite/生产联调。

相关验证命令：

```bash
# YZboard，测试数据库为 SQLite 内存库
php vendor/bin/phpunit --filter 'LiteMonitorTest|MachineAgentTest|MachineHeartbeatTest|MachineTrafficTest|AgentLogPolicyTest|MachineHistoryCleanupTest'
node --test tests/admin-dist.test.cjs

# YZboard-Dash
npm run check
node --test tests/lite-monitor.test.mjs tests/machine-region.test.mjs tests/machine-agent.test.mjs tests/machine-traffic.test.mjs tests/machine-status.test.mjs
npm run build
npm run check:build
node tools/run-browser-tests.mjs lite-monitor.spec.mjs machine-agent.spec.mjs machine-traffic.spec.mjs machine-edit-dialog.spec.mjs
npm run sync:panel
```

2.7.0 开发阶段集成主脚本：`assets/admin-DUTHMtY-.js`，SHA-256：`96d24615801238aaa045a1732e64a4b7f78e02e0d97eb3c09ce3cd0fdebd4e07`。源码、构建产物及本地修改留在两个正式项目目录，未创建额外工作树。
