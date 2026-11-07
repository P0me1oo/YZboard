# 2.6.1 发布记录

维护者：P0me1oo。日期：2026-11-07。

面板、管理端与 Agent 已手动发布正式 Release，全部标记为 Latest。包含此前未发布的 Reality 批量统一与关联池联动、节点运行管理、自动临时限速、安全依赖更新和本轮五项修复。没有发布 Docker 镜像，也没有更新生产面板或节点。

| 项目 | 版本 | 固定源码 |
| --- | --- | --- |
| YZboard | 2.6.1 | 56f6207d48a8ce9eecf509f4894a5ef6775595d0 |
| YZboard-Dash | 0.91.1 | a0aaab157c5182be598e49aef712015c67ec6c65 |
| YZ-Agent | v2.33.1 | b61051f2abfae5d94b9a82f2d21048018e52ea37 |

## 验证范围

GitHub Actions 因账号账单锁定未启动，未产生新的 CI 测试结果。按本次用户确认，使用已有本地验证结果手动发布，没有把 Actions 的启动失败记录为代码测试失败，也没有声称完成新的完整发布流程。

- 面板：PHP 8.4.27，SQLite 与 MariaDB 11.4.8 严格分组的相关回归各 70 项、512 断言通过，覆盖运行管理、用户管理、实时状态、配置同步及流量授权。前期完整验证与最后修复的测试范围分别保留在[运行检查](node-operations.md)，不等同于本次最终源码完整 CI 回归。
- 管理端：3113 份源码检查、151 项行为测试、正式构建和资源检查、7 项相关浏览器场景通过。16 个集成文件与构建一致，仅移除源码映射注释；面板 5 项资源契约通过。发布准备时重新核对 3115 项源码映射和面板集成字节一致，发布状态文档变更不改变程序产物。
- Agent：Go 1.27.3，正式标签的 autospeed、healthcheck、limiter、service 全包竞态及双核心相关专项通过。固定提交的 Linux amd64/arm64 安装包构建完成，VCS revision、GOOS、GOARCH、CGO_ENABLED=0 与 vcs.modified=false 匹配；amd64 Agent 与 xbctl 实际版本一致。两个核心固定依赖不变。
- 本次没有重跑 GitHub 完整发布流程，外部 Mihomo、四组原生防火墙或生产公网节点联调没有新增验证结果。

未启动的工作流：[面板](https://github.com/P0me1oo/YZboard/actions/runs/42348062410)、[管理端](https://github.com/P0me1oo/YZboard-Dash/actions/runs/42348060271)、[Agent](https://github.com/P0me1oo/YZ-Agent/actions/runs/42348072405)。

## 交付与下载核验

三个版本共 18 份附件已下载并与本地准备内容核对，全部 SHA256SUMS 通过。面板和 Agent 保持公开，管理端保持私有，由当前授权账号下载验证。

| 主要附件 | SHA-256 |
| --- | --- |
| yzboard-2.6.1.tar.gz | bc48bd4fbc9a1a4d2ede7f01812f57da3942c34a9832501103905c8c5a774c2e |
| yzboard-dash-0.91.1.tar.gz | 93228e911123e59ada52c9ea944ff0a745b5f9cb5067893a91bc7485498d75b6 |
| yz-agent-linux-amd64 | 6d7cc53f262fd24d0c0b0db538774574e82470696a7250fa798010de9473aa7d |
| yz-agent-linux-arm64 | 8e1c3ffc0b4e724a088d82b11690448541c68216d6dc533d55c80d58718582cd |

面板包为固定版本源码与 composer.lock，不包含生产凭据及运行数据。管理端包不含源码映射。Agent 附件包含两个架构的主程序、兼容名称、xbctl、安装脚本、四份构建信息和 SHA256SUMS；兼容名称与对应架构主程序字节相同。

## 后续更新顺序

实际部署需要另行授权。先按原生 PHP 流程更新面板并执行两项新增迁移，再通过面板服务器管理入口更新 Agent，核对回报版本。面板 2.6.1 已包含管理端 0.91.1，无需另行替换前端目录。

可回退的最近正式版本为面板 v2.3.0、管理端 v0.88.0、Agent v2.32.0；生产面板另有已部署的 2.4.1 固定来源记录。新增授权表、运行管理字段与健康样本表可保留供旧程序回退，删除迁移会丢失这些新功能数据。

发布入口：[面板 v2.6.1](https://github.com/P0me1oo/YZboard/releases/tag/v2.6.1)、[管理端 v0.91.1](https://github.com/P0me1oo/YZboard-Dash/releases/tag/v0.91.1)、[Agent v2.33.1](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.33.1)。
