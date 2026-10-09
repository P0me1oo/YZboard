# Xboard

面板 [`1.59.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.59.1) 已正式发布，配套管理端 `0.34.1`，修复 74 处操作按钮的文字居中及加载状态，并修正相关操作栏的手机布局。双架构镜像、`latest` 和镜像内交付文件已核验，Node 沿用 `v2.9.0`，固定来源与回滚信息见 [兼容矩阵](YZ_COMPATIBILITY.md)。

本版一并纳入 `1.58.0`、`1.59.0` 开发阶段的功能：节点管理增加按名称识别的国家/地区筛选，排序模式底部支持批量置顶、上移、下移和置底，并提供零点趋势与最低流量筛选。无需新增数据库迁移或升级 Node。规则见 [节点管理](docs/node-management.md) 和 [统计分析](docs/statistics.md)。

面板 [`1.57.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.57.0) 已正式发布，配套 Node `v2.9.0`：设备协调复用长连接、稳定来源轻量续期、页面快照合并，并包含省份识别修复。正式 PHP 8.2 两套数据库回归各通过 660 项；双架构镜像、`latest` 和镜像内本次代码、迁移及管理端文件均已核验。先更新面板，再更新 Node；实现、固定来源和回滚参考见 [运行开销优化](docs/runtime-optimization.md) 与 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.56.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.56.0) 已正式发布，配套 Node `v2.8.0`、管理端 `0.32.0`：设备名额满时，新 IP 替换最久没有发起新连接的旧来源，确认旧连接关闭后接入；旧来源冷却 60 秒，有空位可提前恢复。同时交付统计排序、日期布局和入站 IP 省份展示。正式 PHP 8.2 两套数据库测试各通过 648 项，双架构镜像、`latest` 及镜像内全部本次修改的代码和管理端文件已核验。先更新面板，再更新全部相关 Node；规则、固定来源和回滚版本见 [设备换网](docs/device-handover.md)、[统计分析](docs/statistics.md) 和 [兼容矩阵](YZ_COMPATIBILITY.md)。

