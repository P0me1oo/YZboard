# Solid-US LA 面板 2.3.0 更新

日期：2026-11-06（北京时间）。维护者：P0me1oo。

已从 2.2.0 更新到 [2.3.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.3.0)，源码固定为 `0f75b799dbcfb757c1e023143f9123fb2a015cde`，集成管理端 0.88.0。当前用户授权仅更新 Solid-US LA 面板；Agent 节点保持原版本。

生产目录 `/root/yzboard`，运行账户 `www-data`，PHP 8.4.27、MariaDB 12.3.3、Redis 8.10.3。两套数据库完整回归与平台依赖检查通过，991 份源码按 Git 对象逐项核对；发布包下载摘要与本地一致。

在独立目录按 composer.lock 安装生产依赖，暂停定时调度与三个面板服务后同步程序和依赖，保留 `.env`、storage、插件及主题。以 www-data 执行 config:clear、xboard:update、config:cache，恢复调度并启动服务。没有新增数据库迁移，复用现有 2.2.0 回滚程序与已发布固定源码。

UTC 17:33:30 至 17:33:34 完成切换及服务启动检查。Web、Horizon、WebSocket 均正常，无自动重启或新增服务错误；管理入口、首页及配置接口返回 200，6 份脚本及编辑器资源摘要匹配。原 48 台在线机器全部恢复上报，47 条 WebSocket 连接已建立。迁移批次 80、141 条迁移、713 条失败队列均未变化，环境配置摘要一致。

源码、版本和检查编号见[结构化核验](deployment-2.3.0-verification.json)，附件及完整发布检查见[发布记录](release-2.3.0.md)。
