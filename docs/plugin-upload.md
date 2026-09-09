# 插件上传限制

从正式版 `1.13.3` 起，管理端允许上传最大 **64 MiB** 的插件 ZIP，即 `67,108,864` 字节。恰好达到上限的文件允许上传，超过上限一字节即拒绝；文件格式仍需通过原有 ZIP 校验。

## 请求大小配置

旧版插件接口和 Octane Swoole 默认都限制为 10 MiB。包含本地 IP 数据库的插件包可能超过这个大小，导致请求在进入安装逻辑前返回 HTTP 413。管理端原先没有对应提示，容易显示为“未知错误”或“未知异常”。

新版同时调整三个位置：

| 位置 | 新上限 | 作用 |
| --- | --- | --- |
| 插件上传接口 | `65536 KiB` | 约束单个插件 ZIP 为 64 MiB |
| Docker PHP | `upload_max_filesize=64M`、`post_max_size=80M` | 单文件大小和整次表单请求大小 |
| Octane Swoole | `package_max_length=80 * 1024 * 1024` | 在 HTTP 接收阶段允许完整上传请求 |

请求上限大于文件上限，是为 multipart 表单字段和分隔内容留出空间。管理端也会在发送前检查文件大小；服务器返回 413 时显示“上传文件过大，请检查服务器上传限制”。

PHP 配置位于 `.docker/php/zz-xboard.ini`，Swoole 配置位于 `config/octane.php`。自行挂载 PHP 配置时，需要确认生效值没有覆盖成更小的限制。非 Docker 部署也应在实际加载的 PHP 配置中设置同样的值，并重启相应 PHP / Octane 服务。

## 外部反向代理

镜像自带的 Caddy 没有额外设置请求大小上限。如果容器前还有 Nginx、OpenResty 或其他网关，也需要让它允许至少 80 MiB 的请求；容器配置无法覆盖外部网关的限制。

Nginx / OpenResty 可在对应站点配置中设置：

```nginx
client_max_body_size 80m;
```

修改后使用实际管理该站点的服务检查配置并重载。若站点已有更大的限制，可以继续使用原值。

## 更新与检查

本修复与默认内核、上游同步一同纳入正式版 `1.13.3`，不单独发布此前的 `1.13.2` 开发版本。实际发布状态以 [兼容矩阵](../YZ_COMPATIBILITY.md) 中的 Tag、完整 commit 和镜像 digest 为准。

确认包含本次修复的正式镜像已发布后，在服务器实际 Compose 部署目录执行以下命令。示例要求服务名确实为 `xboard`，并已备份现有 Compose、持久化数据与回滚镜像信息。

```bash
docker compose pull xboard
docker compose config -q
docker compose up -d --no-build xboard
docker compose ps xboard
docker compose logs --tail=100 xboard
```

检查容器实际加载的 PHP 上限：

```bash
docker compose exec xboard php -r 'echo "upload_max_filesize=", ini_get("upload_max_filesize"), PHP_EOL, "post_max_size=", ini_get("post_max_size"), PHP_EOL;'
```

预期分别为 `64M` 和 `80M`。更新后刷新管理端页面，再上传插件。若仍返回 413，先检查外部网关和自定义 PHP 配置；文件超过 64 MiB 则会在页面中直接提示。

## 本地验证

```bash
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/PluginUploadTest.php
node --test tests/admin-plugin-upload.test.cjs
```

测试调用真实控制器的文件校验并替换安装操作，验证旧上限以上、恰好 64 MiB、超出一字节、非 ZIP 和缺少文件等情况；管理端测试执行实际构建补丁产物的上传方法和错误处理，检查资源引用、重复运行及锚点失效时的行为。
