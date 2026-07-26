# Changelog

## 1.1.0 - 2026-07-26

- 新增中转节点能力：一个 VLESS 入口节点可以带多个中转逻辑节点，订阅中显示为多个独立的普通节点，共用入口地址、端口和全部 Reality 参数，出口分别落在各自的落地服务器。
- 重新定义节点的“父级节点”字段。父级节点为空时行为不变；父级节点不为空时当前节点是中转逻辑节点，父级节点是客户端实际连接的真实入口。第一版只支持“一个真实入口加一层落地”，中转协议只支持 Shadowsocks。
- 节点表增加 `vless_route`，由面板自动分配 1-65535 的路由编号并写入订阅 UUID 的第 7、8 字节。编号创建后保持稳定，分配游标只增不减，删除节点后不会立即复用。
- 保存节点时校验中转拓扑，拒绝自引用、多层中转、入口套入口、非 VLESS 父级和不支持的中转加密算法。
- 节点配置接口增加 `relay` 段：入口节点下发全部逻辑节点的内部出站参数和路由编号映射，落地节点只下发自己那条内部入站的监听参数。内部认证信息由应用密钥派生，不落库、不进入订阅。
- 落地节点不再下发面板用户，避免在落地端重复统计用户流量。
- 节点上报接口接受 `relay_traffic`，按出站标签或逻辑节点 ID 记入落地节点的节点流量，不参与用户套餐扣费，也不套用倍率。
- 中转逻辑节点强制继承入口的基础倍率和动态时段倍率，自身填写的倍率不参与用户扣费。
- 中转逻辑节点使用自身的心跳、在线数和负载状态，掉线告警不再被跳过；旧语义的子节点保持原行为。

## 1.0.6 - 2026-07-26

- 服务器管理的机器安装命令改用 YZboard-Node 最新正式 GitHub Release 中的安装器，并显式选择 Xray 内核。
- 在线设备 IP 去重后重新生成连续数组，避免 PHP 将带空洞的数组编码为 JSON 对象。
- YZboard Docker 正式版本继续同时发布不可变镜像标签、版本别名和 `latest`，生产 Compose 可固定使用 `latest` 拉取更新。

## 1.0.5 - 2026-07-26

- 订阅响应头中的上传、下载、总量和到期时间统一输出为非负整数，兼容仅接受整数字节数的客户端。
- 该格式化仅发生在订阅响应阶段，不修改数据库流量、节点上报、套餐扣量或限额判断。

## 1.0.4 - 2026-07-26

- YZboard Docker 发布改为从固定 Tag 或完整 commit 构建，不再在镜像内跟随移动分支。
- GitHub Actions 发布 `linux/amd64`、`linux/arm64` 到 `ghcr.io/p0me1oo/yzboard`，并生成“面板版本 + 短 commit”的不可变镜像标签。
- 工作流保留可选的面板版本别名和 `latest`，生产部署要求使用不可变标签并校验多架构 manifest。
- 镜像内版本保持 `config/app.php` 的语义版本，不再在构建期间临时改写成日期和短 SHA。

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
