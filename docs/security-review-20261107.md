# 面板与 Agent 审查记录（2026-11-07）

本次发现 5 项业务代码风险，并发现需要集中处理的依赖安全公告。面板其中 4 项已在隔离环境复现；Agent 升级行为已核对实现并运行相关既有测试。

后续处理：用户说明 Agent 的校验清单降级用于兼容 GitHub 不可达的机器，并明确本轮保留该行为；其他四项风险与依赖更新的进度、最终验证见[修复记录](security-fixes-20261107.md)。下文保留审查当时的事实与复现记录。

本报告只记录审查结论，不表示已修复或已部署。生产环境是否暴露相同问题仍需核对实际运行版本和配置。

**范围与版本**

| 项目 | 本次审查基线 | 工作区状态 |
| --- | --- | --- |
| YZboard | HEAD `3b8104a4a1a947073eae566d9956601adb96cb66`，当前工作区标识 2.5.1 | 含原有未提交修改 |
| YZ-Agent | HEAD `8622d86cc5c3e565de344f5c668ad86038cfb97e`，v2.32.0 | 干净 |
| YZboard-Dash | HEAD `ca26cd735d7c74766bd8abeffaf51011396f485a`，当前工作区 0.90.0 | 含原有未提交修改 |

检查包括登录与管理员权限、机器与节点鉴权、WebSocket 会话、流量入账、Agent 通信与升级、管理前端相关变更和依赖。当前未提交的 Reality 与前置池改动也进行了源码阅读；此次确认的主要问题位于已有公共路径。

本次没有 Lite MCP 可用，未连接生产服务器，未核验生产 Nginx、日志、端口暴露或实际安装版本，也未执行公网攻击测试。

**1. P1：Agent 获取不到校验清单时继续执行下载的程序**

位置：`YZ-Agent/internal/agentcli/upgrade.go:49`、`upgrade.go:104`、`internal/agentcli/download.go:157`、`install.sh:1187`。

升级器把所有来源都无法提供校验清单作为跳过 SHA-256 校验的条件，随后给下载文件增加执行权限并直接运行版本查询。安装脚本存在同样的降级行为。版本查询已经是代码执行，不能作为程序可信性的验证。

此外，备用镜像同时可以提供二进制和校验清单。即使校验通过，也只能证明两者一致，不能证明来自项目发布者；没有发现使用独立可信公钥验证发布签名的路径。

触发条件是执行安装或升级，且清单不可用，或选中的第三方镜像被控制。常规安装与远程升级以 root 运行，因此影响可能达到整台节点。此次没有真实执行升级或恶意程序。

建议：默认缺少可信校验即中止；在任何执行前验证固定版本附件及发布者签名。镜像只承担文件传输，签名信任独立于镜像。

验证：相关 Go 测试通过，其中现有 `TestUpgradeContinuesWhenAllChecksumSourcesAreUnavailable` 明确验证了“清单不可用仍升级”的行为。这是风险行为得到确认，不是该行为安全。

**2. P1：重置机器令牌没有撤销已有 WebSocket 连接**

位置：`app/Http/Controllers/V2/Admin/Server/MachineController.php:114`、`app/WebSocket/NodeWorker.php:334`、`app/WebSocket/NodeEventHandlers.php:159`。

重置接口只更新数据库中的 token。已建立的连接后续通过内存登记和节点成员列表判断身份，不比较当前令牌或认证代次，也没有由重置操作发出的断连通知。

隔离复现：建立代表已认证机器的连接状态后调用实际重置方法；旧 token 发起 HTTP 握手返回 401，但同一旧连接的 `report.traffic` 消息仍收到 `traffic.ack`，并新增流量批次。

影响：泄漏凭据在管理员重置后仍可能保有长连接权限，直到连接断开、被新连接替换或服务重启。源码中的配置请求也沿用该连接身份，但此次动态复现只验证流量上报。

建议：为连接记录认证代次；重置后在事务提交时通知长连接进程断开旧会话，同时周期性或在敏感操作前复核，覆盖通知丢失情况。

**3. P1：旧流量接口接受负数，可减少已用流量**

位置：`app/Services/ServerService.php:273`、`app/Jobs/TrafficFetchJob.php:36`；入口包括仍开放的 `/api/v2/server/push`。

