# YZboard 2.30.0 发布

日期：2026-11-26。维护者：P0me1oo。

[v2.30.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.30.0) 已发布并设为 Latest，固定源码 `a972353baefe2a00165c9c2ab1ee659e82f45060`。系统日志、Agent / 核心日志及故障诊断保留原文，隐私保护和加密策略不变。配套 Agent v2.51.0，管理端保持 0.111.0；没有数据库迁移、依赖或管理端资源变化，没有发布 Docker 镜像。

[固定来源 CI](https://github.com/P0me1oo/YZboard/actions/runs/45673659735) 完整通过：PHP 8.2.36，SQLite 与 MariaDB 11.4 各 1,989 项测试、36,491 / 36,505 条断言，SQLite 12 项按数据库条件跳过，MariaDB 无跳过，5 项管理端资源检查通过。前轮发布检查识别两处旧脱敏断言，已更新为原文保留，同时保留公开响应、权限和重复上报验证。见[检查记录](release-2.30.0-ci.json)。

三份正式附件均已匿名下载，大小、SHA-256、GitHub 摘要及校验清单匹配，源码包包含 composer.lock 和现有管理端，不含部署凭据、本地规则或 vendor。见[附件核验](release-2.30.0-verification.json)。

Solid-US LA 已完成部署，45 台可管理 Agent 已通过面板升级 v2.51.0，版本、任务结果和心跳正常，隐私保护状态保持。最初本机 Lite 转接入口被 Cloudflare 拒绝，随后原生 Lite MCP 访问成功，全部远程操作经该授权通道完成。详细状态、例外及回退见[部署记录](deployment-2.30.0.md)。
