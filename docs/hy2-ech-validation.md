# HY2 ECH 前置入口验证记录

日期：2026-09-08。面板源码版本为 `1.12.0`，配套 Node 源码版本为 `v1.13-yz.22`。
本页记录本地源码与构建验证；正式发布来源、镜像和后续 CI 结果以兼容矩阵中的发布记录为准。

## 固定基线与修改范围

| 项目 | 本次基线 |
| --- | --- |
| YZboard | `f5d5075baf9592080a9bd0a1dba8c492f3684cc3`，`master` 工作区 |
| YZboard-Node | `0066db507d5fe26698528175d69e30522fa2f4ce`，`upgrade/singbox-v1.14.0` 工作区 |
| YZ-Xray-core | `v26.7.11-yz.6` / `b4caa82d6414196565599c19ebc1b53e331349b6` |
| sing-box | `v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427` |

面板原先在 `ServerRelayService::validateEntrySettings` 中直接拒绝 HY2 ECH，导致节点不能成为前置入口候选，
已关联的逻辑节点也无法正常投影。Node 同样有明确的中转拒绝规则，且 Xray 的 HY2 入站单独生成 TLS 配置，
没有调用已有的 ECH 密钥转换逻辑。固定 Xray 核心已能通过 HY2 的 TLS 配置处理 ECH，本次没有修改核心或依赖版本。

本次改为允许密钥来源完整的 HY2 ECH 入口，并补齐 Node 的 `tlsSettings.echServerKeys`。
Mihomo HY2 订阅增加 `ech-opts`，sing-box 沿用已有的 `tls.ech`。逻辑节点的认证路由、落地协议、倍率、
用户流量和落地流量报告结构保持兼容。HY1、无效混淆、无效内部协议组合及 VLESS 中转的既有限制继续生效。

## 已完成的验证

| 验证项 | 结果与范围 |
| --- | --- |
| 面板全量测试 | 60 项测试、813 个断言通过 |
| 入口候选与保存 | Xray／sing-box 的 HY2 ECH 入口可成为候选，接受两种内核的 SS2022／VLESS 落地；缺少 ECH 密钥时仍拒绝 |
| 配置与订阅 | 密钥正确下发给入口；两种订阅保留路由认证、入口地址和公共 ECH 参数，不输出服务端 ECH 密钥 |
| 客户端版本 | 识别为 `meta` 且版本低于 `1.19.9` 时过滤 HY2 ECH；未知版本保留参数，应用外壳版本不作为 Mihomo 内核版本 |
| 管理端资源 | 完整资源副本打补丁成功，重复执行结果一致，JavaScript 语法检查通过；候选使用后端 `relay_entry_supported` |
| Node 全量测试 | 五个发布功能标签下的 `go test ./...` 通过 |
| 固定兼容模块 | AnyTLS、SS2022、Xray singbridge／HY2、sing-box gRPC／SS2022 六组测试通过 |
| ECH 密钥来源 | Xray 的内联 PEM、Base64、文件三种来源均完成真实 QUIC 握手，`TLS.ECHAccepted=true` |
| 中转组合 | 两种入口内核 × 两种落地内核 × 开关 Salamander，共八组；每组均验证直出、SS2022、VLESS 的 TCP/UDP |
| 客户端联测 | sing-box `1.14.0-yz.2` 与官方 Mihomo `1.19.9` 均完成中转；Mihomo 参数直接来自面板 `ClashMeta::buildHysteria` |
| 错误与生命周期 | 错误 ECH 公钥被拒绝；用户增删、重复同步、ECH 密钥与端口变更、停止恢复后选路正常 |
| 流量 | 用户与用户-线路计数按有效载荷精确累计，重载和恢复不丢失，落地不重复计入用户套餐流量 |
| Linux 构建 | Node 与 xbctl 的 `linux/amd64`、`linux/arm64` 四个开发构建通过；固定模块替换、架构及 VCS 信息已核对 |

所有握手和转发在 Windows 本机回环地址完成，测试使用临时生成的证书、ECH 密钥和身份。
正确配置的客户端校验证书，没有通过关闭证书验证来制造握手成功。失败用例检查 ECH 拒绝状态，
并用 Mihomo 的错误公共配置验证客户端不会忽略 ECH 参数后继续中转。

## 复现命令

面板在本机 PHP 8.4.21 下执行：

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium vendor/bin/phpunit --stop-on-error --stop-on-failure
```

首次直接运行 `php vendor/bin/phpunit tests/Feature/Server/ServerRelayTest.php` 时，33 项测试因
`could not find driver` 无法使用 SQLite 内存数据库。所需扩展已安装，改为只在测试进程加载后通过，
没有修改全局 PHP 配置或测试断言。

Node 的普通全量与 ECH 专项命令见 Node 仓库的 `docs/hy2-ech-validation.md`。
跨仓库联测通过环境变量指定本地 Mihomo 可执行文件与面板根目录，临时配置经标准输入交给面板构建器，
认证信息不进入命令行参数或报告。

## 验证限制与发布安排

- 本机默认 `CGO_ENABLED=0`，且没有可用的 C 编译器，`go test -race` 无法运行。普通测试通过不代表完成了数据竞争检测。
- Linux 两种架构完成交叉构建，本次没有执行 Linux 上的实际转发，也没有进行真实服务器测试。
- 实际 ECH 握手使用内联公共配置；DNS 查询域名的订阅字段已验证，外部 DNS HTTPS 记录的部署未验证。
- 通用 HY2 URI、v2rayN、Shadowrocket 等格式本次没有新增未经确认的 ECH 字段；使用已验证的 sing-box JSON 或 Mihomo YAML 订阅。

开发构建带有 `vcs.modified=true`，不作为正式发布产物。正式发布需在提交源码后按固定 Tag／commit 重新构建，
先升级 Node，再升级面板并刷新客户端订阅。若回退到 Node `v1.13-yz.21`／面板 `1.11.0`，
需要先关闭 HY2 入口的 ECH 并刷新订阅，旧版本会拒绝该 ECH 中转拓扑。
