# Xboard

面板 `1.93.0` 新增服务器流量保护“仅计费代理流量”，配套管理端 `0.65.0`、Agent `v2.17.0`。按同机全部受管节点的原始上传加下载计算，不乘倍率、排除系统流量，同机中转只计一次；保留原单向、双向及额度恢复机制，见[使用说明](docs/server-traffic-protection.md)。

面板 [`1.92.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.92.0) 已发布，配套管理端 `0.64.0`、Agent [`v2.16.1`](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.16.1)。节点配置可自定义预设扫描目标，默认移除 Amazon 主页和 Google 下载地址；开始检测按钮靠右，使用结果后自动回到节点表单并填入 SNI 与端口。同时交付下方开发阶段记录的功能，见 [Reality 目标检测](docs/reality-target-scan.md)。

原开发版本 `1.91.0`（管理端 `0.63.0`）的以下修改已纳入 `1.92.0`：订阅设置和模板移到“订阅管理”，设置分为基础、输出两页。新增按节点名称添加国旗、按实际倍率添加名称后缀的开关，默认关闭，1 倍不显示；见[订阅设置](docs/subscription-settings.md)。保留已有未发布功能，其配套要求见[兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.88.2`](https://github.com/P0me1oo/YZboard/releases/tag/v1.88.2) 已发布，包含管理端 `0.60.2`，配套 Node [`v2.14.0`](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.14.0)。Reality 检测增加 CDN 厂商、本地 IP 位置、页面状态和证书链大小，未通过原因使用提醒图标与无句号警告文案；一并交付扫描入口修正和访问记录分组。位置只使用 ip2region，IPv6 暂显示未知，证书链仅展示大小。双数据库完整回归和双架构镜像核验通过，先更新面板，再更新 Node；使用说明见 [Reality 目标检测](docs/reality-target-scan.md)与[访问记录](docs/access-logs.md)，固定来源及回滚参考见[兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.85.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.85.0) 已发布：Reality 节点支持当前目标检测、域名/IP/指定网段批量查找和结果回填，预设去掉 Cloudflare。检测由节点服务器执行，配套管理端 `0.57.0`、Node `v2.12.0`；两个核心不变。双架构镜像及 `latest` 已核验，先更新面板，再更新 Node。使用方式及限制见 [Reality 目标检测](docs/reality-target-scan.md)，验证范围与既有前端检查问题见[兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.82.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.82.0) 已发布，包含管理端 `0.54.0`，配套 Node [`v2.11.0`](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.11.0)。新增服务器外网网卡流量保护，移除节点旧额度与承载量展示，并一并交付订阅用户限频、限制管理及 Telegram 提醒。旧服务器默认不限，首次启用从 0 开始，无手动校准；用户套餐扣费和历史统计保留。双数据库完整回归、双核心实连、双架构产物及 `latest` 已核验。见[使用规则](docs/server-traffic-protection.md)和[发布记录](YZ_COMPATIBILITY.md)。

面板 [`1.78.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.78.1) 已发布，配套管理端 `0.50.0`：用户端 Telegram 入口、账号和绑定状态统一使用新版，群组链接迁入“Telegram Bot / 群组与节点”，旧设置网址跳到新版。包含下方入站 IP 完整属性修复，保留旧配置和绑定；生产 PHP 两套数据库回归各 881 项通过，双架构镜像、`latest` 及关键文件已核验。无需升级 Node 或核心，使用规则见 [Telegram Bot](docs/telegram-bot.md)，固定来源及回退参考见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 `1.78.0` 本地开发，尚未发布，配套管理端 `0.50.0`：用户端 Telegram 入口、账号及绑定状态统一使用新版，群组链接移到“Telegram Bot / 群组与节点”。升级保留原链接和历史绑定，移除旧设置入口，旧网址跳到新版；兼容 `/bind` 指令，包含下方 `1.77.1` 入站 IP 修复，无需升级 Node 或核心。见 [Telegram Bot](docs/telegram-bot.md)。

面板 `1.77.1` 本地修复，尚未发布：入站 IP 改为读取官网公开页内对应地址的完整数据，修复网络类型，并补齐明确返回的代理、VPN、Tor、爬虫及威胁检测结果。旧摘要升级后按原限额分批重新查询，保留地区、ASN 和历史记录；沿用管理端 `0.49.0`，无需升级 Node 或核心。见[统计分析](docs/statistics.md)与[兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.77.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.77.0) 已发布，配套管理端 `0.49.0`：节点用量按用户使用的线路归属，服务器代理流量独立统计，同机中转只计一次，两类均不乘倍率。保留旧口径和原流量上限、重置规则；新统计从升级后开始。包含 Telegram、用户日历和菜单改动，新增三项数据库迁移，Node 与核心无需更新。双架构镜像、`latest` 和实际程序已核验，见[兼容矩阵](YZ_COMPATIBILITY.md)及[统计分析](docs/statistics.md)。

下方 `1.73.1` 至 `1.76.1` 为本地开发记录，相关修改已统一纳入 `1.77.0` 发布。

面板 `1.76.1` 本地整合，尚未发布，配套管理端 `0.48.1`：保留新版 Telegram Bot“群组与节点”等已有修改，外观设置移到站点设置下方并使用调色盘图标，节点操作的隐藏／显示移到删除下方。验证见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 `1.76.0` 本地开发中，尚未发布，配套管理端 `0.48.0`：Telegram Bot 新增“群组与节点”，支持指定群组自动审核入群和节点掉线通知。两项默认关闭，入群资格使用新版绑定；节点默认超过 30 分钟无心跳后通知管理员，每次掉线防重。新增数据库迁移，包含此前工单、支付、令牌表单和用户日历修改，无需升级 Node 或核心。使用规则见 [Telegram Bot](docs/telegram-bot.md)。

面板 `1.75.0` 本地开发中，尚未发布，配套管理端 `0.47.0`：Telegram Bot 新增“工单与支付”，工单通知、工单回复、支付通知分别开关，默认关闭。通知面向新版绑定的管理员、员工，回复核对真实提醒和当前权限，按接收人防重与重试，避免旧插件重复处理。新增数据库迁移，保留已有令牌、Webhook Base URL 表单和用户日历修改，无需升级 Node 或核心。使用规则见 [Telegram Bot](docs/telegram-bot.md)。

面板 `1.74.0` 本地开发中，尚未发布，配套管理端 `0.46.0`：Telegram Bot 使用“机器人令牌”和“Webhook Base URL”，仅填写基础网址即可自动生成新版接收地址；令牌可直接输入，眼睛按钮用于显示或隐藏。包含下方 `1.73.1` 用户日历修改，无数据库迁移，无需升级 Node 或核心。使用规则见 [Telegram Bot](docs/telegram-bot.md)。

面板 `1.73.1` 本地开发中，尚未发布，配套管理端 `0.45.1`：日历时长预设从未到期日期续加，已过期或没有日期时从当前时间计算；增加后自动切换并选中对应日期。无数据库迁移，无需升级 Node 或核心。规则见 [用户管理](docs/user-management.md)。

面板 [`1.73.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.73.0) 已发布：入站 IP 后台分批读取官网公开网页，补齐网络类型、运营商及页面明确提供的检测结果；列表和卡片直接读取缓存，不在点击时查询，也不启动浏览器。保留原 ASN 与历史数据，在线设备和 Telegram 等场景继续使用原接口，沿用管理端 `0.45.0`，无需升级 Node 或核心。两套数据库各 777 项完整回归通过，双架构镜像与 `latest` 已核验。规则见 [统计分析](docs/statistics.md)，固定来源、摘要与回退信息见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.72.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.72.0) 已发布，配套管理端 `0.45.0`：每条路由可以单独启用或停用，关闭时保留配置和节点绑定，重新开启后恢复。已有路由升级后保持开启，无需升级 Node 或核心。两套数据库各 764 项完整回归通过，双架构镜像及 `latest` 已核验。使用说明见 [路由管理](docs/server-routes.md)，固定来源、摘要与回退记录见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.71.2`](https://github.com/P0me1oo/YZboard/releases/tag/v1.71.2) 已发布，配套管理端 `0.44.0`：中转落地默认跟随前置倍率，可开启独立倍率；节点列表和订阅显示实际生效值，基础倍率与日计费汇总支持 8 位小数。两套数据库各 760 项完整回归通过，双架构镜像及 `latest` 已核验。包含独立倍率开关和精度迁移，无需升级 Node 或核心；使用规则见 [中转节点](docs/relay-nodes.md)，固定来源、摘要与回滚说明见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.70.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.70.1) 已发布，配套管理端 `0.43.1`：客户端限制侧栏改用带勾盾牌图标，一并纳入下方入站 IP 属性卡片和侧栏外观设置改动。两套数据库各 754 项完整测试通过，双架构镜像及 `latest` 已核验。包含数据库迁移，无需升级 Node 或核心；固定来源、摘要与回滚记录见 [兼容矩阵](YZ_COMPATIBILITY.md)。

`1.70.0` 开发改动纳入 `1.70.1`：系统设置新增[外观设置](docs/admin-appearance.md)，可隐藏或显示侧边栏整组及单个模块。

`1.69.0` 开发改动纳入 `1.70.1`：入站 IP 增加白色属性卡片，修复省份列对齐及入站 / 在线设备 IP 查询链接。数据来源和缓存规则见 [统计分析](docs/statistics.md)。

面板 [`1.68.5`](https://github.com/P0me1oo/YZboard/releases/tag/v1.68.5) 已发布：流量报告按到期时间分批清空详细内容，永久保留防重编号；机器监控历史自动保留最近 24 小时，Compose 样例增加容器日志上限。新增数据库迁移，配套管理端 `0.41.4`，无需升级 Node 或核心。生产 PHP 两套数据库各 747 项测试通过，双架构镜像与 `latest` 已核验；见 [运行数据清理](docs/runtime-cleanup.md) 与 [兼容矩阵](YZ_COMPATIBILITY.md)。

`1.68.4` 开发修改已纳入 `1.68.5`：修复入站 IP 和用户流量列表返回时丢失滚动位置，更新客户端拦截提示及 Telegram 管理员通知文案。使用与验证记录见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.68.3`](https://github.com/P0me1oo/YZboard/releases/tag/v1.68.3) 已发布，配套管理端 `0.41.3`：新增「订阅管理 → 客户端限制」，按下载软件上报名称设置黑白名单，用户全部有效权限组均在豁免名单内才跳过限制。列表采用紧凑行，输入完整后自动保存，关键词标签不再附带大小写说明。两套数据库完整回归各通过 735 项，双架构镜像、`latest` 和关键文件均已核对；默认关闭，无需数据库迁移或升级 Node。固定来源与回滚参考见 [兼容矩阵](YZ_COMPATIBILITY.md)，使用方法见 [订阅客户端限制](docs/subscription-client-control.md)。

面板 [`1.67.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.67.0) 已正式发布，新增「Telegram Bot / 管理员通知」：指定多个接收人，分别开启设备与连接限制告警，重复间隔默认 60 分钟；用户无需绑定 Telegram。配套管理端 `0.40.0`，新增管理员告警迁移，并包含下方入站 IP 搜索及后台无订阅账户修复。双架构镜像、`latest` 与关键文件已核验，生产 PHP 两套数据库各 717 项及完整浏览器 141 项测试通过。无需升级 Node 或核心，配置方法见 [Telegram Bot](docs/telegram-bot.md)，固定来源与回滚版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

`1.66.1` 开发改动纳入 `1.67.0`：修复后台创建账户选择“无订阅”仍赠送试用套餐及权限组的问题，自行注册的试用规则不变。使用说明见 [用户管理](docs/user-management.md)。

`1.66.0` / 管理端 `0.39.0` 开发改动纳入 `1.67.0` / `0.40.0`：入站 IP 列表和用户明细支持完整或部分 IP 搜索；流量筛选移到实际 / 扣费流量选择框左侧并加边框；用户节点明细改用“流量类型”，显示“直出 / 中转”。该统计改动无新增迁移或 Node 变更，规则见 [统计分析](docs/statistics.md)。

面板 `1.65.0`，配套管理端 `0.38.0`：将“流量概览”改为“流量统计”，用户统计移入同页标签；上传、下载与倍率统一为用户流量记录样式。保留筛选、旧链接和明细返回，规则见 [统计分析](docs/statistics.md)，发布状态见 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.61.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.61.0) 已正式发布，配套管理端 `0.36.0`：用户管理新增可搜索的“套餐”多选筛选，节点管理的“国家/地区”改称“地区”。两套数据库完整测试各通过 666 项，完整浏览器回归 131 项通过；双架构镜像、`latest` 和镜像内交付文件均已核验。规则见 [用户管理](docs/user-management.md) 与 [节点管理](docs/node-management.md)，来源、摘要与回滚记录见 [兼容矩阵](YZ_COMPATIBILITY.md)。

本版纳入 `1.60.0` / `0.35.0` 开发阶段的改动：流量条件收进“流量筛选”，同时用于用户排行和节点明细；修正统计操作列对齐；端口重叠改为红色图标提醒，允许保存、开启和批量修改。无需数据库迁移或升级 Node，规则见 [统计分析](docs/statistics.md) 和 [端口提醒](docs/node-port-validation.md)。

面板 [`1.59.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.59.1) 已正式发布，配套管理端 `0.34.1`，修复 74 处操作按钮的文字居中及加载状态，并修正相关操作栏的手机布局。双架构镜像、`latest` 和镜像内交付文件已核验，Node 沿用 `v2.9.0`，固定来源与回滚信息见 [兼容矩阵](YZ_COMPATIBILITY.md)。

本版一并纳入 `1.58.0`、`1.59.0` 开发阶段的功能：节点管理增加按名称识别的国家/地区筛选，排序模式底部支持批量置顶、上移、下移和置底，并提供零点趋势与最低流量筛选。无需新增数据库迁移或升级 Node。规则见 [节点管理](docs/node-management.md) 和 [统计分析](docs/statistics.md)。

面板 [`1.57.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.57.0) 已正式发布，配套 Node `v2.9.0`：设备协调复用长连接、稳定来源轻量续期、页面快照合并，并包含省份识别修复。正式 PHP 8.2 两套数据库回归各通过 660 项；双架构镜像、`latest` 和镜像内本次代码、迁移及管理端文件均已核验。先更新面板，再更新 Node；实现、固定来源和回滚参考见 [运行开销优化](docs/runtime-optimization.md) 与 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.56.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.56.0) 已正式发布，配套 Node `v2.8.0`、管理端 `0.32.0`：设备名额满时，新 IP 替换最久没有发起新连接的旧来源，确认旧连接关闭后接入；旧来源冷却 60 秒，有空位可提前恢复。同时交付统计排序、日期布局和入站 IP 省份展示。正式 PHP 8.2 两套数据库测试各通过 648 项，双架构镜像、`latest` 及镜像内全部本次修改的代码和管理端文件已核验。先更新面板，再更新全部相关 Node；规则、固定来源和回滚版本见 [设备换网](docs/device-handover.md)、[统计分析](docs/statistics.md) 和 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.54.2`](https://github.com/P0me1oo/YZboard/releases/tag/v1.54.2) 已正式发布：修复 MariaDB 下入站 IP 用户明细读取失败及旧流量排行数字类型不一致。正式 PHP 8.2 流程中，SQLite、MariaDB 两套完整测试各通过 627 项；双架构镜像、`latest` 和镜像内关键文件均已核验。后续推送到 `master`、PR 和镜像发布均执行两套回归，测试入口见 [测试说明](docs/testing.md)，固定来源、镜像摘要和回滚版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

原 `1.54.1` 管理端修改统一纳入 `1.54.2`：配套管理端 `0.31.1`，例外 IP、标签及权限组等多选输入已有内容时隐藏占位提示，清空后恢复。验证及集成记录见 [兼容矩阵](YZ_COMPATIBILITY.md)，Node 无需升级。

面板 [`1.54.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.54.0) 已正式发布，配套管理端 `0.31.0`。统计分析新增“入站 IP”：按用户查看来源地址、省份分类、归属地、ASN，以及首次和最近出现时间。历史保留 30 天，默认查看今天，排除前置服务器 IP；IPv4 使用固定纯真数据库，ASN 和 IPv6 地区沿用 IP2Location，完整成功结果缓存 30 天。新增两张表，Node 无需升级。双架构镜像、latest 及镜像内关键文件已核验，规则见 [统计分析](docs/statistics.md)，固定镜像、验证和回滚版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

保留此前 `1.53.1` 开发修改：流量概览和用户统计默认查看今天，仍可选择其他时间；用户明细沿用排行范围。

上一正式版本 [`1.53.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.53.0) 为本次回滚基线，支持 WireGuard 普通直连节点，可不绑定前置，保留原 WG 中转。配套管理端 `0.30.0`、Node `v2.7.1`，提供用户订阅、独立身份、权限、流量、限速、到期停用及按公网 IP 计算的设备限制；普通 WG 也支持大陆来源拦截。包含原 `1.52.0` 的来源策略改动。历史发布核验见 [兼容矩阵](YZ_COMPATIBILITY.md)，使用规则见 [普通 WG](docs/wireguard-direct.md) 与 [来源拦截](docs/source-policy.md)。

此前的 [`1.51.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.51.1) 配套管理端 `0.28.1`、Node `v2.5.0`，固定镜像保留为历史回滚参考。统计分析新增日期时间弹窗，今天可按分钟筛选，历史及跨日按小时筛选；分钟记录只保留今天，小时记录保留 30 个自然日。节点排行支持名称搜索与流量升降序。固定来源与验证限制见 [发布记录](YZ_COMPATIBILITY.md)，查询边界见 [统计分析](docs/statistics.md)。

上一正式版本为 [`1.50.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.50.0)，配套管理端 `0.27.0`、Node `v2.5.0`。流量概览按所有节点相加；用户统计将中转只归到落地，前置只保留直出。非 1 倍明细显示当时倍率和已扣量归属，套餐扣费与节点额度规则保持不变。

上一正式版本为 [`1.49.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.49.0)，配套管理端 `0.26.0`。用户明细改为排行的二级页面，统计列表支持每页条数选择，单日趋势新增小时展示。包含原 `1.48.1` 的统计图标、已删除节点原名、提醒开关调整；新增名称记录和小时汇总两项迁移，Node 沿用 `v2.5.0`。小时记录按面板首次接收时间归档，旧历史继续按天展示。历史验证见 [兼容矩阵](YZ_COMPATIBILITY.md)。

上一正式版本为 [`1.48.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.48.0)，配套管理端 `0.25.0`。新增独立「统计分析」入口，分为流量概览和用户统计，支持流量趋势、节点及用户排行、用户节点明细。默认最近 30 天，历史保留 30 个自然日；统计口径和上线前历史限制见 [统计分析](docs/statistics.md)。同时发布 `1.47.0` 开发阶段的 Telegram Bot 四项独立提醒及设置，新增两项数据库迁移。该版本发布核验记录见 [兼容矩阵](YZ_COMPATIBILITY.md)，提醒规则见 [Telegram Bot 文档](docs/telegram-bot.md)。

上一正式版本 [`1.46.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.46.1)，配套管理端 `0.23.1`、Node 沿用 `v2.5.0`。节点类型筛选补齐 WireGuard 和 AnyTLS；Telegram Bot 剩余重置天数改为「流量重置时间：x天」，无新增数据库迁移。历史发布来源与验证结果见 [兼容矩阵](YZ_COMPATIBILITY.md)。

此前开发版本 `1.45.0` 的 Telegram Bot「绑定记录」改为「用户管理」，管理员可在桌面或手机上确认后手动解绑；保留用户账号、套餐和订阅，重复请求不影响重新绑定的新关系。无新增数据库迁移，该功能无需更新 Node。使用方式见 [Telegram Bot](docs/telegram-bot.md)。

此前正式版本 [`1.44.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.44.0)，配套管理端 `0.21.0`。Telegram Bot 绑定记录新增用户名，支持带或不带 `@` 的用户名搜索；绑定时保存，后续私聊消息和按钮操作更新，移除用户名后显示「—」。旧绑定在下次互动时补齐，账号关系仍以 Telegram ID 为准。新增可空用户名的数据库迁移，保留已有绑定。包含此前未发布的欢迎语、订阅入口合并、流量进度条、重置倒计时、到期前 72 小时提示，以及密钥星号显示、启用期间编辑和保存时自动停用流程。Node 沿用 `v2.4.1`。双架构镜像与 `latest` 已发布并核对可获取，验证及来源见 [兼容矩阵](YZ_COMPATIBILITY.md)。

上一正式版本为 [`1.41.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.41.0)，配套管理端 `0.20.1`。Telegram Bot 私聊菜单新增「重置订阅」，确认后发送新链接，保留原有绑定；旧链接和节点连接凭据失效，需更新客户端订阅。保留套餐权限组选择提示修复，以及 Telegram 页面自动检查、精简统计和密钥显示。Node 沿用 `v2.4.1`；新增短期重置确认字段的数据库迁移，不删除已有绑定。正式镜像与发布核验见 [兼容矩阵](YZ_COMPATIBILITY.md)，功能说明见 [Telegram Bot](docs/telegram-bot.md)。

保留服务器状态过期时的列表修复、用户实时网速和此前修改；不再使用中转来源 IP 白名单与确认提示，仍保留自动端口放行。

[用户管理](docs/user-management.md) 支持表头左对齐、自定义显示列，以及连接数和上传、下载速度排序。实时网速不保存历史，所需的节点上报已随 Node `v2.3.0` 发布；旧 Node 显示未知，其他管理功能继续可用。保留用户增加时长、WireGuard、Telegram Bot 和现有实时通信功能。正式发布状态、来源与历史版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-Channel-blue)](https://t.me/XboardOfficial)
![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 11, focusing on providing a clean and efficient user experience.

## ✨ Features

- 🚀 Built with Laravel 12 + Octane for significant performance gains
- 🎨 Redesigned admin interface (React + Shadcn UI)
- 📱 Modern user frontend (Vue3 + TypeScript)
- 🐳 Ready-to-use Docker deployment solution
- 🎯 Optimized system architecture for better maintainability

## 🚀 Quick Start

```bash
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard && \
cd Xboard && \
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    xboard php artisan xboard:install && \
docker compose up -d
```

> After installation, visit: http://SERVER_IP:7001  
> ⚠️ Make sure to save the admin credentials shown during installation

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins
- [插件上传限制与错误提示](./docs/plugin-upload.md) - 64 MiB 安装包、独立上传超时及网关错误排查
- [Node User Sync Reconciliation](./docs/en/development/node-user-sync.md) - Explains periodic node user-list reconciliation for expired users
- [Mihomo 订阅兼容说明](./docs/mihomo-subscription.md) - 各协议参数核对、ECH / XHTTP 转换、内核版本要求与验证范围
- [节点防火墙和 HY2 端口跳跃](./docs/firewall-port-hopping.md) - 端口列表、范围、自动放行与停用清理
- [节点运行开关](./docs/node-runtime-switch.md) - 单节点与批量启停、显隐单向联动、同服务器节点隔离与独立部署说明
- [服务器 agent 管理](./docs/machine-agent-management.md) - 远程更新与重启、运行版本、IPv4 与 IPv6 公网地址、状态与结果显示
- [REALITY 防盗用模式](./docs/reality-anti-abuse.md) - 伪装回源只放行伪装站点域名，避免节点被当成通用 TLS 转发入口
- [不计入设备数的来源 IP](./docs/device-ip-exclude.md) - 排除 flux 等转发机出口地址，填写规则、生效方式与版本要求
- [接口限流与浏览器安全头](./docs/security-hardening.md) - 各端点限流档位、真实 IP 与 `TRUSTED_PROXIES`、CSP 与安全头配置项
- [YZboard/Xray Compatibility](./YZ_COMPATIBILITY.md) - Fixed Xray fork, Node build, and report compatibility matrix

### Deployment Guides
- [Deploy with 1Panel](./docs/en/installation/1panel.md)
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
- [Deploy with aaPanel](./docs/en/installation/aapanel.md)
- [Deploy with aaPanel + Docker](./docs/en/installation/aapanel-docker.md) (Recommended)

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)

## 🛠️ Tech Stack

- Backend: Laravel 11 + Octane
- Admin Panel: React + Shadcn UI + TailwindCSS
- User Frontend: Vue3 + TypeScript + NaiveUI
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 📷 Preview
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## ❤️ Support The Project

If this project has helped you, donations are appreciated. They help support ongoing maintenance and would make me very happy.

TRC20: `TLypStEWsVrj6Wz9mCxbXffqgt5yz3Y4XB`

## 🌟 Maintenance Notice

This project is currently under light maintenance. We will:
- Fix critical bugs and security issues
- Review and merge important pull requests
- Provide necessary updates for compatibility

However, new feature development may be limited.

## 🔔 Important Notes

1. Restart required after modifying admin path:
```bash
docker compose restart
```

2. For aaPanel installations, restart the Octane daemon process

## 🤝 Contributing

Issues and Pull Requests are welcome to help improve the project.

## 📈 Star History

[![Stargazers over time](https://starchart.cc/cedar2025/Xboard.svg)](https://starchart.cc/cedar2025/Xboard)
