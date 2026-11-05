# VLESS XHTTP 支持审查

> 后续修复：面板 2.3.0、管理端 0.88.0、Agent v2.32.0 已在本地完成以下问题的配置适配，原问题描述保留为检查记录。当前用法见 [VLESS XHTTP](vless-xhttp.md)，验证状态见[兼容矩阵](../YZ_COMPATIBILITY.md)。尚未发布或部署。

检查日期：2026-11-05。项目维护者：`P0me1oo`。

## 结论与范围

当前 Xray 核心具备完整的 XHTTP 传输和独立下载能力，Node 能下发这些配置。面板端的编辑、参数保存、订阅转换和校验尚不完善，不能据此认定所有客户端和配置组合都能正确工作。

本次检查本地工作区源码及其锁定依赖，参考 `/root/YZboard/3x-ui`；只启动本机环回地址的临时测试实例，没有检查或变更生产节点。面板和 Dash 已有其他任务的未提交修改，本次保留这些修改，仅新增本文。

| 项目 | 检查来源 |
| --- | --- |
| YZboard | 工作区 HEAD `30d60d924fc6142ae8d1d07f2510474cd7b71def` 加现有未提交修改；配置版本 `2.2.0` |
| YZboard-Dash | 工作区 HEAD `34186f91fed775f604135b330e9ccee886eb786e` 加现有未提交修改；配置版本 `0.87.0` |
| YZ-Agent | `27be804d76d1f713b5d9d80fd7455b5aa52eb8ee` |
| Node 实际 Xray 依赖 | `github.com/P0me1oo/YZ-Xray-core v0.0.0-20261103192507-3c480b0c0d73`；上游版本标识 `26.10.31`，fork 标识 `v26.11.1` |
| 3x-ui 参考 | `83ea59fb693fee1815d867dff4d33028ddaf12a9` |

## 上下行分流的实际边界

| 能力 | 结果 |
| --- | --- |
| XHTTP `auto`、`packet-up`、`stream-up`、`stream-one` 基础传输 | 本机 VLESS 数据往返通过；另验证了 TLS 上的 XHTTP 数据往返 |
| `auto`、`packet-up`、`stream-up` 加独立 `downloadSettings` | 本机两个入口的实际传输通过；上传入口只有上传请求，下载入口只有下载请求 |
| `stream-one` 加独立下载 | 核心明确拒绝，属于模式限制 |
| 上下行指向两套独立 XHTTP 入站 | 本机负向测试未能完成传输；入站各自持有会话，不能直接拼接 |
| Mihomo 订阅 | 已实现独立下载的地址、端口、TLS、Reality、ECH、请求头和连接池转换；有客户端能力与版本限制，本次仅源码核对 |
| 通用 VLESS URI | 放在 `extra` 内的下载配置有输出，但存在下述空对象问题；放在外层的下载配置会遗漏 |
| Shadowrocket 专用订阅 | 只输出 XHTTP 路径、Host、模式，没有输出下载配置和其他高级参数 |
| sing-box 客户端 | 当前订阅过滤 XHTTP，不能作为 XHTTP 客户端使用 |
| Node 运行内核 | 使用 Xray；机器模式能针对 XHTTP 自动由 sing-box 切换为 Xray，独立配置模式会拒绝不兼容内核 |

分流可以使用不同的连接地址、端口和前端 TLS 配置，但上下行最终需要到达**同一个 XHTTP 入站**。不同路径或 Host 还需由反向代理正确重写，匹配该入站的路径、Host 和会话参数。HTTP/3 可由外部入口终止；当前 Node 的原生 HTTP/3 配置缺口见下文。

代码依据：核心 `transport/internet/splithttp/dialer.go` 的独立下载拨号、`hub.go` 的入站会话表，以及 `infra/conf/transport_method.go` 的模式限制。

## 需要修正的问题

### 1. 新建默认 Vision 在切换 XHTTP 后保留，连接失败

严重程度：P1，影响默认操作路径。

新建 VLESS 默认设置 `flow=xtls-rprx-vision`。选择 XHTTP 后，表单只调整 TLS/Reality 可选组合，没有清除或限制 Vision；Node 也直接把 Flow 写入客户端账户。未启用 VLESS Encryption 时，XHTTP 返回的连接不属于 Vision 要求的直接 TLS/Reality 连接。

