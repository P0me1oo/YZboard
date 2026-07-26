# 中转节点端到端验收报告

本报告记录“单一 VLESS 入口、多逻辑节点、多落地出口”功能的远程验收结果。
所有测试凭据、订阅、密钥和节点身份均为一次性测试数据，验收结束后已删除，本文不含任何认证信息。

验收日期：2026-07-26 至 2026-07-27。

## 一、被测版本

| 项目 | 标识 |
| --- | --- |
| YZboard 面板版本 | `1.1.0`（运行容器内 `config/app.php` 已确认） |
| YZboard 基线 commit | `78e9d81`（`1.0.6` 发布记录）+ 本次中转功能工作区改动 |
| 测试镜像 | `yzboard-relay-test:local`，镜像 ID `sha256:6cf1234891d4c1ca52e7766fad430973792844014b855e3addf00115944972ee` |
| 测试镜像构建方式 | `FROM ghcr.io/p0me1oo/yzboard:latest`（面板 `1.0.6`）叠加 `app/`、`database/`、`config/` |
| YZboard-Node 版本串 | `v1.13-yz.5-test-f4d8552-dirty`（构建时间 `2026-07-26T14:33:32Z`，commit `f4d8552`） |
| Node 二进制 SHA-256 | `1392b9a189287bff5aa3f3144a160a694270e3face234e6993eaafb57bf6506b` |
| Xray 内核 | fork `v26.7.11-yz.1`，上游 `v26.7.11` @ `50231eaff98ccc31b5cbd247a721c16e97fe5ec1`，fork commit `620bee93867095f73880056cdfb08bc54a15f69e` |
| Xray 模块解析 | `github.com/P0me1oo/YZ-Xray-core@v0.0.0-20260724203739-620bee938670` |
| sing-box | 请求 `v1.13.2`，实际 `v1.14.0-alpha.2.0.20260316103356-2e665cb7e295`（本次未使用） |
| 客户端 | mihomo Meta `v1.19.29` linux/amd64（go1.26.5） |

Node 二进制版本串带 `-dirty`，因为构建源包含尚未提交的工作区改动；三台服务器上的二进制 SHA-256 一致。

## 二、测试角色与隔离范围

| 服务器 | 角色 | 系统 / 架构 |
| --- | --- | --- |
| Cloudnium-US（`192.255.175.211`） | 隔离测试面板 + 真实入口节点 A | Debian 12，x86_64，2 GB 内存 |
| 独角鲸-DE（`46.101.205.124`） | 独立落地服务器 B | Debian 13，x86_64，8 GB 内存 |
| 独角鲸-US（`138.68.47.103`） | 独立测试客户端（mihomo） | Debian 13，x86_64 |

隔离资源：

| 项目 | 取值 |
| --- | --- |
| 面板目录 | `/opt/xboard-test/panel`（已删除） |
| 节点目录 | `/opt/xboard-test/node`（保留二进制） |
| 客户端目录 | `/opt/mihomo-xboard-test`（保留二进制） |
| Compose 项目 | `yzboard-relay-test`（独立容器、网络、卷） |
| 面板数据库 | 容器内 SQLite，独立卷内 Redis |
| 面板监听 | `18081/tcp`，仅通过 `DOCKER-USER` 放行落地与客户端两个来源 IP |
| 入口客户端端口 | `24443/tcp`，临时 `ufw allow` |
| 内部中转端口 | 落地 `28388/tcp+udp` |
| 客户端本地入口 | `127.0.0.1:19080`（mixed）、`19081`（socks）、`19090`（控制器） |
| systemd 单元 | `xboard-node-relay-test.service`（两台节点，现为禁用状态） |

未改动任何既有服务：Cloudnium-US 的 `nodeget-agent` 与 `panstar-checkin` 容器、独角鲸-DE/US 的 `narwhal-agent`、`rfw`、`podman` 及 8 个既有容器全程保持运行。客户端未启用 TUN，未修改系统代理与默认路由。

## 三、测试拓扑

- 节点 A：VLESS 入口，父级节点为空，绑定 Cloudnium-US，倍率 2，订阅可见。
- 节点 B：Shadowsocks 逻辑节点，父级节点选择 A，绑定独角鲸-DE，节点自身倍率填 9（用于验证不生效），订阅可见。
- 权限组：`relay-test-full`（可用 A、B）与 `relay-test-entry-only`（只可用 A）。
- 测试用户一名，套餐 100 GiB。

