# 队列工作命令兼容

面板 2.11.2 修复锁定依赖 Laravel 12.71.0 与 Horizon 5.33.1 的参数定义不一致。框架在启动工作进程时读取 `stop-when-empty-for`，Horizon 自己的命令签名缺少该项，导致工作进程报“参数不存在”后退出，主进程仍可能显示运行中。

应用的 `HorizonServiceProvider` 在工作命令解析完成后补齐缺失参数；默认值 `0` 表示不按空闲时间退出，沿用现有常驻消费行为。显式设置秒数时交由框架处理。没有修改 vendor、依赖锁文件或队列配置；上游已经提供参数时保留原定义。将来升级 Horizon，并验证其自身兼容当前框架后，可移除本段兼容注册。

本修复不删除、重新投递或改写已有任务；恢复工作进程后，由原队列正常消费积压。历史失败任务不会自动重试。无需新增数据库迁移，不要求前端或 Agent 更新。

## 验证

`tests/Feature/HorizonWorkerCompatibilityTest.php` 通过实际控制台命令验证默认参数、显式空闲超时和常驻工作模式；使用专用本机 Redis 验证真实任务消费、删除和空队列轮询。提供 `YZ_TEST_HORIZON_REDIS_PORT`，或复用项目专用 `YZ_TEST_DEVICE_REDIS_PORT`；仅清理随机测试前缀，不连接生产 Redis。

```bash
YZ_TEST_HORIZON_REDIS_PORT=<专用本机端口> php vendor/bin/phpunit tests/Feature/HorizonWorkerCompatibilityTest.php --do-not-cache-result
```

上线后须同时核对实际工作进程、参数错误增量、待处理数量、成功和失败任务。只看到 Horizon 服务为 active 不代表队列已恢复。补处理大量积压时，CPU 和数据库写入可能暂时增加，应按实际吞吐和失败情况判断。
