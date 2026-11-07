# PowerDNS 四个权威 NS 部署记录

维护者：P0me1oo。执行日期：2026-11-06（北京时间）。

## 当前状态

按用户指定，将 `ns2` 调整为学长-HK¹，Cloudnium-US 使用 `ns3`，Aether-US 使用 `ns4`，`ns1` 继续为 Hytron-HK。四台服务器端配置已完成，两个区域的全部解析记录一致，四台区域检查均为 0 错误、0 警告。

2026-11-06 09:15，`.xyz` 三台父域仍发布 `ns1`、`ns2`，`ns2` 的 IPv4 Glue 仍为德国旧地址 `212.60.153.11`，TTL 为 3600 秒。09:16，`.tech` 四台父域仍只发布 `ns1`、`ns2`，TTL 为 900 秒。

09:52，用户完成 Spaceship 登记，注册局 RDAP 已确认 `hytron.xyz` 使用全部四个 NS，四个 NS 的 IPv4 Glue 与本文地址表一致；`ns2` 仅保留 `216.236.7.228`，德国旧地址已删除。注册商登记仅含 IPv4，区域内其他三台的 AAAA 继续提供 IPv6 解析。此时 `.xyz` 三台父域仍发布旧 NS/Glue，正在等待新登记发布，公网委派尚未完成。

09:53，通过 IANA 指定的 `.tech` RDAP 服务核对，`52mjj.tech` 注册局当时仍仅登记 `ns1`、`ns2`，最后变更时间仍为初次迁移时间；`.tech` 父域也只发布两个 NS。

10:03，用户完成 get.tech 修改，`.tech` 注册局 RDAP 已确认 `52mjj.tech` 使用全部四个 NS，最后变更时间为 10:03:28。两个域名的注册商登记和正确 IPv4 Glue 已全部确认，DNSSEC 均保持未签名。此时 `.xyz` 三台和 `.tech` 四台父域仍发布旧的两个 NS，`.xyz` 的旧 Glue 尚未换成新地址，父域发布验证仍待完成。

10:04，从腾讯大陆服务器核对 Cloudflare、Google、阿里和腾讯公共解析器：两个域名均返回四个 NS，`ns2` A 均为 `216.236.7.228`，`xtom.52mjj.tech` 业务地址正常，共 16 项查询通过。公共解析器已能从区域查询获得四个 NS，不能据此替代对父域实际委派发布的确认。

| NS 名称 | Lite 节点 | 角色 | IPv4 | IPv6 |
| --- | --- | --- | --- | --- |
| ns1.hytron.xyz | Hytron-HK | 主权威 | 82.152.164.103 | 2401:b60:1c:1::2 |
| ns2.hytron.xyz | 学长-HK¹ | 辅助权威 | 216.236.7.228 | 无公网 IPv6 |
| ns3.hytron.xyz | Cloudnium-US | 辅助权威 | 192.255.175.211 | 2607:9d00:2000:39::294 |
| ns4.hytron.xyz | Aether-US | 辅助权威 | 23.246.167.243 | 2602:faa8:502:c7::a |

三个辅助权威均从 Hytron-HK 同步 `hytron.xyz` 和 `52mjj.tech`，不提供面板写入 API。解析器自行选择可用权威服务器，NS 编号不表示查询优先级。

德国 Bitsflow-DE Nue 的 DNS 在前次迁移时已验证为 `inactive`、`disabled`，并保留了当时的原配置和数据库。工作服务器上的停用前快照本次再次校验通过，见[德国辅助迁移记录](powerdns-secondary-migration-cloudnium-us-20261106.md)。本次收尾时，该旧节点已不在 Lite 节点列表，无法再复核原机当前状态；可用的迁移恢复来源为工作服务器私有快照。

## 部署与同步

学长-HK¹ 和 Aether-US 均为 Debian 13，部署前可用空间分别约 5.9 GiB 和 16 GiB，可用内存分别约 710 MiB 和 516 MiB。两台公网 TCP/UDP 53 没有冲突。

两台新增官方 PowerDNS Authoritative 5.1.5 和 SQLite 后端，包版本均为 `5.1.5-1pdns.debian13`，使用官方签名仓库 `trixie-auth-51`。初始化复用已经校验的德国辅助数据库快照和 TSIG 同步配置，随后从香港重新传送当前区域。

学长-HK¹ 监听 `127.0.0.1`、`::1` 和 `216.236.7.228`，只通过香港 IPv4 同步；原 `systemd-resolved` 的回环监听保留。Aether-US 同时监听本机回环、新 IPv4 和 IPv6，通过香港 IPv4/IPv6 同步。两台仅新增双栈 TCP/UDP 53 的 UFW 放行规则。

三个辅助权威均保持 `secondary=yes`、`primary=no`、`api=no`、`webserver=no`、禁止未签名通知和禁止对外区域传送，继续使用 HMAC-SHA256 TSIG 认证。辅助数据库由 `pdns` 运行账户访问，`replication.env` 保持 root 所有、0600；密钥不进入文档或仓库。

