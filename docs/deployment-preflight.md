# 原生部署预检隔离

维护者：P0me1oo。适用于 YZboard 原生 PHP 部署的源码和依赖准备阶段。

## 为什么必须隔离

Laravel 控制台引导会初始化已启用插件。即使验证脚本只写了 `SELECT`，引导、服务提供者、Composer 脚本和插件本身仍可能写数据库、缓存或发送任务。不能把“检查脚本只有查询”视为只读保证。

2.12.5 的历史预检在没有插件文件的暂存目录引用生产 `.env`，存在误删生产插件安装记录的路径，见[插件安装状态](plugin-installation-state.md)。本次应用修复防止缺失入口删除记录，但部署预检仍须独立于生产连接。

## 准备源码和依赖

1. 从已核对的固定源码准备独立暂存目录。保留锁文件；不复制或软链接生产 `.env`、其他环境文件、`bootstrap/cache/*.php`、持久化 `storage` 或数据库文件。暂存目录中的日志、缓存、插件和公开资源目录独立创建。
2. 执行任何 Composer 或 PHP 检查前，核对实际暂存路径，确认没有指向生产目录的软链接，也没有继承 `APP_BASE_PATH`、`APP_CONFIG_CACHE`、`DATABASE_URL`、数据库、Redis 或其他真实服务凭据。仅设置 `APP_ENV=testing` 无法覆盖已存在的生产配置缓存。
3. 检查现有 PHP、Composer、磁盘空间及目录权限后，按原锁文件安装生产依赖，关闭项目脚本；仅在隔离测试环境中需要时显式运行脚本。

```bash
# 执行位置：已确认的独立暂存源码根目录。
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
composer check-platform-reqs --no-dev
```

不重新生成锁文件，不将生产 `.env` 临时放进暂存目录。Composer 及后续测试都使用受控的进程环境；保留工具正常需要的 PATH、HOME 和 Composer 缓存目录，逐项传入必要变量，不继承整套生产服务环境。

## 启动框架的检查

语法和纯自动加载检查可以不启动框架。需要执行 Artisan、迁移、插件或业务测试时，使用独立测试库与缓存；不能为了让验证通过而接入生产数据库。

本项目局部 PHPUnit 测试可使用 SQLite 内存数据库。以下命令须在已核对且没有真实环境文件的测试源码目录执行；测试依赖需事先按锁文件安装，不能在 `--no-dev` 目录临时补装开发包并直接部署。

```bash
YZ_PREFLIGHT_DIR=$(mktemp -d /tmp/yzboard-preflight-XXXXXX)
env -i PATH="$PATH" \
  APP_ENV=testing \
  APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: \
  CACHE_DRIVER=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  MAIL_MAILER=array LOG_CHANNEL=stderr LOG_LEVEL=critical \
  APP_CONFIG_CACHE="$YZ_PREFLIGHT_DIR/config.php" \
  APP_SERVICES_CACHE="$YZ_PREFLIGHT_DIR/services.php" \
  APP_PACKAGES_CACHE="$YZ_PREFLIGHT_DIR/packages.php" \
  php vendor/bin/phpunit tests/Feature/PluginStatePreservationTest.php \
    tests/Feature/PluginInitializationTest.php tests/Feature/PluginUploadTest.php \
    --do-not-cache-result
```

上面的固定 APP_KEY 仅用于测试。`tests/TestCase.php` 将 SQLite 数据库路径设置为真正的 `:memory:`；独立 Artisan 脚本不能直接照搬并假设同样生效。需要真实 MariaDB、Redis 或插件服务时，应使用任务专用实例、测试凭据和模拟外部请求；默认 `sync` 队列会立即执行任务，也必须确保任务没有生产连接。

独立缓存路径用于避免读取生产配置缓存，也避免测试覆盖现有工作区缓存；执行结束只清理本次创建的临时目录。PHPUnit 结果与源码摘要一并记录，测试通过后不反复运行无关的完整回归。

## 生产状态核对与切换

生产检查通过独立 PDO 连接查询必要字段，连接后开启 `START TRANSACTION READ ONLY`，结束回滚；不引导 Laravel、不调用插件方法、不执行 Artisan。连接参数只在受限进程内读取，输出不包含凭据或配置值。

在暂存预检前、预检后及正式切换后分别核对：

- 插件文件及公开资源内容。
- `v2_plugins` 的插件编号、代码、版本、启用状态、安装时间、创建和更新时间。
- 插件配置的完整内容是否一致。需要保存前后摘要时使用仅在当前任务内保留的随机密钥计算 HMAC；不要将配置、令牌或低熵敏感值的普通摘要写进公开报告。

任何非预期差异都应先查明原因。正常版本升级可能改变插件版本或配置，应逐项说明，不能用“文件未变”代替数据库核对。

取得本次生产更新授权后，按现有原生部署流程停止相关服务、同步已验证程序和依赖，并在正式目录、正式运行账户下显式执行包发现、`xboard:update` 及所需缓存重建，再启动服务和核验。`--no-scripts` 不代表省略正式切换所需的包发现。生产 `.env`、插件、主题和业务数据在切换中保留；测试配置与临时缓存不得进入部署内容。
