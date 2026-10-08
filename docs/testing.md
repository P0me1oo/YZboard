# 面板回归测试

## 固定检查范围

维护面板时必须运行 SQLite 和 MariaDB 两套完整 PHPUnit 测试，以及管理端资源检查。不能以 SQLite 通过替代 MariaDB 验证；MariaDB 必须启用严格分组检查（`ONLY_FULL_GROUP_BY`）。

`.github/workflows/tests.yml` 在推送到 `master` 和 PR 时执行以上检查。镜像发布工作流复用同一检查，并通过任务依赖阻止失败版本进入镜像构建与发布。手动发布时，测试和构建使用同一固定源码引用。

自动检查固定使用 PHP 8.2 和 MariaDB `11.4.8`，与当前生产数据库保持在 11.4 系列；数据库版本升级时同时更新测试镜像并重新运行完整回归。管理端源码工程自身的界面测试沿用该工程约定。

## 本地执行

安装项目测试依赖后，在仓库根目录执行 SQLite 完整测试与资源检查：

```bash
php vendor/bin/phpunit --do-not-cache-result
node --test --test-reporter=tap tests/admin-dist.test.cjs
```

MariaDB 测试使用一次性本机实例，只监听 `127.0.0.1`。例如在本地开发机启动以下容器，等待健康检查通过：

```bash
docker run -d --rm --name yzboard-mariadb-test \
  -p 127.0.0.1:33368:3306 \
  -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
  -e MARIADB_DATABASE=yzboard_mariadb_test \
  --health-cmd="healthcheck.sh --connect --innodb_initialized" \
  --health-interval=5s --health-timeout=5s --health-retries=20 \
  mariadb:11.4.8
docker inspect --format '{{.State.Health.Status}}' yzboard-mariadb-test
```

PHP 需启用 `pdo_mysql` 扩展。Linux / macOS 执行：

```bash
YZ_TEST_MARIADB_PORT=33368 php vendor/bin/phpunit --bootstrap tests/bootstrap-mariadb.php --do-not-cache-result
```

Windows PowerShell 执行：

```powershell
$env:YZ_TEST_MARIADB_PORT = '33368'
php -d extension=pdo_mysql vendor/bin/phpunit --bootstrap tests/bootstrap-mariadb.php --do-not-cache-result
```

测试入口强制使用本机 `yzboard_mariadb_test` 专用库和一次性实例的空密码账号，不读取部署数据库连接；该库会被完整重建，不能存放需要保留的数据。入口核对真实 MariaDB，引导应用后再次核对测试库和会话严格分组模式，条件不满足即失败。

测试结束后停止本次创建的实例：

```bash
docker stop yzboard-mariadb-test
```

## 入站 IP 回归

完整测试包含同省份 IPv4 / IPv6 合并、不同原省份的过期 IPv6 合并到“未知”、跨日完整 IP 去重、省份筛选、分页与空结果。原有重复上报、前置地址排除、归属地失败、缓存重载、保留期及权限检查继续执行。

写入失败和删除失败用例在事务内部注入一次查询异常，验证先前写入的回滚和后续重试。避免用建触发器制造故障，因为 MariaDB 建触发器会隐式提交事务，破坏用例的事务隔离，见 [MariaDB 官方说明](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit)。

需要真实提交的节点资料缓存测试逐例清空数据，并在测试类结束后重置迁移状态，避免影响后续事务测试。旧流量排行回归同时检查节点、用户的本期流量、上期流量及变化比例，确保两种数据库输出相同的数字类型。