香港通过本机 PowerDNS API 完成以下变更：

- 两个区域的 NS 均更新为 `ns1.hytron.xyz` 至 `ns4.hytron.xyz`。
- `ns2` 的 A 改为学长-HK¹ 地址，删除原 AAAA。
- 新增 `ns3` 的 Cloudnium-US A/AAAA 和 `ns4` 的 Aether-US A/AAAA。
- 两个区域的 `ALSO-NOTIFY` 同时包含三个辅助的 IPv4 地址。

NS 和 NS 主机地址 TTL 均保持 3600 秒，SOA 刷新、重试和负缓存保持 60 秒。其他业务记录和面板归属注释已核对原样保留，SOA 主服务器继续为 `ns1.hytron.xyz`。面板仍通过香港原 HTTPS API 写入，不需要变更 DNS 预设或面板程序。

学长-HK¹ 和 Aether-US 的 `pdns.service` 均为 `active`、`enabled`。Aether-US 原 `yz-agent.service` 保持 `active`，主进程编号在部署前后均为 `667186`。

## 验证结果

| 区域 | 四台一致的记录数 | SOA 序列号 | 全部记录内容 SHA-256 |
| --- | --- | --- | --- |
| 52mjj.tech | 38 | 1793557051 | 247be46cdea5edbc499a1447e05a92862284390c9a510a2f335ba2fddb7c95ed |
| hytron.xyz | 12 | 1793561339 | aaf10a2bca749af6d4ef72b4ce944c0dc8cf075a4fa0d37d91e1d34829828387 |

- 四台数据库完整性检查均为 `ok`，区域检查均为 0 错误、0 警告。
- 新辅助成功接收初始认证区域传送，并在香港修改 NS 后自动接收两个区域的新序列号。
- 从香港验证学长 IPv4、Aether IPv4/IPv6 的两个区域 UDP/TCP 权威查询，共 12 项通过。
- 从腾讯大陆服务器验证四台 IPv4 的两个区域 UDP/TCP 查询，首次 15 项通过、Cloudnium 的 1 项 UDP 超时；该失败项重复查询两次均成功，TCP 复测成功。
- 从大陆移动 iStoreOS 验证四个 IPv4 和三个 IPv6 的 `hytron.xyz` SOA；Hytron、学长、Aether 首次正常，Cloudnium 的两个地址首次超时，复测均恢复。
- 从香港复测 Cloudnium 的 IPv4/IPv6 UDP/TCP，4 项均通过。

当前证据说明所测线路上四台均能返回正确解析，Cloudnium 在大陆线路出现过短暂超时；不能据此保证所有大陆线路持续可达，也不能将一次超时认定为全网封锁。

## 注册商登记

Spaceship 的 `Advanced DNS` → `Personal Nameservers` 已完成四个主机的 IPv4 登记，`ns2` 旧地址已删除，`hytron.xyz` 的四个 Custom nameservers 已由注册局确认。其他主机的 IPv6 按界面支持情况填写，不要求为了 IPv6 Glue 重新修改已经正确保存的 IPv4 登记。

`hytron.xyz` 和 `52mjj.tech` 的注册商及注册局均已确认以下四个 NS：

```text
ns1.hytron.xyz
ns2.hytron.xyz
ns3.hytron.xyz
ns4.hytron.xyz
```

先保存主机 Glue，再保存域名 NS 列表。两个域名继续保持现有未签名状态，本次不需要启用 DNSSEC 或修改 DS。

用户保存和注册局登记均已确认，所测公共递归解析及业务地址已通过。剩余步骤为确认 `.xyz` 三台父域的新 NS、Glue，以及 `.tech` 四台父域的四个 NS。缓存有效期从父域实际发布新登记后计算，不能从服务器端配置完成时计算。

## 保存资料与恢复

香港调整前的 NS、地址、SOA 和通知目标已保存为 root 私有文件：

```text
/var/backups/powerdns-migration/four-ns-20261106-before.json
```

JCloud-ES Ryzen 另外保存了上述回滚资料的副本及本次部署说明，目录权限为 0700，文件权限为 0600：

```text
/var/backups/powerdns-migration/four-ns-20261106/primary-before.json
/var/backups/powerdns-migration/four-ns-20261106/deployment-notes.md
```

学长-HK¹ 和 Aether-US 各自保存了部署前防火墙和安装包默认配置：

```text
/var/backups/powerdns-migration/four-ns-20261106/ufw-before.tar
/var/backups/powerdns-migration/four-ns-20261106/pdns-packaged.conf
```

德国停用前的数据库、同步密钥和配置快照继续保存在 JCloud-ES Ryzen：

```text
/var/backups/powerdns-migration/bitsflow-de-nue-20261106/bitsflow-de-nue-powerdns-20261106.tar.gz
```

本次目标机上传包、临时密钥副本和任务临时目录已清理。恢复新的辅助服务时，使用已保存资料初始化后从香港获取最新区域，不能用旧辅助快照覆盖主区域数据。移除或替换 NS 时，同时处理香港区域 NS/地址、通知目标和注册商委派及 Glue。
