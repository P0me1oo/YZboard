# YZboard v2.22.2 发布

维护者：P0me1oo。日期：2026-11-17。

[v2.22.2](https://github.com/P0me1oo/YZboard/releases/tag/v2.22.2) 已发布并设为 Latest，固定源码 `8e0d1661530c070b9af4fac139e6bbac79b61b05`，内置管理端 [v0.104.2](https://github.com/P0me1oo/YZboard-Dash/releases/tag/v0.104.2)，固定源码 `5b9c9a692cca3c7f1ed3c392d5ccc198ab890407`。

本版汇总全部本地面板与管理端更新：直出负载池、隐私日志正常记录、访问记录开关及停用代次联动、权限组菜单归属和用户套餐长名称显示。隐私日志及访问记录联动配套 [Agent v2.39.0](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.39.0)。没有新增数据库迁移、锁定依赖或核心版本更新。

PHP 8.4.27 / PHPUnit 11.5.57：SQLite 完整 1962 项、36189 条断言通过，12 项 MariaDB 专项按环境跳过；与生产同版 MariaDB 12.3.3 严格分组下的完整 1962 项、36203 条断言全部通过，无跳过。使用本次专用 Redis 8.0.2；首次本机检查缺少 Redis 扩展配套加载，补齐临时配置后完整重跑通过。结果见[完整检查记录](release-2.22.2-tests.json)。

管理端 179 项行为及面板 5 项资源检查通过；同一源码与产物的源码、构建和相关页面浏览器结果复用。GitHub 自动检查在启动阶段失败，没有执行测试步骤，不计作通过；使用上述本机结果。未发布 Docker 镜像。

面板及管理端六份附件已下载并核对摘要，面板正式安装包可匿名获取，管理端保持私有；见[附件核验](release-2.22.2-verification.json)。本版已在 Solid-US LA 更新，运行文件、入口、节点和持久化内容核验通过，见[部署记录](deployment-2.22.2.md)。
