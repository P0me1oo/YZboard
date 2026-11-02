# hytron.xyz PowerDNS 部署记录

维护者：P0me1oo。核对日期：2026-11-02（北京时间）。

## 部署状态

hytron.xyz 已加入现有 PowerDNS Authoritative 5.1.5 香港主权威和德国辅助权威。用户已确认没有需要保留的现有解析，并指定以下 NS 名称：

2026-11-02 04:27，三台 `.xyz` 父域服务器均已发布新 NS 和正确 IPv4 Glue，不再返回旧 DS。Google、Cloudflare、阿里和腾讯 DNS 的 SOA 均来自 PowerDNS（主服务器 `ns1.hytron.xyz`，序列号 1793561337），公网迁移已验证生效。

| 角色 | Lite 节点 | NS 名称 | IPv4 | IPv6 |
| --- | --- | --- | --- | --- |
| 主权威 | Hytron-HK | ns1.hytron.xyz | 82.152.164.103 | 2401:b60:1c:1::2 |
| 辅助权威 | Bitsflow-DE Nue | ns2.hytron.xyz | 212.60.153.11 | 2602:fb54:1801:105::a |

新区域保存 SOA、两条 NS 和两个 NS 主机的 A/AAAA，共 7 条记录。SOA 以 `ns1.hytron.xyz` 为主服务器，刷新、重试和负缓存均为 60 秒，过期为 7 天。香港通过 `SOA-EDIT-API=INCREASE` 维护序列号，德国复用现有 HMAC-SHA256 TSIG 认证进行同步。

香港 API 后端仍只监听本机。Nginx 增加 `/api/v1/servers/localhost/zones/hytron.xyz.` 的完整路径白名单，仅允许 GET/PATCH。HTTPS、来源 IP 白名单和密钥保护沿用现有部署。未安装新依赖或更新程序。

## 面板预设

DartNode-US `/root/yzboard` 已新增 `hytron.xyz (PowerDNS)`，编号 4：

- 域名：`hytron.xyz`。
- API 基础地址：`https://82.152.164.103`。
- 服务器 ID：`localhost`。
- API 密钥沿用现有服务，使用面板加密字段保存。

现有三个面板服务继续运行。版本仍为 1.112.0，固定提交为 `7f1de79e256eb3f2cdd0a3c0a791fd00bd184c68`。服务器 DDNS 前缀和三个域名开关由用户配置，本次没有自动启用或选择前缀。

## 注册商及 DNSSEC

注册局 RDAP 确认域名于 2026-11-02 03:22:37（北京时间）注册。2026-11-02 03:47 核对 `.xyz` 权威，初始委派已经发布，当前 NS 为 `launch1.spaceship.net` 和 `launch2.spaceship.net`，TTL 为 3600 秒。

注册信息最初启用 DNSSEC，Spaceship 保存了自己的 DS 验证记录。用户已选择先关闭 DNSSEC 完成迁移，新 PowerDNS 区域保持未签名。操作顺序为：在 Spaceship 停用旧 DNSSEC，确认 `.xyz` 的旧 DS 已撤销并考虑缓存有效期，登记 Glue，再切换 NS。

关闭 DNSSEC 的选择不代表注册商开关已经执行；仍需核对实际 DS 状态。原 Spaceship 的 DS 不能继续用于新 PowerDNS 区域。

03:50 核对时，`.xyz` 三台权威及所测公共解析器仍返回旧 DS，Key Tag 为 6587，算法为 13，摘要类型为 2，TTL 为 3600 秒。停用后需要确认权威 DS 已撤销，再为已缓存旧 DS 的解析器留出有效期；不能从提交关闭请求或登记 Glue 的时间直接推定撤销完成。

Spaceship 的 Glue 功能称为 Personal Nameservers，可在 Advanced DNS 中管理。用户需先登记上述两个主机与 IP，再将 Custom nameservers 设置为 `ns1.hytron.xyz` 和 `ns2.hytron.xyz`。若页面域名已固定为 hytron.xyz，主机部分分别填写 `ns1`、`ns2`。IPv6 按注册商界面的支持情况填写。

