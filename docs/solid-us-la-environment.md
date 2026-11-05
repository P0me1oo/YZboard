# Solid-US LA 主控迁移预备环境

日期：2026-11-05（北京时间）。维护者：P0me1oo。

2026-11-05 已完成主控迁入，当前生产在 Solid-US LA 运行。本文保留环境准备时的快照，迁移完成时的状态见[迁移记录](migration-solid-us-la-20261105.md)，当前 2.3.0 运行环境见[更新记录](deployment-2.3.0.md)。

## 准备时的状态

- 目标为 Solid-US LA，Lite UUID `604540c9-316f-40de-9990-2e7a9547361c`，主机名 `sa.sasas`。
- IPv4：`172.81.59.192`；IPv6：`2602:fcc0:7777:d034::1`。
- Debian 13 / amd64，4 核、约 4 GiB 内存、40 GB 磁盘，已有 4 GiB 交换空间；准备后磁盘仍有约 32 GiB 可用。
- 生产主控仍在 Solid-US KC（`167.253.64.231`），面板 2.0.4，固定提交 `e5d7c95d4687df256dd66e1a155885f887ff4a50`。本次对 KC 仅进行只读核对，结束时三个面板服务均正常运行。
- 本次完成环境准备。应用源码、生产数据库、Redis 状态、应用密钥、插件、主题、网站和证书尚未迁入；没有切换 DNS。
- LA 的 Nginx、MariaDB、Redis 已运行并设置开机启动。三个面板服务已准备，保持禁用且未运行；定时任务受就绪标记控制。

## 组件

| 组件 | 安装版本 | 来源 |
| --- | --- | --- |
| PHP | 8.4.27 | Sury Debian 13 软件源 |
| Swoole | 6.2.4 | KC 已验证模块，重新封装为 dpkg 包 `6.2.4+yz1` |
| PHP Redis 扩展 | 6.3.0 | Sury PHP 8.4 对应软件包 |
| MariaDB | 12.3.3 | 官方 12.3 软件源 |
| Redis | 8.10.3 | 官方软件源，APT 选择 8.10 分支 |
| Nginx | 1.30.6 | 官方稳定软件源 |
| Composer | 2.11.1 | 官方固定版本 PHAR，校验值与 KC 相同 |

组件版本与当前 KC 一致。仅安装 PHP 8.4；服务和定时任务均明确使用 `/usr/bin/php8.4`。同时准备了 Certbot、ACL、rsync、unzip 等迁移所需工具。

Swoole 经 Lite 文件工具只读取得 KC 已安装模块，两台服务器均为 Debian 13 / amd64、PHP 8.4 ABI。文件 SHA-256 为 `c9b93c36b0c3714e497887de5040f3acd4fa9a16f9794170f78b84381abd5076`，传输后及安装后均一致。原构建来源为官方固定提交 `c7e514509451beafd4d70ce5b78943d51e76381c`；来源及许可证保存在 `/usr/share/doc/php8.4-swoole/`。重新封装的包 SHA-256 为 `7447c0964a54af6365233237f732d692560837ff4d30c609a17b3cd89561b75f`，与原 KC 安装包的归档校验值分别记录。

软件源位于 `/etc/apt/sources.list.d/yzboard-{php,mariadb,redis,nginx}.list`，APT 选择规则位于 `/etc/apt/preferences.d/yzboard-runtime`。四个官方源的签名公钥指纹与 KC 核对一致。

## 目录与连接

- 应用预留目录为 `/root/yzboard`，运行账户为 `www-data`，目录权限 0750；已准备 `storage/`、`bootstrap/cache/`、`plugins/` 和 `public/plugins/`。通过 ACL 向运行账户授予 `/root` 的目录穿越权限。
- PHP 配置为 `/etc/php/8.4/cli/conf.d/99-yzboard.ini`：内存 256 MiB、上传 64 MiB、表单 80 MiB，关闭命令行 OPcache、Swoole 短名称和 PHP 版本暴露。
- MariaDB 配置为 `/etc/mysql/mariadb.conf.d/99-yzboard.cnf`：仅监听 `127.0.0.1:3306`，字符集 `utf8mb4`、排序规则 `utf8mb4_unicode_ci`，缓冲池 1 GiB，SQL 模式与 KC 一致。
- 空业务库为 `xboard`；专用账户 `yzboard_app` 仅获该库权限，允许本机连接。验证后仍为 0 张表。
- 新随机连接凭据保存在 `/etc/yzboard/connection.env`（`root:www-data 0640`）和 `/etc/yzboard/client.cnf`（`root:root 0600`），未写入源码或记录。迁移时需将目标连接设置合并到应用 `.env`；Laravel 不会自动读取外部连接文件。
- Redis 配置为 `/etc/redis/yzboard.conf`，由主配置加载；仅监听 IPv4/IPv6 回环，保持保护模式、RDB 保存周期 `900 1 300 10 60 10000`、关闭 AOF、`noeviction` 和不限制最大内存的设置。
- `/etc/sysctl.d/99-yzboard-redis.conf` 设置 `vm.overcommit_memory=1`，已生效。
- 沿用既有 UFW，仅增加 80/443 TCP 的 IPv4/IPv6 规则；既有管理端口 10721 保持。数据库、Redis 和内部代理端口没有向公网放行。

