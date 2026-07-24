# YZboard 兼容矩阵

本文件记录面板、Node、Xray fork 和 sing-box 的可回滚兼容关系。面板版本与兼容标识必须和对应 Node Release、Xray fork commit 及变更说明一起发布。

## 当前版本

| 项目 | 标识 |
| --- | --- |
| YZboard 面板版本 | `1.0.2` |
| 面板兼容标识 | `xray-v26.7.11-yz.1` |
| YZboard-Node 发布版本 | `v0.1.0-yz.1` |
| Xray 官方预发布 Tag | `v26.7.11` |
| Xray 上游 Tag commit | `50231eaff98ccc31b5cbd247a721c16e97fe5ec1` |
| YZ-Xray-core fork 版本 | `v26.7.11-yz.1` |
| YZ-Xray-core fork commit | `620bee93867095f73880056cdfb08bc54a15f69e` |
| Node Xray replace pseudo-version | `v0.0.0-20260724203739-620bee938670` |
| sing-box `require` 版本 | `v1.13.2` |
| sing-box 实际 replacement | `github.com/cedar2025/sing-box v1.14.0-alpha.2.0.20260316103356-2e665cb7e295` |

三个项目的内部版本不强行相同：面板使用自己的语义版本，Node 使用独立的 Release Tag，Xray 使用“上游版本 + YZ fork patch”格式。官方 `v26.7.11` Tag 不由 YZ fork 创建或覆盖。

## Node report 兼容约束

- 新版 Node 为每次刷出的流量批次携带 `report_id`，失败重试复用同一批次 ID。
- 面板按节点和 `report_id` 在 24 小时内只认领一次流量批次，避免 HTTP 响应丢失时重复派发累加任务。
- 重复请求仍会刷新 `last_check_at`、`last_push_at`、在线连接缓存和节点指标；只有已认领的流量批次不会再次累计。
- 不带 `report_id` 的旧 Node 请求保持兼容，仍按旧协议处理。
- 流量方向保持 `[upload, download]`；用户流量和节点累计流量仍由既有队列任务按倍率处理。

## 发布与回滚

发布前应同时确认：

1. 面板 `config/app.php`、`CHANGELOG.md` 和构建镜像标签均指向 `1.0.2`；
2. Node Release 使用独立的 `v0.1.0-yz.1` Tag，并在二进制 `-v/version` 输出中显示 Xray 上游与 fork 信息；
3. Node `go list -m -json github.com/xtls/xray-core` 的 replacement 路径和 pseudo-version 指向 `620bee...`；
4. Xray fork 的 `v26.7.11-yz.1` Tag 指向同一 fork commit；
5. sing-box 请求版本和实际 replacement 版本与上表一致。

回滚时，面板镜像、Node Release 和 Node 的 Xray replace 应成套退回上一条兼容矩阵记录，不要只修改其中一个版本字段。