## 四、订阅与入口验证

面板自动分配路由编号：A = `1`，B = `2`，均在 1–65535 范围内，节点改名与协议调整后保持不变。

mihomo 订阅（`clash-verge/mihomo` UA）输出两个可直接选择的普通节点：

| 项目 | 节点 A | 节点 B |
| --- | --- | --- |
| 类型 | `vless` | `vless` |
| 服务器 | `192.255.175.211` | `192.255.175.211` |
| 端口 | `24443` | `24443` |
| Reality 公钥 / 短标识 / SNI | 相同 | 相同 |
| 传输方式 / 浏览器指纹 | `tcp` / `chrome` | `tcp` / `chrome` |
| 名称 | `RelayTest-A-Entry` | `RelayTest-B-Landing-DE` |
| UUID 第三段（路由编号） | `0001` | `0002` |

UUID 其余部分与面板用户的原始 UUID 完全一致，只有第 7、8 字节被替换。

订阅泄漏检查：落地地址 `46.101.205.124`、内部端口 `28388`、`shadowsocks`、`2022-blake3`、`dialer-proxy` 命中数均为 0；`proxies` 恰好 2 个，无 `type: ss` 条目，无前置/落地/代理链节点。

入口单入站验证（Cloudnium-US）：

- 监听套接字：仅 `0.0.0.0:24443/tcp` 一个客户端监听；
- Xray 入站计数器：仅 `inbound>>>vless-in`；
- Xray 出站计数器：`direct`、`block`、`relay-2`；
- 路由规则：`vlessRoute "1" → direct`、`vlessRoute "2" → relay-2`。

节点 B 没有在 Cloudnium-US 上产生第二个相同端口的客户端入站。

## 五、出口验证

客户端通过 mihomo 控制器切换 `GLOBAL` 选择器，分别用三个独立公网地址检测源确认：

| 选择的逻辑节点 | api.ipify.org | icanhazip.com | ifconfig.me | 结论 |
| --- | --- | --- | --- | --- |
| A | `192.255.175.211` | `192.255.175.211` | `192.255.175.211` | 出口为 Cloudnium-US |
| B | `46.101.205.124` | `46.101.205.124` | `46.101.205.124` | 出口为独角鲸-DE |

客户端自身直连出口为 `138.68.47.103`，与两者均不同。

UDP 验证使用 SOCKS5 UDP ASSOCIATE 发送 STUN 绑定请求，读取 XOR-MAPPED-ADDRESS（即真实 UDP 出口）：

| 选择的逻辑节点 | UDP 出口 |
| --- | --- |
| A | `192.255.175.211` |
| B | `46.101.205.124` |

TCP 与 UDP 都完成了真实请求，而非仅端口连通性检查。

落地服务器日志直接印证链路：`from 192.255.175.211:<port> accepted api.ipify.org:443 [relay-in >> direct]`，即流量由入口服务器 IP 进入内部 `relay-in` 入站，再由落地本机直接出网。入口通过独立 Shadowsocks 出站连接落地，未使用 Realm、GOST、NodePass、WireGuard 或 iptables 端口转发，落地也未开启系统级转发或出口地址转换。

## 六、流量与倍率验证

测试数据：通过逻辑节点 B 下载 10 MiB × 10 = **104,857,600 字节（100 MiB）**，请求头禁用缓存，每次实际下载字节数均为 10,485,760。

| 指标 | 测试前 | 测试后 | 增量 |
| --- | --- | --- | --- |
| 用户已用流量 | 87,266 | 210,292,584 | **210,205,318 B（200.47 MiB）** |
| 入口 A 节点流量 | 43,633 | 105,146,292 | **105,102,659 B（100.23 MiB）** |
| 逻辑节点 B 节点流量 | 24,361 | 105,195,182 | **105,170,821 B（100.30 MiB）** |

结论：

