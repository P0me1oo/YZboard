# Mihomo 订阅兼容说明

面板 `1.12.1` 修复 Mihomo 订阅的 ECH、XHTTP，以及其他协议的字段遗漏、错误转换和内核版本判断。Node 和服务端核心依赖沿用 `1.12.0`，没有数据库变更。正式发布引用与镜像验证记录见 [兼容矩阵](../YZ_COMPATIBILITY.md)。

## 核对来源

- 面板修改基线：`4eb7238e381e8afa217cfbbea76c02f266ae1660`。
- Mihomo 源码：`fc8c5a24b16991f98cd736950c17d1aa306a5041`，核对 `adapter/outbound` 中各协议的选项结构、初始化逻辑及对应传输实现。
- Xray 字段语义：配套 YZ-Xray-core `v26.7.11-yz.6` 的 `infra/conf/transport_method.go`、`transport/internet/splithttp` 和 TLS ECH 解析逻辑。
- 服务端参数：配套 Node `v1.13-yz.22` 的 Xray、sing-box 配置生成逻辑，重点核对多路复用和 HY1 的 ALPN。
- 官方文档：[ECH 参数](https://wiki.metacubex.one/config/proxies/tls/#ech-opts)、[XHTTP 参数](https://wiki.metacubex.one/config/proxies/transport/#xhttp-opts)。

最低版本根据本地 Mihomo Git 标签中的源码确定；实际解析验证使用上面的固定提交，不把文档最新内容当成旧版内核已有能力。

## 协议核对范围

本次覆盖面板向 Mihomo 开放的 10 类节点。HY1、HY2 使用不同的 Mihomo 出站类型，因此实际解析覆盖 11 种类型。

| 协议 | 本次修复或核对结果 |
| --- | --- |
| Shadowsocks | 修复插件布尔值、默认选项、ShadowTLS 扩展及 Restls 必需字段；验证 3 种常用 AEAD、2 种 AES SS2022 算法及插件解析 |
| VMess | 补齐 WebSocket / HTTPUpgrade 请求头、TCP HTTP 请求方法、gRPC User-Agent 和可表示的 authority；核对 TLS、ECH、多路复用 |
| VLESS | 覆盖 VMess 的上述传输修复，另修复 XHTTP、Reality 默认指纹及 Encryption 版本过滤；删除无意义的 VMess 专用字段 |
| Trojan | 补齐 WebSocket / HTTPUpgrade 请求头、gRPC User-Agent、Reality 默认指纹；核对 TLS、ECH、多路复用 |
| Hysteria | 补齐 HY1 ECH、sing-box HY1 的 ALPN；HY1/HY2 的认证、带宽、跳跃端口及间隔通过解析检查 |
| TUIC | 补齐 ECH；版本 4/5 的认证、ALPN、拥塞算法和 UDP 转发模式通过解析检查 |
| AnyTLS | 补版本过滤、统一 ECH 转换；`padding_scheme` 由服务端协商下发，无需复制为客户端字段 |
| SOCKS5 | 普通和 TLS 配置通过解析；过滤当前核心无法表示的 ECH、独立 TLS 服务名 |
| HTTP | 补 TLS 服务名到 `sni`；过滤当前核心无法表示的 ECH |
| Mieru | 修复单端口与范围冲突，启用 UDP，补 `traffic-pattern` 及版本过滤；TCP、UDP 传输均验证 |

面板另有 Naive 节点类型，但当前核对的 Mihomo 没有对应出站实现，继续从其订阅协议名单中过滤。上表区分已经修复的问题与核对正常的字段，不表示每个协议都曾存在同一种错误。

## 订阅识别与版本过滤

`mihomo` 与 `meta` 均选择 Mihomo YAML 格式。请求标识中的 `mihomo/版本`、`clash.meta/版本`、`meta/版本` 可以提供内核版本，支持前缀 `v`。混合标识同时含有应用版本和明确的内核版本时，下表能力使用内核版本判断。

Clash Verge、FlClash、ClashMetaForAndroid 等应用的版本不能直接当作 Mihomo 内核版本。只给出应用版本、开发版标识没有语义版本，或使用不含版本的 `flag=meta`、`flag=mihomo` 时，保留节点和参数。现有接口的显式 `flag` 优先于 User-Agent，因此不含版本的 `flag` 也不会再从 User-Agent 推断版本。

| 能力 | Mihomo 最低版本 | 面板行为 |
| --- | --- | --- |
| 基础 Mieru | `1.19.0` | 已知内核更旧时过滤 Mieru 节点 |
| AnyTLS | `1.19.3` | 已知内核更旧时过滤 AnyTLS 节点 |
| 基础 ECH、内联公共配置 | `1.19.9` | 已知内核更旧时过滤开启 ECH 的节点 |
| VLESS Encryption | `1.19.13` | 开启且配置值非空、非 `none` 时，过滤已知旧内核 |
| 不带内联配置、通过 `query-server-name` 查询 ECH | `1.19.20` | 已知内核更旧时过滤；内联配置存在时仍按基础 ECH 判断 |
| Mieru `traffic-pattern` | `1.19.21` | 仅填写该字段时提高最低版本 |
| VLESS XHTTP 的 `auto`、`stream-one`、`stream-up`、`packet-up`，基础下载配置 | `1.19.22` | 已知内核更旧时过滤 XHTTP 节点 |
| XMUX / `reuse-settings`，固定数值 `sc-max-each-post-bytes` | `1.19.23` | 按实际输出字段判断 |
| 新填充、会话和上传字段，`h-keep-alive-period`，`sc-min-posts-interval-ms`，`sc-max-each-post-bytes` 范围值 | `1.19.24` | 按实际输出字段判断 |
| XHTTP 下载使用 HTTP/3 或 HTTP/1.1 | `1.19.24` | 按下载 ALPN 判断；无 TLS 下载也需要此版本 |

使用本文全部 ECH、XHTTP 高级参数时，客户端应使用 Mihomo `1.19.24` 或更新版本。未知版本保留节点只表示面板无法判断能力，不代表旧内核已经支持这些字段。本表记录本次按标签确认的能力；面板原有的其他客户端版本规则继续生效。

## ECH 输出

VLESS、VMess、Trojan、HY1、HY2、TUIC 和 AnyTLS 使用统一的 ECH 转换。VLESS 的 ECH 只在普通 TLS 模式输出，Reality 模式使用原有 Reality 参数。

| 面板字段 | Mihomo 字段 |
| --- | --- |
| `ech.enabled` | `ech-opts.enable` |
| `ech.config` | `ech-opts.config`，把 PEM 公共配置转换为 Base64 |
| `ech.query_server_name` | `ech-opts.query-server-name` |

关闭 ECH 时不生成该选项。开启时只输出客户端公共字段，服务端 `key`、`key_path` 和 `config_path` 不进入订阅。内联配置优先；没有内联配置时由 Mihomo 查询 HTTPS 记录。

## XHTTP 字段转换

当前核对的 Mihomo 在 VLESS 中支持 XHTTP。VMess、Trojan 的 XHTTP 以及其他不支持的传输继续按协议白名单过滤，不改写成 TCP。

读取节点的 `protocol_settings.network_settings`。存在 `extra` 对象时，遵循 Xray 的替换规则：`path`、`host`、`mode` 始终取外层；其余高级设置全部取 `extra`，不合并外层请求头或 XMUX。

| Xray 字段 | Mihomo 字段 |
| --- | --- |
| `path`、`host`、`mode`、`headers` | `xhttp-opts` 下的同名字段 |
| `noGRPCHeader` | `no-grpc-header` |
| `xPaddingBytes`、`xPaddingObfsMode` | `x-padding-bytes`、`x-padding-obfs-mode` |
| `xPaddingKey`、`xPaddingHeader`、`xPaddingPlacement`、`xPaddingMethod` | `x-padding-key`、`x-padding-header`、`x-padding-placement`、`x-padding-method` |
| `uplinkHTTPMethod` | `uplink-http-method`，按 Xray 语义转成大写 |
| `sessionIDPlacement`、`sessionIDKey` | `session-placement`、`session-key`；也接受旧的 `sessionPlacement`、`sessionKey`，两者同时出现时新字段优先 |
| `seqPlacement`、`seqKey` | `seq-placement`、`seq-key` |
| `uplinkDataPlacement`、`uplinkDataKey`、`uplinkChunkSize` | `uplink-data-placement`、`uplink-data-key`、`uplink-chunk-size` |
| `scMaxEachPostBytes`、`scMinPostsIntervalMs` | `sc-max-each-post-bytes`、`sc-min-posts-interval-ms` |
| `xmux.maxConcurrency`、`xmux.maxConnections`、`xmux.cMaxReuseTimes` | `reuse-settings.max-concurrency`、`max-connections`、`c-max-reuse-times` |
| `xmux.hMaxRequestTimes`、`xmux.hMaxReusableSecs`、`xmux.hKeepAlivePeriod` | `reuse-settings.h-max-request-times`、`h-max-reusable-secs`、`h-keep-alive-period` |
| `downloadSettings` | `download-settings`，按下一节转换 |

范围值统一输出为字符串，倒序范围按 Xray 语义交换上下界。普通开关的 `false`、连接池的 `0` 和保活间隔的 `-1` 会保留。`xPaddingBytes=0` 转为 `100-1000`，`scMaxEachPostBytes=0` 转为 `1000000`，`scMinPostsIntervalMs=0` 转为 `30`，避免把 Xray 的默认值标记交给 Mihomo 后发生拒绝或语义变化。

启用填充混淆而没有填写具体字段时，补齐 Xray 默认的 `x_padding`、`X-Padding`、`queryInHeader` 和 `repeat-x`。通过 header 或 cookie 上传时，同样补齐默认的数据字段名与分块范围。显式空 XMUX 或全零 XMUX 使用 Xray 默认连接池：6 个连接、每连接 600–900 次请求、可复用 1800–3000 秒。

`noSSEHeader`、`scMaxBufferedPosts`、`scStreamUpServerSecs` 等服务端选项不转成客户端字段。`headers` 中不能填写 `Host`；使用独立的 `host` 字段。

## 独立下载链路

`downloadSettings` 使用 Xray 的流配置结构，接受 `network=xhttp` 或 `splithttp`，从 `xhttpSettings` 或 `splithttpSettings` 读取下载传输参数。

| 下载流配置 | Mihomo 下载选项 |
| --- | --- |
| `address`、`port` | `server`、`port` |
| 下载传输的 `path`、`host`、`headers`、`xmux` | `path`、`host`、`headers`、`reuse-settings` |
| `security=none` 或空值 | `tls=false`，使用 HTTP/1.1；忽略残留的 TLS 设置 |
| `security=tls` | `tls=true`，转换 `serverName`、`allowInsecure`、`alpn`、`fingerprint` |
| `security=reality` | `tls=true`，转换服务名、指纹、`publicKey` 或 `password`、`shortId` |
| `tlsSettings.ech` | 同面板 ECH 公共字段转换 |
| `tlsSettings.echConfigList` | 支持 Base64、PEM；DNS URI 提取可选的查询域名 |

Xray 下载流与上行独立，Mihomo 的可空下载选项默认继承上行。因此下发时显式填写下载 TLS 开关、服务名、ALPN、指纹和证书验证开关；没有 ECH 或 Reality 时显式关闭，没有下载请求头时输出 `{}`，避免继承上行参数。

上行设置了 XMUX 而下载没有设置时，下载使用 Xray 默认连接池。只有下载设置 XMUX 时，也为上行补上默认连接池，以满足当前 Mihomo 创建下载连接池的条件。

ECH DNS URI 中指定的解析服务器不能直接映射到这里的订阅字段；Mihomo 仍使用自己的 DNS 配置。服务端密钥、证书私钥、Xray 的 socket 和本地路由设置不复制到客户端订阅。

以下组合会过滤对应节点，其他节点继续生成：

- `stream-one` 同时配置独立下载，或下载链路再次嵌套下载配置。
- 下载缺少有效地址、端口，或使用不能转换的传输、安全模式。
- 使用当前 Mihomo 尚无对应字段的自定义 `sessionIDTable`、`sessionIDLength`。
- 上下行需要不同的会话编号位置、字段名或填充格式，包括下载留空但 Xray 默认值与上行不同的情况；Mihomo 的下载选项不能独立覆盖这些字段。
- 下载显式覆盖其他不能独立表示的 XHTTP 高级字段，或提供不能转换的类型和数值范围。

## 旧 HTTP/2 节点

VLESS、VMess 中旧 Xray 的 `network=http` 按 HTTP/2 别名输出为 `network=h2` 和 `h2-opts`。原有 `network=tcp` 配合 HTTP 请求头伪装仍输出 Mihomo 的 `network=http` 和 `http-opts`。两种含义分别处理，避免旧节点意外退回 TCP。

## 其他传输与 TLS 参数

WebSocket、HTTPUpgrade 保留完整请求头。Host 依次取独立的 `network_settings.host`、请求头中的 Host、TLS 服务名，最后由 Mihomo 使用连接地址。Host 请求头名称不区分大小写。路径中的 `?ed=` 保留，由 Mihomo 提取 early data 配置。

TCP HTTP 伪装补齐 `header.request.method` 到 `http-opts.method`。gRPC 补齐 `user_agent` 到 `grpc-user-agent`。无 TLS 的 VMess/VLESS 可通过 `servername` 保留自定义 authority；有 TLS 时，Mihomo 的 authority 与 TLS 服务名共用字段，不能表示不同的两个名称，此类节点会过滤。没有填写 TLS 服务名时，连接主机可作为显式 authority；其他默认行为按各协议的实际实现判断。

VLESS、Trojan 的 Reality 要求客户端指纹。未指定指纹或指定 `none` 时补 `chrome`，已指定的其他指纹保留；普通 TLS 不强制添加指纹。

HTTP 代理的 `tls_settings.server_name` 输出为 `sni`。当前 Mihomo 的 HTTP、SOCKS5 出站没有 ECH 选项，SOCKS5 也没有独立 TLS 服务名；开启这些无法表示的参数时过滤对应节点，TLS 关闭时忽略残留 TLS 设置。

## Reality 传统与抗量子握手兼容

面板 `1.21.0` 自动为 VLESS、Trojan 的 Mihomo Reality 节点输出 `reality-opts.support-x25519mlkem768: true`，让客户端保留抗量子混合握手能力。不增加节点开关，普通 TLS 不添加该项；Stash 和 sing-box 订阅不添加 Mihomo 专用字段。

VLESS 分享链接自动附加 `support-x25519mlkem768=true`。链接接收端是否读取该参数取决于其实现，不能保证所有客户端都支持；Mihomo 用户优先使用原生 YAML 订阅。Trojan 分享链接沿用原有输出。

配套 YZ-Xray-core `v26.8.1` 的兼容补丁允许传统 X25519 和混合 X25519MLKEM768 客户端连接同一个节点。支持混合握手的客户端在目标站点同样支持时可以协商该算法；只支持传统握手的客户端继续使用传统算法，不是先尝试失败后再重连降级。认证失败不会因此被放行。

上游 `v26.9.9` 使用的 REALITY 版本强制要求客户端携带混合密钥项，当前已发布 Node `v1.17.0` 仍具有这一限制。新核心源码尚未自动进入已发布 Node，部署时必须使用包含补丁的新 Node 构建，不能仅更新面板。

旧草稿中“老内核开启混合握手必然失败”的结论不成立，不能据此要求所有旧节点关闭参数。当前验证针对兼容补丁和固定客户端版本；未覆盖的历史客户端或核心组合不作连通性保证。具体测试与发布状态见 [兼容矩阵](../YZ_COMPATIBILITY.md)。

## Shadowsocks 插件

插件存在而选项为空时，仍生成该插件需要的默认客户端选项。支持转换 `obfs` / `obfs-local`、`v2ray-plugin`、`gost-plugin`、`shadow-tls`、`restls`，并保留 `kcptun` 选项。未知插件从订阅中过滤。

- `v2ray-plugin` / `gost-plugin` 保留 `tls=false`、`mux=0` 等布尔值，`server` 标志不再误启用 TLS；补齐证书验证和指纹选项，v2ray-plugin 另保留 HTTPUpgrade 开关。
- ShadowTLS 补齐 `fingerprint`、`skip-cert-verify`、逗号分隔的 `alpn`。
- Restls 必须提供 `host`、`password`、`version-hint=tls12` 或 `tls13`；缺失或无效时过滤节点。未指定脚本时由核心使用默认脚本，删除原来固定写入的无效脚本。

解析通过仅说明 Mihomo 可以加载客户端插件配置。服务端仍需实际部署对应插件，配套 Node 的协议入站本身不能代替外置插件。

## Mieru、多路复用与 HY1

Mieru 的 `port` 与 `port-range` 互斥；配置范围时只输出范围，同时输出 `udp=true`，使 Mihomo 对外声明节点支持 UDP。`traffic_pattern` 转成 `traffic-pattern`。面板通用的 sing-box 多路复用对象不等同于 Mieru 自身的 `multiplexing` 枚举，不直接套用。

VMess、VLESS、Trojan 的 `smux` 仅在节点实际使用 sing-box 时输出。配套 Node 的 Xray 后端不应用这套多路复用配置。中转逻辑节点按入口内核生成客户端选项，落地节点在数据库中的内核选择不变。

配套 Node 的 sing-box HY1 入站显式使用 `h3`，Mihomo HY1 默认使用 `hysteria`；此类节点下发 `alpn: [h3]`，使两端一致。HY2 保持其原有默认行为。当前配套 Node 的 Xray 后端不创建 HY1 入站，HY1 服务需要使用 sing-box。

## 验证记录

2026-09-08 本地验证结果：完整 PHPUnit 共 105 项测试、1693 个断言通过；扩展核对中，实际 PHP 生成的 68 组配置全部通过固定 Mihomo 源码的 `adapter.ParseProxy`，覆盖 11 种出站类型、226 个结构解码字段检查。ECH 公共配置同时通过 `echparser.ParseECHConfigList` 检查，并检查 Reality 指纹和 Mieru UDP 能力。修改文件的 PHP 语法检查和 Git 空白检查通过。

ECH/XHTTP 专项此前另有 32 组配置、175 个字段检查通过实际解析，两轮样例部分重叠，不累计为独立用例总数。面板回归覆盖 ECH 开关和版本边界、混合客户端标识、XHTTP 四种模式、高级参数与默认值、`extra` 优先级、下载 TLS / ECH / Reality / ALPN / 空请求头 / XMUX、错误节点隔离、重复生成和 HTTP/2 别名。

扩展回归覆盖完整 WebSocket 请求头、Host 优先级、gRPC authority 的 TLS 开关和默认行为、TCP HTTP 请求方法、HTTP TLS 服务名、Shadowsocks 插件开关及 Restls 必需参数、Mieru 范围和流量模式、Reality 指纹、sing-box 多路复用、中转内核投影、HY1 ALPN，以及新增版本边界。

运行面板回归测试：`php vendor/bin/phpunit --do-not-cache-result`。Windows 本次使用 PHP `8.4.21`，通过命令行显式加载 `pdo_sqlite` 和 `sqlite3`，数据库使用内存 SQLite。Mihomo 解析程序通过 `go run -mod=readonly` 执行，不修改核心仓库。

测试身份和 ECH 密钥在运行时生成；解析用 YAML 只通过进程管道传递。验证没有连接实际节点，也没有验证公网 DNS ECH 记录或真实上下行转发，不能把配置解析通过当作所有线路已经完成握手。

`v1.12.1` 已正式发布，固定来源为 `0f2b708cdd5c7bdfc346831356f12712e3ed37a3`。
[发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34241282162) 完成 `linux/amd64`、`linux/arm64` 构建；镜像 `ghcr.io/p0me1oo/yzboard:1.12.1-0f2b708`、版本别名和 `latest` 的摘要一致，两架构的来源、版本及匿名拉取已核验。
镜像摘要与 `1.12.0` 回滚引用见 [兼容矩阵](../YZ_COMPATIBILITY.md)。
