# 服务端路由预设验证记录

开发阶段验证日期：2026-11-23。版本：YZboard 2.25.0、Dash 0.107.0、Agent v2.44.0。本记录对应开发阶段的未提交工作区，当时未推送、发布或部署；没有安装 WARP、修改系统网络或访问生产服务。

## 来源与范围

- YZboard：`f420c6d2be32804b96346123e5e04af9572f0404` 加当前工作区修改。
- YZboard-Dash：`538c2131fad6968b51e580606362bd6b9f4b8a20` 加当前工作区修改。
- YZ-Agent：`a207b0bde6dcb0beca0286e5df5507a876125987` 加当前工作区修改。

保留 Dash 原有的用户筛选、提现和旧 Telegram 页面整理；构建包含这些既有修改。Agent 在任务开始已有分类转换及排序修改，本次在其基础上补齐数据语义、已有出口校验、恢复行为和测试。两个核心正式依赖未变，没有修改相邻核心仓库。

## 管理端与面板

- `npm run check`：3123 份源码通过，导入路径和绑定有效。
- `node --test tests/route-presets.test.mjs tests/route-conditions.test.mjs`：9 项通过，包含十一项预设、清空旧条件、保留选择、可修改出口及无共享数组污染。
- `npm run build`、`npm run check:build`、`npm run sync:panel`：通过，274 份资源和 125 份 gzip 副本集成至面板。逐字节检查与 `dist/` 一致（仅移除源码映射注释），所有 gzip 解压后等于对应文件。
- `npm run test:browser -- route-management.spec.mjs --output=<临时目录>/browser`：11 项通过，使用面板最终集成资源与完整 CSP；覆盖 1440/390/320 像素，新增十项预设、原测速拦截、空出口校验、保存失败重试、重新编辑、启停与节点选择。已查看桌面和 320 像素截图。
- `php vendor/bin/phpunit --filter 'ServerRouteTest|ServerRouteEnabledTest' --do-not-cache-result`：SQLite 内存库 10 项、203 断言通过，覆盖保存、读取、两核心相同分类下发、空出口拒绝及原绑定、开关与推送。PHP 语法检查通过。
- `node --test tests/admin-dist.test.cjs`：5 项通过。

主脚本 `public/assets/admin/assets/admin-D6yZDFoN.js`，SHA-256：`db784a3b18b108d14c6a3d833658fb9121848057d92ac874da3ab87271ed2437`。保留已有哈希资源，未发布源码映射。

## Agent

Go 1.27.3 / Linux amd64。以下命令均使用 `-mod=readonly -race -count=1` 和正式标签：

```text
with_quic with_utls with_wireguard with_gvisor with_acme with_clash_api
```

通过的范围：

```bash
go test <上述参数> ./internal/model ./internal/kernel ./internal/kernel/geodata ./internal/kernel/xray -timeout=5m
go test <上述参数> ./internal/kernel/geodata ./internal/kernel/singbox -run 'Test(LoadRules|PrepareGeo|GeoRule|BuildRoutes|BuildConfig|CompilePanelRouteRule|RouteConditions)' -timeout=5m
go test <上述参数> ./internal/kernel/singbox -run 'Test(PrivateDomainGuard|SingBoxRuntimeDirectGuard)' -timeout=1m
go test <上述参数> ./internal/kernel/singbox -run '^TestGeoRulesReloadFailureAndRecovery$' -timeout=1m
go test <上述参数> ./internal/kernel/singbox -run '^TestRouteIPBlockBeforeRelayRuntime$' -timeout=2m
go test <上述参数> ./internal/kernel/geodata ./internal/kernel/singbox -run 'Test(LoadRules|IPRule|GeoRule)' -timeout=1m
go test <上述参数> ./internal/service ./internal/kernel/geodata -run 'Test(InvalidConfigSnapshots|ValidateNodeRuntime|ApplyChanges|LandingUserEvent|.*Config.*Recover|LoadRules|IPRule)' -timeout=3m
```

覆盖双核心 BT/UDP 中转拦截与普通流量对照，入口解析域名后的 IP 拦截、重复重载与撤销恢复，域名四种匹配、属性、IP 网段、反选与两个原生匹配器的语义对照，已有自定义逻辑集、重复分类、冲突、缺失／损坏数据、空补集以及已有出口来源校验。

首轮对照发现 IPv4 分类简单取反会在 sing-box 额外匹配 IPv6，改为按实际地址族构造补集后通过。旧内网重载用例在未改路由的 HEAD 上也失败，原因是宿主 `localhost` 的双栈解析与 IPv4 测试服务不一致；改用已有本机 DNS 夹具固定回答后，原拦截、放行、撤销断言全部通过。没有跳过或弱化断言。

实际分类文件专项：从既有 [Loyalsoldier 发布源](https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest) 下载到任务临时目录，十个预设引用分别被 Xray 原生匹配器与 sing-box 原生实例接受。该网络专项通过临时 Go 测试覆盖文件执行，未把大数据文件、绝对临时路径或网络依赖加入仓库测试。

| 文件 | 字节数 | SHA-256 |
| --- | --- | --- |
| geosite.dat | 11482331 | `94e6f902cfa9d4d70ebf3c0138d9cc24175b51885c490cb673ee54f5e1b6ce6f` |
| geoip.dat | 16503149 | `d4ec4c5e5d5a38c1399964ea9fd8c94288426a3b31b483904b67819fc07f4466` |

`go build -mod=readonly -trimpath -buildvcs=true -tags '<正式标签>' ./cmd/yz-agent` 通过。产物为 Linux amd64，`vcs.modified=true`，实际模块仍为 YZ-sing-box v1.16.0、YZ-Xray-core `v0.0.0-20261116081756-36f4516090e3`，无本地替换。本次构建仅作验证，不是发布附件。

## 验证边界与清理

本次修改不涉及数据库查询、事务或迁移，未执行 MariaDB 完整回归；未执行全站前端 verify、Agent 全库发布矩阵、跨架构构建、生产节点或实际 WARP／解锁联调。第三方服务是否可用仍取决于已有出口。

复用现有 Go、Node、PHP 和 Chromium。C 编译器、浏览器缺失共享库与字体只解包至 `/tmp` 的任务目录，没有安装系统软件；测试服务和浏览器已退出。临时数据、覆盖文件、验证程序与依赖在结束时清理，正式源码、文档、Dash 的 `dist/` 及面板集成资源保留。
