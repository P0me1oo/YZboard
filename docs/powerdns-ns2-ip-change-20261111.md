# 学长-HK¹ 权威 DNS 地址变更

维护者：P0me1oo。执行日期：2026-11-11（北京时间）。

## 状态

学长-HK¹（`ns2.hytron.xyz`）的 IPv4 从 `216.236.7.228` 变为 `216.236.7.193`。原 PowerDNS 配置仍绑定旧地址，日志报告 `Cannot assign requested address`，服务因此反复启动失败。

用户授权修改服务器端配置，已通过 Lite MCP 完成修复并验证解析及区域同步。用户随后完成 Spaceship 更新；14:05，通过注册局 RDAP 主机记录确认 `ns2.hytron.xyz` 仅登记新 IPv4 `216.236.7.193`，最后变更时间为 14:04:35。注册商登记已完成，无需用户重复修改。所测 `.xyz` 的 `x.nic.xyz`、`y.nic.xyz`、`z.nic.xyz` 仍发布旧地址，父域实际发布尚待确认。

## 已完成修改

- 学长-HK¹：将 `/etc/powerdns/pdns.conf` 的 `local-address` 改为 `127.0.0.1,::1,216.236.7.193`，配置检查通过后重启 `pdns.service`。服务为 `active`、`enabled`，新地址 TCP/UDP 53 正常监听，后续检查无自动重启。
- Hytron-HK：通过本机 PowerDNS API 将 `hytron.xyz` 区域内 `ns2.hytron.xyz` 的 A 记录改为 `216.236.7.193`，保留 TTL 3600 秒及原注释。区域 SOA 序列号由 `1793561339` 增至 `1793561340`。
- Hytron-HK：将 `hytron.xyz` 和 `52mjj.tech` 的 `ALSO-NOTIFY` 中旧地址替换为新地址，保留 Cloudnium-US 和 Aether-US 两个通知目标，并发送区域更新通知。

修改前后通过 API 核对，其他解析记录及其注释保持原样。现有防火墙已允许 TCP/UDP 53，无需修改；辅助角色、香港同步来源、TSIG 认证、NS 名称和面板 DNS 预设保持原配置。未重启主权威或面板服务，未安装软件、更新程序或创建全库备份。

## 验证

四台 PowerDNS 均正常运行，两个区域检查均为 0 错误、0 警告，SQLite 完整性检查均为 `ok`。首次一致性核对时，四台记录数及摘要如下：

| 区域 | 记录数 | SOA 序列号 | 全部记录内容 SHA-256 |
| --- | --- | --- | --- |
| hytron.xyz | 12 | 1793561340 | 143b618fdd130583bb09eba999cecf3886fb86c3340357856e31508720a47583 |
| 52mjj.tech | 189 | 1793557120 | a8169ccd481388bfba789a1a49b3f4b103ec42183e401e3b70ece6f77a69818e |

- 从工作服务器验证四个 IPv4、两个区域的 UDP/TCP SOA，共 16 项通过；四台均返回权威应答。
- 四台 `ns2.hytron.xyz` A 查询均返回 `216.236.7.193`，共 4 项通过。
- 从 Hytron-HK 验证学长新地址的 UDP/TCP SOA 查询通过。
- 学长日志确认从 `82.152.164.103` 接收 `hytron.xyz` 新序列号 `1793561340`，随后也正常接收 `52mjj.tech` 更新；收尾复测四台 `52mjj.tech` SOA 均为 `1793557122`。
- 用户完成登记后，Cloudflare `1.1.1.1`、Google `8.8.8.8`、阿里 `223.5.5.5` 和腾讯 `119.29.29.29` 均返回新 `ns2` 地址，学长新地址的 UDP/TCP 权威查询仍正常。
- `.xyz` 三台父域仍返回旧 Glue `216.236.7.228`，TTL 为 3600 秒。

## Spaceship 登记与父域发布

用户已在 `hytron.xyz` 的 `Advanced DNS` → `Personal Nameservers` 中编辑已有 `ns2.hytron.xyz`：

```text
旧 IPv4：216.236.7.228
新 IPv4：216.236.7.193
```

14:05:50，依据 IANA RDAP 引导服务找到的[注册局主机记录](https://rdap.centralnic.com/xyz/nameserver/ns2.hytron.xyz)返回 `ipAddresses.v4 = ["216.236.7.193"]`，没有旧地址。`hytron.xyz` 和 `52mjj.tech` 的四个 NS 名称不变，get.tech 不需要因本次换 IP 修改 NS 列表。

登记已完成，剩余步骤为确认 `.xyz` 三台父域发布新 Glue，再观察递归缓存。公共解析器返回新 A 记录不能替代父域 Glue 发布验证；缓存有效期从父域实际发布新地址后计算，不能从服务器修复或注册商保存时直接计算。
