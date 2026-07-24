# Changelog

## 1.0.2 - 2026-07-25

- 增加 `xray-v26.7.11-yz.1` YZ-Xray-core fork 兼容标识。
- 配套 Node 使用 `v1.13-yz.1`，延续上游 `v1.13` 版本线；旧的 `v0.1.0-yz.1` 不作为部署版本。
- Node 重试同一报告批次时，V2 节点流量上报保持幂等，避免重复累计。
- 保持心跳、在线连接状态、指标和 `[upload, download]` 流量方向不变。

## 1.0.1 - 2026-07-06

- Added `node:sync-users` to periodically reconcile online node user lists with
  the panel's current eligibility rules.
- Scheduled node user reconciliation every five minutes so naturally expired
  users are removed from node runtime authentication tables without requiring a
  node restart.
