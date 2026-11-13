# 状态处理与静态资源性能优化

维护者：P0me1oo。日期：2026-11-13。面板 `2.16.5`，管理端 `0.98.3`。

后续已按授权完成[发布](release-2.16.5.md)和[Solid-US LA 部署](deployment-2.16.5.md)。以下保留最初实现、局部验证和待上线时的记录，当前运行结果以部署记录为准。

本次落实[生产检查](performance-review-20261112.md)中可直接验证的状态读取去重、HTTP 连接复用和静态资源压缩。源码与配置已准备，尚未提交、发布或部署；生产只读复核仍为 `2.16.3`。保留已有健康归属改动及未上线的 `2.16.4` 前置池成员校验修复。

## 状态处理

- 节点负载与运行指标投影共用一次主机监控开关读取，常规双字段投影从两次 SQL 减为一次。复用值在投影锁内取得，不携带接收快照、等待锁之前的旧判断；下一次调用继续查询最新绑定和接入开关。
- 没有 CPU/内存的纯内核状态直接清除负载缓存，省去无结果用途的策略查询。原生、旧版 HTTP 的独立状态处理仍自行读取策略。
- 指标包含控制结果或调度探测时，相关处理结束后重新读取开关，避免等待或配置写入后继续使用之前的判断。
- 机器历史在当前周期已经写入时直接返回，省去锁内重复查询。下一条机器状态仍检查监控开关；需要写入历史时继续在锁内复核。
- WebSocket 每条消息一次完成机器与节点身份检查，省去第二次相同的机器资料读取。未知事件、未登记节点仍按原规则忽略，但身份失效时关闭连接；下一条消息、主动发送和定期复核继续检查令牌、启停与归属。

没有增加跨请求授权缓存，也没有改变设备许可、可靠流量报告、重放防重或结算事务。日志隐私策略仍保留其当前数据库复核，未直接复用可能已经变旧的机器模型。

## 资源预压缩

管理端集成工具在去除源码映射注释后，为最终发布的 `.js`、`.css`、`.svg`、`.json` 生成 gzip level 6 副本，原文件保留。小于 1 KiB 或压缩无收益时不保留副本；同名资源再次集成时会同步替换或移除旧压缩内容，不复制构建目录可能遗留的 `.gz`。

本次 274 份资源生成 125 份压缩副本。全部副本解压后与原文件逐字节一致，既不发布源码映射文件，也不在压缩脚本中残留源码映射注释。

| 项目 | 原文件 | gzip | 减少 |
| --- | ---: | ---: | ---: |
| 当前管理主脚本 | 6,946,903 B | 1,876,867 B | 73.0% |
| 125 份可压缩资源合计 | 18,655,663 B | 4,619,788 B | 75.2% |

这些是实际文件体积，不是浏览器加载时间或服务器 CPU 降幅。应用运行时和页面拆包方式未调整；客户端仍解析同一份 JavaScript。

## Nginx 配置

已准备两份配置：

1. [HTTP 连接池](examples/nginx-yzboard-keepalive.conf)放入 `http` 上下文，为入口 7001、HTTP 工作进程 7002 各保留最多 32 条空闲连接。外层普通请求通过命名上游转发，并根据 Upgrade 头选择连接头；内层普通 HTTP 清空 Connection。独立 `/ws` 路径保持原升级转发。
2. [管理端静态资源](examples/nginx-yzboard-admin-static.conf)放入内部 7001 的 `server` 块，直接从面板 `public` 目录提供 `/assets/admin/`，启用 `gzip_static` 和 `Vary: Accept-Encoding`。不接受 gzip 的客户端取得原文件。该路径不再占用 PHP 工作进程发送静态资源。

生产检查确认当前仍为两层 HTTP 代理；上述文件尚未写入系统配置。应用时须使用当时的生效配置保留 Host、转发头、日志及 WebSocket 路由，核对运行账户读取权限和 gzip_static 模块，先通过 `nginx -t` 再平滑重载。

官方语义依据：[上游连接复用](https://nginx.org/en/docs/http/ngx_http_upstream_module.html#keepalive)、[WebSocket 升级](https://nginx.org/en/docs/http/websocket.html)、[静态 gzip](https://nginx.org/en/docs/http/ngx_http_gzip_static_module.html)。实际测试明确设置 HTTP/1.1 和 Connection 头，不依赖不同 Nginx 版本的默认值。

## 验证

PHP `8.4.27`，SQLite 内存库及本机独立 MariaDB `11.8.6`，后者启用 `ONLY_FULL_GROUP_BY`，与生产 `12.3.3` 版本不同。测试环境复用现有 PHP 和 Node；MariaDB、Nginx 仅从现有系统软件源下载并解包到本次临时目录，没有安装系统服务。

相关 PHPUnit 命令使用独立框架缓存路径，避免加载开发或生产缓存：

```bash
php vendor/bin/phpunit --do-not-cache-result --filter 'LiteHostMonitoringTest|NodeCredentialRevocationTest|NodeHandshakeTest|RealtimeServerTest|NodeRuntimeMetadataTest|DeviceHandoverTest'
YZ_TEST_MARIADB_PORT=<专用测试端口> php vendor/bin/phpunit --bootstrap tests/bootstrap-mariadb.php --do-not-cache-result --filter 'LiteHostMonitoringTest|NodeCredentialRevocationTest|NodeHandshakeTest|RealtimeServerTest|NodeRuntimeMetadataTest|DeviceHandoverTest'
```

回归覆盖 SQL 去重、绑定变化、旧快照投影、历史节流、控制处理后的重新读取、下一消息复核、旧令牌撤销、节点迁移、未知事件及原有设备路径。最终结果记录于[验证数据](performance-optimization-20261113-verification.json)。没有执行与改动无关的全站回归。

管理端执行压缩集成测试、正式构建、构建检查、同步，并逐一验证压缩内容；面板资源契约 5 项通过。

```bash
# 在 YZboard-Dash 中执行
node --test tests/precompress-asset.test.mjs
npm run build
npm run check:build
npm run sync:panel
# 在 YZboard 中执行
node --test tests/admin-dist.test.cjs
python3 tests/Integration/nginx-performance.py --nginx <本机nginx路径>
```

Nginx `1.26.3` 隔离集成使用两个仅监听回环地址的代理和一个临时后端，加载上述相同配置片段：20 个独立客户端请求在入口层和最终后端均只建立 1 条复用连接；WebSocket 返回 101；gzip 解压一致，identity、gzip;q=0、仅 br 三种情况正确回退原文件，范围请求 206、缺失资源 404。该结果证明配置行为，不代表生产现有全部站点已验证或资源占用已经下降。

## 上线范围与后续

本次未修改生产服务。服务器更新需遵循当前项目授权与部署流程；如上线，需核对是否纳入已存在的 `2.16.4` 修改，按最终来源制作增量清单，保留旧程序及配置以便回退。没有数据库迁移，无需为本次普通源码和配置更新新建全库备份。

上线后再在相近节点数和上报量下比较 SQL、Redis、CPU、连接创建量与状态新鲜度，核对真实域名的压缩协商和 WebSocket。PHP 字节码缓存、工作进程回收、透明大页及防重表结构属于后续独立试验，不包含在本次运行修改中。