旧处理路径只检查上、下行值是否为数字，未要求非负整数；后台任务直接把它们乘以倍率累加到用户用量。新批量报告中的非负处理没有覆盖这个兼容入口。

隔离复现：使用合法机器凭据，经真实 HTTP 路由提交负增量，并执行实际流量任务。初始上、下行各 100，最终分别变为 50、25。

触发需要节点或机器凭据，不是普通未登录用户可直接利用。即使新版 Agent 不再使用该兼容路径，持有凭据者仍能主动调用。

建议：所有报告入口统一使用非负整数、上限和溢出检查；旧接口复用相同校验与结算实现。无需兼容的入口可在确认使用情况后关闭。

**4. P1：机器可以给不属于节点权限组的用户增加流量**

位置：`app/Services/NodeReportService.php:41`、`app/Services/UserService.php:117`、`app/Jobs/ProcessNodeReportBatch.php:169`。

机器鉴权正确验证了 node_id 所属机器，但 traffic 中的用户 ID 没有绑定到该节点的授权用户范围。归一化只处理数据形状与数值，结算直接按用户主键更新。

隔离复现：节点只允许权限组 1，测试用户属于权限组 2，节点用户列表为空。仍可用该机器令牌为该用户报告 700/900 流量，最终用户用量从 100/100 增为 800/1000。

影响：一台机器凭据泄漏或节点被控制后，可污染其他权限组用户的账单并耗尽额度。攻击者需要已知或猜测用户数字 ID。

建议：校验节点曾获授权的用户范围，并把授权版本与报告身份关联。不能简单只取当前“未过期且未超额”的在线用户列表，否则会丢弃合法的延迟报告；需保留有界的历史授权或结算宽限。

**5. P2：发信限流没有同一 IP 的总量限制**

位置：`app/Providers/RouteServiceProvider.php:69`、`app/Http/Controllers/V1/Passport/CommController.php:18`。

限流键是“IP 与邮箱的组合”，并非独立的 IP 限额和邮箱限额。更换邮箱就能得到新额度；控制器内的间隔同样按邮箱计算。

隔离复现：关闭人机验证、关闭邮箱白名单后，同一 IP 连续使用 12 个不同邮箱，请求全部成功并产生 12 个邮件任务。邮件任务已拦截，未实际发送邮件。

影响：上述配置下，未登录请求可持续消耗邮件队列和 SMTP 配额。生产是否启用人机验证、网关限流尚未核验。

建议：保留邮箱间隔，增加独立且适合共享出口的 IP 总额度和全局邮件预算；人机验证作为补充，避免把组合键误当成 IP 上限。

**6. 依赖安全更新：版本命中已确认，可利用性需逐项核对**

面板执行 `composer --no-plugins --no-scripts audit --locked --no-dev --format=json`，126 个生产依赖中有 16 个包命中 58 条公告记录。记录可能重复描述同一漏洞，不能当作 58 个独立可利用漏洞。扫描返回 1 条 critical、16 条 high，其余为 medium、low 或未标等级。

| 生产依赖 | 锁定版本 | 公告记录数 |
| --- | --- | ---: |
| firebase/php-jwt | 6.11.1 | 1 |
| google/protobuf | 4.31.1 | 1 |
| guzzlehttp/guzzle | 7.10.0 | 9 |
| guzzlehttp/psr7 | 2.9.0 | 4 |
| laravel/framework | 12.54.1 | 6 |
| league/commonmark | 2.8.1 | 13 |
| league/flysystem | 3.32.0 | 1 |
| paragonie/sodium_compat | 1.24.0 | 1 |
| symfony/http-client | 7.3.1 | 2 |
| symfony/http-foundation | 7.4.7 | 6 |
| symfony/http-kernel | 7.4.7 | 2 |
| symfony/mailer | 7.4.6 | 1 |
| symfony/mime | 7.4.7 | 2 |
| symfony/polyfill-intl-idn | 1.33.0 | 1 |
| symfony/routing | 7.4.6 | 3 |
| symfony/yaml | 7.3.1 | 5 |

