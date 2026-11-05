# Solid-US 原生部署环境

维护者：P0me1oo。准备及更新核验日期：2026-11-03。

本文中的 Solid-US 为现名 Solid-US KC，已于 2026-11-05 完成迁往 LA 并停用旧主控。当前生产与维护目标见[LA 迁移记录](migration-solid-us-la-20261105.md)，下文保留原环境准备和历史运行记录。

## 目标与状态

- 目标节点：Solid-US，Lite UUID `73c250bd-235a-462d-97e1-74a42406c7e9`。
- 主机名：`qwwq333`；IPv4：`167.253.64.231`；IPv6：`2602:fcc0:9999:e80f::1`。
- 系统：Debian 13，amd64；4 核、约 4 GiB 内存、40 GB 磁盘，已有 4 GiB 交换空间。
- 预留程序目录：`/root/yzboard`；运行账户：`www-data`。
- 2026-11-04 已完成面板 2.0.3、生产环境、业务数据、插件、主题、网站和证书迁移；详情见 [迁移记录](migration-solid-us-20261104.md)。
- Nginx、MariaDB、Redis 和三个面板服务已运行并设置开机启动；生产域名与 HTTPS 已切换到 Solid-US。
- 三个面板服务与每分钟定时任务已启用，`/etc/yzboard/production-ready` 已创建。

## 组件与版本选择

| 组件 | 已安装版本 | 来源与说明 |
| --- | --- | --- |
| PHP | 8.4.27 | Sury 的 Debian 13 软件包；当前面板锁定依赖允许使用的最新分支及补丁版本 |
| Swoole | 6.2.4 | 官方固定源码构建，Debian 安装包版本为 `6.2.4+yz1` |
| PHP Redis 扩展 | 6.3.0 | PHP 8.4 对应扩展包 |
| MariaDB | 12.3.3 | 官方软件源，核验时的最新长期支持分支 |
| Redis | 8.10.3 | Redis 官方 Debian 13 软件源当前最新稳定版 |
| Nginx | 1.30.6 | Nginx 官方稳定版软件源 |
| Composer | 2.11.1 | 官方固定版本 PHAR，位于 `/usr/local/bin/composer` |

PHP 官方最新分支为 8.5；当前锁定的 HTTP 消息处理依赖 `laminas/laminas-diactoros 3.6.0` 不接受 PHP 8.5。PHP 8.4.27 符合全部生产依赖的版本要求，且没有修改 `composer.lock`。PHP 8.2 软件包保留，系统默认 PHP、三个面板服务和定时任务均明确使用 PHP 8.4。

PHP、MariaDB、Redis、Nginx 的源配置分别位于 `/etc/apt/sources.list.d/yzboard-{php,mariadb,redis,nginx}.list`。MariaDB 来源固定在 12.3 分支；Redis 在 `/etc/apt/preferences.d/yzboard-runtime` 中选择 8.10 分支，允许该分支补丁更新；Nginx 使用官方稳定源。

Swoole 扩展由 dpkg 管理，运行模块为 `/usr/lib/php/20240924/swoole.so`。已安装包的缓存副本已在迁移收尾时删除。来源是官方 v6.2.4 的固定提交 `c7e514509451beafd4d70ce5b78943d51e76381c`；源码归档 SHA256 为 `3f31fc0c617d3751774c4ecb0cf980c9168c9efb58c5db5845fdc484b562883f`，安装包 SHA256 为 `870863913523f6c78591a36de33302622ddfdc71e8e36ee06dc181828a5d7785`。启用 sockets、mysqlnd、c-ares、OpenSSL、cURL、Zstd 和 SQLite；未启用 io_uring 和 Brotli。

Composer PHAR 的 SHA256 为 `6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c`，与官方下载校验值一致。

## 目录、连接与参数

