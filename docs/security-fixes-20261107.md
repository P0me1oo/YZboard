# 2.5.2 安全修复与依赖更新

日期：2026-11-07。维护者：P0me1oo。本次为本地修改，未提交、发布或部署。

配套管理端 0.90.1、Agent 2.32.1。包含工作区已有的面板 2.5.0/2.5.1 和管理端 0.90.0 改动；这些既有内容没有回滚或覆盖。

## 修复范围

- 机器令牌重置、停用和删除后，在数据库提交后发出撤销通知。节点长连接保存凭据摘要，收到请求、发送配置以及每五秒心跳前复核身份；缓存或数据库无法确认身份时关闭连接。通知延迟不会误关使用新令牌建立的连接。旧单节点模式也复核全局令牌。
- 用户流量和旧兼容接口统一检查正整数用户 ID、非负整数流量、数值范围及倍率。负值、浮点数、科学计数形式和溢出转换不能进入结算；已排队的旧负流量任务也被拒绝。旧接口仍支持十进制数字字符串和 JSON 顶层认证字段。
- 结算检查节点的用户权限组，包含入口可以承载的中转线路权限组；不把到期、超额或封禁当作已经发生的流量无需结算。未授权用户使整次新报告返回校验错误，不静默确认或部分扣费。
- 原始流量、插件调整后的流量和按用户拆分的落地流量均检查用户授权。相同报告编号已持久化时继续使用首次接收的快照，重复确认不会重新计费，也不受后续权限变化影响。
- 邮件验证码与邮件登录入口共用独立的 IP 与全站请求预算，v1/v2 路由共享；邮箱间隔与组合键限流保留。
- 中转流量按唯一落地节点批量查询和检查归属。100 个用户引用同一个节点时，由原来的 100 次查询降至最多 2 次。

Agent 安装升级的 GitHub/镜像校验清单降级逻辑按用户要求保留，本次没有改变。管理员令牌有效期和可选健康检查监听地址属于原审查中的策略建议，不纳入本轮四项漏洞修复。

## 流量补报授权与迁移

新增迁移 `2026_11_07_000001_create_node_traffic_grants.php`，创建 `v2_node_traffic_grant`。记录节点、所属机器、用户 ID 和期限，不保存 UUID、密码、令牌或设备地址。

HTTP 和 WebSocket 下发用户列表前登记授权，仍有效的记录至多每小时续期一次。用户或节点权限组改变后，已下发的授权保留七天，允许节点补报；缓存丢失不会清除数据库授权。节点改绑到另一台机器后不能继承原机器的历史授权。

当前仍具备节点权限组的用户可以直接结算，无需先调用一次用户接口，兼容升级前已运行的节点。升级前已经撤销且从未登记过的旧权限没有可核实来源，不自动补造授权；撤权后超过七天的新批次会被拒绝。已接收报告的重复确认不受此期限影响。

定时任务 `node:prune-traffic-grants` 每分钟清理最多 1000 条到期记录，删除时再次核对期限，避免误删刚续期的授权。新表不加入后台任意清理目标。

未来部署须先按项目原生更新流程执行数据库迁移，再启动程序；仅增加新表，旧程序回退不依赖删除该表。本次没有执行生产迁移或服务器更新。

## 邮件请求预算

| 环境变量 | 默认值 | 用途 |
| --- | ---: | --- |
| `EMAIL_VERIFY_PER_IP_HOUR` | 30 | 同一来源每小时发信入口请求数，独立于邮箱 |
| `EMAIL_VERIFY_PER_HOUR` | 1000 | 全站每小时发信入口请求数 |

这是请求额度，包含被控制器进一步拒绝的请求；达到限制返回 429，不创建邮件任务。额度至少为 1，不能用 0 意外关闭保护。需要调整时按实际共享出口和邮件供应商容量设置；配置缓存部署需按正常流程重新生成。

## 依赖版本

面板使用 Composer 的兼容约束和最小更新策略，固定解析平台为 PHP 8.2.35，避免在较新开发环境锁入生产 PHP 无法运行的依赖。主要更新：

| 依赖 | 更新后 |
| --- | --- |
| Laravel | 12.71.0 |
| Symfony HTTP Foundation / Kernel / Client / Routing / YAML | 7.4.23 |
| Symfony Mailer / Mime | 7.4.22 |
| Guzzle / PSR-7 | 7.16.1 / 2.13.2 |
| CommonMark | 2.10.7 |
| Google Auth / Protobuf | 1.55.3 / 4.33.6 |
| Firebase JWT | 7.2.1 |
| Flysystem / Sodium Compat | 3.36.0 / 1.24.2 |
| PHPUnit | 11.5.57 |

锁文件同时移除已不在依赖声明中的 Reverb 及其孤立依赖。当前节点通道使用 Workerman。

Agent 更新 Brotli 1.2.8、klauspost/compress 1.20.3、Chi 5.5.0、MongoDB 驱动 1.17.10、x/net 0.62.0；x/crypto 固定到公告修复提交 `2f1f834330b2d36ab3db8bfb33aaa1ac16794588`。该提交与原 0.58.0 的源码差异仅在未使用的 openpgp 目录，保留其他加密实现。两个核心 fork 的固定版本不变。

