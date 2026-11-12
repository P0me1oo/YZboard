# 52mjj.tech PowerDNS 部署记录

维护者：P0me1oo。初始部署核对：2026-11-02；最近状态核对：2026-11-11（北京时间）。

## 当前状态

2026-11-11，四个 NS 继续为 Hytron-HK（`ns1` 主权威）、学长-HK¹（`ns2`）、Cloudnium-US（`ns3`）和 Aether-US（`ns4`）。学长-HK¹ 的地址已改为 `216.236.7.193`，DNS 服务已恢复，主权威的 `ns2` A 记录及两个区域的通知目标均已更新。四台区域记录核对一致，TCP/UDP 查询通过；用户已完成 Spaceship 更新，14:05 注册局确认 `ns2.hytron.xyz` 的新 IPv4 登记，父域实际发布尚待确认。域名 NS 列表及面板 DNS 预设不变。详见[地址变更记录](powerdns-ns2-ip-change-20261111.md)及[四个 NS 部署记录](powerdns-four-nameservers-20261106.md)。德国 DNS 已停止，原数据和停用前快照见[辅助服务器迁移记录](powerdns-secondary-migration-cloudnium-us-20261106.md)。以下初始部署过程为历史记录，地址表已更新为当前地址。

两台服务器已通过 Lite MCP 部署官方 PowerDNS Authoritative 5.1.5，使用 SQLite 后端和 systemd 开机启动。两个域名共用 `ns1.hytron.xyz` 和 `ns2.hytron.xyz`。2026-11-02 04:41 已确认四台 `.tech` 父域服务器发布新 NS，DS 为空；Google、Cloudflare、阿里和腾讯 DNS 的 SOA 均来自 PowerDNS，52mjj.tech 的公网权威迁移已验证生效。

用户已确认 Cloudflare 没有需要保留的解析，本次省略 Cloudflare 导出及记录迁移。区域保留 SOA、两条共用 NS，以及四条旧 NS 地址记录，共 7 条记录；实际 NS 主机地址由 hytron.xyz 区域管理。当前序列号为 1793556992。

| 角色 | Lite 节点 | NS 主机名 | IPv4 | IPv6 |
| --- | --- | --- | --- | --- |
| 主权威 | Hytron-HK | ns1.hytron.xyz | 82.152.164.103 | 2401:b60:1c:1::2 |
| 辅助权威 | 学长-HK¹ | ns2.hytron.xyz | 216.236.7.193 | 无公网 IPv6 |
| 辅助权威 | Cloudnium-US | ns3.hytron.xyz | 192.255.175.211 | 2607:9d00:2000:39::294 |
| 辅助权威 | Aether-US | ns4.hytron.xyz | 23.246.167.243 | 2602:faa8:502:c7::a |

Hytron-HK 为 Debian 12、1 核、约 973 MiB 内存，完成部署后磁盘剩余约 3.1 GiB、可用内存约 708 MiB。德国服务器为 Debian 13、1 核、约 1.9 GiB 内存，部署前磁盘剩余约 17 GiB。每个 PowerDNS 进程当前约占 25 MiB。

两台均开放公网 IPv4/IPv6 TCP、UDP 53。香港原有 systemd-resolved 的回环监听继续保留，PowerDNS 使用显式地址绑定。

## 同步与访问保护

香港运行主区域，德国通过 HMAC-SHA256 TSIG 认证接收 SOA 检查、通知及区域传送。香港禁止未签名区域传送；德国只接受香港地址的签名通知，不提供管理 API。SOA 刷新和重试均为 60 秒，过期为 604800 秒，负缓存为 60 秒。API 修改通过 `SOA-EDIT-API=INCREASE` 更新序列号。

德国在香港停止时继续提供已有解析；香港恢复后继续同步。辅助服务器不接收面板写入，不构成管理 API 自动切换。

香港 API 后端只监听 `127.0.0.1:8081`。面板使用 `https://82.152.164.103`，Nginx 和 UFW 只允许 DartNode-US 的 `166.88.160.30` 访问 HTTPS。外部代理以完整路径分别允许 GET/PATCH 访问 `52mjj.tech.` 和 `hytron.xyz.` 区域，不开放全局管理或 TSIG 密钥接口。

2026-11-02 增加的第二个域名、面板预设及注册商待办见 [hytron.xyz 部署记录](powerdns-hytron-xyz.md)。

HTTPS 使用 Let's Encrypt 的 IP 地址证书，Certbot 5.9.0 位于 `/opt/powerdns-tools/venv`。`powerdns-api-cert-renew.timer` 每 6 小时检查续期，续期后检查并重载 Nginx。TCP 80 仅提供 ACME 验证路径，其余请求返回 404。

香港 API 凭据位于 `/etc/powerdns/api.env`，两台同步凭据位于 `/etc/powerdns/replication.env`，均为 root 所有、0600。香港通过 systemd 环境文件在启动前生成 `/run/pdns/config/api.conf`，该文件为 root:pdns、0640。原始密钥不进入本文或命令行参数。

## 面板设置

