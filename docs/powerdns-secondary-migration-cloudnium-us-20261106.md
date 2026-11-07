# PowerDNS 辅助服务器迁移至 Cloudnium-US

维护者：P0me1oo。执行日期：2026-11-06（北京时间）。

本文保留当天首次从德国迁移至 Cloudnium-US 的过程和备份资料。随后配置已调整为四个 NS，Cloudnium-US 现使用 `ns3`，学长-HK¹ 使用 `ns2`，Aether-US 使用 `ns4`。当前注册商登记应按[四个 NS 部署记录](powerdns-four-nameservers-20261106.md)执行。

## 状态

用户授权保存德国辅助 DNS 的迁移资料、将服务迁移到 Cloudnium-US，并停止 Bitsflow-DE Nue 的 DNS 服务。服务器端迁移已完成：香港主权威保持运行，美国辅助权威已同步两个区域，德国 `pdns.service` 已停止并禁用开机启动，原配置和数据库保留。

2026-11-06 08:36，`.xyz` 的 `x.nic.xyz`、`y.nic.xyz`、`z.nic.xyz` 仍发布德国旧 IPv4 Glue `212.60.153.11`，TTL 为 3600 秒。注册商新地址登记及公网委派迁移尚未验证完成，不能将服务器端验收视为公网迁移完成。

| 角色 | Lite 节点 | NS 名称 | IPv4 | IPv6 | 服务状态 |
| --- | --- | --- | --- | --- | --- |
| 主权威 | Hytron-HK | ns1.hytron.xyz | 82.152.164.103 | 2401:b60:1c:1::2 | active |
| 新辅助权威 | Cloudnium-US | ns2.hytron.xyz | 192.255.175.211 | 2607:9d00:2000:39::294 | active、enabled |
| 原辅助权威 | Bitsflow-DE Nue | 原 ns2 地址 | 212.60.153.11 | 2602:fb54:1801:105::a | inactive、disabled |

## 保存资料

德国 DNS 的停用前快照已通过 Lite MCP 复制到源码工作服务器 JCloud-ES Ryzen，保存位置：

```text
/var/backups/powerdns-migration/bitsflow-de-nue-20261106/bitsflow-de-nue-powerdns-20261106.tar.gz
```

备份目录权限为 0700，压缩包权限为 0600，属主为 root。压缩包包含 PowerDNS 配置、同步密钥环境文件、使用 SQLite 在线备份接口生成的一致性数据库快照、原防火墙配置、系统服务定义和版本清单。数据库中的 TSIG 同步密钥也已保留。不要将压缩包解压到仓库、加入提交或输出原始密钥。

- 大小：13729 字节。
- SHA-256：`6b62fe69fc3cee875805771afc8c8a135127050faf1cbbbdb3fd76ec59db3f9d`。
- 本地副本摘要一致，数据库 `integrity_check` 返回 `ok`。
- 快照中的 `52mjj.tech` 为 36 条记录，`hytron.xyz` 为 7 条记录。

香港仅保留受影响记录和通知目标的回滚资料，无需全库导出：

```text
/var/backups/powerdns-migration/secondary-cloudnium-us-20261106-before.json
```

Cloudnium-US 的部署前防火墙和安装包默认配置位于：

```text
/var/backups/powerdns-migration/cloudnium-us-20261106/ufw-before.tar
/var/backups/powerdns-migration/cloudnium-us-20261106/pdns-packaged.conf
```

## 实施

Cloudnium-US 为 Debian 12，部署前根分区剩余约 18 GiB、可用内存约 1.7 GiB。使用官方签名 APT 仓库 `bookworm-auth-51`，安装固定版本 `pdns-server` 和 `pdns-backend-sqlite3`，均为 `5.1.5-1pdns.debian12`。

新机恢复德国辅助数据库和同步配置，监听本机 `127.0.0.1`、`::1` 及新 IPv4/IPv6 的 TCP/UDP 53。原 `systemd-resolved` 的 `127.0.0.53`、`127.0.0.54` 监听保持正常；UFW 仅新增双栈 TCP/UDP 53 放行规则。

