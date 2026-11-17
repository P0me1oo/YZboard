# 隐私日志加密验证

日期：2026-11-17。候选版本：面板 `2.22.0`、Agent `v2.39.0`、管理端 `0.104.0`。这是本地未提交工作区的验证，没有发布或部署。

## 来源与范围

| 项目 | 工作区基线 |
| --- | --- |
| YZboard | `b2773f72881c80fe9b442a8b9c982545995c7ddb` 加未提交修改 |
| YZ-Agent | `eaed060c4fe642c28ca79ce42ae539dfcca7855f` 加未提交修改 |
| YZboard-Dash | `bcb89c2d8d9283cf8e7d0a95e826f2fe5f6b1c70` 加未提交修改 |

保留工作区原有改动。本次范围为节点运行日志加密及上传、机器密钥使用、面板运行与拦截日志接收、访问记录开关，以及管理端说明。两个核心仓库和 Agent 的固定依赖未修改，未进行生产节点或真实面板数据库联调。

## Agent

Go `1.27.3`、Linux amd64、任务临时目录内的 Zig `0.15.2`，使用 `cc -target x86_64-linux-gnu.2.36` 完成并发检测。最终九个包的 300 个顶层用例通过，无数据竞争；两个既有原生来源 SOCKS 用例因缺少隔离客户端、专用 rootfs 和网络命名空间按条件跳过。

```sh
go test -mod=readonly -race -count=1 -timeout 5m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  ./internal/privacy ./internal/nlog ./internal/agentlog ./internal/accesslog \
  ./internal/panel ./internal/config ./internal/service ./internal/machine ./cmd/yz-agent
```

覆盖密文文件与权限、容量轮转、历史密钥、并发记录、机器日志隔离、日志等级、磁盘故障不回退明文、加密缓存及固定编号重试、其他机器缓存保全。新的独立子进程用例验证重新领取密钥、关闭后重启及再次开启；历史日志均保持原密文。密钥接口确认的策略可直接保护启动记录。

配置回归发现旧绑定清理断言将任意文本中的 `117` 当作遗留机器编号；改为验证实际绑定字段，并增加同编号扩展配置保全检查。生产绑定清理逻辑未改变。

双核心实际日志工厂及处理器的相关并发专项通过，验证运行中切换、节点归属、原日志等级及私有记录读取。Xray 不再让明文记录经过异步标准输出队列。

```sh
go test -mod=readonly -race -count=1 -timeout 2m \
  -tags 'with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api' \
  -run 'RuntimeLog|RunningLogFactory|PanelLogLevel|CustomConfig.*Privacy' \
  ./internal/kernel/xray ./internal/kernel/singbox
```

上述九个包的 `go vet` 静态检查通过。Linux amd64、arm64 的完整 Agent 构建通过，使用正式功能标签、`-mod=readonly`、`CGO_ENABLED=0`；版本为 `v2.39.0`，构建元数据记录工作区基线及 `vcs.modified=true`。固定依赖仍为 YZ-sing-box `v1.16.0` 与 YZ-Xray-core `v26.12.0`。

## 面板

PHP `8.4.27`、PHPUnit `11.5.57`、SQLite 内存数据库：80 项相关用例，79 项通过，766 条断言；1 项 MariaDB 查询计划检查按数据库环境跳过。无新增迁移，没有运行数据库完整发布矩阵。

```sh
php vendor/bin/phpunit --do-not-cache-result \
  --filter 'AgentLogPolicyTest|AgentPrivacyTest|RouteBlockLogTest|LogSettingsTest|AgentLogOwnershipTest|LogManagementTest|TelegramXrayCrashTest|ProxyAccessLogTest'
```

覆盖原机器密钥和历史恢复、身份范围、隐私机器运行日志入库与防重、崩溃通知、记录开关、迟到批次、等级筛选、访问及拦截记录、保存期限和到期清理。拦截记录仍不读取通用日志开关。三个受影响 PHP 程序文件语法检查通过。

## 管理端

Node.js `24.22.1`、Vite `7.3.7`、Playwright `1.63.0`。`npm run check` 的 3,126 份源码检查、正式构建、构建资源检查均通过；274 份资源、125 份 gzip 副本集成本地面板，面板 `node --test tests/admin-dist.test.cjs` 的五项资源检查通过。

```sh
npm run check
npm run build
npm run check:build
npm run sync:panel
npm run test:browser -- machine-log-policy.spec.mjs
```

浏览器使用现有 Playwright Chromium；缺少的共享库和中文字体只在任务临时目录解包，通过环境变量加载，没有安装系统软件。最终 1440、390 像素的两项浏览器场景通过，覆盖新说明、取消、保存失败重试、其他字段编辑及反复开关，已查看桌面和手机截图。未运行全站 `verify`。

主脚本仍为 `assets/admin-BYr07d9F.js`，SHA-256 为 `23c53d8fda53e818b10423c5595c4b07af21d3dbd15b67dbcfb027b585a43979`；本次界面变更位于三种语言资源。临时编译器、验证程序、共享库、字体和截图在任务结束后清理。

使用及兼容说明见[隐私保护模式](agent-log-policy.md)和[Agent 加密日志](../../YZ-Agent/docs/log-privacy.md)。
