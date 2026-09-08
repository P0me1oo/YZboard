# YZboard 兼容矩阵

本文件记录面板、Node、Xray fork 和 sing-box 的可回滚兼容关系。面板版本与兼容标识必须和对应 Node Release、Xray fork commit 及变更说明一起发布。

## 节点防火墙与端口跳跃（1.13.0，待发布）

| 项目 | 标识 |
| --- | --- |
| 目标面板版本 | `1.13.0`；本轮完成开发与隔离验证，尚未创建正式 Tag、Release 或镜像 |
| 面板修改基线 | `1bbe4010105a9413ca23548ddbd2a6ce5752f495` |
| 配套 Node | `v1.13-yz.24`；开发基线 `1d74d19cbb738e6fcde466361c24a186b8deec60`，兼容 `v1.13-yz.23` 的安装目录和升级回滚 |
| Xray 固定依赖 | `v26.7.11-yz.6` / `b4caa82d6414196565599c19ebc1b53e331349b6` |
| sing-box 固定依赖 | `v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427` |
| 修改范围 | HY2 端口集合校验、配置接口的 `port_hopping`、订阅端口选择和 sing-box 范围输出；没有数据库变更 |
| 本地回归 | PHP 8.4.21，112 项测试、1762 个断言通过；覆盖配置接口、停用节点发现、订阅和保存校验 |
| Linux 联测 | YT-HK 专用 rootfs 和隔离网络；四组原生防火墙组合通过，Xray/UFW/nftables 和 sing-box/firewalld/iptables 的实际 Node、官方 HY2 客户端测试通过 |
| 联测边界 | Node 使用临时面板接口替身，真实 Laravel API 由本地功能测试验证；没有部署远程完整面板或操作生产环境 |
| 使用说明 | [节点防火墙与端口跳跃](docs/firewall-port-hopping.md)；Node 侧详细结果见其 `docs/firewall-validation.md` |
| 升级和回退 | 先更新 Node、再更新面板和订阅；回退面板 `1.12.1`、Node `v1.13-yz.23` 前先取消跳跃或准备手工转发，正常停止新版完成规则回收 |

以下保留正式发布记录。本节没有改变已发布镜像的版本标签或 `latest`。

## Mihomo 订阅修复（1.12.1）