03:50 已通过注册局 RDAP 确认两项 Glue 主机均为 active：`ns1.hytron.xyz` 对应 `82.152.164.103`，`ns2.hytron.xyz` 对应 `212.60.153.11`，当前登记 IPv4 地址。NS 仍是 Spaceship 的默认服务器，下一步处理旧 DS。

03:53 用户截图确认 DNSSEC 已禁用，注册局 RDAP 的 `delegationSigned` 也已变为 false。`.xyz` 权威 DNS 和公共解析器仍返回旧 DS，管理操作已经完成，但撤销尚未对外发布；不需要重复关闭开关。为避免 DNSSEC 验证中断，应先确认权威停止返回旧 DS，再留出原 3600 秒缓存有效期后切换 NS。

这是无中断迁移的建议顺序。对于本次没有现有业务记录的新域名，若用户接受过渡期解析失败，也可提前切换 NS；验证 DNSSEC 的递归服务器可能暂时返回 SERVFAIL，直到旧 DS 撤销及缓存过期。

03:59 用户已保存新 NS。注册局 RDAP 确认名称服务器变为 `ns1.hytron.xyz`、`ns2.hytron.xyz`，DNSSEC 登记为关闭；`.xyz` 三台权威仍返回旧 Spaceship NS 和旧 DS，尚未完成对外发布。04:02 国内阿里和腾讯 DNS 的 SOA/NS 也仍来自 Spaceship，虽然主机 IP 正确，不能据此认定已切入 PowerDNS。Google、Cloudflare 返回与旧 DS 有关的 SERVFAIL。香港和德国 PowerDNS 直接查询仍正常且序列号一致，当前等待注册局发布及递归缓存更新。

04:05 首次确认 `x.nic.xyz` 已不再返回旧 DS，Cloudflare 的主机地址查询恢复；该父域服务器仍公布旧 Spaceship NS。继续核对三台父域服务器的新 NS 及 Glue 发布情况。

04:10 确认三台父域服务器均已撤销旧 DS，Google、Cloudflare 的 SOA 查询恢复正常；返回的 SOA 仍来自旧 Spaceship 权威。新 NS 登记已完成，但公网委派尚未更新到 PowerDNS。

## 验证及运维

面板现有客户端通过 HTTPS 验证新区域 GET/PATCH，密钥加密验证通过。香港的区域检查为 0 错误、0 警告。

德国已经完成 TSIG 认证区域传送，初次查询因新区域缓存尚未刷新返回 REFUSED；执行 `pdns_control rediscover` 并清理该区域查询缓存后，新区域返回正确权威回答，检查为 0 错误、0 警告。通过命令行增加区域时应核对服务已发现该区域，不能仅以数据库存在或传送完成为准。

Tencent-CN 的两台 IPv4 UDP/TCP 查询全部通过。iStoreOS 的两台 IPv4/IPv6 UDP/TCP 查询全部通过，NS 地址及两域的 SOA 序列号一致；其中德国 IPv4 有一次 UDP 查询超时，重试后恢复。没有观察到德国系统的 UDP 接收或缓冲区错误。hytron.xyz 的核对序列号为 1793561337。

原 52mjj.tech 的记录已通过内容摘要核对保持一致，序列号仍为 1793556991。本次临时文件已清理。两域共用基础服务，维护路径与密钥保护见 [现有部署记录](powerdns-52mjj-tech.md)。

随后用户选择两个域名共用这组 NS。52mjj.tech 的区域 NS/SOA 已同步调整，序列号递增为 1793556992；其 get.tech 注册商 NS 切换和公网验证也已于 04:41 完成。两个域名的 DDNS 命名相互独立，面板程序保持 1.112.0。

hytron.xyz 的 `.xyz` NS、Glue、DS 撤销，以及所测国内外公共 DNS 的 PowerDNS 解析来源已确认，迁移完成。

官方依据：[Spaceship Personal Nameservers](https://www.spaceship.com/blog/dd-dns-management-a-deep-dive/)、[注册局域名信息](https://rdap.centralnic.com/xyz/domain/hytron.xyz)、[PowerDNS 区域缓存](https://doc.powerdns.com/authoritative/settings.html#zone-cache-refresh-interval)。
