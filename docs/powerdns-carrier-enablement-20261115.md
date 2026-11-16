# 运营商 DNS 权威端启用记录

维护者：P0me1oo。日期：2026-11-15（北京时间）。

按用户本次“帮我开启”授权，通过 Lite MCP 在 Hytron-HK、学长-HK¹、Cloudnium-US、Aether-US 配置运营商 DNS 所需能力，并在 Solid-US LA `/root/yzboard` 打开权威端就绪标志。四台使用 PowerDNS Authoritative 5.1.5，面板运行账户为 `www-data`。

## 已生效配置

四台均在原 `/etc/powerdns/pdns.conf` 增加以下配置，并通过配置检查和逐台重启加载：

| 功能 | 配置 | 当前值 |
| --- | --- | --- |
| 执行 Lua 解析规则，复用状态 | `enable-lua-records` | `shared` |
| 接受面板和区域同步对 Lua 记录的更新 | `enable-lua-record-updates` | `yes` |
| 读取 ECS 客户端网段 | `edns-subnet-processing` | `yes` |
| 有限的 Lua 指令执行上限 | `lua-records-exec-limit` | `100000` |

提供区域传送的 Hytron-HK 另设置 `workaround-11804=yes`，使每个 AXFR 报文只承载一条记录。辅助角色、监听地址、区域、API 凭据、访问控制与 TSIG 同步认证保持原配置。

Solid-US LA 的生产环境文件增加 `POWERDNS_CARRIER_READY=true`，以 `www-data` 执行 `php artisan config:cache`，随后重启 `yzboard-web.service`、`yzboard-horizon.service`、`yzboard-websocket.service`。重建后的其余框架配置与原缓存逐项一致，环境文件其余内容不变。前置池管理接口的准备状态已返回 `ready=true`、`reason=null`。

## 实际验证

通过面板现有 PowerDNS HTTPS 客户端，在 `52mjj.tech` 和 `hytron.xyz` 各创建随机独立验证域名。每个区域写入 390 组临时 Lua 记录，覆盖完整、过期和缺少分片的网段库，以及没有对应线路、缺少公共数据和重叠网段的兜底规则。写入前检查命名冲突，使用独立归属注释，写入后逐组核对内容和归属。

网段库共 6632 条：电信 3019、联通 2511、移动 1102。固定来源为 `0ae37d32d44ff03cc38a7dac79e329f6bcb46855`；生产文件与源码工作区的 SHA-256 均为 `b140ebd476bb81838b9e7f5b4dc39dbd23e6df34490edfae9cd5bc1f1e8c3db5`，失效时间为 2026-12-11 09:57:15 UTC。更新仍按[动态调度说明](front-dynamic-scheduling.md)显式固定来源执行。

首次查询验证为 400 项中 286 项通过、114 项失败。四台临时记录的数量和内容摘要完全一致，已排除区域传送缺失。日志出现 Lua 分片执行异常；PowerDNS 5.1.5 默认的 1000 条指令上限不足以执行完整库。主权威提高到有限的 100000 条后，原先失败的电信 IPv4/IPv6 查询恢复正确结果；随后在三台辅助上采用同一上限。

最终验证结果：

- 从源码工作服务器查询四个 IPv4 权威地址，两个区域、UDP/TCP、每组 25 个场景，共 **400 项全部通过**。
- 从 Hytron-HK 查询三个实际监听的公网 IPv6 权威地址，两个区域、UDP/TCP、相同 25 个场景，共 **300 项全部通过**。学长-HK¹ 没有公网 IPv6。
- 场景覆盖三家运营商各自的 IPv4/IPv6 来源、未携带 ECS、零前缀、宽前缀、私网、未知网段、重叠网段、没有对应线路、缺少数据、过期数据及缺少分片，均返回预期 CNAME 和权威应答。
- 四台均完整同步两个区域的临时 Lua 记录，数量和内容摘要一致；API 读写与真实主辅区域传送均通过。
- 验证后按归属注释删除每个区域的 390 组记录，四台均确认临时记录和空非终结节点无残留。全部业务记录的内容、TTL 和停用状态摘要保持一致；SOA 序列号随 API 写入和清理正常递增。
- 清理后复测原区域的权威 SOA 查询，IPv4 16 项、IPv6 12 项 UDP/TCP 检查全部通过。
- 四台 DNS 服务与三个面板服务均为 `active`，自动重启计数为 0；源站 HTTPS 首页、管理入口和公开配置接口均返回 200。

这是权威解析、准备状态和源站检查，未验证真实代理连接或公网浏览器登录。公共递归解析器的 ECS 支持范围沿用原说明。

## 前置池状态

SJC 的七台成员、LAX 的三台成员目前均只配置 `default`。此次保留原成员配置和池内分流开关，两池的 `carrier_enabled` 仍为 `false`，DNS 错误状态均为空。

权威端与面板准备已完成，管理端可配置运营商分流。要让电信、联通、移动分别使用不同前置，须先确定各运营商成员分组，至少保留一个默认成员，再开启对应池的分流开关；不能根据节点名称推定用户的线路选择。

## 清理与回退

本次只修改服务器运行配置，无产品源码、依赖、迁移、Agent 或核心更新。没有提交、推送、Tag、Release 或镜像发布。临时记录验证结束后清理，本次任务目录中的脚本、清单和配置副本也在核验后删除。

当前两池尚未开启分流，若需撤销权威端准备，先关闭面板的 `POWERDNS_CARRIER_READY` 并重建配置、重启三个服务，再从四台 PowerDNS 移除本次新增的四项配置，并从主权威移除 AXFR 兼容配置，配置检查后逐台重启。如果后续已有池启用分流，应先从管理端关闭并同步，将托管 Lua 恢复为默认 CNAME，确认区域同步后再撤销权威端配置。

官方依据：[Lua 记录](https://doc.powerdns.com/authoritative/lua-records/index.html)、[5.1 Lua 更新和 5.0 执行限制变更](https://doc.powerdns.com/authoritative/upgrading.html)、[配置选项](https://doc.powerdns.com/authoritative/settings.html)。