Agent 最低工具链、CI 与 Docker 构建配置统一为已验证的 Go 1.27.3。原自动构建使用 1.27.1，标准库命中 HTTP/2、TLS 等在 1.27.2 修复的公告，以及证书信任公告 GO-2026-6809（1.27.3 修复）；仅升级三方模块不足以覆盖这些问题。构建配置更新复用同一编译器的验证结果，没有触发任何镜像发布。

管理端更新 Vite 7.3.7、source-map-js 1.2.2。正式构建输出与原 0.90.0 的业务资源一致，增量同步面板并保留旧资源。

## 验证

主要检查命令（PHP 使用临时运行环境，数据库、Redis 只连接本次创建的环回实例）：

```bash
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit --bootstrap tests/bootstrap-mariadb.php --do-not-cache-result
php vendor/bin/phpunit tests/Feature/Server/NodeCredentialRevocationTest.php --do-not-cache-result
php vendor/bin/phpunit --bootstrap tests/bootstrap-mariadb.php tests/Feature/Server/NodeCredentialRevocationTest.php --do-not-cache-result
composer --no-plugins --no-scripts audit --locked --format=json
npm audit --package-lock-only --ignore-scripts --json
npm run check && npm test && npm run build && npm run check:build
npm run test:browser -- access-logs.spec.mjs
npm run test:browser -- --grep-invert '访问记录：'
npm run test:browser -- node-reality-unify.spec.mjs --grep '无选择、单选和包含非' --repeat-each=3
npm run test:dev
npm run sync:panel
node --test ../YZboard/tests/admin-dist.test.cjs
go test -mod=readonly -p 2 -v -race -count=1 -timeout 20m -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' ./...
go run golang.org/x/vuln/cmd/govulncheck@v1.9.0 -scan=package -tags 'with_quic,with_utls,with_wireguard,with_gvisor,with_acme,with_clash_api' ./cmd/yz-agent ./internal/...
```

命令分别在所属项目根目录执行；上述记录不是生产更新指令。测试初始化依赖失败后仅重跑受影响项目：PHP 子进程继承完整扩展配置，浏览器补齐临时运行库与字体。旧统计测试补建合法用户和权限组，保留原有计量、并发和重试断言。

- Composer 全部锁定依赖审计：无安全公告命中、无弃用包。
- npm 锁文件审计：无安全公告命中。
- govulncheck v1.9.0：按正式构建标签扫描主入口和 internal 包，无公告命中。它不替代真实公网协议渗透测试。
- PHP 8.4.27、SQLite：1658 项检查，32345 条断言，无失败；10 项 MariaDB 专项按条件跳过。
- PHP 8.4.27、MariaDB 11.4.8（严格分组）及独立 Redis 8.0.2：完整 1658 项、32352 条断言通过，无跳过。补充真实握手身份绑定用例后，两个数据库的令牌撤销专项各 7 项、18 条断言通过；未重复运行已通过且产品代码未变的完整检查。
- 自查补回空中转报告的零查询路径后，双数据库各运行流量授权、中转倍率和代理统计相关 27 项、315 条断言，全部通过。
- 新增漏洞回归覆盖负流量、越权、延迟补报、确认重试、缓存丢失、节点改绑、令牌撤销、通知延迟、读取失败、发信预算及重复 SQL。
- 前端源码 3106 份检查、151 项行为检查、构建及资源检查通过；面板 5 项静态资源契约通过。
- 前端开发模式检查通过，覆盖自动重建、节点编辑、权限组排序、套餐连接数、编辑器工作线程、Telegram Bot 与日期时间弹窗。
- 浏览器 402 个不同场景均取得通过结果：访问记录 7 项通过，其余场景首轮 394 项通过、1 项在重复打开菜单时失败。该项单独复测通过；补充等待菜单退出动画完成后，连续 3 次通过。保留禁用状态与零提交断言，没有修改产品交互或跳过用例；这是分项结果合并，不表述为整套 verify 单次通过。
- Agent 的 8 个核心/兼容包竞态检查与安装器八组脚本通过，包含保留的镜像回退行为。
- Agent 正式标签完整竞态：38 个包、941 个顶层用例通过，未报告数据竞争；6 个条件用例跳过，其中 5 个需专用防火墙、rootfs 或客户端命名空间，1 个因操作系统随机分配端口不在目标端口段而跳过。没有执行外部 Mihomo 或四组真实防火墙集成。
- Agent Linux amd64、arm64 正式标签构建均通过；amd64 实际启动版本为 2.32.1。两份构建均为 CGO_ENABLED=0，模块元数据指向原固定核心，VCS revision 为当前 HEAD 且 vcs.modified=true，属于未提交工作区验证产物。

| 临时验证产物 | SHA-256 |
| --- | --- |
| amd64 | `7cd9465705f25948ff40a3cec50ee585b5d5966cfabdac6ee5bd32475b0fab8d` |
| arm64 | `181789fb9ab53bf06327262dadf734c7099bc64a642ec40efe8b8b0aaaf1f2f1` |

本轮验证已结束。临时数据库、Redis、浏览器和开发服务均已停止，临时工具、验证构建与截图清理后仅保留本记录中的结果及摘要。没有安装系统软件、改变生产服务、提交、推送、发布或部署。
