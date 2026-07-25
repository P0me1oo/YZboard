# Changelog

## 1.0.3 - 2026-07-25

- 服务器管理生成的机器安装命令固定使用 `P0me1oo/YZboard-Node` 的 `v1.13-yz.2` 安装器和 Release。
- 安装命令显式传入 Node 版本，避免后续 `latest` 变化导致面板与 Node 兼容关系漂移。
- Node 安装和升级资产增加 `SHA256SUMS` 完整性校验。

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
