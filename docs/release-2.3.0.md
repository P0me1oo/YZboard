# 2.3.0 发布与 Solid-US LA 更新

维护者：P0me1oo。日期：2026-11-06。

本次交付本地全部面板、管理端和 Agent 改动，包含前置池 Reality 统一、兼容校验和订阅恢复，以及隐私开关和 XHTTP 分流、HTTP/3 适配。仅更新 Solid-US LA 面板；Agent 节点保留原版本。

| 项目 | 版本 | 固定源码 |
| --- | --- | --- |
| YZboard | 2.3.0 | 0f75b799dbcfb757c1e023143f9123fb2a015cde |
| YZboard-Dash | 0.88.0 | 5cf8738697733fd2337de34648c3d12a47a26433 |
| YZ-Agent | v2.32.0 | e9aa39b297a670ce99ce5042cf9979a429f2b002 |

面板包 SHA-256：17f002e18b0050ff0e739b8b1a7a45ae4db2a371d11aa31b57bf4946d867c163。管理端包 SHA-256：cb94453232885e33e3656d10860c76e8d5f28ceaae51a573aa6d2d4db0017dcf。

面板 [固定来源回归](https://github.com/P0me1oo/YZboard/actions/runs/42066176878)完成 PHP 8.2.35、SQLite、MariaDB 11.4.8 严格分组和资源检查。SQLite：1,608 项、31,261 条断言、10 项检查跳过；MariaDB：1,608 项、31,271 条断言全部通过。

生产同版本 PHP 8.4.27、MariaDB 12.3.3 和独立 Redis 测试实例完成相同两套完整回归。源码 991 份文件核对通过；composer.lock 保持 13f51448280db6f551f41d6998656239af62e4c63da20ebd31b75a99d0756e70，无新增数据库迁移。

管理端 [完整检查](https://github.com/P0me1oo/YZboard-Dash/actions/runs/42069528497)的源码、151 项行为、构建及资源检查通过，浏览器 386 项通过、1 项统计位置断言失败。修正只比较不同高度控件上边缘的问题，保留中心对齐、左右顺序、换行和边框检查。产品与依赖源码未改变；[补充发布检查](https://github.com/P0me1oo/YZboard-Dash/actions/runs/42075046371)核对 Git 差异和原始成功分项，仅补测整个统计文件及尚未执行的开发模式，18 项统计页面检查及开发模式检查均通过。

Agent [发布流程](https://github.com/P0me1oo/YZ-Agent/actions/runs/42066161712)完整并发回归、Mihomo、安装器、四组原生防火墙、amd64/arm64 构建通过；12 份安装附件已核对校验值、版本、架构和 VCS 来源。amd64 Agent SHA-256 为 b31e99ca041945a7ebf26d1cef79871d35bab2623454a8cfd8da98db52f12c74，arm64 为 eb4a6f4d0e3b8db111029f1677861511b74b13ec7e4751c5ab821dcd0a41ccfb。两个核心固定依赖未改变。

Solid-US LA 已按原生 PHP 流程更新，运行目录 `/root/yzboard`，账户 `www-data`，程序版本 2.3.0、固定源码 `0f75b799dbcfb757c1e023143f9123fb2a015cde`。三个面板服务正常；管理入口、首页和公开配置接口返回 200，6 份脚本及编辑器工作线程的 HTTP 内容摘要匹配。原 48 台在线机器全部恢复上报，WebSocket 有 47 条已建立连接，Horizon 正常，无新增服务错误。迁移批次 80、141 条迁移和失败队列 713 条保持不变；生产环境配置摘要一致，保留持久化目录。

切换开始于 UTC 2026-11-05 17:33:30，检查三个服务启动完成于 17:33:34。旧版本固定为 v2.2.0 / 84fa6c465c6391f5340a8d60262991d06120c923，复用已有回滚来源，详细记录见[部署核验](deployment-2.3.0.md)。

面板和 Agent 为公开仓库；管理端仓库保持私有，附件由当前授权账号下载校验。三个正式版本及全部附件均可按对应仓库权限获取。

发布入口：[面板 v2.3.0](https://github.com/P0me1oo/YZboard/releases/tag/v2.3.0)、[管理端 v0.88.0](https://github.com/P0me1oo/YZboard-Dash/releases/tag/v0.88.0)、[Agent v2.32.0](https://github.com/P0me1oo/YZ-Agent/releases/tag/v2.32.0)。