例如 Laravel 公告 [GHSA-f9x3-7vq9-cph6](https://github.com/advisories/GHSA-f9x3-7vq9-cph6) 涉及 TokenGuard，当前应用 API 使用 Sanctum，不能仅凭版本命中声称登录鉴权已被绕过。HTTP、邮件、Markdown 和 YAML 相关依赖仍应集中升级到不命中公告的兼容版本，并验证实际调用路径。

Agent 使用 govulncheck v1.9.0，对 `./cmd/yz-agent ./internal/...` 进行包级扫描，识别 194 个模块。6 个模块命中 10 个不同公告 ID，其中 3 个 ID 涉及扫描范围实际导入的包。未做函数调用可达性分析，也未扫描全部发布构建标签或深入审计两个核心 fork。

| Agent 依赖 | 版本 | 命中公告 | 扫描层级说明 |
| --- | --- | --- | --- |
| github.com/andybalholm/brotli | 1.2.3 | GO-2026-6888 | 命中已导入包；公告修复版本 1.2.7 |
| github.com/klauspost/compress | 1.20.0 | GO-2026-6865 | 命中已导入包；公告修复版本 1.20.2 |
| go.mongodb.org/mongo-driver | 1.4.4 | GO-2021-0112、GO-2026-5327 | 前者命中已导入包；两条公告修复版本分别为 1.5.1、1.17.7 |
| github.com/go-chi/chi/v5 | 5.2.5 | GO-2026-5774、5775、5777 | 此次仅模块版本命中 |
| golang.org/x/crypto | 0.58.0 | GO-2026-5932 | 此次仅模块版本命中，涉及 openpgp |
| golang.org/x/net | 0.61.0 | GO-2026-6860、6866 | 此次仅模块版本命中，涉及 html |

公告可按完整编号在 https://pkg.go.dev/vuln/ 查阅。上表不意味着所有缺陷都能从节点公网协议触发。升级需保留两个核心 fork 的固定来源，并核验相关协议兼容性。

管理前端执行 `npm audit --package-lock-only --ignore-scripts --json`，命中 2 个开发依赖包：

- `vite 7.3.6`：扫描建议 7.3.7；包括开发服务、编辑器启动和路径访问相关问题。
- `source-map-js 1.2.1`：公告 [GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q)，修复版本 1.2.2。

这些命中针对开发依赖，不能直接等同于生产静态页面漏洞。npm 扫描也不覆盖恢复工程中手工保留在 vendor-runtime.js 内的所有第三方代码。

**值得优化的地方**

1. 中转流量归一化存在重复查询。位置 `app/Services/ServerService.php:1021`。对每个“用户、落地节点”组合重复查询节点；本地用 100 个用户引用同一落地节点，确认执行 100 次 SQL。建议先按唯一节点 ID 批量加载，复用归属判断；不要使用跨请求且无法失效的授权缓存。
2. 管理员登录令牌默认有效一年。位置 `app/Services/AuthService.php:24`。建议管理员采用更短有效期与空闲过期，敏感操作要求重新验证；属于安全策略优化，未据此认定存在鉴权绕过。
3. Agent 健康检查开启后监听所有网卡。位置 `YZ-Agent/cmd/yz-agent/main.go:133`。该功能默认关闭；若启用，建议提供显式监听地址，默认环回地址，按监控需要开放，避免不必要的公网连接与状态暴露。

**验证与交付状态**

- PHP 8.4.27、SQLite 内存数据库：现有 `tests/Feature/SecurityHardeningTest.php` 11 项、37 条断言通过。
- 同一隔离环境：临时问题复现 4 项、28 条断言全部确认上述不安全行为；重复查询检查 1 项、2 条断言确认 100 次 SQL。
- 复现使用临时身份和模拟队列、Redis 交互；未发送真实邮件、未使用生产数据库、未连接真实节点。模拟 Redis 的未配置发布调用产生过被应用捕获的警告，不影响上述断言，但不属于真实 Redis 联调。
- Go 1.27.3：运行 `go test ./internal/agentcli ./internal/panel -run 'Test(Upgrade|Checksum|MissingChecksums|VerifyReleaseChecksum|RequestErrors|MachineControl|DeviceHandoverHTTP)' -count=1 -timeout=120s`，两个包通过。
- 完成 Composer、npm 依赖审计及上述 Go 包级漏洞扫描；没有运行自动修复。
- 未运行完整回归、MariaDB 复现、浏览器全站测试或真实节点联调。本机 PHP 版本也不等同于生产版本。
- 没有修改产品代码、版本或锁文件，没有提交、推送、发布或部署。仅新增本审查报告；原有未提交修改保留。
