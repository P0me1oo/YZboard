# YZboard 2.26.0 发布

维护者：P0me1oo。日期：2026-11-24。

[v2.26.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.26.0) 已发布并设为 Latest，固定源码 `214b3d1a2b40c5f81c51150365175b97e8117aed`，内置管理端 0.108.0。用户列表在邮箱下方直接显示备注，桌面和手机可展开、收起；使用见[用户管理](user-management.md#列表备注)。本次无数据库迁移、依赖或 Agent 更新。

复用开发阶段同一产品源码和资源的源码检查、构建、12 项相关页面场景及受影响手机场景补查，发布准备补查管理端完整 180 项行为通过。274 份资源和 125 份 gzip 副本逐字节一致，主脚本 SHA-256 为 `bb825d2810430dc1bc423ea79782c48f9607fc184a41524f90e6475bed27618c`。

GitHub [面板完整检查](https://github.com/P0me1oo/YZboard/actions/runs/45384230949) 通过：SQLite、MariaDB 11.4.8 各 1,974 项，分别 36,395 和 36,409 条断言；SQLite 12 项数据库专用检查按条件跳过，MariaDB 无跳过；5 项管理端资源检查通过。管理端[全站自动检查](https://github.com/P0me1oo/YZboard-Dash/actions/runs/45384190434) 在发布交付记录时仍运行，未计作已完成结果。两个仓库均未设置必须等待全站检查的发布门禁，本次所需验证按影响范围完成。

三份附件下载、大小和 SHA-256 与 GitHub 摘要一致，见[附件核验](release-2.26.0-verification.json)。安装包 `yzboard-2.26.0.tar.gz` 摘要为 `b2d7af77a16444e4774e5928c574392fc9a5a6a0b27283e9c9e5598ea83bc295`。发布元数据可匿名获取，生产服务器也匿名下载并核验安装包。

已更新 Solid-US LA，见[部署记录](deployment-2.26.0.md)。本次未发布 Docker 镜像。
