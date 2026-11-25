# YZboard 2.29.0 发布

日期：2026-11-25。维护者：P0me1oo。

[v2.29.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.29.0) 已发布并设为 Latest。固定源码 `9af18473e67d095b3b31fb8d5e140b579a3a8fba`，内置 Dash 0.111.0（`facd408a56131283613b06c9f0ac3bb9a9f01328`），前置配套 Agent v2.50.0。新增落地独立 DDNS 与服务器公网 IPv4/IPv6 来源，订阅继续沿用前置域名；DNS 写入确认后更新前置、测速与来源核对地址。使用和回退见[节点 DDNS](node-ddns.md)。

[固定来源 CI](https://github.com/P0me1oo/YZboard/actions/runs/45644312385) 全部通过：PHP 8.2.36，SQLite 与 MariaDB 严格分组各 1,989 项，36,491 / 36,505 断言；SQLite 12 项按数据库条件跳过，MariaDB 无跳过，5 项管理端资源检查通过。详见[CI](release-2.29.0-ci.json)和[统计](release-2.29.0-tests.json)。复用已完成的局部页面、双数据库与两个内核地址切换验证，不重复构建管理端。

三份附件已匿名下载，大小及 SHA-256 与 GitHub 摘要一致；固定源码包包含 composer.lock、管理端资源和兼容迁移，不含 vendor、部署凭据或本地规则。管理端当前 274 份资源和 125 份 gzip 与固定提交逐字节一致，主资源 `admin-PG8MIMWo.js` 摘要为 `b4d286344c6cc9fbeeff4f615779c887bcbcf97fc60c9346cd7059686c12b833`。见[附件核验](release-2.29.0-verification.json)。

本次未更新生产服务器或运行节点，未发布 Docker 镜像。后续先更新面板，再通过“服务器管理”升级全部前置并核对 v2.50.0 回报，随后启用落地 DDNS。自动来源依赖落地服务器已有认证地址上报。回退 v2.28.0 前先关闭落地 DDNS、核对手填 IP 并保留新增数据库列。

管理端发布补充（2026-11-26）：最终标签固定 `f1889d8f8b4cc0750e9494ee736b4a20245d8554`，相对上述构建来源只调整既有测试与受限补测流程，产品资源不变。488 项全站场景成功、五项旧断言修正及开发模式补测通过，详见管理端 `docs/release-0.111.0.md`。