面板 [`1.54.2`](https://github.com/P0me1oo/YZboard/releases/tag/v1.54.2) 已正式发布：修复 MariaDB 下入站 IP 用户明细读取失败及旧流量排行数字类型不一致。正式 PHP 8.2 流程中，SQLite、MariaDB 两套完整测试各通过 627 项；双架构镜像、`latest` 和镜像内关键文件均已核验。后续推送到 `master`、PR 和镜像发布均执行两套回归，测试入口见 [测试说明](docs/testing.md)，固定来源、镜像摘要和回滚版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

原 `1.54.1` 管理端修改统一纳入 `1.54.2`：配套管理端 `0.31.1`，例外 IP、标签及权限组等多选输入已有内容时隐藏占位提示，清空后恢复。验证及集成记录见 [兼容矩阵](YZ_COMPATIBILITY.md)，Node 无需升级。

面板 [`1.54.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.54.0) 已正式发布，配套管理端 `0.31.0`。统计分析新增“入站 IP”：按用户查看来源地址、省份分类、归属地、ASN，以及首次和最近出现时间。历史保留 30 天，默认查看今天，排除前置服务器 IP；IPv4 使用固定纯真数据库，ASN 和 IPv6 地区沿用 IP2Location，完整成功结果缓存 30 天。新增两张表，Node 无需升级。双架构镜像、latest 及镜像内关键文件已核验，规则见 [统计分析](docs/statistics.md)，固定镜像、验证和回滚版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

保留此前 `1.53.1` 开发修改：流量概览和用户统计默认查看今天，仍可选择其他时间；用户明细沿用排行范围。

上一正式版本 [`1.53.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.53.0) 为本次回滚基线，支持 WireGuard 普通直连节点，可不绑定前置，保留原 WG 中转。配套管理端 `0.30.0`、Node `v2.7.1`，提供用户订阅、独立身份、权限、流量、限速、到期停用及按公网 IP 计算的设备限制；普通 WG 也支持大陆来源拦截。包含原 `1.52.0` 的来源策略改动。历史发布核验见 [兼容矩阵](YZ_COMPATIBILITY.md)，使用规则见 [普通 WG](docs/wireguard-direct.md) 与 [来源拦截](docs/source-policy.md)。

此前的 [`1.51.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.51.1) 配套管理端 `0.28.1`、Node `v2.5.0`，固定镜像保留为历史回滚参考。统计分析新增日期时间弹窗，今天可按分钟筛选，历史及跨日按小时筛选；分钟记录只保留今天，小时记录保留 30 个自然日。节点排行支持名称搜索与流量升降序。固定来源与验证限制见 [发布记录](YZ_COMPATIBILITY.md)，查询边界见 [统计分析](docs/statistics.md)。

上一正式版本为 [`1.50.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.50.0)，配套管理端 `0.27.0`、Node `v2.5.0`。流量概览按所有节点相加；用户统计将中转只归到落地，前置只保留直出。非 1 倍明细显示当时倍率和已扣量归属，套餐扣费与节点额度规则保持不变。

上一正式版本为 [`1.49.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.49.0)，配套管理端 `0.26.0`。用户明细改为排行的二级页面，统计列表支持每页条数选择，单日趋势新增小时展示。包含原 `1.48.1` 的统计图标、已删除节点原名、提醒开关调整；新增名称记录和小时汇总两项迁移，Node 沿用 `v2.5.0`。小时记录按面板首次接收时间归档，旧历史继续按天展示。历史验证见 [兼容矩阵](YZ_COMPATIBILITY.md)。

上一正式版本为 [`1.48.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.48.0)，配套管理端 `0.25.0`。新增独立「统计分析」入口，分为流量概览和用户统计，支持流量趋势、节点及用户排行、用户节点明细。默认最近 30 天，历史保留 30 个自然日；统计口径和上线前历史限制见 [统计分析](docs/statistics.md)。同时发布 `1.47.0` 开发阶段的 Telegram Bot 四项独立提醒及设置，新增两项数据库迁移。该版本发布核验记录见 [兼容矩阵](YZ_COMPATIBILITY.md)，提醒规则见 [Telegram Bot 文档](docs/telegram-bot.md)。

上一正式版本 [`1.46.1`](https://github.com/P0me1oo/YZboard/releases/tag/v1.46.1)，配套管理端 `0.23.1`、Node 沿用 `v2.5.0`。节点类型筛选补齐 WireGuard 和 AnyTLS；Telegram Bot 剩余重置天数改为「流量重置时间：x天」，无新增数据库迁移。历史发布来源与验证结果见 [兼容矩阵](YZ_COMPATIBILITY.md)。

此前开发版本 `1.45.0` 的 Telegram Bot「绑定记录」改为「用户管理」，管理员可在桌面或手机上确认后手动解绑；保留用户账号、套餐和订阅，重复请求不影响重新绑定的新关系。无新增数据库迁移，该功能无需更新 Node。使用方式见 [Telegram Bot](docs/telegram-bot.md)。

此前正式版本 [`1.44.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.44.0)，配套管理端 `0.21.0`。Telegram Bot 绑定记录新增用户名，支持带或不带 `@` 的用户名搜索；绑定时保存，后续私聊消息和按钮操作更新，移除用户名后显示「—」。旧绑定在下次互动时补齐，账号关系仍以 Telegram ID 为准。新增可空用户名的数据库迁移，保留已有绑定。包含此前未发布的欢迎语、订阅入口合并、流量进度条、重置倒计时、到期前 72 小时提示，以及密钥星号显示、启用期间编辑和保存时自动停用流程。Node 沿用 `v2.4.1`。双架构镜像与 `latest` 已发布并核对可获取，验证及来源见 [兼容矩阵](YZ_COMPATIBILITY.md)。

上一正式版本为 [`1.41.0`](https://github.com/P0me1oo/YZboard/releases/tag/v1.41.0)，配套管理端 `0.20.1`。Telegram Bot 私聊菜单新增「重置订阅」，确认后发送新链接，保留原有绑定；旧链接和节点连接凭据失效，需更新客户端订阅。保留套餐权限组选择提示修复，以及 Telegram 页面自动检查、精简统计和密钥显示。Node 沿用 `v2.4.1`；新增短期重置确认字段的数据库迁移，不删除已有绑定。正式镜像与发布核验见 [兼容矩阵](YZ_COMPATIBILITY.md)，功能说明见 [Telegram Bot](docs/telegram-bot.md)。

保留服务器状态过期时的列表修复、用户实时网速和此前修改；不再使用中转来源 IP 白名单与确认提示，仍保留自动端口放行。

[用户管理](docs/user-management.md) 支持表头左对齐、自定义显示列，以及连接数和上传、下载速度排序。实时网速不保存历史，所需的节点上报已随 Node `v2.3.0` 发布；旧 Node 显示未知，其他管理功能继续可用。保留用户增加时长、WireGuard、Telegram Bot 和现有实时通信功能。正式发布状态、来源与历史版本见 [兼容矩阵](YZ_COMPATIBILITY.md)。

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-Channel-blue)](https://t.me/XboardOfficial)
![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 11, focusing on providing a clean and efficient user experience.

## ✨ Features

- 🚀 Built with Laravel 12 + Octane for significant performance gains
- 🎨 Redesigned admin interface (React + Shadcn UI)
- 📱 Modern user frontend (Vue3 + TypeScript)
- 🐳 Ready-to-use Docker deployment solution
- 🎯 Optimized system architecture for better maintainability

## 🚀 Quick Start

```bash
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard && \
cd Xboard && \
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    xboard php artisan xboard:install && \
docker compose up -d
```

> After installation, visit: http://SERVER_IP:7001  
> ⚠️ Make sure to save the admin credentials shown during installation

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins
- [插件上传限制与错误提示](./docs/plugin-upload.md) - 64 MiB 安装包、独立上传超时及网关错误排查
- [Node User Sync Reconciliation](./docs/en/development/node-user-sync.md) - Explains periodic node user-list reconciliation for expired users
- [Mihomo 订阅兼容说明](./docs/mihomo-subscription.md) - 各协议参数核对、ECH / XHTTP 转换、内核版本要求与验证范围
- [节点防火墙和 HY2 端口跳跃](./docs/firewall-port-hopping.md) - 端口列表、范围、自动放行与停用清理
- [节点运行开关](./docs/node-runtime-switch.md) - 单节点与批量启停、显隐单向联动、同服务器节点隔离与独立部署说明
- [服务器 agent 管理](./docs/machine-agent-management.md) - 远程更新与重启、运行版本、IPv4 与 IPv6 公网地址、状态与结果显示
- [REALITY 防盗用模式](./docs/reality-anti-abuse.md) - 伪装回源只放行伪装站点域名，避免节点被当成通用 TLS 转发入口
- [不计入设备数的来源 IP](./docs/device-ip-exclude.md) - 排除 flux 等转发机出口地址，填写规则、生效方式与版本要求
- [接口限流与浏览器安全头](./docs/security-hardening.md) - 各端点限流档位、真实 IP 与 `TRUSTED_PROXIES`、CSP 与安全头配置项
- [YZboard/Xray Compatibility](./YZ_COMPATIBILITY.md) - Fixed Xray fork, Node build, and report compatibility matrix

### Deployment Guides
- [Deploy with 1Panel](./docs/en/installation/1panel.md)
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
- [Deploy with aaPanel](./docs/en/installation/aapanel.md)
- [Deploy with aaPanel + Docker](./docs/en/installation/aapanel-docker.md) (Recommended)

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)

## 🛠️ Tech Stack

- Backend: Laravel 11 + Octane
- Admin Panel: React + Shadcn UI + TailwindCSS
- User Frontend: Vue3 + TypeScript + NaiveUI
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 📷 Preview
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## ❤️ Support The Project

If this project has helped you, donations are appreciated. They help support ongoing maintenance and would make me very happy.

TRC20: `TLypStEWsVrj6Wz9mCxbXffqgt5yz3Y4XB`

## 🌟 Maintenance Notice

This project is currently under light maintenance. We will:
- Fix critical bugs and security issues
- Review and merge important pull requests
- Provide necessary updates for compatibility

However, new feature development may be limited.

## 🔔 Important Notes

1. Restart required after modifying admin path:
```bash
docker compose restart
```

2. For aaPanel installations, restart the Octane daemon process

## 🤝 Contributing

Issues and Pull Requests are welcome to help improve the project.

## 📈 Star History

[![Stargazers over time](https://starchart.cc/cedar2025/Xboard.svg)](https://starchart.cc/cedar2025/Xboard)
