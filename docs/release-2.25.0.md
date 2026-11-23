# YZboard 2.25.0 发布

维护者：P0me1oo。日期：2026-11-23。

[v2.25.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.25.0) 已发布并设为 Latest，固定源码 `b556551f5256c71493c8358f1e07f60c90d7bc93`，内置 Dash 0.107.0，配套 Agent v2.44.0。新增十项快捷预设、共享分类与入口拦截顺序的使用边界见[路由管理](server-routes.md#快捷预设)。WARP 使用已有出口，节点升级单独执行。

本机 PHP 8.4.27 / PHPUnit 11.5.57：SQLite、MariaDB 11.4.8、生产同版 MariaDB 12.3.3 各 1974 项完整检查通过。SQLite 36395 断言，12 项数据库专项按条件跳过；两套 MariaDB 各 36409 断言、无跳过，开启严格分组与快照隔离。首轮 PHP 缺 Redis 扩展，补齐临时验证环境后完整通过。见[核验](release-2.25.0-tests.json)。

GitHub [完整检查](https://github.com/P0me1oo/YZboard/actions/runs/45156105540) 最终通过。前一来源的工作流缺少 MariaDB 快照隔离，已显式补齐；最终来源首轮并发订阅测试出现两次锁超时，原样重试后通过，没有改测试断言或产品逻辑。

管理端 180 项完整行为、63 项本次补充浏览器检查通过，复用开发阶段的 11 项路由浏览器、源码、构建与资源检查。274 份资源和 125 份 gzip 副本与集成内容逐字节一致。Dash 此前已集成面板的用户筛选、提现和旧 Telegram 整理纳入本次固定源码。

三份附件下载、大小及 GitHub 摘要一致；公开元数据可匿名获取，生产也匿名下载并核验完整安装包。安装包 SHA-256：`8da2958fd02293c520a2bb2b2ee0604b9d2917323ca67e989efa35fcc8103103`。见[附件](release-2.25.0-verification.json)和[部署](deployment-2.25.0.md)。无数据库迁移，无依赖升级，未发布 Docker 镜像。
