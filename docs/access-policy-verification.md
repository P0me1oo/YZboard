# 代理访问记录开关联动验证

日期：2026-11-17。候选版本：面板 `2.22.0`、Agent `v2.39.0`。本地源码已验证，尚未提交、发布或部署；沿用当前候选版本，不改变管理端资源和两个核心的固定依赖。

## 本地审查修复（2026-11-17）

修复访问记录快速关闭再开启后、下一轮清理前崩溃导致旧缓存恢复的问题，以及机器保留停用编号时单节点重建误删有效批次的问题。面板在现有 `log_settings` 设置值中与开关一同保存停用代次，串行保存时读取数据库中的实际状态；握手、机器发现、控制和状态回执下发代次。Agent 将代次随缓存保存并在恢复时核对，初始化本地编号时使用当前连接的基线；上报保持原批次编号，旧代次批次在节点发送前阻止，面板接收后也会确认并丢弃。没有新增数据库迁移或核心依赖。

节点列表改用实际扣费倍率，直出成员原倍率 3、池倍率 2 的回归现在显示 2，原配置仍保留 3。

PHP `8.4.27` / PHPUnit `11.5.57`：同组 151 项相关回归在 SQLite 下通过 150 项、2377 条断言，1 项 MariaDB 查询计划检查按环境跳过；MariaDB `11.4.8` 严格分组模式下 151 项全部通过、2389 条断言。

```sh
php vendor/bin/phpunit --do-not-cache-result \
  --filter 'DirectPoolTest|ProxyAccessPolicyTest|ProxyAccessLogTest|LogSettingsTest|RelayRateTest|ServerRelayTest|RealtimeServerTest|MachineRealtimeTest|AgentLogPolicyTest|AgentLogOwnershipTest|UserConnection.*Test|UserSpeed.*Test|UserIpConnection.*Test'
YZ_TEST_MARIADB_PORT=<本次专用端口> php vendor/bin/phpunit \
  --bootstrap tests/bootstrap-mariadb.php --do-not-cache-result \
  --filter 'DirectPoolTest|ProxyAccessPolicyTest|ProxyAccessLogTest|LogSettingsTest|RelayRateTest|ServerRelayTest|RealtimeServerTest|MachineRealtimeTest|AgentLogPolicyTest|AgentLogOwnershipTest|UserConnection.*Test|UserSpeed.*Test|UserIpConnection.*Test'
```

新增回归覆盖同秒关闭再开启、停用代次持久化、重复关闭及保存期限调整、数据库保存失败后的原状态保全与重试、所有策略回复渠道、清理前崩溃、离线期间开关变化、机器节点重建、有效批次的新进程恢复、旧面板字段缺失与迟到节点回复，以及旧批次发送屏障。

Go `1.27.3` / Linux amd64，使用临时 Zig `0.15.2` 的 `cc -target x86_64-linux-gnu.2.36` 完成正式功能标签下七包完整并发检测，无失败或数据竞争。七包的普通测试也通过。双核心实际代理与普通 WireGuard 专项并发检测通过；双核心 VLESS、Shadowsocks、Trojan 的实连测试位于 sing-box 测试包中，Xray 测试包此次筛选没有匹配项，不计为单独执行的专项。

```sh
go test -mod=readonly -race -count=1 -timeout=5m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  ./internal/accesslog ./internal/panel ./internal/controlplane ./internal/service \
  ./internal/machine ./internal/agentlog ./internal/privacy
go test -mod=readonly -race -count=1 -timeout=5m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  -run '^TestAccessLog|^TestDirectWireGuardTCPUDPAndUsers$' \
  ./internal/kernel/singbox ./internal/kernel/xray ./internal/directwg
```

五个受影响 Go 包的静态检查、正式功能标签的 Linux amd64 Agent 构建和 PHP 语法检查通过。本次只修复后端接口与 Agent，复用已有管理端源码和集成资源，没有重跑浏览器或全站发布矩阵。MariaDB 程序、C 编译器和测试数据仅放在任务临时目录；测试库只监听本机专用端口，结束后关闭并清理。

以下为此次修复前的首次开关联动验证记录。

## 行为

