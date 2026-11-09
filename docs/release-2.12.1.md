# 2.12.1 发布与部署记录

维护者：P0me1oo。日期：2026-11-09。配套管理端 0.96.1。

本次包含此前未发布的用户 IP 连接数、Lite 主机监控、路由拦截记录、队列兼容、Lite 自动绑定与调度，以及链路健康状态和保存后重检修正。生产从 2.11.2 更新；新增机器 Lite 自动绑定开关的兼容迁移，原有数据保留。本次不更新 Agent 或核心。

## 固定来源

| 项目 | 版本 | 提交 |
| --- | --- | --- |
| YZboard | 2.12.1 | 445bfaf07b0e22ab686a6ba1b83dfc80dfd6970f |
| YZboard-Dash | 0.96.1 | 1381497a7af7170f0c5f3522a9b87a07e394730a |

[面板 v2.12.1](https://github.com/P0me1oo/YZboard/releases/tag/v2.12.1) 与 [管理端 v0.96.1](https://github.com/P0me1oo/YZboard-Dash/releases/tag/v0.96.1) 均已正式发布并标记 Latest。六份附件均重新下载，与本地发布包、清单逐字节匹配，SHA256SUMS 通过。发布包与校验记录见 Release 附件；源码与管理端产物逐字节核对，规则文件及真实配置不进入附件。

## 验证

PHP 8.4.27，SQLite 完整 1,740 项、33,061 断言无失败，12 项 MariaDB 专项按条件跳过；MariaDB 11.8.6 严格分组与 Redis 8.0.2 完整 1,740 项、33,080 断言通过，无跳过。命令：`php vendor/bin/phpunit --do-not-cache-result`；MariaDB 增加 `--bootstrap tests/bootstrap-mariadb.php`，均使用独立本机测试库与 Redis。

浏览器完整检查首轮 434 个场景中 432 项通过；固定参考缺少已部署的路由标签，补齐两个标签的明确断言后 29 路由对照通过。手机权限组再次打开后 Escape 关闭偶发超时，未改产品或测试断言，原场景连续三次复验通过。434 个不同场景均有通过结果，不是一次完整命令全部通过。

Node 24.22.1：3,120 份源码、157 项行为、正式构建、资源、开发模式检查通过；274 份管理资源已集成，面板 5 项资源契约通过。

GitHub Actions 因账号账单锁定未开始测试：面板运行 42540967812 无测试步骤，管理端运行 42540961904 启动失败且无任务。本次使用本地验证结果，不把远端启动失败表述为检查通过。

## 生产

部署目标：Solid-US LA（Lite 当前名称 Solid-US），目录 `/root/yzboard`，运行账户 `www-data`。暂存目录从固定提交获取源码，按相同 composer.lock 安装和平台检查；依赖版本不变，仅重新生成两个自动加载文件。

回退来源 `/root/yzboard-deployments/2.11.2/`，被替换程序及框架缓存保存在本次 `runtime-before/`。回退程序保留新增列、迁移记录及业务数据库和 Redis，不回退已处理流量与用户数据。

## 发布附件

| 附件 | SHA-256 |
| --- | --- |
| yzboard-2.12.1.tar.gz | 4197434afc8bfebcbc516169c3d2617b773b7cab981fa0dabb4b1c20258c6afe |
| yzboard-dash-0.96.1.tar.gz | f13cdb81b6f9954afb58f6e61ff789bae0832032c0a5efba331e8d1296a27035 |

面板公开、管理端私有，访问范围保持。安装包来自表中固定提交，后续提交只补交发布与部署文档，标签不移动。未发布 Docker 镜像。

Solid-US LA 已更新到本版，迁移成功，三个服务正常、49 台机器有近期心跳。详细切换结果及既有日志问题见[部署记录](deployment-2.12.1.md)，结构化发布验证见[核验记录](release-2.12.1-verification.json)。
