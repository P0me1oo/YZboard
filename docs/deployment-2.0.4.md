# 2.0.4 Solid-US 原生部署

日期：2026-11-04。维护者：P0me1oo。

- 生产服务器为 Solid-US，目录 `/root/yzboard`，运行账户 `www-data`。
- 面板 [v2.0.4](https://github.com/P0me1oo/YZboard/releases/tag/v2.0.4)，固定源码 `e5d7c95d4687df256dd66e1a155885f887ff4a50`。
- 管理端 [v0.85.4](https://github.com/P0me1oo/YZboard-Dash/releases/tag/v0.85.4)，固定源码 `7c7a924a3e341e3fe3e1c3d5dc06ad0b9e3af889`。
- 原生产版本为 2.0.3 / `1821ca78897eff6017a255dbd2767a12be2f12cb`。本次没有更新 Agent、核心、系统软件或 Docker 镜像。

## 准备与切换

通过 Lite MCP 核对迁移后的实际部署。PHP 8.4.27、MariaDB 12.3.3 与现有服务正常；48 台启用机器中 45 台近期上报，45 条节点 WebSocket 后端连接正常，失败队列总数为 713。

在独立临时目录下载已发布源码包并验证 SHA-256 `9e6cce7a785d531b2b496796be080ef7fdb04542a2212b34c21062f4080baca4`，归档来源与固定提交一致。生产依赖锁文件与新版本相同，Composer 安装预检显示无需安装、更新或移除依赖，实际平台要求及三个变更 PHP 文件的语法检查通过，复用现有生产依赖。

准备目录沿用生产 Git 的浅历史边界，并通过 Lite 文件工具传入固定版本增量元数据；保留原提交对象和 `deploy-2.0.3` 分支作为本机回滚来源。没有在生产目录执行 `git pull`、`git reset` 或重新克隆，没有重复导出全库。

UTC 2026-11-03T19:31:47Z 开始切换，19:31:51Z 三个服务重新运行，约 4.37 秒。停止 Web、Horizon、WebSocket 后原子替换 15 个变更文件，同步固定版本元数据，以 `www-data` 重建配置并执行 `php8.4 artisan xboard:update --no-interaction`。

迁移检查、默认插件检查和版本缓存刷新成功；没有待执行的新迁移，批次保持 80。当前未设置活动主题，主题刷新未修改文件。环境、依赖锁文件、插件和主题的内容指纹保持一致，数据库、Redis 和 Nginx 主进程保持不变。952 个程序文件与固定源码逐字节一致，当前 Git 分支为 `deploy-2.0.4`。

## 运行验证

- 三个面板服务正常运行，自动重启次数均为 0，Horizon 正常。
- 源站管理入口、公开配置和主脚本连续 5 轮返回 200；主脚本及管理 HTML 引用均与新版本一致。
- 源站管理页、API 和两个用户站的 HTTPS 验证通过；两个用户站的公网检查返回 200。
- 45 台机器保持近期上报，45 条节点 WebSocket 后端连接恢复，失败队列总数保持 713，没有新增应用或服务错误。
- 本机来源的公网管理页检查仍被 Cloudflare 返回 403，与迁移及旧版本一致；未修改边缘策略，未把该次公网管理页检查称为通过。

主应用脚本 `assets/admin-CztrUXv6.js`，文件及 HTTP 内容的 SHA-256 均为 `636383f43714e9d492739a0bb04c33d2beb50136dc7ae14f8f9a181f337bf9ed`。

生产当前没有前置池。本次只更新程序，没有创建测试池、修改 DNS 或升级节点；功能及浏览器验证沿用[固定版本发布记录](release-2.0.4.md)中的结果。

## 记录与保留

机器可读核验记录：[deployment-2.0.4-verification.json](deployment-2.0.4-verification.json)。服务器记录位于 `/root/yzboard/storage/logs/deployment-2.0.4-20261104.json`。

原 `deploy-2.0.3` 分支及固定提交对象在 Solid-US 本机保留，回退源码不依赖已停用的 DartNode-US。回退前按实际池配置评估旧 PROXY 成员限制。本次临时目录、下载包、验证响应和终端已清理，已有迁移文档及生产持久化内容保留。
