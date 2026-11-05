# VLESS XHTTP

面板 `2.3.0`、管理端 `0.88.0` 和 Agent `v2.32.0` 统一 XHTTP 的保存、订阅与 Node 配置转换。两个核心固定依赖不变，无新增数据库迁移。

## 普通节点

选择 VLESS 和 XHTTP 后，可以直接编辑路径、Host 和模式，其他高级参数继续通过“编辑协议”修改 JSON。

- `auto`：由客户端按安全方式和下载配置选择模式。
- `packet-up`：分包上传，适用于需要经过 CDN 或反向代理的入口。
- `stream-up`：流式上传，反向代理需要允许流式请求并关闭请求缓冲。
- `stream-one`：上传与下载使用同一请求，不能配置独立下载通道。

未启用 VLESS Encryption 时，XHTTP 的流控应选择“无”。管理端切换 XHTTP 时会清除不兼容的默认 Vision；后端和 Node 同样检查该组合。旧的错误 Flow 需要编辑节点并保存修正。

## 上下行分流

打开“上下行分流”，填写下载地址、端口、路径、Host 和下载安全性。下载可以独立选择无、TLS 或 Reality；TLS 的握手域名和 HTTP 版本也与上传独立。Reality 下载需填写客户端公钥和 Short ID。

上传与下载入口可以采用不同的域名、端口及 TLS 设置，但它们最终必须回源到**同一个 XHTTP 入站**。不同路径和 Host 需要正确重写以匹配该入站。两台独立 Node 各自持有会话，不能直接拼接为上下行。

JSON 的最小分流示例如下。`download.example.com` 是需要替换的示例地址。

```json
{
  "path": "/",
  "mode": "packet-up",
  "downloadSettings": {
    "address": "download.example.com",
    "port": 443,
    "network": "xhttp",
    "security": "tls",
    "tlsSettings": {
      "serverName": "download.example.com"
    },
    "xhttpSettings": {
      "path": "/"
    }
  }
}
```

普通模板默认不包含下载配置；分流模板单独提供。空下载地址、无效端口、嵌套下载、`stream-one` 与分流同时启用，以及连接池并发数和连接数同时非零，均会在保存或启动前拒绝。

## 高级参数和订阅

`network_settings` 接受外层高级字段，也兼容 `extra`。有 `extra` 时，外层 `path`、`host`、`mode` 优先，其余高级字段全部取自 `extra`；保存时按这一实际语义展开为统一配置。

- 旧 `sessionPlacement`、`sessionKey` 分别迁移为 `sessionIDPlacement`、`sessionIDKey`。
- 旧 `xmux.cMaxLifetimeMs` 按毫秒换算为 `hMaxReusableSecs`，已有新字段优先。当前默认连接池为 3 个连接。
- PHP 保存后的空对象在 Xray 订阅及 Node 配置中恢复为对象，ALPN 等数组保持数组类型。
- 通用 VLESS 与 Shadowrocket 专用订阅都输出客户端所需的完整 XHTTP `extra`；服务端选项及私钥不进入客户端参数。
- Mihomo 的独立下载转换保留既有版本检查和能力过滤；它不能独立表达的会话、填充及下载参数仍会过滤对应节点，详见[Mihomo 订阅](mihomo-subscription.md)。

具体客户端仍需支持对应的 XHTTP 参数；本次没有使用真实 Shadowrocket 或公网 CDN 联调。sing-box 客户端继续过滤 XHTTP。

## HTTP/3

VLESS XHTTP 使用 TLS 时，可选择原生 HTTP/3；保存为 `tls_settings.alpn=["h3"]`，Node 下发到核心。此时监听使用 UDP，面板端口冲突检查和 Node 自动防火墙均按 UDP 处理，不支持 TCP PROXY 头。Reality 入口不能选择原生 HTTP/3。

HTTP/3 和 TCP 的 HTTP/1.1、HTTP/2 可以在相同数值端口共存。外部 CDN 终止 HTTP/3、再通过 TCP 回源属于另一种部署方式，不要求 Node 自身监听 HTTP/3。

## 修复验证

原问题与检查来源见[审查报告](vless-xhttp-review-20261105.md)，本次修复结果见[兼容矩阵](../YZ_COMPATIBILITY.md)。源码和管理端产物保留在工作区，未发布或部署服务器。