本机对照结果：相同 TLS XHTTP 配置关闭 Vision 可以传输，开启 Vision 后连接失败。这个结论限定于未启用 VLESS Encryption；当前核心对 Encryption 的连接封装有另一条处理路径，不能把所有 Encryption 组合一概判为不支持。

位置：Dash `src/features/nodes/NodeEditor.jsx:434`、`VlessFields.jsx:71` 和 `VlessFields.jsx:480`；Node `internal/kernel/xray/config.go:326`；核心 `proxy/vless/outbound/outbound.go:262`。

建议：按传输、安全和 Encryption 的实际组合校验 Flow，切换传输时同步处理默认值；后端和 Node 保留相同约束。

### 2. 通用订阅没有还原空对象，当前核心解析失败

严重程度：P1，影响模板及常见高级配置。

面板将协议设置按 PHP 关联数组保存，空的 `headers`、`tlsSettings`、`sockopt` 会成为 `[]`。通用订阅直接 `json_encode(extra)`，没有按 Xray 字段类型还原为 `{}` 或删除不必要的空字段。

Node 已递归删除 `extra` 内的空数组，因此可能出现服务端可应用、客户端配置不能解析的差异；Node 对没有 `extra` 的外层空 `headers` 也没有进行同样处理。

使用相同 JSON 形态交给 Node 锁定的实际核心，分别复现了 `headers` 数组无法转换为字符串映射，以及 `downloadSettings.tlsSettings` 数组无法转换为 TLS 配置。PHP 的保存和输出路径由源码确认，本次未执行 PHP 端到端请求。

位置：面板 `app/Models/Server.php:440`、`app/Protocols/General.php:248`；Node `internal/kernel/xray/relay.go:263`、`config.go:906`。

建议：在统一的 XHTTP 参数转换层按字段类型处理空对象，并供 Node 下发和各订阅输出使用，避免两端处理不同。

### 3. 外层高级参数在通用订阅和中转配置中丢失

严重程度：P1，影响参数对应和分流。

核心允许将 `sessionIDPlacement`、`sessionIDKey`、填充参数、`xmux`、`downloadSettings` 等直接放在 `network_settings` 外层。普通 Node 会保留这些字段，Mihomo 转换也支持外层参数；但通用 URI 只复制路径、Host、模式和已有 `extra`，没有将外层高级字段装入订阅 `extra`。

VLESS 中转生成客户端配置时，XHTTP 白名单只有 `path`、`host`、`mode`、`headers`、`extra`，其他外层字段被删除。服务端使用自定义会话位置而客户端回到默认路径时，连接无法对应；下载配置放在外层时，客户端不会启用所配置的分流。

另一个使用边界由核心确认：只要存在 `extra`，除外层 `path`、`host`、`mode` 外，高级参数全部取自 `extra`，不是逐字段合并。混合填写需要避免误以为外层高级参数仍生效。

位置：面板 `app/Protocols/General.php:242`、`app/Services/ServerRelayService.php:454`；核心 `infra/conf/transport_method.go:318`。

建议：统一计算核心实际生效的 XHTTP 配置，再提取客户端字段；不能只复制现有 `extra`。3x-ui 的 `internal/sub/service.go` 中 `buildXhttpExtra` 和 `applyXhttpExtraParams` 可参考。

### 4. Shadowrocket 专用订阅缺少分流与高级参数

严重程度：P1，使用该订阅格式时影响能力。

VLESS XHTTP 分支只输出 `obfs`、`path`、`obfsParam` 和 `mode`，没有输出 `extra` 或下载配置。服务端设置高级参数并不表示这个客户端能获得对应配置。

位置：面板 `app/Protocols/Shadowrocket.php:265`。

建议：先核对目标客户端实际支持的格式；支持时补足对应参数，不能表达时按能力过滤节点，避免输出缺参数的节点。

### 5. 模板包含无效下载地址，保存校验不足

严重程度：P1，影响模板直接使用和错误配置处理。

默认 XHTTP 模板无条件包含 `downloadSettings.address=""`，并非可直接使用的普通 XHTTP 示例。当前核心保留空地址；Mihomo 转换会因地址无效过滤节点。把模板模式改为 `stream-one` 后，核心配置构建直接失败。

前端 JSON 编辑器主要检查 JSON 是否有效、结果是否为对象；后端只声明 `network_settings` 为数组。Node 的普通节点校验未检查 XHTTP 细项。本机复现了非法模式、`stream-one` 加下载配置、XMUX 同时设置非零 `maxConnections` 与 `maxConcurrency` 均被 Node 校验放行、随后被核心拒绝。