- 面板通过握手、机器控制、机器节点发现及 HTTP/WebSocket 状态回执返回最新访问记录开关，沿用版本 2 能力声明。
- Agent 收到关闭状态后，连接回调停止新增记录，上报入口停止发送；下一轮队列处理清除内存及本节点的 `pending-access-*.json`。队列正常每 5 秒处理一次，通信中断时等待原同步链路恢复。
- 已收到关闭状态后，快速重新开启也会清除旧批次，只采集后续请求。上报期间关闭后，未确认队列不会重新保存供以后补报。
- 关闭状态下启动直接清除本节点访问缓存，不依赖解密或恢复。开启期间仍保全损坏缓存，收到明确关闭后才清除；清理失败继续重试。
- 退出时保存最后的有效队列不会重新打开采集。机器内各节点使用已收到的机器策略，迟到节点响应不能覆盖它。
- 代理转发、用户流量计费、在线状态及路由拦截记录保持原路径。面板历史访问记录继续按保存期限查询和清理。

旧 Agent 不识别新增开关字段，需要配套升级。新 Agent 面对旧面板没有开关字段时沿用原能力判断；运行回执缺失策略或请求失败不会意外重新开启已关闭记录。

## 来源

| 项目 | 本地基线 |
| --- | --- |
| YZboard | `b2773f72881c80fe9b442a8b9c982545995c7ddb` 加未提交修改 |
| YZ-Agent | `eaed060c4fe642c28ca79ce42ae539dfcca7855f` 加未提交修改 |

保留工作区现有隐私日志、直出负载池及健康检查修改。本次没有数据库迁移、记录入库 SQL、核心源码或依赖变更。

## 面板

PHP `8.4.27`、PHPUnit `11.5.57`、SQLite 内存数据库：46 项相关用例中 45 项通过，559 条断言；1 项 MariaDB 用户汇总查询计划检查按环境跳过。

```sh
php vendor/bin/phpunit --do-not-cache-result \
  --filter 'ProxyAccessPolicyTest|ProxyAccessLogTest|LogSettingsTest|RealtimeServerTest|MachineRealtimeTest|HostMonitoringTest'
```

覆盖开关反复切换、机器和节点握手、机器控制及发现、两类 HTTP 状态回执、WebSocket 节点回执、原批次防重和流量独立、迟到数据、历史查询及清理。六份受影响 PHP 文件语法检查通过。只修改能力声明及状态回复，没有运行 MariaDB 或数据库完整发布矩阵。

## Agent

Go `1.27.3`、Linux amd64、任务临时目录内的 Zig `0.15.2`，使用正式功能标签与并发检测。访问队列、面板通信、控制层、服务层四包合计 212 个顶层用例通过，无数据竞争；2 个既有原生来源 SOCKS 环境用例缺少隔离运行条件而跳过。

```sh
go test -mod=readonly -race -count=1 -timeout=5m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  ./internal/accesslog ./internal/panel ./internal/controlplane ./internal/service
```

四包首次完整检查通过后，补充损坏缓存快速关闭再开启的恢复路径，最终服务包单独完整复验 113 项通过；四包计数采用该最终服务结果。新增队列回归覆盖实时停止、固定批次重试、快速切换、关闭状态启动、上报过程中关闭、损坏缓存保全及明确关闭后的清理，以及退出采集屏障。

双核心实连检查共 6 个顶层专项通过，无跳过、无数据竞争：覆盖 Xray/sing-box 的 VLESS、Shadowsocks、Trojan TCP/UDP、配置重载、交叉中转、实际出口、WireGuard 中转及普通 WireGuard。关闭期间实际代理请求仍返回正确内容且不产生访问记录，重新开启恢复新记录。

```sh
go test -mod=readonly -race -count=1 -timeout=5m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  -run '^TestAccessLog' ./internal/kernel/singbox ./internal/kernel/xray ./internal/directwg
go test -mod=readonly -race -count=1 -timeout=5m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  -run '^TestDirectWireGuardTCPUDPAndUsers$' ./internal/directwg
```

四包静态检查及正式功能标签的 Agent 构建通过。构建为 Linux amd64、`CGO_ENABLED=0`、`vcs.modified=true`，来源基线与上表一致；实际模块保持 YZ-sing-box `v1.16.0` 和 YZ-Xray-core `36f4516090e3e2fe88dcbe0840126582cd6fb795`（正式版本 `v26.12.0`）。临时编译器、测试日志和构建程序在任务结束后清理。

使用说明见[访问记录](access-logs.md)及[日志设置](log-settings.md)。