## 服务与入口

| 服务 | 准备的命令与监听 |
| --- | --- |
| `yzboard-web.service` | Octane / Swoole，`127.0.0.1:7002`，2 个工作进程、1 个任务进程 |
| `yzboard-horizon.service` | Horizon 队列 |
| `yzboard-websocket.service` | 节点 WebSocket，`127.0.0.1:8076` |

服务配置位于 `/etc/systemd/system/`，与 KC 的运行参数一致，使用 `www-data`、文件句柄上限 65536 和文件创建掩码 0027。三个服务都要求 `/etc/yzboard/production-ready`、应用 `artisan` 和 `.env` 同时存在；当前就绪标记和应用环境均未创建。启动检查确认服务因条件不满足而跳过，保持禁用及未运行。

`/etc/cron.d/yzboard` 已准备每分钟调度，同样由就绪标记控制。

Nginx 使用 `www-data`，加载 `conf.d` 和 `sites-enabled`。内部代理 `/etc/nginx/conf.d/yzboard-internal.conf` 监听 `127.0.0.1:7001`，`/ws` 转发至 8076，其余请求转发至 7002。公网默认 HTTP 入口返回 444；`/etc/nginx/sites-available/yzboard` 仅为未启用的占位模板。正式多域名站点、独立用户网站和 HTTPS 证书留待迁移，当前没有 443 TLS 监听。

## 验证与清理

- 官方面板 2.0.4 发布包 SHA-256 `9e6cce7a785d531b2b496796be080ef7fdb04542a2212b34c21062f4080baca4` 校验通过。
- 在临时目录按原 `composer.lock` 安装 126 项生产依赖，没有修改锁文件；平台要求、Laravel 包发现和启动检查通过，框架版本为 12.54.1。锁文件 SHA-256 为 `13f51448280db6f551f41d6998656239af62e4c63da20ebd31b75a99d0756e70`。
- 以 `www-data` 验证必需 PHP 扩展、专用数据库连接、临时表中文读写和事务回滚；确认该账户无法读取系统账户表，业务库保持空库。
- Redis 随机临时键中文读写及删除检查通过，验证后键数为 0。
- 临时 Swoole 服务的 HTTP 7001→7002 转发，以及 WebSocket 7001→8076 握手和回显检查通过。探针退出后 7002/8076 均无监听。
- Nginx 配置、三个 systemd 服务配置、软件包一致性、运行账户目录权限和连接配置权限检查通过；三个基础服务自动重启次数均为 0，检查期间无错误级别日志。
- 本次没有重复应用完整回归；运行组件与已验证的 KC 相同，执行了 LA 的环境集成验证。生产数据一致性、多域名 HTTPS、节点重连和队列状态需在实际迁移后验收。
- 本次 `/tmp/yzboard-la-env.Y7XXSf` 中的源码、依赖、下载包、缓存和探针已清理。正式组件及来源记录保留，没有将临时验证源码留在生产目录。

机器可读记录：[solid-us-la-verification.json](solid-us-la-verification.json)。服务器上的记录为 `/etc/yzboard/environment.json`。

## 后续迁移所需工作

1. 固定迁移来源版本；迁入生产应用、数据库、Redis 状态、持久化目录、插件、主题、网站和证书。
2. 保留原应用密钥，将 LA 新数据库连接参数合并到迁入的 `.env`，核对运行账户及正式 Nginx 多域名配置。
3. 在迁移授权范围内完成最终停写和数据一致性检查，再创建就绪标记、启用并启动三个面板服务。
4. 验证应用入口、HTTPS、队列和节点连接后切换 DNS，并完成旧主控收尾。本次环境准备尚未执行这些迁移步骤。