位置：Dash `src/features/nodes/JsonTemplateDialog.jsx:131`；面板 `app/Http/Requests/Admin/ServerSave.php:148` 附近的 VLESS 规则；Node `internal/model/validate.go:75`。

建议：普通模板默认不含独立下载；分流单独提供模板或表单，要求有效下载地址与端口。保存前校验模式、字段类型、互斥项和组合限制。

### 6. 旧会话字段和连接池默认值没有完全跟随核心

严重程度：P2，影响高级配置语义。

- 默认模板仍写入当前核心已不识别的 `xmux.cMaxLifetimeMs`，没有设置替代的 `hMaxReusableSecs`。实际构建得到可复用时间 `0-0`，旧字段不生效。
- Mihomo 转换的空连接池默认仍为 6 个连接，当前核心已经默认 3 个；通常不阻断连接，但不是相同策略。
- 当前核心只识别 `sessionIDPlacement`、`sessionIDKey`。Node 没有迁移旧 `sessionPlacement`、`sessionKey`；实际构建回到默认 `path`。Mihomo 转换仍识别旧字段，可能造成两端语义不同。

位置：Dash `src/features/nodes/JsonTemplateDialog.jsx:127`；面板 `app/Support/MihomoXhttp.php:57`；核心 `infra/conf/transport_method.go:278` 和 `:462`。

建议：同步模板和默认连接池，明确兼容基线；在保存、下发和订阅的公共转换入口迁移旧会话字段。3x-ui 的 `frontend/src/schemas/protocols/stream/xhttp.ts` 已有相关迁移和当前 XMUX 定义。

### 7. Node 未传递 XHTTP TLS 的 ALPN，不能选择原生 HTTP/3 入站

严重程度：P2，属于能力缺口。

当前核心通过 TLS `alpn=["h3"]` 选择 UDP/QUIC 的 XHTTP/3 监听；Node 生成 VLESS TLS 参数时，只对 Hysteria 传输主动写入 `h3`，没有读取 XHTTP 的 ALPN 设置。当前端口识别也将 XHTTP 归为 TCP。

本机配置生成复现：即使 Node 输入 `tls_settings.alpn=["h3"]`，输出 TLS 配置仍没有 ALPN 字段。外部 CDN/反向代理终止 HTTP/3 后回源到当前 XHTTP TCP 入站属于另一种部署方式，不受这一原生监听缺口直接限制。

位置：Node `internal/kernel/xray/config.go:562`；核心 `transport/internet/splithttp/hub.go:466`；面板 `docs/node-port-validation.md`。

建议：补充原生 HTTP/3 时，同时处理 ALPN 下发、UDP 端口识别和防火墙，不能只增加一个前端选项。

## 已执行的验证

复用现有 Go `1.27.3` 和缓存依赖，没有安装工具或修改项目依赖。

现有相关测试通过：

```bash
/root/sdk/go1.27.3/bin/go test ./internal/kernel/xray ./internal/model \
  -run 'Test.*(Relay|Transport|VLESSHealth|InboundTCPHealth)' -count=1
```

为本次审查在 `/tmp` 中创建临时测试文件，通过 Go `-overlay` 进入现有 Node 包，使用 Node 锁定的实际 Xray 模块；没有把测试文件写入仓库源码。专项覆盖：四种模式的数据往返、三个分流模式的入口请求计数、独立入站不能共享会话、TLS XHTTP 的 Vision 对照、实际前端模板构建、空数组解析失败、旧字段与 `extra` 语义、Node 校验与核心拒绝的差异，以及 ALPN 参数下发。

相关专项分组均通过，包括对预期失败场景的验证。最初数据往返测试被 Node 和核心的默认私网屏蔽阻止，临时测试显式允许环回目标后，正常传输通过；这一调整仅存在于测试配置。没有将该测试前置条件误报为 XHTTP 缺陷。

本机没有 PHP 命令，因此未运行 PHPUnit、Laravel 保存接口和完整订阅请求；没有运行真实 Mihomo、Shadowrocket 客户端、CDN/公网分流或生产节点测试。上述客户端转换结论基于源码，不等同于这些环境已联调通过。

## 处理顺序

优先处理默认 Vision、空对象转换、客户端参数完整性、普通/分流模板和保存校验，再处理旧字段迁移、XMUX 默认值和原生 HTTP/3。核心基本分流实现已有验证，主要修复应落在面板、Dash 和 Node 参数适配层。