- `/root` 只向 `www-data` 授予目录穿越权限；程序及持久化目录为 `www-data:www-data`，权限 0750。
- PHP 配置：`/etc/php/8.4/cli/conf.d/99-yzboard.ini`。单进程内存限制 256 MiB，上传限制 64 MiB，表单限制 80 MiB，命令行 OPcache 关闭，Swoole 短名称关闭。
- 数据库配置：`/etc/mysql/mariadb.conf.d/99-yzboard.cnf`。监听 `127.0.0.1:3306`，字符集 `utf8mb4`、排序规则 `utf8mb4_unicode_ci`，缓冲池 1 GiB，保持旧服务器的严格 SQL 模式。
- 生产业务库为 `xboard`；专用账户 `yzboard_app` 只授予相关业务库权限，允许本机连接。迁移时核对 71 张业务表、4,683,939 行数据一致。
- 新建连接参数保存在 `/etc/yzboard/connection.env`，权限 `root:www-data 0640`；数据库客户端配置为 `/etc/yzboard/client.cnf`，权限 `root:root 0600`。随机凭据只保存在服务器配置文件，不写入文档或源码。后续迁移时需合并数据库连接参数；该外部环境文件不会自动被 Laravel 读取。
- Redis 配置：`/etc/redis/yzboard.conf`，由主配置末尾加载。仅监听 IPv4/IPv6 回环，保持保护模式；沿用旧服务器的 RDB 保存周期、关闭 AOF、`noeviction` 策略和不限制最大内存的设置。
- Redis 后台保存所需的内存分配参数在 `/etc/sysctl.d/99-yzboard-redis.conf` 中设置为 `vm.overcommit_memory=1`，已加载生效。
- 防火墙沿用现有 UFW，只增加 80/443 TCP 的 IPv4/IPv6 规则。数据库、Redis、7001、7002、8076 均不向公网放行。

## 服务及反向代理

| 服务 | 运行命令概要 |
| --- | --- |
| `yzboard-web.service` | PHP 8.4 / Octane / Swoole，监听 `127.0.0.1:7002`，2 个工作进程、1 个任务进程 |
| `yzboard-horizon.service` | PHP 8.4 / Horizon 队列 |
| `yzboard-websocket.service` | PHP 8.4 / 节点 WebSocket，监听 `127.0.0.1:8076` |

服务配置位于 `/etc/systemd/system/`，使用明确的 `/usr/bin/php8.4`，文件句柄上限 65536，文件创建掩码 0027。`/etc/cron.d/yzboard` 为 `www-data` 准备每分钟一次的 `artisan schedule:run`，只在就绪标记存在时执行。

新版 Nginx 的主配置使用 `www-data`，同时加载 `conf.d` 和 `sites-enabled`，面板日志格式只在内部代理配置中定义一次。`/etc/nginx/conf.d/yzboard-internal.conf` 保留 `127.0.0.1:7001` 的入口：`/ws` 转至 8076，其余请求转至 7002。公网站点使用 `/etc/nginx/sites-available/yzboard` 的已迁移域名及代理配置。

原生产服务器的多个域名、独立前端站点、代理片段及证书均已核对并迁移。

## 已完成验证

- 软件包配置及版本核对完成；Nginx、MariaDB、Redis 和三个面板服务运行正常。
- Nginx 配置检查、三个面板 systemd 服务配置检查通过。PHP、服务模板及定时任务均指向 8.4。
- 固定面板 1.114.0 来源 `d1c3b0162cfa8acc87e6c956b000ce32d6147108` 的配置和锁文件校验通过；临时目录安装原有锁定依赖，PHP 8.4 平台要求检查通过。
- 在隔离的本机 MariaDB 12.3.3 与 Redis 8.10.3 实例上执行完整回归。MariaDB 测试实例启用 `ONLY_FULL_GROUP_BY`，仅使用 `yzboard_mariadb_test` 专用库，未操作目标业务库。

| 数据库 | 测试数 | 断言数 | 失败/错误 | 跳过 |
| --- | --- | --- | --- | --- |
| SQLite | 1577 | 18460 | 0 / 0 | 10 项 MariaDB 专属检查 |
| MariaDB 12.3.3 | 1577 | 18470 | 0 / 0 | 0 |

机器可读记录：[solid-us-verification.json](solid-us-verification.json)。

- PHP 必需扩展、专用数据库账号连接及临时表中文读写、PHP 8.4 对 Redis 8.10.3 的随机隔离键读写及清理检查通过；运行账户持久化目录权限已核对。
- PHP 8.4 / Swoole 6.2.4 / Nginx 1.30.6 的独立 HTTP 7001→7002 请求检查，以及 `/ws` 的握手与回显检查通过。
- 回归数据、临时源码、编译目录、临时实例及探针进程在任务结束时清理。验证记录保留于程序目录，安装包缓存副本已清理。
- 本次完成环境及固定源码回归验证；正式 Laravel 生产服务、原生产数据导入、节点连接及各域名 HTTPS 仍需在实际迁移时验收。

## 当前维护与回滚来源

当前生产服务器为 Solid-US，日常更新按项目 AGENTS.md 执行；实际版本、源码、依赖、运行账户和持久化内容仍须每次核对。

DartNode-US 的旧转发、Nginx、MariaDB、Redis、面板进程及证书续期均已停用，生产运行完全独立于旧服务器。完整迁移验证、保留项和实际公网探测限制见 [迁移记录](migration-solid-us-20261104.md)。