DartNode-US 的生产目录为 `/root/yzboard`，运行账户为 `www-data`。程序仍为 1.112.0，固定提交为 `7f1de79e256eb3f2cdd0a3c0a791fd00bd184c68`，本次没有更新程序或重启面板服务。

已新增 DNS 预设 `52mjj.tech (PowerDNS)`，编号 3：

- 域名：`52mjj.tech`。
- API 基础地址：`https://82.152.164.103`。
- 服务器 ID：`localhost`。
- API Key 使用现有面板加密字段保存，不回显。

原 Cloudflare 预设编号 2 继续保留，核对时没有 DDNS 绑定或前置池使用它。

迁移生效后，在服务器设置中选择新预设，逐台填写三级 DDNS 前缀。使用用户指定的前缀，例如 `a` 时，基础地址为 `a.52mjj.tech`；开启用户独立域名后，现有程序自动生成固定的 32 位随机十六进制标识，地址为 `随机标识.a.52mjj.tech`，以 CNAME 指向基础地址。DDNS、用户独立域名和跟随用户状态是三个独立开关。本次没有替用户选择前缀或启用这些开关。

## 已完成验证

- 两台配置和生产区域检查均为 0 错误、0 警告。
- Tencent-CN 验证两台 IPv4 UDP/TCP 权威响应；iStoreOS 验证两台 IPv4/IPv6 UDP/TCP 响应；DartNode-US 也完成双栈查询。
- 验证两台 NS 和 SOA 一致，三级 A/AAAA 与四级 CNAME 查询一致。
- 在保留域 `.invalid` 的临时区域验证 API 记录及归属注释的读写、地址更新和自动同步。
- 撤销四级 CNAME 后，两台均返回 NXDOMAIN，德国自动同步撤销约 2 秒后可见。
- 停止香港 PowerDNS 后，德国 IPv4/IPv6 UDP/TCP 仍返回正确权威响应；香港已恢复运行。
- 使用面板现有客户端验证真实 HTTPS GET/PATCH；密钥在数据库中加密保存。
- 无密钥请求返回 401，禁止的写入方法返回 403，私有管理接口返回 404；非白名单来源无法连接 HTTPS 或直连 8081。
- 证书续期模拟及 Nginx 重载钩子通过，续期计时器已启用。
- 所有临时区域、临时密钥传送文件和本次测试目录已清理；两台仅保留生产区域。

04:42 从 Tencent-CN 实测，阿里 DNS 对两个域名均返回 PowerDNS SOA；该地区腾讯 DNS 的 52mjj.tech NS/SOA 仍有旧 Cloudflare 缓存。04:45 再测，腾讯 DNS 已能正确返回 ns1.52mjj.tech 的 A 和 ns2.52mjj.tech 的 AAAA，地址与 PowerDNS 一致。父域委派已完成，部分地区的 NS/SOA 缓存显示尚未全部一致，已测地址查询正常。

## 注册商设置

用户已改用在 Spaceship 登记的共用 NS，不再需要为 `ns1.52mjj.tech`、`ns2.52mjj.tech` 注册 Glue。旧注册请求由用户向 Namify 支持撤销。hytron.xyz 的 NS、Glue 和 DNSSEC 撤销已通过父域和公共 DNS 核对。

在 get.tech 的 Custom nameservers 填写：

```text
Nameserver 1: ns1.hytron.xyz
Nameserver 2: ns2.hytron.xyz
```

2026-11-02 04:34:12，用户已保存注册商 NS。04:34:53 注册局 RDAP 确认新 NS 已关联到域名，DNSSEC 为未签名。04:41 四台 `.tech` 父域服务器及所测国内外公共 DNS 已确认采用新权威，SOA 主服务器为 `ns1.hytron.xyz`，序列号为 1793556992。服务器侧两台已同步采用共用 NS，面板两个 API 预设验证通过；国内双栈 UDP/TCP 查询也通过，德国 IPv4 UDP 有两次超时重试后恢复。

注册商切换、父域委派、所测公共递归解析及国内可达性已核对。后续新增域名或更换 NS 时，仍应重复核对这些环节。

共用 NS 不改变两个域名的解析记录或 DDNS 名称，但要求持续保持 hytron.xyz 的注册有效并及时续费，避免 NS 名称失效。

## 运维位置

两台主配置均为 `/etc/powerdns/pdns.conf`，数据库为 `/var/lib/powerdns/pdns.sqlite3`。香港 API 代理为 `/etc/nginx/sites-available/powerdns-api`。配置及防火墙的部署前资料位于各机 `/var/backups/powerdns-migration`。

查服务状态可使用 `systemctl status pdns`；香港还需核对 `nginx` 和 `powerdns-api-cert-renew.timer`。不要把含密钥的环境文件、数据库或完整环境输出粘贴到聊天或提交到仓库。

官方依据：[PowerDNS 5.1.5](https://doc.powerdns.com/authoritative/changelog/5.1.html)、[TSIG 同步](https://doc.powerdns.com/authoritative/tsig.html)、[Let's Encrypt IP 证书](https://letsencrypt.org/2026/03/11/shorter-certs-certbot)。

