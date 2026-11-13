# YZboard 2.16.6 发布

维护者：P0me1oo。日期：2026-11-13（北京时间）。

[面板 v2.16.6](https://github.com/P0me1oo/YZboard/releases/tag/v2.16.6) 已发布并设为 Latest，固定源码 `214ba5ba148d2e88c6ac581d2483d4a3e1db9918`。Solid-US LA 已部署，见[部署记录](deployment-2.16.6.md)。配套 [Agent v2.37.1](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.37.1) 已发布，由用户在 WebUI 升级运行节点；管理端保持 0.98.3。

## 发布范围与验证

设备锁竞争恢复、503 限时重试和 DNS 脱敏诊断见[实现说明](runtime-log-fixes-20261113.md)。无数据库迁移、依赖、管理端或核心更新。

PHP 8.4.27：SQLite 完整 1,885 项、35,228 断言通过，12 项数据库专用检查按条件跳过；MariaDB 11.4.8 完整 1,885 项、35,244 断言通过，无跳过，开启严格分组和快照隔离。使用独立 Redis 8.0.2；管理资源五项检查通过。

GitHub Actions 运行 `43393450053` 因账户账单锁定未启动，步骤数为 0，未计为通过。发布依据为本机隔离验证；没有更改验证要求或发布 Docker 镜像。

安装包 `yzboard-2.16.6.tar.gz` 从固定标签生成，SHA-256 为 `2d5f102cd34d6cf998ae80e98075753a4ec7c49b3bf667f71d11603b427d5adc`。安装包、`release.json` 和 `SHA256SUMS` 三份附件均已下载核对，公开元数据另经匿名下载确认。详细结果见[发布核验](release-2.16.6-verification.json)。后补文档另行提交，不移动标签或替换安装包。
