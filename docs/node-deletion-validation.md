# 节点确认删除验证

维护者：P0me1oo。日期：2026-11-26。面板 2.31.0、管理端 0.112.0，本地未提交、未发布、未部署。

## 行为与范围

单条和批量删除共用影响预览与事务处理。共享/中转线路引用可在确认后解除，相关线路停用、隐藏；落地池移除被删成员，主线路删除、成员不足或失去入口依赖时停用。池记录保留，主线路为空的池可重新配置。前置池成员和直出池订阅线路继续由各自池入口管理，显示明确原因。

影响确认包含选中节点、关联线路和池配置，实际删除在数据库锁内重新核对，健康检查状态不参与确认。清理引用、历史名称和删除共同回滚，调度缓存与节点通知在提交后刷新。保存、复制节点时再次锁定关联节点，避免表单校验后入口被删除又写回旧关联；落地池保存和调度更新共用池行锁。

新增迁移 `2026_11_26_000001_allow_landing_pool_without_primary` 保留池数据和唯一索引，允许多个待配置池的主线路为空。仍有空主线路的池时拒绝回退该迁移，不自动清理数据。使用与回退见[节点管理](node-management.md#引用节点的删除)。

## 后端

环境：PHP 8.4.27、PHPUnit 11.5.57。SQLite 使用内存数据库；MariaDB 使用本次独立临时容器中的 12.3.3，仅连接本机专用测试库，未使用生产数据库。

最终两个数据库均运行：

```bash
php vendor/bin/phpunit --filter 'ServerDeletion|DeletedServerStatisticsTest|SchedulingTest|ServerSwitchTest|ServerRelayTest|DirectPoolTest|PoolMemberValidationTest|ServerKernelDefaultsTest|ServerRouteTest|DdnsTest|NodeRuntimeMetadataTest'
```

MariaDB 通过 `tests/bootstrap-mariadb.php` 和本次临时映射端口运行同一筛选。

| 数据库 | 结果 | 断言 |
| --- | --- | --- |
| SQLite | 269 项通过 | 5902 |
| MariaDB 12.3.3 | 269 项通过 | 5897 |

新增 19 项检查覆盖：单条/批量确认、共享与中转依赖、主线路与普通成员删除、成员不足、间接依赖池停用、关联变更拒绝旧确认、健康状态变化允许确认、选择不符、重复删除、整批回滚、历史名称、权限、原前置池/直出池保护、主线路重新配置、真实提交通知、回滚不通知和迁移回退保护。两数据库既有测试的条件断言数量不同，测试数相同；未跳过或弱化测试。

全部变更 PHP 文件语法检查及差异空白检查通过。初轮新测试夹具缺少真实机器外键和合法中转参数，按项目已有约束补齐后通过；未改变业务校验来迁就测试。

## 前端与集成

环境：Node.js 24.22.1、Vite 7.3.7、Playwright 1.63.0，复用已有 Chromium。浏览器缺少的共享库及中文字体只下载、解包到本次临时目录，没有安装系统软件。

- `npm run check`：3127 份源码通过。
- `node --test tests/node-behavior.test.mjs tests/scheduling.test.mjs tests/load-balancer.test.mjs tests/load-balancer-api.test.mjs`：39 项通过。
- `npm run build`、`npm run check:build`、`npm run sync:panel`：通过。
- 三种语言的删除文案键与主线路缺失提示齐全。
- 面板 `node --test tests/admin-dist.test.cjs`：5 项通过。
- 274 份集成文件与构建输出逐字节匹配；仅移除正式面板不发布的源码映射注释，125 份 gzip 解压内容一致。

最终浏览器使用面板集成资源、完整内容安全策略和本机模拟接口运行：

```bash
npm run test:browser -- node-deletion.spec.mjs load-balancer.spec.mjs \
  --grep '单条删除|预览读取失败|关联变化及删除失败|批量删除复用|前置池保护|主线路删除后的|落地主备：'
```

11 项通过：1440/320 像素的单条与批量删除、取消、预览读取失败、加载中关闭、迟到响应、关联变化、事务失败重试、提交防重复、待提交禁止关闭、前置池具体拒绝原因，以及落地池新建/启停/失败重试和主线路删除后重新配置。已查看桌面与手机删除确认截图，长名称换行，窄屏确认按钮可达。

正式面板主脚本：`assets/admin-CUgnLkzd.js`。

SHA-256：`40396caf3b1357216ab59bce20bf72094f29edb8e2aab7cdc30e831f893aa7b5`。

仅检查受影响功能和关键依赖，没有运行全站 `verify` 或无关后端测试。浏览器模拟不等同于生产联调。未操作生产节点、部署、提交、推送或发布；原有两份 2.29.0 部署文档保留未动。本次临时数据库容器、浏览器依赖、截图和日志在核对后清理。
