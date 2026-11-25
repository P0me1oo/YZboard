# 中转来源限制验证（2026-11-25）

本地版本：面板 2.28.0、Agent v2.47.0、管理端 0.110.0。三个正式源码目录内的修改尚未提交、推送、发布或部署。保留任务开始时已有的用户套餐详情、Next 适配、端口转发等未提交改动；未修改两个核心仓库或锁定依赖。最终源码与资源摘要见[验证清单](relay-firewall-validation.json)。

## 验证范围与结果

| 验证 | 结果 |
| --- | --- |
| PHP 8.4.27 / PHPUnit 11.5.57，SQLite | 相关 179 项、4653 断言通过；最终补充提示上报后，来源与运行状态 25 项、151 断言通过 |
| MariaDB 11.4.8，严格分组 | 相同相关 179 项、4647 断言通过；最终来源与运行状态 25 项、151 断言通过 |
| Go 1.27.3，四个受影响包的竞态检查 | 308 个顶层用例通过，6 个需要原生隔离环境的用例按条件跳过；最终修正目标更新及待核对共用声明后，受影响包和专项补测通过 |
| Linux 实际 nftables / iptables | 两套独立网络环境均通过双栈 TCP/UDP 放行、拒绝、重复应用、读回失败与归属写盘失败恢复、待核对保持、异常重启恢复、旁边端口可达与手工表不变检查 |
| Agent 完整功能标签构建 | Linux amd64 构建通过，沿用锁定模块；产物属于未提交工作区，不是发布包 |
| 管理端 | 源码检查、正式构建、构建资源检查、3 项健康状态行为检查通过；1440/390 像素的 2 项告警显示、文本转义、恢复清除浏览器用例通过，已查看手机截图 |
| 面板资源 | 274 份集成文件与构建逐字节一致，125 份 gzip 副本一致；5 项资源契约通过；相关 PHP 语法和差异空白检查通过 |

表内补测与初次检查有重叠，不相加为新的测试数量。默认回归中的原生跳过项不能当成已通过；本次仅另行执行新中转限制的 nftables/iptables 两套原生场景，未执行旧端口跳跃、SOCKS 和 UFW/firewalld 的完整环境矩阵。

## 关键场景

- 单前置与完整前置池：两个成员均包含 IPv4/IPv6，未完成成员核对时不给出部分名单；移除成员重新计算并集，来源变化先进入待核对再替换。
- 目标和身份：目标端口、机器进程标识改变后旧指纹失效；拒绝不属于当前绑定的报告、旧时间报告、私网来源、网段输入与部分失败测量；报告过期后分钟任务推送待核对。
- Agent：前置池没有单入口编号也生效，IPv4 通配监听覆盖 IPv6；与原节点来源限制取交集，共用端口拒绝变更；待核对不阻止无关节点更新。
- 失败恢复：真实命令在规则替换后注入一次回读失败，核对恢复后的实际 TCP/UDP 连通性；重启重新读取归属记录，另一个节点同步时仍保留待核对端口的原限制。
- 运行状态：来源或测量目标更新不触发核心重建；警告不改变核心运行状态，恢复后清除。文本按普通文字显示，不执行其中的 HTML。

## 命令与环境

面板相关回归：

```sh
php vendor/bin/phpunit --filter 'RelayFirewallTest|ServerRelayTest|ServerAutoFirewallTest|MachineAgentTest|NodeOperationsTest|PoolMemberValidationTest|SchedulingTest' --do-not-cache-result
YZ_TEST_MARIADB_PORT=<本次独立测试端口> php vendor/bin/phpunit --bootstrap tests/bootstrap-mariadb.php --filter 'RelayFirewallTest|ServerRelayTest|ServerAutoFirewallTest|MachineAgentTest|NodeOperationsTest|PoolMemberValidationTest|SchedulingTest' --do-not-cache-result
```

最终面板补测将过滤条件缩小为 `RelayFirewallTest|NodeOperationsTest`。数据库只使用本机临时容器和 `yzboard_mariadb_test`，没有连接生产库。

Agent 使用 `go test -mod=readonly -race ./internal/firewall ./internal/model ./internal/service ./internal/panel -count=1`。随后防火墙与运行层补测及来源告警专项均使用竞态检测。构建使用 `with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api` 功能标签。

原生用例为 `TestNativeRelayFirewallDualStackAndRollback`；仅在独立 mount/net/pid 命名空间中启用，要求 `/run/yz-firewall-test` 隔离标记、专用 `yzfw0` 接口与 `yzfw-client` 客户端。分别选择 nftables 与 iptables，未修改宿主机防火墙、网络或服务。缺少的编译器、ipset 和浏览器运行库仅在任务临时目录解包，没有安装系统软件。

管理端使用 `npm run check`、`npm run build`、`npm run check:build`、`npm run sync:panel`、`node --test tests/node-health.test.mjs` 和 `npm run test:browser -- relay-firewall.spec.mjs`；浏览器读取最终面板资源并应用 CSP。测试进程、一次性数据库与本次临时文件在结束时清理。

没有执行生产部署、真实跨服务器端到端出口测量、完整发布数据库矩阵或全站前端验证。NAT 和策略路由的证明边界见[使用说明](relay-firewall.md)。Laravel 锁行为依据[官方缓存文档](https://laravel.com/docs/12.x/cache#atomic-locks)，nftables 事务依据[官方原子规则替换说明](https://wiki.nftables.org/wiki-nftables/index.php/Atomic_rule_replacement)。