- 用户原始流量只在入口 A 按真实 VLESS 用户身份统计一次，为 100.23 MiB，与实际下载的 100 MiB 相符（差值来自协议开销）。
- 用户扣除流量 ÷ 入口原始流量 = **2.000000**，两者相差 **0 字节**，即扣费严格等于「入口原始流量 × 入口倍率 2」。
- 若错误地把入口与落地流量相加后再乘倍率，结果应为 401.06 MiB；实际为 200.47 MiB，证明未重复计费。
- 逻辑节点 B 的 100.30 MiB 通过入口上 `relay-2` 出站独立统计，按稳定出站标签回写到节点 B，未进入用户套餐扣费。
- B 的出站统计比 A 的用户统计多 68,162 字节（**0.065%**），来自 Shadowsocks 协议封装，属于不同统计口径。
- 节点 B 自身填写的倍率为 9，但读取到的有效倍率为 **2**，即强制继承入口 A，未单独生效，也未与 A 相加或相乘。

## 七、权限、幂等与生命周期

| 测试项 | 结果 |
| --- | --- |
| 有 B 权限的用户订阅 | 同时包含 A 与 B |
| 切换到无 B 权限的组 | 订阅只剩 A，A 仍正常显示 |
| 切回原权限组 | A、B 恢复 |
| 连续两次重启入口节点服务 | 监听端口 1 个、`inbound>>>vless-in` 1 个、`outbound>>>relay-2` 1 个，无重复入站/出站/路由 |
| 停止落地内部服务 | 节点 B 不可用，节点 A 出口仍为 `192.255.175.211`，不受影响 |
| 恢复落地内部服务 | 节点 B 出口恢复为 `46.101.205.124` |
| 禁用逻辑节点 B | 订阅中 B 消失；入口出站只剩 `direct`、`block`（`relay-2` 及其路由规则消失）；入口真实入站与监听端口不受影响 |
| 重新启用 B | 订阅恢复；出站标签仍为 `relay-2`、路由编号仍为 `2`（编号稳定） |
| 删除逻辑节点 B | 订阅只剩 A；入口无 `relay-2` 残留；入口入站与直接出口正常；落地节点上报被面板以“节点不存在”拒绝 |

## 八、未通过项、环境限制与剩余风险

### 1. 测试环境的 REALITY 握手失败（已确认属于测试环境问题，非版本不兼容）

> **后续更正（2026-07-27）：** 生产环境已确认 mihomo 客户端连接 Xray `v26.7.11` 的
> VLESS + REALITY 节点工作正常。因此下面记录的握手失败**只发生在本次的隔离测试环境**，
> 不是 mihomo 与 Xray `v26.7.11` 的版本级不兼容。原始排查记录保留如下供参考，测试环境
> 与生产的差异点包括：当场生成的 Reality 密钥对、非标准端口 `24443`、全新安装的隔离面板。
> 具体是哪一项导致握手失败仍未定位，但它不影响生产，也不影响中转功能。

初始配置下入口 A 使用 VLESS + REALITY，mihomo `v1.19.29` 连接失败：

- 客户端报 `connect error: EOF`；
- 入口 Xray 报 `REALITY: processed invalid connection ... handshake did not complete successfully`；
- 用普通 TLS 客户端探测入口端口，REALITY 正确回落到 `dest`，返回真实 `www.microsoft.com` 证书且验证通过，说明 REALITY 入站本身工作正常、`dest` 可达；
- 面板生成的公私钥经独立计算确认为匹配的 X25519 密钥对（用私钥做 `scalarmult_base` 得到的公钥与订阅下发的一致）；
- Node 写入的 `realitySettings` 为 `minClientVer: "0.0.0"`（Node `yz.4` 起显式下发该默认值），`serverNames`、`shortIds` 与订阅一致；
- 服务端两侧的 `shortId` 解析一致：Xray 用 8 字节缓冲区 `hex.Decode`，mihomo 用 `[8]byte` 零值 `hex.Decode`，`909f6787` 在两边都得到 `{90,9f,67,87,00,00,00,00}`；
- mihomo 的公钥解析为 `base64.RawURLEncoding` 且校验长度 32 字节，与面板下发格式一致；
- 尝试为客户端补上 `reality-opts.support-x25519mlkem768: true` 仍失败。

**根因尚未定位。** 按 `github.com/xtls/reality`（2026-03-22）`tls.go` 的服务端逻辑，认证成功需要依次满足：SNI 命中 `serverNames`、协商到 TLS 1.3、取到客户端 X25519 公钥、`aead.Open` 解密成功，最后再检查 `minClientVer`/`maxClientVer`/`maxTimeDiff`/`shortIds`。由于本次 `minClientVer` 实际下发的是 `0.0.0`，`maxClientVer` 未设置、`maxTimeDiff` 为 0，且 `shortId` 两边一致，**最后一组条件不构成拒绝原因**；失败只能发生在更靠前的环节（SNI/TLS 版本/公钥选取/AEAD 解密），具体是哪一步本次未能确认。