| 项目 | 标识 |
| --- | --- |
| 当前面板源码版本 | `1.12.1`；Tag、Release 与双架构镜像已发布 |
| 面板修改基线 | `4eb7238e381e8afa217cfbbea76c02f266ae1660` |
| 本版发布 Tag / commit | `v1.12.1` / `0f2b708cdd5c7bdfc346831356f12712e3ed37a3` |
| 本版 Release | [v1.12.1](https://github.com/P0me1oo/YZboard/releases/tag/v1.12.1)，最新正式版本 |
| 本版不可变镜像 | `ghcr.io/p0me1oo/yzboard:1.12.1-0f2b708`；`1.12.1` 与 `latest` 已同步 |
| 本版 Docker manifest | `sha256:39b49d8879da52402c2e8710fb65b9248a63a07596d5aced1cf907638f45ade1` |
| Docker 架构与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=0f2b708cdd5c7bdfc346831356f12712e3ed37a3`、`version=1.12.1-0f2b708` |
| 配套 Node / 服务端核心 | 沿用下方 `1.12.0` 的固定依赖，无数据库变更 |
| 面板回滚 Tag / commit | `v1.12.0` / `c2d6873ec055dbb8d48184eb296d50db6c85e529` |
| 面板回滚镜像 | `ghcr.io/p0me1oo/yzboard:1.12.0-c2d6873` |
| 回滚 Docker manifest | `sha256:39065c1b1fb66537e62f8b18f8c44c21a8ae0e64b3d120944573a17cfd471aa6`；两架构与来源已重新核对 |
| Mihomo 核对源码 | `fc8c5a24b16991f98cd736950c17d1aa306a5041` |
| 修改范围 | ECH / XHTTP、常见传输参数、Shadowsocks 插件、Mieru、HTTP TLS、Reality 指纹、入口内核与多路复用、sing-box HY1 ALPN、内核版本过滤 |
| 字段和验证记录 | [Mihomo 订阅兼容说明](docs/mihomo-subscription.md) |

### 本版发布验证（2026-09-08）

[面板发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34241282162) 从 `v1.12.1` 固定提交构建并发布两个 Linux 架构，构建与多架构清单检查全部通过。
通过 GHCR 匿名拉取接口重新获取清单、架构清单和镜像配置，校验响应内容的 SHA256、OCI 来源及版本；不可变标签、版本别名和 `latest` 均指向上表摘要。

| 架构 | 架构清单 digest | 镜像配置 digest |
| --- | --- | --- |
| `linux/amd64` | `sha256:c0b77b1d84266edac82003fe1c9026183399ba4562ba94bf3b1d4e0aff017148` | `sha256:e0a84cd5ed180755ab3ccab4848f00675e6f965dae2da6c11635153e2d6ff170` |
| `linux/arm64` | `sha256:5131ccbe1ca06401c633156ce8125d1cb7d3668401893154652db2da9039219f` | `sha256:fc805e69ceb80421d95afbc26df366d05dcfbed5f18e47df883bfb44fed78d7d` |

发布源码的完整面板回归为 105 项测试、1693 个断言；Mihomo 扩展核对中，68 组配置、11 种出站类型、226 项字段检查通过实际解析。
测试使用本地 PHP 8.4.21、内存 SQLite 和固定 Mihomo 源码，具体范围见兼容说明。双架构镜像完成构建及元数据核验，本次未进行实际线路握手或转发测试。

Node 继续使用 `v1.13-yz.22`，Xray、sing-box 依赖和数据库结构不变。已使用 `1.12.0` 配套版本时，只需更新面板并刷新客户端订阅。
回滚面板后也应刷新订阅，旧版不包含本次参数修复；Node 和核心无需随本补丁回退。
发布记录以独立文档提交补充，已发布 Tag 和镜像的源码固定在上表提交；本次没有操作生产服务器。下方保留 `1.12.0` 的历史发布与回滚记录。

## HY2 ECH 前置入口（1.12.0）

| 项目 | 标识 |
| --- | --- |
| 本节面板版本 | `1.12.0`；Tag、Release 与正式镜像已发布 |
| 本次面板修改基线 | `f5d5075baf9592080a9bd0a1dba8c492f3684cc3` |
| 配套 Node 版本 | `v1.13-yz.22`，正式来源 `2aa021b65481a14b1d34ff9f594939387c5f0f05` |
| Xray 固定依赖 | `v26.7.11-yz.6` / `b4caa82d6414196565599c19ebc1b53e331349b6`，本次没有修改核心 |
| sing-box 固定依赖 | `v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427`，本次没有修改依赖 |
| 修改范围与验证 | HY2 ECH 候选、Node 密钥写入、Mihomo 订阅参数及客户端版本判断；见 [验证记录](docs/hy2-ech-validation.md) |
| 部署顺序 | 先更新 Node，再更新面板和客户端订阅 |

以下记录本版正式发布结果；回滚基线为面板 `1.11.0` 与 Node `v1.13-yz.21`。

## 历史正式发布引用与回滚基线：1.12.0

| 项目 | 标识 |
| --- | --- |
| YZboard 面板源码版本 | `1.12.0`；固定来源使用同名语义版本 Tag |
| 面板兼容标识 | `xray-v26.7.11-yz.6` |
| YZboard 上游仓库 | `https://github.com/cedar2025/Xboard.git` |
| YZboard 上游基线 | `master` 固定快照 / `8ecb762d77ef16491fe919b7092aea66b834deed` |
| YZboard 本版发布 Tag / commit | `v1.12.0` / `c2d6873ec055dbb8d48184eb296d50db6c85e529` |
| YZboard 本版 Release | [v1.12.0](https://github.com/P0me1oo/YZboard/releases/tag/v1.12.0) |
| YZboard 回滚 Tag / commit | `v1.11.0` / `f91568d72ffb55205cbcd9b15a8476283a017683` |
| YZboard 回滚 Docker 镜像 | `ghcr.io/p0me1oo/yzboard:1.11.0-f91568d` |
| YZboard 回滚 Docker manifest | `sha256:9ec52732a2f93f77e1ae6f34e314cf9399b26a8c4febf4a82db2e32cd7e651b4`；包含 `linux/amd64` 与 `linux/arm64` |
| YZboard Docker 架构 | `linux/amd64`、`linux/arm64` |
| YZboard 本版 Docker 构建 | `ghcr.io/p0me1oo/yzboard:1.12.0-c2d6873`；`1.12.0` 与 `latest` 已同步并核对同一 digest |
| YZboard 本版 Docker manifest | `sha256:39065c1b1fb66537e62f8b18f8c44c21a8ae0e64b3d120944573a17cfd471aa6` |
| YZboard 本版 Docker OCI 标识 | 两架构均为 `revision=c2d6873ec055dbb8d48184eb296d50db6c85e529`、`version=1.12.0-c2d6873` |
| YZboard-Node 兼容版本 / commit | `v1.13-yz.22` / `2aa021b65481a14b1d34ff9f594939387c5f0f05`；HY2 ECH 中转需成套升级 |
| YZboard-Node 本版 Release | [v1.13-yz.22](https://github.com/P0me1oo/YZboard-Node/releases/tag/v1.13-yz.22)；10 个附件均已下载核验 |
| YZboard-Node 本版 Docker manifest | `sha256:8c831eca80ebc66680055e0f6f443a2c0e1830a28fc6bab638fc7a0487a54235`；版本、完整提交和 `latest` 标签一致，含两个 Linux 架构 |
| YZboard-Node 上一正式版本（回滚） | `v1.13-yz.21` |
| YZboard-Node 上游基线 | `v1.13` / `0a29338e1f102a462363ce3527417029f89bab28` |
| YZboard-Node 回滚 commit | `2f08f4134d352e127828e1e15aeaa4cfd479864c` |
| YZboard-Node 回滚 Release | GitHub [Release v1.13-yz.21](https://github.com/P0me1oo/YZboard-Node/releases/tag/v1.13-yz.21)；发布记录见 Node 兼容矩阵 |
| YZboard-Node 回滚 Docker manifest | `sha256:500bd8ac445a38ae76550bc2d66c7fd9700515255ab92e9276020bc6136984a0`；包含 `linux/amd64` 与 `linux/arm64` |
| Xray 官方预发布 Tag | `v26.7.11` |
| Xray 上游 Tag commit | `50231eaff98ccc31b5cbd247a721c16e97fe5ec1` |
| YZ-Xray-core 源码 Tag | `v26.7.11-yz.6` |
| YZ-Xray-core fork commit | `b4caa82d6414196565599c19ebc1b53e331349b6` |
| Node Xray replace pseudo-version | `v0.0.0-20260907200713-b4caa82d6414` |
| sing-box `require` 版本 | `v1.14.0` |
| sing-box 实际 replacement | `github.com/P0me1oo/YZ-sing-box v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427` |
| SS2022 关闭补丁 | Node 的 `compat/sing-shadowsocks`，随 Node 固定提交发布；上游 `v0.2.8` / `e0612494bafdd1429e9632bc52fd278585d28690` |

三个项目的内部版本不强行相同：面板使用自己的语义版本，Node 使用独立的 Release Tag，Xray 使用“上游版本 + YZ fork patch”格式。官方 `v26.7.11` Tag 不由 YZ fork 创建或覆盖。

## 历史发布验证：1.12.0（2026-09-08）

[面板发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34173634258) 从 `v1.12.0` 的固定提交构建并发布两个 Linux 架构。
不可变标签、版本别名和 `latest` 的 manifest digest 一致，两个架构的 OCI 来源与版本均已核对，支持匿名拉取。
[面板 Release](https://github.com/P0me1oo/YZboard/releases/tag/v1.12.0) 已设为最新正式版本；面板完整测试为 60 项、813 个断言。

配套 Node 的 [正式发布 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34172378951) 完成 Linux `make test`，
主模块和六组兼容模块共 661 项测试及子测试通过，无失败、无跳过、无数据竞争报告。
双架构安装包、镜像和来源检查通过，10 个 Release 附件已下载核对摘要及实际构建信息，`vcs.modified=false`。
Linux amd64 runner 已执行实际 ECH 握手和八组中转组合；arm64 镜像通过 QEMU 运行版本检查，未进行 arm64 实际转发。
本地 sing-box 与官方 Mihomo `1.19.9` 联测、公共参数及环境限制见 [HY2 ECH 验证记录](docs/hy2-ech-validation.md)。

Node 先于面板发布。生产环境先升级相关 Node，再更新面板和客户端订阅。
回退至 Node `v1.13-yz.21` 或面板 `1.11.0` 前，应先关闭 HY2 入口的 ECH 并刷新订阅。
发布记录以单独文档提交补充，已发布 Tag 和镜像来源保持不变；本次没有操作生产服务器。

## 历史发布验证：1.11.0（2026-09-08）

[面板发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34161072639) 从 `f91568d72ffb55205cbcd9b15a8476283a017683` 构建并发布两个 Linux 架构。
不可变标签、面板版本别名和 `latest` 的 manifest digest 一致，各架构的 OCI 来源及版本均已核对。
[面板 Release](https://github.com/P0me1oo/YZboard/releases/tag/v1.11.0) 已创建并设为最新正式版本；面板完整测试为 57 项、549 个断言。

配套 Node 的 [发布前 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34158600477) 和
[正式发布 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34159504349/attempts/2) 各完成 637 项测试及子测试，无跳过、无竞争报告。
两个架构的安装包与镜像构建、来源及运行版本检查通过；下载后的 10 个附件与校验清单、GitHub 摘要及实际构建信息一致。
正式 CI 第一次因测试回环端口被占用失败，随后使用同一源码、Tag 和全部检查在新 runner 上通过，具体记录见 Node 验证文档。

Node 已先于面板发布。生产环境由用户确认部署目录、服务和持久化数据后执行更新，先升级相关 Node，再升级面板并启用新拓扑。
回滚基线为 Node `v1.13-yz.19` 与面板 `1.9.0`；应同时评估已启用的中转组合。发布记录以单独的文档提交补充，已发布 Tag 和镜像来源保持不变。

## sing-box 中转兼容约束（1.11.0）

- 面板 `1.11.0` 与 Node `v1.13-yz.21` 支持 sing-box VLESS/HY2 入口，以及 sing-box Shadowsocks/VLESS 落地；入口和落地可以与 Xray 混用。
- 修改基于面板 `eff2fa22531f2e15168d3e7e96d8ab45639b1969` 和 Node `94e2a76e42c1f059126b588b2d02f49e54fd8246` 的工作区，保留已有 HY2 实现。2026-09-08 将 Node 的 Xray、sing-box 依赖及构建标识固定到上表提交，并纳入 SS2022 关闭补丁。
- sing-box 使用原生 `auth_user` 规则，按「用户 × 线路」生成认证身份。`relay`、`traffic`、`relay_traffic`、`relay_user_traffic` 的格式不变，速度和设备限制仍使用真实用户。
- 任一端使用 sing-box 时，VLESS 内部链路只允许 RAW/TCP、WebSocket、gRPC、HTTPUpgrade，并按传输限制 TLS/Reality；不支持 VLESS Encryption、TCP 头部伪装及无效 Vision 组合。
- sing-box 的线路流量按实际选中出站的有效载荷统计；Xray 沿用内部出站计数，两者节点总量口径可能不同。用户流量仍只在入口扣费一次。
- 先升级相关 Node，再升级面板并启用拓扑；上一正式版本继续用于回滚。
- 本轮面板完整测试为 57 项、549 个断言，通过。管理端原始资源、旧补丁升级及重复应用检查通过。Node 和双机验收结果记录在 Node 的 `docs/singbox-relay-validation.md`。
- 当前固定 Xray `yz.6` 包含 VLESS 首批缓冲上传计数、UDP 缓存与 HY2 会话关闭同步修复；sing-box `yz.2` 和 Node 自有 SS2022 兼容模块修复其余两类并发问题。`yz.5` 阶段 Node 完整普通测试通过：17 个有测试的包、609 项测试及子测试，无失败或测试跳过。
- 修复后四个相关包各连续 10 轮并发检测通过，共 180 项测试执行。Linux 完整 sing-box 包并发检测为 142 项测试及子测试全部通过，耗时 126.042 秒，无失败、无跳过、无竞争报告；此前失败的混合内核及 gRPC 分支保留。
- YT-HK、DGN-HK 使用修复后依赖完成 60 次实际转发及生命周期检查，包含 Xray VLESS 前置到 sing-box SS2022 落地的 TCP/UDP；原 `yz.4` 下的竞争报告保留在 Node 验证文档的历史部分。
- 本地和双机探针属于发布前阶段；正式 Node 安装包及镜像已从上表固定提交发布并核对实际依赖、目标架构、来源及 `vcs.modified=false`。面板镜像也已按固定来源发布两个 Linux 架构。
- 发布前完整 Node 并发检测曾在 HY2 关闭状态处失败，该次未进入构建和发布。补充的 Xray `yz.6` 用例在旧代码上复现管理器和单会话两处状态竞争，修复后 5 项测试连续 10 轮 Linux 并发检测共 50 次通过；新依赖随后通过上述完整 Node 发布验收。

## HY2 前置入口基线（1.10.0）

- 面板 `1.10.0` 与 Node `v1.13-yz.20` 为上一轮源码目标，没有单独生成对应 Tag、Release 或镜像，相关功能随 `1.11.0` 与 `yz.21` 一同发布。回滚使用上表明确标注的面板 `1.9.0` 与 Node `yz.19`。
- 修改起点为面板 `eff2fa22531f2e15168d3e7e96d8ab45639b1969` 和 Node `94e2a76e42c1f059126b588b2d02f49e54fd8246`。HY2 认证路由复用当时固定 Xray `yz.3` 的既有能力；该轮尚未纳入 VLESS 出站统计修复，后续接入见上文 `1.11.0` 记录。
- HY2 前置入口和 Shadowsocks/VLESS 落地均使用 Xray。HY2 认证 UUID 携带原有路由编号，`relay`、`traffic`、`relay_traffic`、`relay_user_traffic` 的结构保持兼容。
- 发布后应先升级 Node，再在面板启用 HY2 前置入口；旧 Node 会拒绝这种中转入口配置。普通节点及原有 VLESS 中转沿用既有配置。
- 使用步骤和支持范围见 [中转节点说明](docs/relay-nodes.md)。

### 本地验证状态（2026-09-07，Xray yz.3 历史结果）

- 面板完整测试通过：55 项测试、412 个断言，包含列表切换内核、编辑请求省略字段和无效落地过滤；管理端补丁在当前产物和旧补丁升级路径上均通过语法、候选过滤和重复执行检查。
- 固定核心下的 HY2 实际转发、用户流量和用户-落地明细验证通过，但 VLESS 落地出站上传计数为零，运行测试在该断言失败。
- 原因是 Xray 的缓冲写入绕过了出站计数器。仅用本地临时覆盖补上字节写入计数后，Node 完整 Go 测试及核心 `common/buf` 测试通过；这些结果不能替代正式固定依赖的验收。
- Node 的 `linux/amd64`、`linux/arm64` 交叉编译通过，产物确认使用当前固定依赖并标记 `vcs.modified=true`；Windows 的 race 检查因未启用 CGO 未执行，Linux 运行验收未执行。
- 核心修复及依赖更新仍待确认，当前代码尚不具备完整发布验收结果。

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
- 中转在面板 `1.11.0`、Node `v1.13-yz.21` 起支持两种内核混用；旧版本中转仍要求 Xray。XHTTP 等仅 Xray 传输按链路两端的共同能力校验。
- 管理端构建阶段补丁 `.docker/patch-admin-relay.php` 增加内核下拉、表单字段和 Xray 默认值；补丁保持锚点失败即中止，并按内容 hash 重命名入口产物。

## 1.8.1 管理端白屏热修复

- 修复节点内核选择器的构建期注入缺少一个闭合调用的问题。此前生成的管理端 bundle 会在 Chrome 中报 `Uncaught SyntaxError: Unexpected token '.'`，导致 React 根节点为空并显示白屏。
- 修复后的管理端 bundle 已在当前 Chrome 152 中实际加载验证，登录界面可以正常挂载；补丁脚本重复执行仍保持幂等。

## 1.8.2 管理端节点编辑修复

- 修复节点编辑弹窗标题区域的内核下拉错误使用 `Controller`/`FormItem` 的问题。该区域不在 React Hook Form 的 `FormProvider` 内，点击任意节点编辑时会触发 `Cannot destructure property 'getFieldState' ... as it is null` 并显示 500 错误页。
- 内核下拉现在直接使用当前表单实例的 `watch` 和 `setValue`，仍位于协议选择左侧，默认 Xray；生成的管理端 JavaScript 通过语法检查，补丁重复执行保持幂等。

## 1.8.3 管理端 bundle 语法修复

- 修复 `v1.8.2` 内核选择器补丁在协议选择器尾部多插入一组 `]})` 的问题。Node 语法检查未报告该问题，但 Chrome 和 Acorn 会在节点表单末尾报告 `Unexpected token ']'`，导致页面白屏。
- `v1.8.3` 使用浏览器兼容的 Acorn 解析和 Chrome 运行时解析双重校验管理端 bundle。

## 中转节点兼容约束

- 面板 `1.1.0` 起在节点配置接口增加 `relay` 段，并在上报接口接受 `relay_traffic`；对应 Node 版本为 `v1.13-yz.5`。
- 旧版 Node 会忽略 `relay` 段，也不会上报 `relay_traffic`，因此升级面板但未升级 Node 时中转拓扑不会生效，普通节点行为不变。
- Xray 中转入口使用 VLESS 路由值能力（认证前清零 UUID 第 7、8 字节，认证后还原并由 `vlessRoute` 规则匹配）；sing-box 入口使用线路认证身份及 `auth_user` 规则。
- `relay_traffic` 只累计到逻辑节点的节点流量，不进入用户套餐扣费，也不套用倍率；用户流量仍只在入口按真实用户身份统计一次。
- 节点表新增 `vless_route` 列，迁移会按 id 顺序回填存量节点并记录分配游标。回滚该迁移会删除列和索引，但不会回收已写入订阅的编号。
- 面板 `1.2.0` 起中转关系存放在新增的 `relay_entry_id` 列，不再借用 `parent_id`；`parent_id` 的行为与上游完全一致。升级后该列对存量节点为空，不会有节点被识别成中转逻辑节点。节点端接口未变，`v1.13-yz.5` 及以上均兼容。
- 管理端的「前置入口」下拉和节点列表的「前置入口」列，由构建阶段补丁 `.docker/patch-admin-relay.php` 注入到 `xboard-admin-dist` 产物。上游管理端产物结构变化会导致补丁锚点失配并使镜像构建失败，此时需要同步更新补丁而不是跳过。
- 面板 `1.3.0` 起管理端节点列表接口 `GET /api/v2/admin/server/manage/getNodes` 增加 `relay_entry_name` 字段，仅用于列表展示，节点端接口未变。
- 面板 `1.4.0` 与 Node `v1.13-yz.10` 起，`relay` child/landing 可以使用 `protocol: vless`，并增加嵌套 `vless` 配置。旧 Node 不认识该结构，VLESS 落地必须成套升级；既有 Shadowsocks relay 的平面 `cipher/password` 结构不变。
- 两端均为 Xray 时，VLESS 中转支持 RAW/TCP、WS、gRPC、XHTTP、HTTPUpgrade、mKCP、Hysteria；Reality 只允许 RAW/TCP、gRPC、XHTTP，Hysteria 只允许 TLS，H2/HTTP 不支持。任一端使用 sing-box 时，内部传输限于 RAW/TCP、WS、gRPC、HTTPUpgrade，并按传输校验安全组合。
- VLESS Encryption 的 `encryption` 只进入入口 child，`decryption`、Reality 私钥和证书配置只进入落地顶层配置。内部 UUID 与 Hysteria transport auth 由面板应用密钥按独立域派生，不落库、不进入订阅。
- `1.4.0` 的管理端构建补丁复用 Reality 的浏览器端 X25519 生成器，为 VLESS Encryption
  提供钥匙按钮并同时填入 `decryption`/`encryption`。生成动作只修改未保存表单，手工填写
  和通过 `xray vlessenc` 生成的 ML-KEM-768 配置继续兼容。

## 1.5.0 节点管理兼容约束

- 节点批量权限组操作复用 `POST /api/v2/admin/server/manage/batchUpdate`，新增 `group_action=add|remove` 与 `group_id` 参数；不改变节点端通信协议。
- 批量添加权限组是集合合并，批量移除权限组只删除指定组；重复调用保持幂等，未选中的节点不受影响。
- Shadowsocks 新建表单默认使用 `2022-blake3-aes-128-gcm`；该默认值只存在于管理端表单，存量节点的 `protocol_settings.cipher` 不会被迁移。
- 管理端补充界面继续由 `.docker/patch-admin-relay.php` 在构建阶段注入。上游管理端产物锚点变化时，构建应失败并更新补丁，不应跳过补丁。

## 历史发布与回滚记录

以下为 `1.8.3` 及更早版本的历史发布记录；当前版本与回滚基线见本文开头：

1. 面板 `v1.8.3` 固定到 `68a08991a6109c93285eb0653cd8d25981acc8d5`，不可变镜像 `1.8.3-68a0899`、版本别名 `1.8.3` 和 `latest` 指向 manifest `sha256:a752b4ce9006407e25b888237e84826ce6a14f9660a8ce11524135935825bfa9`；
2. 面板 [run 33644362438](https://github.com/P0me1oo/YZboard/actions/runs/33644362438) 固定来源构建成功，构建和清单验证均通过，manifest 包含 `linux/amd64` 与 `linux/arm64`；
3. 上一条 `v1.8.2` 生产基线固定到 `50c5118e4681e986adb0edd6b1c6cd1ec49e4618`，不可变镜像 `1.8.2-50c5118`、版本别名 `1.8.2` 和旧 `latest` 指向 manifest `sha256:23b4ae1782d4b08cd70931699756eae32f936f8371339a666a4f487bbfca74bd`；
4. Node `v1.13-yz.15` 固定到 `d821de890769aa20a001ca3f4ef43d24c001c48b`，Release 与镜像由 [run 33592394571](https://github.com/P0me1oo/YZboard-Node/actions/runs/33592394571) 发布；
5. Node Docker manifest 为 `sha256:f3e0895ebc04ac603158a5b96413e7695e5c7a1abd596ee864d7d4852b0b4665`，包含 `linux/amd64` 与 `linux/arm64`；
6. Node 使用 YZ-Xray-core `v26.7.11-yz.2`，固定 commit `26b01717dd8d1fd604de5e23e2868fdef59eba2f` 与 pseudo-version `v0.0.0-20260901175116-26b01717dd8d`；
7. sing-box 请求版本和实际 replacement 版本与上表一致。

上一条 `1.5.0` 镜像 manifest `sha256:0ed171a1e86709b81bd2ecaf0f1f356d0d0d2c470b9768bdcb50a20b5c9accf9` 仍保留用于回滚审计。

推送语义版本 Tag 会自动触发构建，并同时更新“面板版本 + 短 commit”的不可变标签、面板版本别名和 `latest`，中间没有确认环节。**推 `v*` Tag 等同于发布生产**，不要用它做试探性标记。手动 dispatch 仍然可用，适合对固定 Tag 或完整 commit 重新构建，那条路径的 `publish_latest` 默认关闭，需要显式勾选。生产 Compose 长期使用 `latest` 拉取更新，不可变标签和 digest 用于确认实际版本与回滚。

本仓库是 `cedar2025/Xboard` 的 fork，GitHub 对 fork 默认禁用事件触发的工作流，所以 `v1.0.6` 到 `v1.3.0` 五个 Tag 都没有触发构建，那几版实际都由手动 dispatch 发布。该限制已于 2026-07-27 在仓库 Actions 页面解除，`v1.3.1` 是第一个由 Tag 推送自动构建的版本。

回滚时，面板镜像、Node Release 和 Node 的 Xray replace 应成套退回上一条兼容矩阵记录，不要只修改其中一个版本字段。
