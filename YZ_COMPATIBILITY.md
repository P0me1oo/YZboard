# YZboard 兼容矩阵

本文件记录面板、Node、Xray fork 和 sing-box 的可回滚兼容关系。面板版本与兼容标识必须和对应 Node Release、Xray fork commit 及变更说明一起发布。

## 当前源码与已发布基线

| 项目 | 标识 |
| --- | --- |
| YZboard 面板源码版本 | `1.8.0`（待发布） |
| 面板兼容标识 | `xray-v26.7.11-yz.2` |
| YZboard 上游仓库 | `https://github.com/cedar2025/Xboard.git` |
| YZboard 上游基线 | `master` 固定快照 / `8ecb762d77ef16491fe919b7092aea66b834deed` |
| YZboard 目标发布 Tag | `v1.8.0` / 待发布，commit 尚未固定 |
| YZboard 最近已发布 Tag / commit | `v1.5.0` / `1ea82abba7624113303d17d9aac635772865d20d` |
| YZboard 已发布 Docker 镜像 | `ghcr.io/p0me1oo/yzboard:latest`、`ghcr.io/p0me1oo/yzboard:1.5.0`；审计和回滚使用不可变标签 `ghcr.io/p0me1oo/yzboard:1.5.0-1ea82ab` |
| YZboard Docker manifest | `sha256:0ed171a1e86709b81bd2ecaf0f1f356d0d0d2c470b9768bdcb50a20b5c9accf9`；包含 `linux/amd64` 与 `linux/arm64` |
| YZboard Docker 架构 | `linux/amd64`、`linux/arm64` |
| YZboard Docker 构建 | 固定来源 `v1.5.0`；Tag 推送触发 GitHub Actions [run 32290030304](https://github.com/P0me1oo/YZboard/actions/runs/32290030304)；生产 `latest` 已通过 [run 32300726906](https://github.com/P0me1oo/YZboard/actions/runs/32300726906) 精确重标记回同一 manifest |
| YZboard-Node 兼容版本 | `v1.13-yz.15`（待发布；节点级内核选择需成套升级） |
| YZboard-Node 最近已发布版本 | `v1.13-yz.14` |
| YZboard-Node 上游基线 | `v1.13` / `0a29338e1f102a462363ce3527417029f89bab28` |
| YZboard-Node 最近已发布 commit | `82114adc8755ef520df6d99e3cd25a4b97073cec` |
| YZboard-Node 发布 | GitHub [Release v1.13-yz.10](https://github.com/P0me1oo/YZboard-Node/releases/tag/v1.13-yz.10)；以固定 Tag 手动触发 GitHub Actions [run 31269894172](https://github.com/P0me1oo/YZboard-Node/actions/runs/31269894172) |
| YZboard-Node Docker manifest | `sha256:48ce4fe3605e2e3aa29292a65fc5003ca2561c098ff3ae87ba85da86a462f1ed`；包含 `linux/amd64` 与 `linux/arm64` |
| Xray 官方预发布 Tag | `v26.7.11` |
| Xray 上游 Tag commit | `50231eaff98ccc31b5cbd247a721c16e97fe5ec1` |
| YZ-Xray-core fork 版本 | `v26.7.11-yz.2` |
| YZ-Xray-core fork commit | `26b01717dd8d1fd604de5e23e2868fdef59eba2f` |
| Node Xray replace pseudo-version | `v0.0.0-20260901175116-26b01717dd8d` |
| sing-box `require` 版本 | `v1.13.2` |
| sing-box 实际 replacement | `github.com/cedar2025/sing-box v1.14.0-alpha.2.0.20260316103356-2e665cb7e295` |

三个项目的内部版本不强行相同：面板使用自己的语义版本，Node 使用独立的 Release Tag，Xray 使用“上游版本 + YZ fork patch”格式。官方 `v26.7.11` Tag 不由 YZ fork 创建或覆盖。

## Node report 兼容约束

- 新版 Node 为每次刷出的流量批次携带 `report_id`，失败重试复用同一批次 ID。
- 面板 `1.7.0` 起按 `server_id + report_id` 把流量写入 `v2_node_report_batch`。用户流量、用户日统计、节点日统计和中转流量在同一数据库事务中结算，任务失败会整体回滚并自动重试。
- HTTP 接收成功只表示报告已经持久化或已经结算，不再表示多个下游队列任务都已完成。相同批次的重复请求不会重复扣量，已完成记录保留 7 天。
- 重复请求仍会刷新 `last_check_at`、`last_push_at`、在线连接缓存、设备状态和节点指标；流量批次单独按持久化记录保证幂等。
- 不带 `report_id` 的旧 Node 请求继续接受，但面板只能为每次请求生成临时批次 ID，无法识别 HTTP 响应丢失后的重复请求。
- 流量方向保持 `[upload, download]`；倍率在接收报告时固定，避免排队期间节点倍率变化影响已经产生的流量。

## 1.7.0 同步兼容约束

- 升级面板源码后必须执行数据库迁移，创建 `v2_node_report_batch`；未迁移时 V2 节点流量报告会失败并由 Node 保留批次重试。
- 面板在线人数以 Node 的 `online` 全量快照为准，设备状态以 `alive` 或 WebSocket `report.devices` 全量快照为准；空对象表示当前没有在线用户或设备，不能省略成旧值。
- `v1.13-yz.13` 在 WebSocket 正常时仍至少每 5 分钟执行一次 REST ETag 对账，用于修复 Redis Pub/Sub、Workerman 重启或短暂断线造成的推送丢失。
- Node 每次设备报告都发送完整快照并包含空快照，面板按节点替换状态并续期 300 秒 TTL。首次升级会兼容扫描旧设备键并建立节点索引，后续不再全量扫描。
- 批量封禁和套餐强制更新使用按权限组的全量用户推送；即使 Redis 推送丢失，周期 REST 对账和 `node:sync-users` 仍会恢复最终状态。

## 1.8.0 节点级内核兼容约束

- 面板节点表新增可空的 `kernel_type`：`xray` 或 `singbox`；空值兼容历史数据并按 Xray 处理，默认值不改变已有协议配置。
- 机器节点发现接口和节点配置接口都会返回有效的 `kernel_type`。Node `v1.13-yz.15` 及以上在机器模式按节点选择后端，同一台机器可以同时运行 Xray 与 sing-box。
- 机器模式节点的内核选择变化会触发该节点单独重启；机器只包含 Xray 节点时不会启动 sing-box 服务实例。
- 中转入口和落地节点仍必须使用 Xray；sing-box 不具备当前 VLESS 路由编号能力。XHTTP 等仅 Xray 传输仍按 Node 的内核能力校验。
- 管理端构建阶段补丁 `.docker/patch-admin-relay.php` 增加内核下拉、表单字段和 Xray 默认值；补丁保持锚点失败即中止，并按内容 hash 重命名入口产物。

## 中转节点兼容约束

- 面板 `1.1.0` 起在节点配置接口增加 `relay` 段，并在上报接口接受 `relay_traffic`；对应 Node 版本为 `v1.13-yz.5`。
- 旧版 Node 会忽略 `relay` 段，也不会上报 `relay_traffic`，因此升级面板但未升级 Node 时中转拓扑不会生效，普通节点行为不变。
- 中转依赖 Xray 的 VLESS 路由值能力（认证前清零 UUID 第 7、8 字节，认证后还原并由 `vlessRoute` 规则匹配），入口和落地节点都必须使用 xray 内核。
- `relay_traffic` 只累计到逻辑节点的节点流量，不进入用户套餐扣费，也不套用倍率；用户流量仍只在入口按真实用户身份统计一次。
- 节点表新增 `vless_route` 列，迁移会按 id 顺序回填存量节点并记录分配游标。回滚该迁移会删除列和索引，但不会回收已写入订阅的编号。
- 面板 `1.2.0` 起中转关系存放在新增的 `relay_entry_id` 列，不再借用 `parent_id`；`parent_id` 的行为与上游完全一致。升级后该列对存量节点为空，不会有节点被识别成中转逻辑节点。节点端接口未变，`v1.13-yz.5` 及以上均兼容。
- 管理端的「前置入口」下拉和节点列表的「前置入口」列，由构建阶段补丁 `.docker/patch-admin-relay.php` 注入到 `xboard-admin-dist` 产物。上游管理端产物结构变化会导致补丁锚点失配并使镜像构建失败，此时需要同步更新补丁而不是跳过。
- 面板 `1.3.0` 起管理端节点列表接口 `GET /api/v2/admin/server/manage/getNodes` 增加 `relay_entry_name` 字段，仅用于列表展示，节点端接口未变。
- 面板 `1.4.0` 与 Node `v1.13-yz.10` 起，`relay` child/landing 可以使用 `protocol: vless`，并增加嵌套 `vless` 配置。旧 Node 不认识该结构，VLESS 落地必须成套升级；既有 Shadowsocks relay 的平面 `cipher/password` 结构不变。
- VLESS 中转的当前有效传输为 RAW/TCP、WS、gRPC、XHTTP、HTTPUpgrade、mKCP、Hysteria；Reality 只允许 RAW/TCP、gRPC、XHTTP，Hysteria 只允许 TLS，H2/HTTP 不支持。
- VLESS Encryption 的 `encryption` 只进入入口 child，`decryption`、Reality 私钥和证书配置只进入落地顶层配置。内部 UUID 与 Hysteria transport auth 由面板应用密钥按独立域派生，不落库、不进入订阅。
- `1.4.0` 的管理端构建补丁复用 Reality 的浏览器端 X25519 生成器，为 VLESS Encryption
  提供钥匙按钮并同时填入 `decryption`/`encryption`。生成动作只修改未保存表单，手工填写
  和通过 `xray vlessenc` 生成的 ML-KEM-768 配置继续兼容。

## 1.5.0 节点管理兼容约束

- 节点批量权限组操作复用 `POST /api/v2/admin/server/manage/batchUpdate`，新增 `group_action=add|remove` 与 `group_id` 参数；不改变节点端通信协议。
- 批量添加权限组是集合合并，批量移除权限组只删除指定组；重复调用保持幂等，未选中的节点不受影响。
- Shadowsocks 新建表单默认使用 `2022-blake3-aes-128-gcm`；该默认值只存在于管理端表单，存量节点的 `protocol_settings.cipher` 不会被迁移。
- 管理端补充界面继续由 `.docker/patch-admin-relay.php` 在构建阶段注入。上游管理端产物锚点变化时，构建应失败并更新补丁，不应跳过补丁。

## 发布与回滚

当前已发布结果及 `1.5.0` 生产基线：

1. 面板 `v1.5.0` 固定到 `1ea82abba7624113303d17d9aac635772865d20d`，不可变镜像 `1.5.0-1ea82ab`、版本别名 `1.5.0` 和 `latest` 指向 manifest `sha256:0ed171a1e86709b81bd2ecaf0f1f356d0d0d2c470b9768bdcb50a20b5c9accf9`；
2. `latest` 已通过 [run 32300726906](https://github.com/P0me1oo/YZboard/actions/runs/32300726906) 从现有不可变镜像重新标记，没有重新构建或移动 `v1.5.0` Tag；
3. Node `v1.13-yz.10` 固定到 `82114adc8755ef520df6d99e3cd25a4b97073cec`，本次 1.5.0 改动不要求 Node 版本变更；
4. Node `go list -m -json github.com/xtls/xray-core` 的 replacement 路径和 pseudo-version 指向 `620bee93867095f73880056cdfb08bc54a15f69e`；
5. Xray fork 的 `v26.7.11-yz.1` Tag 指向同一 fork commit；
6. sing-box 请求版本和实际 replacement 版本与上表一致。

推送语义版本 Tag 会自动触发构建，并同时更新“面板版本 + 短 commit”的不可变标签、面板版本别名和 `latest`，中间没有确认环节。**推 `v*` Tag 等同于发布生产**，不要用它做试探性标记。手动 dispatch 仍然可用，适合对固定 Tag 或完整 commit 重新构建，那条路径的 `publish_latest` 默认关闭，需要显式勾选。生产 Compose 长期使用 `latest` 拉取更新，不可变标签和 digest 用于确认实际版本与回滚。

本仓库是 `cedar2025/Xboard` 的 fork，GitHub 对 fork 默认禁用事件触发的工作流，所以 `v1.0.6` 到 `v1.3.0` 五个 Tag 都没有触发构建，那几版实际都由手动 dispatch 发布。该限制已于 2026-07-27 在仓库 Actions 页面解除，`v1.3.1` 是第一个由 Tag 推送自动构建的版本。

回滚时，面板镜像、Node Release 和 Node 的 Xray replace 应成套退回上一条兼容矩阵记录，不要只修改其中一个版本字段。