需要注意：不能把内置下限 `26.3.27` 当作本次的原因——该默认值只在配置未填 `minClientVer` 时生效，而 Node 已显式写入 `0.0.0`。

待办的定位手段：Xray 的 `realitySettings.show` 为 `true` 时会打印 `ClientVer`、`ClientTime`、`ClientShortId` 和 `hs.c.conn == conn`，可直接区分是解密失败还是末尾条件失败。当前 Node 把该字段硬编码为 `false`，需要先让它可配置（例如新增 `kernel.reality_show`）再复测。

处理方式：为不阻塞功能验收，把入口 A 临时切换为不加密的 VLESS over TCP（`tls=0`，不使用 `xtls-rprx-vision`），其余配置不变，随后全部功能项通过。中转能力位于 VLESS 协议层，与 TLS/REALITY 层解耦，因此该替换不影响本次结论的有效性；REALITY 参数在订阅中的继承已在第四节单独验证。

**剩余风险**：本次未在 REALITY 传输下跑通实际流量。生产已确认 REALITY 正常，因此不构成上线阻塞；但严格说中转链路在 REALITY 之上的真实运行仍属「未实测」，首次在生产配置中转时建议先用一个测试用户验证再放开权限组。

### 2. 客户端保留旧订阅时的行为

禁用逻辑节点 B 后，仍持有旧订阅的客户端选择 B，流量不会失败，而是落到入口的默认 `direct` 出站，从入口 A 出网（不再经过落地）。这与第一版“不处理用户保留旧配置或手工修改路由字节绕过订阅权限”的范围声明一致，且不会泄漏落地信息、不影响入口。若需要严格拒绝，应在后续版本增加按用户校验路由编号的能力。

### 3. 本地测试环境限制

- 本地 Windows 环境缺少 C 编译器，`go test -race` 无法执行；已执行非竞态全量测试、`go vet` 与 `linux/amd64`、`linux/arm64` 交叉构建。发布前建议在 Linux 上补跑 `go test -race`。
- 本地 PHP CLI 默认未启用 `pdo_sqlite`，测试通过命令行参数临时加载扩展执行，未修改用户的 `php.ini`。

### 4. 其他

- 本次验收使用 SQLite 与容器内置 Redis 的最小化面板部署，未覆盖 MySQL/PostgreSQL 与外部 Redis 组合。
- 入口服务器内存仅 2 GB，面板与节点同机运行时余量较小；生产部署应分离或提高配置。
- 面板与落地之间的 API 通道在测试期间通过 `DOCKER-USER` 限定来源 IP 放行，属于临时测试配置，已在验收后撤销。

## 九、清理结果

按工作区“保留复用”模式执行：

| 服务器 | 已停止/删除 | 保留 |
| --- | --- | --- |
| Cloudnium-US | 服务已停止并禁用；面板容器、网络、卷、测试镜像已删除；面板目录（含 SQLite 库、`.env`、测试用户/节点/Token/UUID/Reality 私钥/SS 凭据）、节点配置、订阅文件、构建目录与一次性脚本已删除；`ufw` 的 `24443/tcp` 与全部 `DOCKER-USER` 临时规则已撤销 | Node 二进制、隔离目录结构、禁用状态的 service 单元 |
| 独角鲸-DE | 服务已停止并禁用（失败状态已重置）；节点配置与一次性文件已删除；内部入站端口不再监听 | Node 二进制、隔离目录结构、禁用状态的 service 单元 |
| 独角鲸-US | mihomo 进程已停止；订阅、配置、缓存与日志已删除；本地测试端口不再监听 | mihomo 二进制与 geoip 数据库 |

清理后复核：三台服务器的既有服务与容器全部正常，Cloudnium-US 仅剩 `panstar-checkin` 容器与 `nodeget-agent`，独角鲸-DE 的 8 个既有容器与 `narwhal-agent`、`rfw`、`podman` 均正常，独角鲸-US 无代理环境变量、默认路由未改动。
