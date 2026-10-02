# Xboard

当前版本为 `1.37.3`，配套管理端 `0.18.1`、节点程序 `v2.3.1`。减少未变化设备的缓存重写、快照重建和重复发送，修复管理页面快照去重；保留秒级状态上报和统计采样。设备变化沿用秒级通知，无变化时每五秒完整校准资格、过期及遗漏通知，无需升级节点程序或迁移数据库。设计与验证见 [状态处理优化](docs/runtime-performance.md)，正式发布状态和镜像来源见 [兼容矩阵](YZ_COMPATIBILITY.md)。

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