新机维持辅助角色，不提供管理 API，不允许未签名通知，也不提供区域传送。香港 IPv4/IPv6 为唯一同步来源，两个区域继续使用原 HMAC-SHA256 TSIG 密钥。

香港通过本机 PowerDNS API，将两个区域的 `ALSO-NOTIFY` 更新为 `192.255.175.211`，并将 `hytron.xyz` 内 `ns2` 的 A/AAAA 更新为新机地址，TTL 保持 3600 秒。其他解析记录和面板归属注释已核对保持原样。面板 API 地址保持 `https://82.152.164.103`，没有更新面板程序或重启香港服务。

新机接收并应用地址变更后，执行德国 `systemctl disable --now pdns.service`；已确认 `inactive`、`disabled`，无 PowerDNS 监听进程。Lite Agent 保持运行，没有关闭整台德国服务器。

## 验收

- 新机两个区域检查均为 0 错误、0 警告，数据库完整性为 `ok`。
- 从香港验证新机两个区域的 IPv4/IPv6、UDP/TCP 权威查询，共 8 项通过。
- 从 Tencent-CN 验证新机两个区域的 IPv4 UDP/TCP 权威查询，共 4 项通过。
- 新机成功从香港 IPv4 传送 `52mjj.tech`，从香港 IPv6 传送 `hytron.xyz`；主服务器禁止未签名传送，新机使用保存的 TSIG 密钥进行认证。
- 香港更新 `ns2` 地址后，新机自动接收 `hytron.xyz` 的新序列号，未手动触发该次传送。
- 主、辅助全部记录的内容摘要一致：`52mjj.tech` 为 36 条，SOA 序列号 `1793557050`；`hytron.xyz` 为 7 条，SOA 序列号 `1793561338`。
- 德国停用后，香港和新辅助均返回新 `ns2` A/AAAA，业务地址 `xtom.52mjj.tech` 正常。所测 Cloudflare、Google 公共解析器也返回业务地址；其 `ns2` A 查询在短超时测试中发生超时，AAAA 已返回新地址，公网切换仍受旧 Glue 和缓存影响。

## 注册商待办

在 Spaceship 的 `Advanced DNS` → `Personal Nameservers` 中编辑现有 `ns2.hytron.xyz`：

```text
IPv4: 192.255.175.211
IPv6: 2607:9d00:2000:39::294（界面支持时填写）
```

删除该主机登记中的旧德国地址；`ns1` 保持原样。`hytron.xyz` 和 `52mjj.tech` 的名称服务器继续使用 `ns1.hytron.xyz`、`ns2.hytron.xyz`，不需要重新更换 NS 名称或启用 DNSSEC。

保存后核对 `.xyz` 三台父域的新 Glue，再核对公共解析器和业务域名。当前父域和权威 NS 地址 TTL 均为 3600 秒，缓存有效期应从新地址实际发布后计算。

## 后续恢复或迁移

新机配置为 `/etc/powerdns/pdns.conf`，数据库为 `/var/lib/powerdns/pdns.sqlite3`，同步凭据为 `/etc/powerdns/replication.env`。数据库目录和数据库由 `pdns` 运行账户访问，凭据文件仅 root 可读。

再次迁移时可使用已保存资料恢复辅助角色，将配置监听地址替换为目标机地址，保留香港同步来源和 TSIG 设置，然后从香港重新传送最新区域。停用前快照仅作为恢复起点，不能覆盖香港当前主区域数据。

如需回滚德国辅助服务，应先恢复德国运行和同步，验证最新区域，再将香港 `ns2` A/AAAA、两个区域通知目标及注册商 Glue 一起恢复为德国地址。恢复已禁用的服务使用 `systemctl enable --now pdns.service`。不能仅启动旧服务就认定公网已回滚。
