# YZboard 2.28.0 发布

日期：2026-11-25。维护者：P0me1oo。

[v2.28.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.28.0) 已发布并设为 Latest，固定源码 `c3a5dcd793ca410efffc1a7e9ba49360ffa3a529`，内置 Dash 0.110.0，配套 Agent v2.48.0。按中转绑定核对全部前置成员的 IPv4/IPv6 出口，更新落地实际端口来源限制；核对失败保留原规则并提示。包含未单独发布的 2.27.0 用户套餐详情。范围和特殊网络限制见[说明](relay-firewall.md)。

[最终 CI](https://github.com/P0me1oo/YZboard/actions/runs/45602583261) 完成 SQLite、MariaDB 严格分组各 1,981 项完整回归，分别 36,428 / 36,442 断言；SQLite 12 项按数据库条件跳过，MariaDB 无跳过，5 项管理端资源检查通过，见[CI 记录](release-2.28.0-ci.json)及[测试统计](release-2.28.0-tests.json)。首轮仅两项测试使用的文档 IPv6 地址在 PHP 8.2 下被判为保留地址；改用不发送网络请求的模拟公网格式地址后通过，产品源码和断言要求未改变。CI 实际 PHP 为 8.2.36，工作流请求值为 8.2.35，不能将请求值当作实际运行版本。

三份附件已匿名下载，大小和 SHA-256 与 GitHub 摘要一致，固定标签与安装包来源一致，见[附件核验](release-2.28.0-verification.json)。源码包由固定 Git 提交导出，不含 vendor、部署凭据及协作规则；包含 composer.lock 和管理端构建资源。274 份现用资源及 125 份 gzip 与管理端构建逐字节一致。

本次未部署、未升级运行节点、未发布 Docker 镜像。正式配套顺序为面板、全部前置、落地，Agent 通过面板“服务器管理”升级并检查回报版本与告警。旧版参考为面板 v2.26.0 / Dash v0.108.0 / Agent v2.44.0，回退将失去本次来源保护，需先评估规则清理和新增功能。
