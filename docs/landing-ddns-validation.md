# 落地 DDNS 实现与验证

日期：2026-11-25。面板 2.29.0、管理端 0.111.0、Agent v2.50.0，均为本地开发，未提交、推送、发布或部署。

## 来源与位置

- 面板 `/root/YZboard/YZboard`：基于 master / `566dd3a43f221ae54764f8f0152863d8c7ebe32d`。
- 管理端 `/root/YZboard/YZboard-Dash`：基于 master / `fa7aecc95bd705a56b9b555f7d47c012d10050df`，产物已同步面板。
- Agent `/root/YZboard/.worktrees/YZ-Agent-landing-ddns`：独立工作树，基于已发布 v2.48.0 / `300453fe3dceeb0253f61202ef979e60159c06bc`。原 Agent 工作区有其他任务的未提交改动，未修改它；本工作树保留待整合内容，不能当作已合入主工作区。开发版本使用 v2.50.0，未包含原工作区正在开发的 v2.49.0。

## 行为

落地可独立配置基础 DDNS，支持手填或指定服务器公网 IPv4 / IPv6；客户端订阅继续使用前置域名。公网 IP 来源复用已认证服务器请求的地址，上报过期、机器停用或 DNS 失败保留最后确认值。新地址在 DNS 写入成功后才与同步标记一起保存，再通知前置，定时同步补偿失败。前置实际连接、测速和来源核对使用同一确认 IP，两个核心覆盖 Shadowsocks、VLESS、WireGuard，保留 TLS 身份和原有凭据。

前置 Agent 需要 v2.50.0；保存落地与前置池新增成员时均检查。首次准备时使用手填 IP；关闭后恢复手填 IP，旧解析按原清理流程保留。新增迁移只添加用途、IP 来源和确认地址三列，旧绑定仍为入口和手动来源。使用、连接中断及回退见 [节点 DDNS](node-ddns.md)。

## 验证

PHP 8.4.27 / PHPUnit 11.5.57，SQLite 内存库与独立 MariaDB 11.4.8（严格分组）：

- `php vendor/bin/phpunit --filter 'DdnsTest|ServerRelayTest|RelayFirewallTest|MachineAgentTest|SchedulingTest|DnsSchedulingTest|PowerDnsLockTest|PoolMemberValidationTest' --do-not-cache-result`：两种数据库各 197 项通过，SQLite 4613、MariaDB 4607 断言。
- 最后补充池成员版本保护、原子保存和迁移隔离后，`php vendor/bin/phpunit --filter 'DdnsTest|LandingDdnsMigrationTest|PoolMemberValidationTest|PoolRealitySyncTest|DirectPoolTest' --do-not-cache-result`：两种数据库各 102 项、838 断言通过。
- `php vendor/bin/phpunit tests/Feature/Server/LandingDdnsNotificationTest.php --do-not-cache-result`：两种数据库各 1 项、6 断言通过，验证真实提交后派发、事务回滚不派发、相同 IP 不重复触发。
- MariaDB 命令统一增加 `YZ_TEST_MARIADB_PORT=<本次独立实例端口>` 与 `--bootstrap tests/bootstrap-mariadb.php`。未执行无关全库业务回归。
- 新增池场景夹具最初缺少历史必填列，补齐后通过；迁移测试最初的全历史 rollback 清理碰到既有 SQLite 索引回滚缺陷，改为项目已有 DatabaseTruncation 隔离方式，本次迁移的 down/up 和数据保留断言完整保留。

Go 1.27.3，固定 go.mod/go.sum 与全部正式功能标签 `with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api`：

- `go test -mod=readonly -tags '<上述标签>' ./internal/model ./internal/kernel/xray ./internal/kernel/singbox -run 'TestRelayDDNS|TestBuildConfig_Relay|TestRelayWireGuard|TestWireGuardRelay' -count=1`：三个包通过，sing-box 包含原 WireGuard 真实中转矩阵约 272 秒。
- `go test -mod=readonly -tags '<上述标签>' ./internal/model ./internal/kernel/xray ./internal/kernel/singbox -run TestRelayDDNS -count=1 -timeout=120s`：最终新增配置和运行检查通过。两核心分别从 127.0.0.1 切到 127.0.0.2，域名保持不可外部解析的测试名，实际应答确认目标变化，重复重载正常；IPv6 配置覆盖 SS、VLESS、WG，并核对 TLS 名称保持。
- `go build -mod=readonly -trimpath -tags '<上述标签>' -o <临时目录>/yz-agent ./cmd/yz-agent`：Linux amd64 构建通过，依赖没有变更。
- 竞态命令在启动前因环境没有可用 C 编译器、CGO 关闭被拒绝，未宣称竞态检查通过。本次未新增并发逻辑；未执行 arm64 构建或真实服务器联调。

Node.js 24.22.1 / Vite 7.3.7 / Playwright 1.63.0：

- `npm run check`：3125 份源码检查通过；`node --test tests/load-balancer.test.mjs tests/node-behavior.test.mjs`：31 项通过。
- `npm run build`、`npm run check:build`、`npm run sync:panel` 通过，274 份资源及 125 份 gzip 已集成；面板 `node --test tests/admin-dist.test.cjs` 5 项通过。
- `npm run test:browser -- ddns.spec.mjs` 中原普通 DDNS 和系统设置 4 项通过；新增场景修正模拟节点名称与页面通知定位后，`--grep '落地 DDNS'` 最终 2 项通过，覆盖 1440/320 像素、单前置与前置池、IPv4/IPv6、保存失败恢复和重新编辑。`load-balancer.spec.mjs --grep '节点选择前置池'` 原场景 1 项通过。
- 使用最终面板资源和完整 CSP；已查看桌面与手机截图。浏览器缺少的两个库与中文字体仅下载解包到任务临时目录，复用已有浏览器与其他运行库，没有安装系统软件。
- 主资源 `assets/admin-PG8MIMWo.js`，SHA-256 `b4d286344c6cc9fbeeff4f615779c887bcbcf97fc60c9346cd7059686c12b833`。没有运行全站 verify，模拟页面不代表生产联调。

本次独立 MariaDB 与临时验证文件在交付前清理；Agent 工作树因存在待交付改动保留。
