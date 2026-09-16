# YZboard 兼容矩阵

本文件记录面板、Node、Xray fork 和 sing-box 的可回滚兼容关系。面板版本与兼容标识必须和对应 Node Release、Xray fork commit 及变更说明一起发布。

## 管理员两步验证与管理端产物来源改造（1.16.0，未发布）

| 项目 | 标识 |
| --- | --- |
| 修改基线 | `ed855113ccd52bbb15ed82ed1c9b8bc2c92ad7ad`，包含 `1.15.5` 发布验证记录 |
| 修改范围 | 一、管理员可自愿绑定 TOTP 两步验证；二、`public/assets/admin` 由上游子模块改为仓库内产物，来源为 YZboard-Dash 源码工程 |
| 适用范围 | 两步验证仅 `is_admin` 账号；普通用户登录流程完全不变 |
| 绕过路径处理 | 邮件链接登录与快速登录码同样绕过密码，`token2Login` 已一并要求第二步，避免两步验证被绕开 |
| 数据库迁移 | `2026_09_16_000001_add_totp_fields_to_users`，为 `v2_user` 增加 `totp_secret`、`totp_enabled_at`、`totp_recovery_codes`；密钥与恢复码按 `APP_KEY` 加密存储，恢复码另存哈希 |
| 新增接口 | `POST /api/v{1,2}/passport/auth/loginWithTotp`；管理端 `GET /totp/status`、`POST /totp/setup`、`/totp/confirm`、`/totp/disable`、`/totp/recoveryCodes` |
| 依赖变化 | 无。TOTP 按 RFC 6238 自行实现（`app/Utils/Totp.php`），二维码复用已有 `bacon/bacon-qr-code` |
| 锁定自救 | `php artisan reset:totp <邮箱>` 可在验证器丢失时关闭绑定 |
| 配套关系 | 沿用正式 YZ-Agent `v1.14.0`；无 Node 通信、配置下发或核心依赖变化 |
| 本地验证 | PHP `8.4.21`、内存 SQLite：完整回归 232 项测试、3159 个断言通过；其中两步验证 30 项测试（RFC 6238 官方向量 12 项 + 登录流程 18 项）覆盖挑战单次使用、失败锁定、恢复码一次性、邮件链接拦截、封禁账号、密钥不外泄与管理端接口鉴权。管理端产物契约 5 项测试通过 |
| 管理前端 | YZboard-Dash `0.2.0`：登录页第二步与安全设置页两步验证卡片；`npm run verify` 全量通过（源码检查 3002 文件、12 项单元测试、构建检查、9 项浏览器测试含 2 项两步验证、开发模式冒烟） |
| 未在本地验证 | 本机无 Docker，镜像构建与多架构清单由发布工作流验证 |
| 使用说明 | [管理员两步验证](docs/admin-two-factor.md) |

### 管理端产物来源改造

`public/assets/admin` 此前是指向上游 `cedar2025/xboard-admin-dist` 的 Git 子模块（固定 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7`）。`Dockerfile` 的 `git submodule update --init --recursive --force` 与工作流的 `submodules: recursive` 会在构建期从上游重新取回该目录，覆盖任何本地放入的产物；因此五组 YZ 管理端定制只能靠 `.docker/patch-admin-*.php` 在构建期对压缩产物做字符串补丁。

YZboard-Dash 重建的产物已内置这五组定制，再执行那五个补丁会因锚点匹配不到而全部失败（实测五个脚本退出码均为 1）。本次改为：

- 解除子模块登记，删除 `.gitmodules`，`public/assets/admin` 改为仓库内的普通目录，产物随源码提交；
- 新增 `.gitattributes` 规则把该目录标为 `-text`，避免 `text=auto` 改写换行导致仓库、镜像与构建输出的校验值不一致；
- 删除 `.docker/patch-admin-*.php`（5 个）和 `.docker/admin-*.js`（3 个），`Dockerfile` 不再执行 submodule 更新与补丁步骤，改为在构建期校验产物存在，缺失即失败；
- `init.sh`、`update.sh` 移除 submodule 更新；发布工作流去掉 `submodules: recursive`；
- 依赖补丁锚点的 7 个 `tests/admin-*.test.cjs` 已无法成立，替换为 `tests/admin-dist.test.cjs`：校验清单可被 Laravel 入口解析、产物语法正确且不残留未发布的源码映射引用、三语文案齐全、编辑器资源完整，并逐项确认八组 YZ 定制都在产物内。

此后管理端修改在 YZboard-Dash 完成，执行 `npm run verify` 后用 `npm run sync:panel` 同步到面板。该脚本排除 `.map` 并移除产物末尾的映射注释，面板不发布源码映射。

回滚该改造需要恢复 `.gitmodules`、子模块指针 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7` 及上述被删文件；直接回滚到 `1.15.5` 的镜像标签不受影响。

## 新建节点显隐跟随初始开关（1.15.5，已发布）

| 项目 | 标识 |
| --- | --- |
| 当前正式面板版本 | `1.15.5`；Tag、Release 与双架构镜像已于 2026-09-15 发布并核验 |
| 正式来源 Tag / commit | `v1.15.5` / `3558e0095b28498f11d5c1818b8f49443f80f6df`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.15.5](https://github.com/P0me1oo/YZboard/releases/tag/v1.15.5)，已核对为最新正式版本；发布时间 `2026-09-14T20:32:12Z`；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.15.5-3558e00`；`1.15.5` 与 `latest` 已核对指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:5b966aa0fa3b6b55373ccd5f2d4caac45a60e650c57fcc4118c58ec579e22748` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=3558e0095b28498f11d5c1818b8f49443f80f6df`、`version=1.15.5-3558e00` |
| 修改基线 | `98596cf2a6c7b3f114a1439f42b861786893eddc`，包含 `1.15.4` 发布记录 |
| 修改范围 | 新建保存时根据初始开关设置显隐；开启显示、关闭隐藏，未提交开关按默认开启处理，显式空值保留独立部署设置 |
| 既有节点 | 普通编辑保留独立显隐，不批量改写历史记录；此前受影响的节点使用批量“显示节点”，再刷新用户页面和客户端订阅 |
| 配套关系 | 沿用正式 YZ-Agent `v1.14.0` 的配套关系；没有数据库迁移、Node 通信或核心依赖变化 |
| 本地验证 | PHP `8.4.21`、内存 SQLite：完整回归 202 项测试、3055 个断言通过；其中节点开关 22 项测试、622 个断言通过，覆盖实际新建保存、用户列表和最终订阅、重复启停、编辑保留及失败回滚；PHP 语法检查和 `git diff --check` 通过 |
| 正式发布验证 | [面板 CI 34892223416](https://github.com/P0me1oo/YZboard/actions/runs/34892223416)：PHP `8.2.33` 完整回归 202 项测试、3055 个断言及管理端 72 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.15.4-720ee83`；manifest `sha256:3f7e4d760628bf4d97c7e30df1eceb39f8d636ea00c899e6d45a7c54c95e3826`；发布前已核对版本别名、原 `latest`、两个架构及 OCI 来源，可匿名获取 |
| 使用说明 | [节点运行开关](docs/node-runtime-switch.md) |

发布后通过匿名 GHCR 接口核对三个标签、两个平台清单和 OCI 来源，并流式读取两个架构中的最终文件层。下列文件均与固定发布提交的 Git 原始字节一致，包含新建节点显隐跟随初始开关的修改，应用版本为 `1.15.5`；读取的压缩镜像层也已核对完整 SHA256。

| 镜像内文件（两个架构相同） | 字节数 | SHA256 |
| --- | --- | --- |
| `/www/app/Http/Controllers/V2/Admin/Server/ManageController.php` | `16509` | `255cf7177b71a09facadf0bfe8bca227e77d45188951f567d771517f0c98fba0` |
| `/www/config/app.php` | `7117` | `2f0e51f254039e3b6b655fc5e2bb2785682fb70de354680117475902ed66a065` |

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:e5c3c69cae255af8a57609fa78b6395da0d819f97e6c88b3e8e721784fcb993a` |
| `linux/arm64` | `sha256:89f8358684cc827da42697535122bc45a5033ec30558d0e70a6138918bcb9c61` |

本次只需更新面板，沿用现有 Node 配套版本。用户在实际生产 Compose 部署目录保留原镜像与必要备份后，按本版 Release 更新并核对应用版本、HTTP、日志、OCI revision 和镜像 digest；客户端需要更新订阅，已有隐藏节点按上表单独恢复显示。回滚使用上表的 `1.15.4-720ee83` 或对应 digest。本次未连接或更新生产服务器。

## YZ-Agent 一键安装入口与名称（1.15.4，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.15.4`；Tag、Release 与双架构镜像已于 2026-09-15 发布并核验 |
| 正式来源 Tag / commit | `v1.15.4` / `720ee83f17d875dd6bd3ca91140207be2251693c`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.15.4](https://github.com/P0me1oo/YZboard/releases/tag/v1.15.4)，发布时已核对为最新正式版本；发布时间 `2026-09-14T18:55:13Z`；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.15.4-720ee83`；发布时已核对 `1.15.4` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:3f7e4d760628bf4d97c7e30df1eceb39f8d636ea00c899e6d45a7c54c95e3826` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=720ee83f17d875dd6bd3ca91140207be2251693c`、`version=1.15.4-720ee83` |
| 修改基线 | `v1.15.3` / `e425eb28e95763046184475d2868b9d4c61e4da0`，包含发布记录提交 `422be777373959b341a48246a382dceb3926d356` |
| 修改范围 | 一键安装地址改为 `P0me1oo/YZ-Agent`；服务器管理的三种语言统一显示 `YZ-Agent`，保留原安装参数和内核选择规则 |
| 配套 Node | [YZ-Agent v1.14.0](https://github.com/P0me1oo/YZ-Agent/releases/tag/v1.14.0) / `1bd29cbf23a72c682f25b66beae3ec874b527501`；通信和核心依赖保持原约定 |
| 本地验证 | PHP `8.4.21` 完整回归 192 项测试、2889 个断言及管理端 72 项测试通过；实际渲染页面的三种语言共 15 处名称、旧语言包缓存和重复初始化核对通过 |
| 正式发布验证 | [面板 CI 34882239892](https://github.com/P0me1oo/YZboard/actions/runs/34882239892)：PHP `8.2.33` 完整回归 192 项测试、2889 个断言及管理端 72 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.15.3-e425eb2`；manifest `sha256:d9bfb2a500dd565b3c3ccbdea3f5da241f098c7191ebe718a95da0be0f9ad07e`；版本别名、两个架构及 OCI 来源已再次核对，可匿名获取 |
| 使用说明 | [YZ-Agent 一键安装](docs/yz-agent-installation.md) |

发布后通过匿名 GHCR 接口核对三个标签、两个平台清单和 OCI 来源，并流式读取两个架构中的实际文件。下列文件均与固定发布提交的 Git 原始字节一致，包含新安装器地址、页面初始化前的名称转换以及 `1.15.4` 版本；读取的压缩镜像层也已核对完整 SHA256。

| 镜像内文件（两个架构相同） | 字节数 | SHA256 |
| --- | --- | --- |
| `/www/app/Http/Controllers/V2/Admin/Server/MachineController.php` | `6662` | `92cdf6addc1975d6322c115a40221b18385d23bf71560528919378c96ab6e8fb` |
| `/www/resources/views/admin.blade.php` | `3267` | `1fffc64bfb466336eedb8bdbcb17a069c502c820462412580fb330eb8d93332d` |
| `/www/config/app.php` | `7117` | `a6e6e0d1b1f537b943f3aafdc3266a2b05e45b492f7bea7fa964b665df556827` |

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:165e95054e99619627ce06599b8234b44a6d06ebd5a7f5af2170efec2149f85c` |
| `linux/arm64` | `sha256:12ca56c1439b669b8bb0875e4864a329aa639a04c5467dda14b27dbe1c31e29a` |

新旧仓库的 `releases/latest/download/install.sh` 地址已再次下载核对，均返回 YZ-Agent `v1.14.0` 安装器，SHA256 为 `31131e24ace7bcda452cb1df5cf95e18cddf94a8e8229729e59b094983f665a9`。旧地址可用依赖 GitHub 重定向，本版面板直接使用新地址。

发布顺序为 YZ-Agent `v1.14.0`、面板 `v1.15.4`。更新面板即可取得新的安装入口和显示名称；已有节点按对应 Node Release 常规升级，保留原机器身份与绑定。用户在实际生产 Compose 部署目录按本版 Release 更新面板，并核对应用版本、HTTP、日志、OCI revision 和镜像 digest；回滚使用上表 `1.15.3-e425eb2` 或对应 digest。本次未连接或更新生产服务器。本节保留 `1.15.4` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## 节点开关单向联动显隐（1.15.3，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.15.3`；Tag、Release 与双架构镜像已于 2026-09-15 发布并核验 |
| 正式来源 Tag / commit | `v1.15.3` / `e425eb28e95763046184475d2868b9d4c61e4da0`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.15.3](https://github.com/P0me1oo/YZboard/releases/tag/v1.15.3)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.15.3-e425eb2`；发布时已核对 `1.15.3` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:d9bfb2a500dd565b3c3ccbdea3f5da241f098c7191ebe718a95da0be0f9ad07e` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=e425eb28e95763046184475d2868b9d4c61e4da0`、`version=1.15.3-e425eb2` |
| 修改基线 | `c5333caef4d917aa3d9b00d5205a8a7f80ddab30` |
| 修改范围 | 单节点与批量启停同时设置显隐；单独显隐不触发启停，普通编辑保留显隐设置；同一事务保存，失败全部回滚 |
| 配套关系 | 沿用正式 Node `v1.13.1` 的配套关系；没有数据库迁移、Node 通信或核心依赖变更 |
| 本地验证 | PHP `8.4.21`、内存 SQLite：192 项测试、2889 个断言通过；Node.js `24.14.1` 管理端 72 项测试通过；PHP 语法检查和 `git diff --check` 通过 |
| 正式发布验证 | [面板 CI 34876727278](https://github.com/P0me1oo/YZboard/actions/runs/34876727278)：PHP `8.2.33` 完整回归 192 项测试、2889 个断言及管理端 72 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.15.2-db46a6d`；manifest `sha256:46933f4c5cf9cc9aacbe5c1baefd22bf15bb9472668f5d0d1778af9f74cb6164`，发布前已核对两个架构、OCI revision 与原 `latest` 一致，可匿名获取 |
| 使用说明 | [节点运行开关](docs/node-runtime-switch.md) |

发布后通过匿名 GHCR 接口核对三个标签、两个平台清单和 OCI 来源，并流式读取两个架构的最终文件层。以下文件均与固定发布提交的 Git 原始字节一致，包含单节点及批量启停联动显隐的修改，应用版本为 `1.15.3`；读取的压缩镜像层也已核对完整 SHA256。

| 镜像内文件（两个架构相同） | 字节数 | SHA256 |
| --- | --- | --- |
| `/www/app/Http/Controllers/V2/Admin/Server/ManageController.php` | `16104` | `b487f5ac3306e30cfd05c4f3c22de808e82fee8f854a7066847230b7fa26c30d` |
| `/www/config/app.php` | `7117` | `b7dfa7b221beffa4c65226f84a6e61fc31a600f9d3651ca096d0e2c74e81e26f` |

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:1501f7e4658747b9bb63f5d09ee47e3b0f77415811d0b186459f4063e69db58c` |
| `linux/arm64` | `sha256:f0687aaa87dde58f0cdebd4c96631b9dc8a441e4701916179ca5c968147d39d6` |

本次只需更新面板，沿用现有 Node 配套版本；没有连接或更新生产服务器，也未进行真实节点链路复测。用户在实际生产 Compose 部署目录保留原镜像和必要备份后，按本版 Release 中的命令更新并核对应用版本、HTTP、日志、OCI revision 和镜像 digest。运行启停在 Node 完成同步后生效，客户端需要更新订阅。需要回滚时使用上表的 `1.15.2-db46a6d` 或对应 digest；本节保留 `1.15.3` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## sing-box VLESS 中转路由身份修复（1.15.2，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.15.2`；Tag、Release 与双架构镜像已于 2026-09-13 发布并核验 |
| 正式来源 Tag / commit | `v1.15.2` / `db46a6d7979380d479d5dcf9b1a1345eaff7518c`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.15.2](https://github.com/P0me1oo/YZboard/releases/tag/v1.15.2)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.15.2-db46a6d`；发布时已核对 `1.15.2` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:46933f4c5cf9cc9aacbe5c1baefd22bf15bb9472668f5d0d1778af9f74cb6164` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=db46a6d7979380d479d5dcf9b1a1345eaff7518c`、`version=1.15.2-db46a6d` |
| 修改基线 | `3fcbf3b344e596d3242b37dd49f1cd2df07619ac` |
| 修复范围 | sing-box VLESS 订阅使用已写入路由编号的节点身份，保留前置直出和各落地的独立选路；普通节点保持原有身份 |
| 核心对照 | 官方 sing-box `v1.14.0` / `0b8995879f29a9b98ee027bc17b75e101445b238`；VLESS 出站将配置中的 `uuid` 直接传给客户端实现 |
| 配套关系 | 沿用正式 Node `v1.13.1` 的配套关系；没有数据库迁移、Node 通信或核心依赖变更 |
| 本地中转回归 | PHP `8.4.21`、内存 SQLite：38 项测试、895 个断言通过；覆盖最终 sing-box/Mihomo 订阅、双核心组合、VLESS/HY2 入口、SS/VLESS 落地、重复生成、停用恢复、身份轮换及失效拓扑 |
| 本地完整回归 | PHP `8.4.21`、内存 SQLite：185 项测试、2546 个断言通过；修改涉及的 PHP 文件语法检查和 `git diff --check` 通过 |
| 正式发布验证 | [面板 CI 34736580561](https://github.com/P0me1oo/YZboard/actions/runs/34736580561)：PHP `8.2.33` 完整回归 185 项测试、2546 个断言及管理端 72 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.15.1-8714e57`；manifest `sha256:c8f969bfda12e1707ad33621e4fbbddea55ce5d818fa502d2241895d4c700ef2`，发布前已核对两个架构、OCI revision 与原 `latest` 一致，可匿名获取 |

新增检查在修复前复现了三个出口共用一个身份、落地路由编号被原始 UUID 字节覆盖的问题，修复后通过。
业务行为已通过本地及正式 CI 的订阅生成和面板回归验证，未在 Android 设备或真实服务器链路上复测。说明见 [中转节点文档](docs/relay-nodes.md#sing-box-vless-路由身份)。
发布后通过匿名 GHCR 接口逐项核对三个标签、两个平台清单、配置摘要和 OCI 来源，并流式读取两个架构的实际镜像文件层。
下列文件均与上述固定发布提交的 Git 原始字节一致，包含保留节点路由身份的修复，应用版本为 `1.15.2`；读取的压缩镜像层也已核对完整 SHA256。

| 镜像内文件（两个架构相同） | 字节数 | SHA256 |
| --- | --- | --- |
| `/www/app/Protocols/SingBox.php` | `34004` | `b2e2dfe4b73d110123dde8b5dec8632c8cd779d9372b256a9f3206ef046d0d9e` |
| `/www/config/app.php` | `7117` | `3b1770dbd90b9a62adf0b26920825f2184fa5036f0a1debbcaa62ca037947635` |

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:1ae33f4ec32b794691cf0b0e325ded958949a76a981b737a14e47da6cc212ce8` |
| `linux/arm64` | `sha256:767dfac58e22f4111756b3b11a4d12993936c686b705a2217edcb94fba4af366` |

本次只需更新面板。用户在实际生产 Docker Compose 部署目录确认项目、服务名、现用镜像和持久化挂载，备份必要数据并保留旧镜像，再按本版 Release 的命令更新和检查。
更新后确认应用版本、HTTP、日志、OCI revision 和运行容器的 digest，并在 sing-box 客户端刷新远程配置、重新连接后复测出口 IP。回滚使用上表的 `1.15.1-8714e57` 或对应 digest；本节保留 `1.15.2` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## 插件上传独立超时与错误提示（1.15.1，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.15.1`；Tag、Release 与双架构镜像已于 2026-09-13 发布并核验 |
| 正式来源 Tag / commit | `v1.15.1` / `8714e570cfc9a271d0348e25cd01a497a270137e`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.15.1](https://github.com/P0me1oo/YZboard/releases/tag/v1.15.1)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.15.1-8714e57`；发布时已核对 `1.15.1` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:c8f969bfda12e1707ad33621e4fbbddea55ce5d818fa502d2241895d4c700ef2` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=8714e570cfc9a271d0348e25cd01a497a270137e`、`version=1.15.1-8714e57` |
| 修改基线 | `04ba530ac07a89184c3ca071596ade939f7d367e`；更新前的正式版本为下节的 `1.15.0` |
| 管理端基线 | 上游子模块固定为 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7`，通过现有构建补丁应用修改 |
| 上传行为 | 插件 ZIP 请求独立使用 5 分钟超时；普通请求维持 30 秒，64 MiB 大小限制不变 |
| 错误处理 | 保留服务端消息、表单详情和客户端原因，区分浏览器超时、断网与网关错误；插件上传页面只提示一次 |
| 配套关系 | 沿用正式 Node `v1.13.1` 的配套关系；没有数据库迁移、Node 通信或核心依赖变更 |
| 本地验证 | 完整管理端 72 项测试通过，其中插件上传相关 16 项；PHP `8.4.21` 完整回归 183 项测试、2420 个断言通过；补丁重复执行、资源引用及 PHP / JavaScript 语法检查通过 |
| 正式发布验证 | [面板 CI 34728102435](https://github.com/P0me1oo/YZboard/actions/runs/34728102435)：PHP 8.2 完整回归 183 项测试、2420 个断言及管理端 72 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.15.0-3cafec4`；manifest `sha256:db915f06f5c8737da13ba1c307ebf49afdd3b3c40f278896a3396a11ce9ed241`，发布前已核对两个架构、OCI revision 与原 `latest` 一致，可匿名获取 |
| 配置说明 | [插件上传限制与错误提示](docs/plugin-upload.md)；Octane 执行限制默认仍为 60 秒，外部网关超时独立生效 |

上传测试执行生成产物中的实际方法、错误处理和页面回调，验证超时原因只显示一次、失败后恢复上传状态且不自动重发；同时覆盖旧补丁升级、重复运行、资源引用和锚点失效时不写入半成品。

发布后通过匿名 GHCR 接口核对三个标签、两个平台清单和 OCI 配置，并从镜像最终文件层读取应用版本、管理端脚本、manifest 与 HTML。两架构的 `assets/index-e6d83733.js` 均为 `6552596` 字节，SHA256 为 `e4197b5deb7d35f2a68502c0497c72179a891cccd23f627c94d3e5f3536fc015`；与发布提交及固定子模块的 Git 原始字节重建结果一致，包含 5 分钟插件上传配置及完整错误处理，入口引用有效。

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:ba6d740efd36938e6cd0bdd269b63cce42479ea9f39c33db55b6b62aa756245e` |
| `linux/arm64` | `sha256:db61b1fd70fea5db9565e7824c8e680f1c98889e36b5d69b20974f899d20b470` |

本次只需更新面板，没有新增 Node 升级要求。在实际生产 Compose 部署目录确认项目、服务名、现用镜像和持久化挂载，备份 Compose 与必要数据，并保留旧镜像。服务名为 `xboard`、镜像配置为 `ghcr.io/p0me1oo/yzboard:latest` 时执行：

```bash
docker compose pull xboard
docker compose config -q
docker compose up -d --no-build xboard
docker compose ps xboard
docker compose logs --tail=100 xboard
```

更新后确认面板 HTTP 正常、应用版本为 `1.15.1`，运行容器的 OCI revision 和镜像 digest 与上表一致；完整检查命令见本版 Release。未确认稳定前保留旧镜像，需要回滚时临时固定到 `1.15.0-3cafec4` 或对应 digest 后重建。本节保留 `1.15.1` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## 节点内核筛选与批量权限组选项（1.15.0，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.15.0`；Tag、Release 与双架构镜像已于 2026-09-13 发布并核验 |
| 正式来源 Tag / commit | `v1.15.0` / `3cafec40d273911fed4b6811535539a18cd7fb03`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.15.0](https://github.com/P0me1oo/YZboard/releases/tag/v1.15.0)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.15.0-3cafec4`；发布时已核对 `1.15.0` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:db915f06f5c8737da13ba1c307ebf49afdd3b3c40f278896a3396a11ce9ed241` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=3cafec40d273911fed4b6811535539a18cd7fb03`、`version=1.15.0-3cafec4` |
| 正式发布验证 | [面板 CI 34720505629](https://github.com/P0me1oo/YZboard/actions/runs/34720505629)：PHP 8.2 完整回归 183 项、2420 个断言及管理端 62 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.14.0-8e360ae`；manifest `sha256:ba4d6204f8b10f9a0f9675bff5299bc8d548ad31898b46f5293a219eda5ae046`，发布前已核对两个架构与原 `latest` 一致 |
| 功能提交与管理端基线 | 功能提交 `9b1ae3b2a9207ffa29b5946de892c520ce33b159`；管理端子模块固定为 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7` |

- 本次包含此前未发布的 `1.14.1` 权限组选项修复；内核筛选的修改基线为 `c7cae437351c9e56d80689356f3e8b222034dfdd`。
- 节点列表的类型旁新增内核筛选，支持 sing-box、Xray 多选及与现有条件组合筛选；分类与 `Server::effectiveKernelType` 一致，兼容别名、大小写与历史空值。
- 筛选列仅用于过滤和数量统计，首次渲染及切换排序模式均隐藏；桌面和窄屏复用原有筛选控件，重置与列表刷新沿用表格状态。
- 添加选项保留至少一个所选节点缺少的权限组，移除选项保留至少一个所选节点已拥有的权限组；编号按字符串比较，空选、重新选择和列表刷新均按当前数据处理。
- 固定管理端子模块不变，在现有构建补丁中筛选菜单，兼容已有批量权限组补丁并更新资源摘要和入口引用。
- 沿用现有增量批量更新接口与 Node `v1.13.1` 配套关系，没有数据库迁移、Node 通信字段或核心依赖变更。
- 本地验证：管理端 62 项测试通过，含新增 7 项内核筛选与此前 10 项权限组菜单测试；直接执行生成产物中的表格库、列定义和筛选组件，覆盖组合筛选、数量统计、刷新、排序模式及批量操作联动。
- PHP `8.4.21`、内存 SQLite：内核分类与批量权限组测试共 3 项、20 个断言通过。
- 从修改基线的完整管理端补丁升级与全新构建生成的产物一致；完整补丁重复执行、资源名称与入口引用、JavaScript 和 PHP 语法、发布工作流及锚点变化时不改写原文件的检查通过。

发布后通过匿名 GHCR 接口逐项核对三个标签、两个平台清单、OCI 配置和最终管理端补丁层的 SHA256。两架构的 `assets/index-b66aed64.js` 均为 `6549272` 字节，SHA256 为 `b5f4414a94a3b657771af363278cec15fbbeb011ae20f4632c8a034ea82b2717`；文件与按上述固定提交的 Git 原始字节重建的产物逐字节一致，包含内核筛选、权限组选项筛选及原有节点运行开关，manifest 和 HTML 引用一致，入口文件名与正式 CI 构建输出一致。

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:0c90ed5318266ac912d9fbc836f86715cacc64763a96f59bb112ff741d74f933` |
| `linux/arm64` | `sha256:efbfb1d6d3edd1f3a82acc10c99b552d129311c99a50e3c97ade924fc36f5a28` |

该版仅需更新面板，沿用原有 Node 配套版本；其回滚基线为上表的 `1.14.0-8e360ae`。本节保留历史发布记录，当前正式版本和 `latest` 来源以本文顶部记录为准。

## 节点运行开关（1.14.0，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.14.0`；Tag、Release 与双架构镜像已于 2026-09-13 发布并核验 |
| 正式来源 Tag / commit | `v1.14.0` / `8e360ae81a52ebf1cd4f8d0bd32dd788057f3862`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.14.0](https://github.com/P0me1oo/YZboard/releases/tag/v1.14.0)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.14.0-8e360ae`；发布时已核对 `1.14.0` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:ba4d6204f8b10f9a0f9675bff5299bc8d548ad31898b46f5293a219eda5ae046` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=8e360ae81a52ebf1cd4f8d0bd32dd788057f3862`、`version=1.14.0-8e360ae` |
| 正式发布验证 | [面板 CI 34714913767](https://github.com/P0me1oo/YZboard/actions/runs/34714913767)：PHP 8.2 完整回归 183 项、2420 个断言及管理端 45 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.13.5-e342cbe`；manifest `sha256:72046ae0fe8300c89508b61aeb787577b6a8a7b92e5717baa6abdeb432d53d1c`，发布前已核对两个架构与原 `latest` 一致 |
| 修改基线 | `5a03ea2669467820e1949a2c48e398f8b423ea6d` |
| 管理端 | 固定子模块不变，追加单节点运行开关构建补丁，列由 `show` 改为 `enabled` |
| 控制范围 | 当前节点编号；服务器启用状态、同服务器其他节点及共享通信连接保持独立 |
| 配套关系 | 沿用 `1.13.5` 的 Node `v1.13.1` 配套关系和现有机器发现机制，没有新增通信字段或数据库迁移 |
| 独立部署 | 未绑定服务器的节点在列表中显示不可操作，需在部署端启停 |
| 本地回归 | PHP `8.4.21`、内存 SQLite：183 项测试、2420 个断言通过；管理端 45 项测试通过，含节点开关 12 项 |
| 浏览器验证 | 本地模拟接口，桌面和窄屏卡片的单节点关闭、重新开启与失败恢复通过；未操作真实节点服务器 |
| 使用与验证范围 | [节点运行开关](docs/node-runtime-switch.md) |

发布后通过匿名 GHCR 接口逐项核对三个标签、两个平台清单、OCI 配置和节点运行开关补丁层的 SHA256。两架构的 `assets/index-68ee5c1c.js` 均为 `6548150` 字节，SHA256 为 `fe7f0a73b2de396202ca977aec893cd19ee3b5106b01a9fe3c494d47f1bdc059`；注入脚本与固定 Git 提交中的 `.docker/admin-node-switch.js` 一致，运行列、桌面与窄屏表头及排序模式完整，manifest 和 HTML 引用均指向该入口，文件名与正式 CI 构建输出一致。

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:ad8fdaa20bc2e22b444e8a971aebaf5560417a12096d8e51fd667910f03e8ca3` |
| `linux/arm64` | `sha256:bfec3180d8e110a98ac6b02866046446437d612c7619b4d592109289b7cfe4c3` |

本次只需更新面板，沿用现有 Node 配套版本，没有连接或更新生产服务器。服务器更新由用户在实际部署目录执行，沿用 Compose 的 `latest`；更新前保留原镜像、Compose 和必要的数据备份，需要回滚时使用上表的 `1.13.5-e342cbe`。单节点隔离已通过回归和本地模拟接口验证，未进行真实节点服务器启停测试；实际启停在 Node 完成同步后生效。本节保留 `1.14.0` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## 内部端口冲突检查（1.13.5，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.13.5`；Tag、Release 与双架构镜像已于 2026-09-11 发布并核验 |
| 正式来源 Tag / commit | `v1.13.5` / `e342cbedab78eebf2326a4b709d9c01391f47e1a`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.13.5](https://github.com/P0me1oo/YZboard/releases/tag/v1.13.5)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.13.5-e342cbe`；发布时已核对 `1.13.5` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:72046ae0fe8300c89508b61aeb787577b6a8a7b92e5717baa6abdeb432d53d1c` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=e342cbedab78eebf2326a4b709d9c01391f47e1a`、`version=1.13.5-e342cbe` |
| 正式发布验证 | [面板 CI 34572296684](https://github.com/P0me1oo/YZboard/actions/runs/34572296684)：PHP 8.2 完整回归 176 项、2289 个断言及管理端 33 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.13.4-127a590`；manifest `sha256:ba9e18904000fd1ad96928910e22fa510a01062dd136147bb037eb4b0f93f714`，发布前已核对两个架构与原 `latest` 一致 |
| 修改基线 | `a022a5e859e9f762a65f70d57624bf99388eb029` |
| 检查范围 | 同一绑定服务器、内部端口和 TCP/UDP 监听交集；连接端口不参与重复检查 |
| 复制兼容 | 保留两个端口、绑定服务器和启用状态；未改变监听占用的编辑仍可保存 |
| 批量操作 | 换绑、内核切换和重新启用采用相同校验，任一冲突回滚整批变更 |
| 配套关系 | 沿用当前正式 Node `v1.13.1` 和固定核心依赖，没有新增通信字段或数据库迁移 |
| 管理端 | 固定子模块不变，构建时追加内部端口补丁并更新资源入口名称 |
| 本地回归 | PHP `8.4.21`、内存 SQLite：176 项测试、2289 个断言通过；管理端 33 项测试通过，包含新增 14 项交互和构建补丁用例 |
| 浏览器验证 | 本机 Chrome 独立实例与模拟接口：字段冲突提示、重复端口拦截、TCP/UDP 同号共存、复制后保留端口编辑通过，没有 JavaScript 异常 |
| 使用与验证范围 | [内部端口检查](docs/node-port-validation.md) |

发布后通过匿名 GHCR 接口逐项核对三个标签、两个平台清单和 OCI 配置的 SHA256、版本与完整来源提交，并读取两个架构的内部端口补丁层。两者的 `assets/index-1059ff9a.js` 均为 `6544724` 字节，SHA256 为 `3528645a0ba0b4c5f4b63915b5bb3ebf17dafbe9b485a8a7485d76ca7e9da02e`；字段检查、保存拦截和错误提示代码完整，manifest 和 HTML 引用一致且指向该入口，文件名与正式 CI 构建输出一致。

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:3dbaeab06a01d3833a5437f04266d9a8ff547c34a187ea1d0a3af94f8bba9794` |
| `linux/arm64` | `sha256:cf6d9886aa577e464b7557e02d311e264e45d8a6f0b2f9f489c140d00f1a038a` |

本次只需更新面板，没有连接或更新生产服务器。服务器更新由用户在实际部署目录执行，沿用 Compose 的 `latest`；更新前保留原镜像、Compose 和必要的数据备份，需要回滚时使用上表的 `1.13.4-127a590`。本地数据库验证使用内存 SQLite，未验证 MySQL 多连接并发或真实服务器监听。本节保留 `1.13.5` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## 套餐默认折扣移除（1.13.4，历史发布）

| 项目 | 标识 |
| --- | --- |
| 当时正式面板版本 | `1.13.4`；Tag、Release 与双架构镜像已于 2026-09-10 发布并核验 |
| 正式来源 Tag / commit | `v1.13.4` / `127a590fc19ad7cb0fe7e7d1bc95c01e676c1e32`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.13.4](https://github.com/P0me1oo/YZboard/releases/tag/v1.13.4)，发布时已核对为最新正式版本；交付物为 GHCR 镜像，没有独立安装附件 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.13.4-127a590`；发布时已核对 `1.13.4` 与 `latest` 指向同一镜像，可匿名获取 |
| Docker manifest | `sha256:ba9e18904000fd1ad96928910e22fa510a01062dd136147bb037eb4b0f93f714` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=127a590fc19ad7cb0fe7e7d1bc95c01e676c1e32`、`version=1.13.4-127a590` |
| 正式发布验证 | [面板 CI 34418748588](https://github.com/P0me1oo/YZboard/actions/runs/34418748588)：PHP 8.2 完整回归 142 项、2042 个断言及管理端 19 项测试通过，双架构构建与清单核验成功 |
| 面板回滚基线 | `ghcr.io/p0me1oo/yzboard:1.13.3-f88d243`；manifest `sha256:fe398445e0f793c50585f184e1b6babcb80bd33379297676c7d03612b381330e`，发布前已核对两个架构与原 `latest` 一致 |

- 套餐编辑页基础价格按月付、季付、半年付、年付、两年付、三年付分别乘以 `1 / 3 / 6 / 12 / 24 / 36`，移除内置折扣；流量包和重置包继续使用基础价格。
- 各周期仍可单独修改。再次输入基础价格会重新填入所有周期；打开已有套餐时保留已保存价格，没有数据库迁移或批量调价。
- 管理端子模块继续固定在 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7`，通过 `.docker/patch-admin-plan-prices.php` 在现有补丁之后移除折扣，并同步更新入口文件名称、manifest 和 HTML 引用。
- Node 通信和两个核心依赖保持原配套关系，本次只需更新面板，没有新增 Node 或核心版本要求。
- 本地 PHP 8.4.21 完整回归 142 项、2042 个断言及管理端 19 项测试通过；其中新增 8 项覆盖整数与小数填价、重复输入、空值与零值、已有价格恢复、完整补丁重复执行及锚点异常。PHP 补丁与版本配置、生成的 JavaScript 和发布工作流均通过检查。

发布后通过匿名 GHCR 接口逐项核对三个标签、两个平台清单和 OCI 配置的 SHA256、版本与完整来源提交，并读取两个架构的套餐价格补丁层。两者的 `assets/index-4ca546f6.js` 均为 `6540131` 字节，SHA256 为 `a929827d06e09004a8e6c7f17a3d6cddd92ce6f24362b5a2ed7c991353f32504`，与直接读取上述 Git 提交原始文件、按 Linux 换行格式应用全部补丁生成的产物一致；manifest 和 HTML 引用均指向该文件。

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:fefa4299df7893aad512a6d5bd8e43608406216dcfab49d05855f77c55412e09` |
| `linux/arm64` | `sha256:7d39a936f2450108c04da1fd15c6654a39b070af8c4232489cb754a243151cd6` |

本次未连接或更新生产服务器。服务器更新由用户在实际部署目录执行，沿用 Compose 的 `latest`；更新前保留原镜像、Compose 和必要的数据备份，需要回滚时使用上表的 `1.13.3-f88d243`。本节保留 `1.13.4` 发布时的审计记录，当前正式版本和 `latest` 来源见本文顶部。

## 上游同步与功能合并发布（1.13.3，历史发布）

| 项目 | 标识 |
| --- | --- |
| 面板版本 | `1.13.3`；统一包含上游同步、默认内核和 64 MiB 插件上传，Tag、Release 与双架构镜像已发布 |
| 正式来源 Tag / commit | `v1.13.3` / `f88d243b7e7683e183bf0a3d62af1173220b0855`；后续发布记录提交不改变此构建来源 |
| 正式 Release | [v1.13.3](https://github.com/P0me1oo/YZboard/releases/tag/v1.13.3)；2026-09-10 发布并核验 |
| 不可变镜像标签 | `ghcr.io/p0me1oo/yzboard:1.13.3-f88d243`；`1.13.3` 与 `latest` 已核对指向同一镜像 |
| Docker manifest | `sha256:fe398445e0f793c50585f184e1b6babcb80bd33379297676c7d03612b381330e` |
| Docker 平台与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=f88d243b7e7683e183bf0a3d62af1173220b0855`、`version=1.13.3-f88d243`，应用版本为 `1.13.3` |
| 同步前面板提交 | `2f2991633d7f9d841165e68e66aff50af494cce3` |
| 上游来源 | `F:\xboard\Xboard` 的 `master`，来源为 `https://github.com/cedar2025/Xboard`；已核对本地提交与 GitHub 分支一致 |
| 上游固定区间 | `8ecb762d77ef16491fe919b7092aea66b834deed` → `4f48e61a2cbc6db5338872b6bdb45ef954ec1256`，共 6 个提交 |
| 本地合并提交 | `5035629137eccd3b828890238e7cb2caf8c11b40`；包含上游源码、测试冲突处理及补充回归用例 |
| 修改范围 | 重复下单、支付和退款保护，礼品卡重新校验与奖励回滚，余额抵扣后佣金计算，负金额结算校验，支付宝时间格式，VLESS 名称编码及 Shadowrocket HY2 带宽 |
| 已有兼容实现 | 设备 IP 去重后重新编号已由 YZboard 实现；保留节点索引、状态快照、中转、端口跳跃和订阅流量头等扩展 |
| 配套关系 | Node `v1.13.1` 与面板 `v1.13.3` 均已正式发布；服务器更新顺序为 Node、面板；没有新增数据库迁移，也未改变 Node 通信字段或固定核心依赖 |
| 配套 Node 固定来源 | `v1.13.1` / `ebc52dfd522c140bb03ac34940b46ad77523c58c`；安装器、xbctl 与 Node 使用同一 Release |
| 配套 Node 发布 | [v1.13.1](https://github.com/P0me1oo/YZboard-Node/releases/tag/v1.13.1)；10 个附件的下载摘要和四个程序的实际构建信息全部通过核验 |
| 配套 Node 镜像 | `ghcr.io/p0me1oo/yzboard-node:v1.13.1`；manifest `sha256:6cd65852f0c11a85296660add721f843a6f94d0f7b0ccc81d72e72076638b1eb`；版本、完整提交标签和 `latest` 一致，包含两个 Linux 架构 |
| Node 回滚基线 | `v1.13-yz.24` / `af69ef598f4f75b5bfa0509b1e1d01a653378d47`；manifest `sha256:86c8e0f646ae124fbf44e862bdf3796a5090dd9bb66856826ced8c68662dae24`，已核对两个架构 |
| 本地验证 | PHP `8.4.21`、内存 SQLite：142 项测试、2042 个断言通过；其中新增及同步的 19 项用例、90 个断言通过；管理端 11 项测试通过，相关 PHP 语法及语言 JSON 检查通过 |
| 正式发布验证 | [面板 CI 34380213867](https://github.com/P0me1oo/YZboard/actions/runs/34380213867) 从上述固定 Tag 完成 PHP 8.2 回归：142 项测试、2042 个断言，以及管理端 11 项测试；双架构构建与清单核验通过 |
| 发布前 GHCR 标签核对 | 2026-09-10 查询 GHCR：`latest` 与 `ghcr.io/p0me1oo/yzboard:1.13.0-2f29916` 指向同一摘要，来源 `2f2991633d7f9d841165e68e66aff50af494cce3`；清单包含 `linux/amd64`、`linux/arm64` |
| 发布前镜像摘要 | `sha256:376fc1668d9a51531098b4d35c0787fe3d4e8e3a10d9b93ca3e720d1cd71cd20`；可作为本次发布前的镜像回滚基线 |

两处测试初始化冲突保留 YZboard 的内存数据库、缓存隔离和节点通信扩展测试；没有删除上游新增的订单测试。订单相关用例验证旧模型、重复调用、退款失败重试及礼品卡事务回滚；本次未执行 MySQL 多连接并发测试。正式镜像构建和校验已由发布工作流完成。

原有默认内核和 64 MiB 插件上传修改一并纳入 `v1.13.3` 的发布提交；此前 `1.13.1`、`1.13.2` 为未发布的开发计划，不另行创建 Tag。配套 Node 使用标准正式版本 `v1.13.1`，历史版本保留原名用于升级和回滚。

正式发布工作流先在 PHP 8.2 运行完整面板和管理端补丁测试，再构建 `linux/amd64`、`linux/arm64` 镜像。发布后通过匿名 GHCR 接口获取镜像索引、平台清单和 OCI 配置，逐项核对内容 SHA256、来源、版本及两个目标架构；不可变标签、版本别名和 `latest` 均指向本节摘要。

| 面板镜像平台 | 平台 manifest |
| --- | --- |
| `linux/amd64` | `sha256:6ea2e12da1d13bca2e31fbc9309caebe73a1d3a2278045bd08004d1e83b40402` |
| `linux/arm64` | `sha256:e96396695c332926e5c18e8c97e5e7272e9c8428bff8666fe256a8c0d306c7c1` |

配套 [Node 正式 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34378744231) 已完成 Linux 全量与依赖测试、数据竞争检测、安装器测试、双架构构建和镜像版本运行检查。四个安装程序均为 Go 1.26.4、`CGO_ENABLED=0`、`vcs.modified=false`，实际模块来源保持固定核心依赖；安装器与 Node 发布提交一致。

服务器更新由用户执行。`1.13.3` 发布时的面板回滚基线为本节记录的 `1.13.0-2f29916` 镜像，Node 回滚基线为 `v1.13-yz.24`。以下各节的“待发布”等状态仅对应当时的验证阶段；当前正式发布引用与面板回滚基线见本文顶部记录。

本地复核命令（在 YZboard 仓库根目录执行，Windows PHP 临时加载已安装的扩展）：

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium vendor/bin/phpunit --do-not-cache-result
node --test tests/admin-kernel-defaults.test.cjs tests/admin-plugin-upload.test.cjs
git merge-base --is-ancestor 4f48e61a2cbc6db5338872b6bdb45ef954ec1256 HEAD
```

## 插件上传限制修复（纳入 1.13.3）

| 项目 | 标识 |
| --- | --- |
| 目标面板版本 | `1.13.3`；与默认内核、上游同步一同发布，实际发布引用见顶部记录 |
| 插件上传限制 | 单个 ZIP 最大 `64 MiB`，前后端使用相同边界 |
| 运行环境 | Docker PHP：`upload_max_filesize=64M`、`post_max_size=80M`；Octane Swoole：`package_max_length=83886080` |
| 管理端 | 固定上游子模块保持原提交，镜像构建追加上传补丁；超限文件不发送，HTTP 413 显示明确提示 |
| 本地验证 | PHP 8.4.21 全量 123 项测试、1952 个断言通过；上传相关 6 项和既有内核相关 5 项管理端测试通过，生成产物语法与入口引用有效；PHP 实际加载上限为 `64M` / `80M` |
| 配套关系 | 上传修复没有新增 Node 或核心要求；当前工作树的节点内核和端口跳跃功能仍按下文对应版本配套 |
| 部署说明 | [插件上传限制与更新步骤](docs/plugin-upload.md)；外部反向代理需要允许至少 `80 MiB` 请求 |

本节保留该功能开发阶段的验证结果，完整版本的验证与镜像发布状态见顶部记录。

## 新建节点默认内核（纳入 1.13.3）

| 项目 | 标识 |
| --- | --- |
| 目标面板版本 | `1.13.3`；与插件上传、上游同步一同发布 |
| 修改基线 | 面板 `2f2991633d7f9d841165e68e66aff50af494cce3`，Node `af69ef598f4f75b5bfa0509b1e1d01a653378d47` |
| 配套 Node | `v1.13.1`；新安装和新增绑定默认 sing-box，VLESS 默认 Xray；单节点未指定协议时先读取面板配置 |
| 新建规则 | 除 VLESS 外默认 `singbox`；VLESS 默认 `xray`；手动选择优先，创建时保存明确的 `kernel_type` |
| 已有节点 | 编辑、复制、重复下发保留原内核；数据库中的空值继续按 Xray 解释，无需迁移或批量改写 |
| 安装命令 | 机器安装命令不强制传 `--kernel`，由新版安装器和 xbctl 区分新绑定与已有实例；升级程序不改内核 |
| 内核依赖 | 沿用 Xray `v26.7.11-yz.6` 与 sing-box `v1.14.0-yz.2`，不修改通信字段和两个核心仓库 |
| 本地验证 | PHP 8.4.21 全量 116 项测试、1927 个断言通过，覆盖 V1 单节点协议发现和 V2 机器节点接口；管理端生成产物语法及 5 项交互逻辑、幂等测试通过，旧补丁升级与全新构建产物一致 |

管理端构建补丁同时处理干净产物和已应用旧版内核下拉补丁的产物。新建表单随协议更新默认值，手动选择后保留选择；编辑旧节点时不套用新默认值。

Node `v1.13.1` 的安装器、xbctl 与面板 `1.13.3` 已按顺序发布。Node 的旧配置加载规则仍将省略内核的历史配置解释为 Xray，具体说明见 Node 的兼容矩阵。

## 节点防火墙与端口跳跃（1.13.0，待发布）

| 项目 | 标识 |
| --- | --- |
| 目标面板版本 | `1.13.0`；本轮完成开发与隔离验证，尚未创建正式 Tag、Release 或镜像 |
| 面板修改基线 | `1bbe4010105a9413ca23548ddbd2a6ce5752f495` |
| 面板功能提交 | `cee871e25a54b180e384346689664f538c8c2887` |
| 配套 Node | `v1.13-yz.24`；开发基线 `1d74d19cbb738e6fcde466361c24a186b8deec60`，兼容 `v1.13-yz.23` 的安装目录和升级回滚 |
| Node 验证构建 | `v1.13-yz.24-dev` / `7b3a7b434790ecf7238b4977d02db5610b4db7e5`；两个 Linux 架构的 Node、xbctl 构建和来源检查通过，完整普通 Go 回归、firewall 包竞争检测及安装器 20 个场景通过 |
| Xray 固定依赖 | `v26.7.11-yz.6` / `b4caa82d6414196565599c19ebc1b53e331349b6` |
| sing-box 固定依赖 | `v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427` |
| 修改范围 | HY2 端口集合校验、配置接口的 `port_hopping`、订阅端口选择和 sing-box 范围输出；没有数据库变更 |
| 本地回归 | PHP 8.4.21，112 项测试、1762 个断言通过；覆盖配置接口、停用节点发现、订阅和保存校验 |
| Linux 联测 | YT-HK 专用 rootfs 和隔离网络；四组原生防火墙组合通过，Xray/UFW/nftables 和 sing-box/firewalld/iptables 的实际 Node、官方 HY2 客户端测试通过 |
| 联测边界 | Node 使用临时面板接口替身，真实 Laravel API 由本地功能测试验证；没有部署远程完整面板或操作生产环境 |
| 清理和架构范围 | 本轮服务器测试目录、实例和临时认证数据已清理；实际代理验证为 Linux amd64，arm64 仅完成构建和元数据检查 |
| 使用说明 | [节点防火墙与端口跳跃](docs/firewall-port-hopping.md)；Node 侧详细结果见其 `docs/firewall-validation.md` |
| 升级和回退 | 先更新 Node、再更新面板和订阅；回退面板 `1.12.1`、Node `v1.13-yz.23` 前先取消跳跃或准备手工转发，正常停止新版完成规则回收 |

以下保留正式发布记录。本节没有改变已发布镜像的版本标签或 `latest`。

## Mihomo 订阅修复（1.12.1）

| 项目 | 标识 |
| --- | --- |
| 当前面板源码版本 | `1.12.1`；Tag、Release 与双架构镜像已发布 |
| 面板修改基线 | `4eb7238e381e8afa217cfbbea76c02f266ae1660` |
| 本版发布 Tag / commit | `v1.12.1` / `0f2b708cdd5c7bdfc346831356f12712e3ed37a3` |
| 本版 Release | [v1.12.1](https://github.com/P0me1oo/YZboard/releases/tag/v1.12.1)，最新正式版本 |
| 本版不可变镜像 | `ghcr.io/p0me1oo/yzboard:1.12.1-0f2b708`；`1.12.1` 与 `latest` 已同步 |
| 本版 Docker manifest | `sha256:39b49d8879da52402c2e8710fb65b9248a63a07596d5aced1cf907638f45ade1` |
| Docker 架构与 OCI 标识 | `linux/amd64`、`linux/arm64`；两架构均为 `revision=0f2b708cdd5c7bdfc346831356f12712e3ed37a3`、`version=1.12.1-0f2b708` |
| 配套 Node / 服务端核心 | 沿用下方 `1.12.0` 的固定依赖，无数据库变更 |
| 面板回滚 Tag / commit | `v1.12.0` / `c2d6873ec055dbb8d48184eb296d50db6c85e529` |
| 面板回滚镜像 | `ghcr.io/p0me1oo/yzboard:1.12.0-c2d6873` |
| 回滚 Docker manifest | `sha256:39065c1b1fb66537e62f8b18f8c44c21a8ae0e64b3d120944573a17cfd471aa6`；两架构与来源已重新核对 |
| Mihomo 核对源码 | `fc8c5a24b16991f98cd736950c17d1aa306a5041` |
| 修改范围 | ECH / XHTTP、常见传输参数、Shadowsocks 插件、Mieru、HTTP TLS、Reality 指纹、入口内核与多路复用、sing-box HY1 ALPN、内核版本过滤 |
| 字段和验证记录 | [Mihomo 订阅兼容说明](docs/mihomo-subscription.md) |

### 本版发布验证（2026-09-08）

[面板发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34241282162) 从 `v1.12.1` 固定提交构建并发布两个 Linux 架构，构建与多架构清单检查全部通过。
通过 GHCR 匿名拉取接口重新获取清单、架构清单和镜像配置，校验响应内容的 SHA256、OCI 来源及版本；不可变标签、版本别名和 `latest` 均指向上表摘要。

| 架构 | 架构清单 digest | 镜像配置 digest |
| --- | --- | --- |
| `linux/amd64` | `sha256:c0b77b1d84266edac82003fe1c9026183399ba4562ba94bf3b1d4e0aff017148` | `sha256:e0a84cd5ed180755ab3ccab4848f00675e6f965dae2da6c11635153e2d6ff170` |
| `linux/arm64` | `sha256:5131ccbe1ca06401c633156ce8125d1cb7d3668401893154652db2da9039219f` | `sha256:fc805e69ceb80421d95afbc26df366d05dcfbed5f18e47df883bfb44fed78d7d` |

发布源码的完整面板回归为 105 项测试、1693 个断言；Mihomo 扩展核对中，68 组配置、11 种出站类型、226 项字段检查通过实际解析。
测试使用本地 PHP 8.4.21、内存 SQLite 和固定 Mihomo 源码，具体范围见兼容说明。双架构镜像完成构建及元数据核验，本次未进行实际线路握手或转发测试。

Node 继续使用 `v1.13-yz.22`，Xray、sing-box 依赖和数据库结构不变。已使用 `1.12.0` 配套版本时，只需更新面板并刷新客户端订阅。
回滚面板后也应刷新订阅，旧版不包含本次参数修复；Node 和核心无需随本补丁回退。
发布记录以独立文档提交补充，已发布 Tag 和镜像的源码固定在上表提交；本次没有操作生产服务器。下方保留 `1.12.0` 的历史发布与回滚记录。

## HY2 ECH 前置入口（1.12.0）

| 项目 | 标识 |
| --- | --- |
| 本节面板版本 | `1.12.0`；Tag、Release 与正式镜像已发布 |
| 本次面板修改基线 | `f5d5075baf9592080a9bd0a1dba8c492f3684cc3` |
| 配套 Node 版本 | `v1.13-yz.22`，正式来源 `2aa021b65481a14b1d34ff9f594939387c5f0f05` |
| Xray 固定依赖 | `v26.7.11-yz.6` / `b4caa82d6414196565599c19ebc1b53e331349b6`，本次没有修改核心 |
| sing-box 固定依赖 | `v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427`，本次没有修改依赖 |
| 修改范围与验证 | HY2 ECH 候选、Node 密钥写入、Mihomo 订阅参数及客户端版本判断；见 [验证记录](docs/hy2-ech-validation.md) |
| 部署顺序 | 先更新 Node，再更新面板和客户端订阅 |

以下记录本版正式发布结果；回滚基线为面板 `1.11.0` 与 Node `v1.13-yz.21`。

## 历史正式发布引用与回滚基线：1.12.0

| 项目 | 标识 |
| --- | --- |
| YZboard 面板源码版本 | `1.12.0`；固定来源使用同名语义版本 Tag |
| 面板兼容标识 | `xray-v26.7.11-yz.6` |
| YZboard 上游仓库 | `https://github.com/cedar2025/Xboard.git` |
| YZboard 上游基线 | `master` 固定快照 / `8ecb762d77ef16491fe919b7092aea66b834deed` |
| YZboard 本版发布 Tag / commit | `v1.12.0` / `c2d6873ec055dbb8d48184eb296d50db6c85e529` |
| YZboard 本版 Release | [v1.12.0](https://github.com/P0me1oo/YZboard/releases/tag/v1.12.0) |
| YZboard 回滚 Tag / commit | `v1.11.0` / `f91568d72ffb55205cbcd9b15a8476283a017683` |
| YZboard 回滚 Docker 镜像 | `ghcr.io/p0me1oo/yzboard:1.11.0-f91568d` |
| YZboard 回滚 Docker manifest | `sha256:9ec52732a2f93f77e1ae6f34e314cf9399b26a8c4febf4a82db2e32cd7e651b4`；包含 `linux/amd64` 与 `linux/arm64` |
| YZboard Docker 架构 | `linux/amd64`、`linux/arm64` |
| YZboard 本版 Docker 构建 | `ghcr.io/p0me1oo/yzboard:1.12.0-c2d6873`；`1.12.0` 与 `latest` 已同步并核对同一 digest |
| YZboard 本版 Docker manifest | `sha256:39065c1b1fb66537e62f8b18f8c44c21a8ae0e64b3d120944573a17cfd471aa6` |
| YZboard 本版 Docker OCI 标识 | 两架构均为 `revision=c2d6873ec055dbb8d48184eb296d50db6c85e529`、`version=1.12.0-c2d6873` |
| YZboard-Node 兼容版本 / commit | `v1.13-yz.22` / `2aa021b65481a14b1d34ff9f594939387c5f0f05`；HY2 ECH 中转需成套升级 |
| YZboard-Node 本版 Release | [v1.13-yz.22](https://github.com/P0me1oo/YZboard-Node/releases/tag/v1.13-yz.22)；10 个附件均已下载核验 |
| YZboard-Node 本版 Docker manifest | `sha256:8c831eca80ebc66680055e0f6f443a2c0e1830a28fc6bab638fc7a0487a54235`；版本、完整提交和 `latest` 标签一致，含两个 Linux 架构 |
| YZboard-Node 上一正式版本（回滚） | `v1.13-yz.21` |
| YZboard-Node 上游基线 | `v1.13` / `0a29338e1f102a462363ce3527417029f89bab28` |
| YZboard-Node 回滚 commit | `2f08f4134d352e127828e1e15aeaa4cfd479864c` |
| YZboard-Node 回滚 Release | GitHub [Release v1.13-yz.21](https://github.com/P0me1oo/YZboard-Node/releases/tag/v1.13-yz.21)；发布记录见 Node 兼容矩阵 |
| YZboard-Node 回滚 Docker manifest | `sha256:500bd8ac445a38ae76550bc2d66c7fd9700515255ab92e9276020bc6136984a0`；包含 `linux/amd64` 与 `linux/arm64` |
| Xray 官方预发布 Tag | `v26.7.11` |
| Xray 上游 Tag commit | `50231eaff98ccc31b5cbd247a721c16e97fe5ec1` |
| YZ-Xray-core 源码 Tag | `v26.7.11-yz.6` |
| YZ-Xray-core fork commit | `b4caa82d6414196565599c19ebc1b53e331349b6` |
| Node Xray replace pseudo-version | `v0.0.0-20260907200713-b4caa82d6414` |
| sing-box `require` 版本 | `v1.14.0` |
| sing-box 实际 replacement | `github.com/P0me1oo/YZ-sing-box v1.14.0-yz.2` / `09615a105e219076330d9d2a25ea1e2e733d5427` |
| SS2022 关闭补丁 | Node 的 `compat/sing-shadowsocks`，随 Node 固定提交发布；上游 `v0.2.8` / `e0612494bafdd1429e9632bc52fd278585d28690` |

三个项目的内部版本不强行相同：面板使用自己的语义版本，Node 使用独立的 Release Tag，Xray 使用“上游版本 + YZ fork patch”格式。官方 `v26.7.11` Tag 不由 YZ fork 创建或覆盖。

## 历史发布验证：1.12.0（2026-09-08）

[面板发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34173634258) 从 `v1.12.0` 的固定提交构建并发布两个 Linux 架构。
不可变标签、版本别名和 `latest` 的 manifest digest 一致，两个架构的 OCI 来源与版本均已核对，支持匿名拉取。
[面板 Release](https://github.com/P0me1oo/YZboard/releases/tag/v1.12.0) 已设为最新正式版本；面板完整测试为 60 项、813 个断言。

配套 Node 的 [正式发布 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34172378951) 完成 Linux `make test`，
主模块和六组兼容模块共 661 项测试及子测试通过，无失败、无跳过、无数据竞争报告。
双架构安装包、镜像和来源检查通过，10 个 Release 附件已下载核对摘要及实际构建信息，`vcs.modified=false`。
Linux amd64 runner 已执行实际 ECH 握手和八组中转组合；arm64 镜像通过 QEMU 运行版本检查，未进行 arm64 实际转发。
本地 sing-box 与官方 Mihomo `1.19.9` 联测、公共参数及环境限制见 [HY2 ECH 验证记录](docs/hy2-ech-validation.md)。

Node 先于面板发布。生产环境先升级相关 Node，再更新面板和客户端订阅。
回退至 Node `v1.13-yz.21` 或面板 `1.11.0` 前，应先关闭 HY2 入口的 ECH 并刷新订阅。
发布记录以单独文档提交补充，已发布 Tag 和镜像来源保持不变；本次没有操作生产服务器。

## 历史发布验证：1.11.0（2026-09-08）

[面板发布 CI](https://github.com/P0me1oo/YZboard/actions/runs/34161072639) 从 `f91568d72ffb55205cbcd9b15a8476283a017683` 构建并发布两个 Linux 架构。
不可变标签、面板版本别名和 `latest` 的 manifest digest 一致，各架构的 OCI 来源及版本均已核对。
[面板 Release](https://github.com/P0me1oo/YZboard/releases/tag/v1.11.0) 已创建并设为最新正式版本；面板完整测试为 57 项、549 个断言。

配套 Node 的 [发布前 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34158600477) 和
[正式发布 CI](https://github.com/P0me1oo/YZboard-Node/actions/runs/34159504349/attempts/2) 各完成 637 项测试及子测试，无跳过、无竞争报告。
两个架构的安装包与镜像构建、来源及运行版本检查通过；下载后的 10 个附件与校验清单、GitHub 摘要及实际构建信息一致。
正式 CI 第一次因测试回环端口被占用失败，随后使用同一源码、Tag 和全部检查在新 runner 上通过，具体记录见 Node 验证文档。

Node 已先于面板发布。生产环境由用户确认部署目录、服务和持久化数据后执行更新，先升级相关 Node，再升级面板并启用新拓扑。
回滚基线为 Node `v1.13-yz.19` 与面板 `1.9.0`；应同时评估已启用的中转组合。发布记录以单独的文档提交补充，已发布 Tag 和镜像来源保持不变。

## sing-box 中转兼容约束（1.11.0）

- 面板 `1.11.0` 与 Node `v1.13-yz.21` 支持 sing-box VLESS/HY2 入口，以及 sing-box Shadowsocks/VLESS 落地；入口和落地可以与 Xray 混用。
- 修改基于面板 `eff2fa22531f2e15168d3e7e96d8ab45639b1969` 和 Node `94e2a76e42c1f059126b588b2d02f49e54fd8246` 的工作区，保留已有 HY2 实现。2026-09-08 将 Node 的 Xray、sing-box 依赖及构建标识固定到上表提交，并纳入 SS2022 关闭补丁。
- sing-box 使用原生 `auth_user` 规则，按「用户 × 线路」生成认证身份。`relay`、`traffic`、`relay_traffic`、`relay_user_traffic` 的格式不变，速度和设备限制仍使用真实用户。
- 任一端使用 sing-box 时，VLESS 内部链路只允许 RAW/TCP、WebSocket、gRPC、HTTPUpgrade，并按传输限制 TLS/Reality；不支持 VLESS Encryption、TCP 头部伪装及无效 Vision 组合。
- sing-box 的线路流量按实际选中出站的有效载荷统计；Xray 沿用内部出站计数，两者节点总量口径可能不同。用户流量仍只在入口扣费一次。
- 先升级相关 Node，再升级面板并启用拓扑；上一正式版本继续用于回滚。
- 本轮面板完整测试为 57 项、549 个断言，通过。管理端原始资源、旧补丁升级及重复应用检查通过。Node 和双机验收结果记录在 Node 的 `docs/singbox-relay-validation.md`。
- 当前固定 Xray `yz.6` 包含 VLESS 首批缓冲上传计数、UDP 缓存与 HY2 会话关闭同步修复；sing-box `yz.2` 和 Node 自有 SS2022 兼容模块修复其余两类并发问题。`yz.5` 阶段 Node 完整普通测试通过：17 个有测试的包、609 项测试及子测试，无失败或测试跳过。
- 修复后四个相关包各连续 10 轮并发检测通过，共 180 项测试执行。Linux 完整 sing-box 包并发检测为 142 项测试及子测试全部通过，耗时 126.042 秒，无失败、无跳过、无竞争报告；此前失败的混合内核及 gRPC 分支保留。
- YT-HK、DGN-HK 使用修复后依赖完成 60 次实际转发及生命周期检查，包含 Xray VLESS 前置到 sing-box SS2022 落地的 TCP/UDP；原 `yz.4` 下的竞争报告保留在 Node 验证文档的历史部分。
- 本地和双机探针属于发布前阶段；正式 Node 安装包及镜像已从上表固定提交发布并核对实际依赖、目标架构、来源及 `vcs.modified=false`。面板镜像也已按固定来源发布两个 Linux 架构。
- 发布前完整 Node 并发检测曾在 HY2 关闭状态处失败，该次未进入构建和发布。补充的 Xray `yz.6` 用例在旧代码上复现管理器和单会话两处状态竞争，修复后 5 项测试连续 10 轮 Linux 并发检测共 50 次通过；新依赖随后通过上述完整 Node 发布验收。

## HY2 前置入口基线（1.10.0）

- 面板 `1.10.0` 与 Node `v1.13-yz.20` 为上一轮源码目标，没有单独生成对应 Tag、Release 或镜像，相关功能随 `1.11.0` 与 `yz.21` 一同发布。回滚使用上表明确标注的面板 `1.9.0` 与 Node `yz.19`。
- 修改起点为面板 `eff2fa22531f2e15168d3e7e96d8ab45639b1969` 和 Node `94e2a76e42c1f059126b588b2d02f49e54fd8246`。HY2 认证路由复用当时固定 Xray `yz.3` 的既有能力；该轮尚未纳入 VLESS 出站统计修复，后续接入见上文 `1.11.0` 记录。
- HY2 前置入口和 Shadowsocks/VLESS 落地均使用 Xray。HY2 认证 UUID 携带原有路由编号，`relay`、`traffic`、`relay_traffic`、`relay_user_traffic` 的结构保持兼容。
- 发布后应先升级 Node，再在面板启用 HY2 前置入口；旧 Node 会拒绝这种中转入口配置。普通节点及原有 VLESS 中转沿用既有配置。
- 使用步骤和支持范围见 [中转节点说明](docs/relay-nodes.md)。

### 本地验证状态（2026-09-07，Xray yz.3 历史结果）

- 面板完整测试通过：55 项测试、412 个断言，包含列表切换内核、编辑请求省略字段和无效落地过滤；管理端补丁在当前产物和旧补丁升级路径上均通过语法、候选过滤和重复执行检查。
- 固定核心下的 HY2 实际转发、用户流量和用户-落地明细验证通过，但 VLESS 落地出站上传计数为零，运行测试在该断言失败。
- 原因是 Xray 的缓冲写入绕过了出站计数器。仅用本地临时覆盖补上字节写入计数后，Node 完整 Go 测试及核心 `common/buf` 测试通过；这些结果不能替代正式固定依赖的验收。
- Node 的 `linux/amd64`、`linux/arm64` 交叉编译通过，产物确认使用当前固定依赖并标记 `vcs.modified=true`；Windows 的 race 检查因未启用 CGO 未执行，Linux 运行验收未执行。
- 核心修复及依赖更新仍待确认，当前代码尚不具备完整发布验收结果。

## Node report 兼容约束

- 新版 Node 为每次刷出的流量批次携带 `report_id`，失败重试复用同一批次 ID。
- 面板 `1.7.0` 起按 `server_id + report_id` 把流量写入 `v2_node_report_batch`。用户流量、用户日统计、节点日统计和中转流量在同一数据库事务中结算，任务失败会整体回滚并自动重试。
- HTTP 接收成功只表示报告已经持久化或已经结算，不再表示多个下游队列任务都已完成。相同批次的重复请求不会重复扣量，已完成记录保留 7 天。
- 重复请求仍会刷新 `last_check_at`、`last_push_at`、在线连接缓存、设备状态和节点指标；流量批次单独按持久化记录保证幂等。
- 不带 `report_id` 的旧 Node 请求继续接受，但面板只能为每次请求生成临时批次 ID，无法识别 HTTP 响应丢失后的重复请求。
- 流量方向保持 `[upload, download]`；倍率在接收报告时固定，避免排队期间节点倍率变化影响已经产生的流量。

## 1.7.0 同步兼容约束

- 升级面板源码后必须执行数据库迁移，创建 `v2_node_report_batch`；未迁移时 V2 节点流量报告会失败并由 Node 保留批次重试。
- 面板在线人数以 Node 的 `online` 全量快照为准，设备状态以 `alive` 或 WebSocket `report.devices` 全量快照为准；空对象表示当前没有在线用户或设备，不能省略成旧值。
- `v1.13-yz.13` 在 WebSocket 正常时仍至少每 5 分钟执行一次 REST ETag 对账，用于修复 Redis Pub/Sub、Workerman 重启或短暂断线造成的推送丢失。
- Node 每次设备报告都发送完整快照并包含空快照，面板按节点替换状态并续期 300 秒 TTL。首次升级会兼容扫描旧设备键并建立节点索引，后续不再全量扫描。
- 批量封禁和套餐强制更新使用按权限组的全量用户推送；即使 Redis 推送丢失，周期 REST 对账和 `node:sync-users` 仍会恢复最终状态。

## 1.8.0 节点级内核兼容约束

- 面板节点表新增可空的 `kernel_type`：`xray` 或 `singbox`；空值兼容历史数据并按 Xray 处理，默认值不改变已有协议配置。
- 机器节点发现接口和节点配置接口都会返回有效的 `kernel_type`。Node `v1.13-yz.15` 及以上在机器模式按节点选择后端，同一台机器可以同时运行 Xray 与 sing-box。
- 机器模式节点的内核选择变化会触发该节点单独重启；机器只包含 Xray 节点时不会启动 sing-box 服务实例。
- 中转在面板 `1.11.0`、Node `v1.13-yz.21` 起支持两种内核混用；旧版本中转仍要求 Xray。XHTTP 等仅 Xray 传输按链路两端的共同能力校验。
- 管理端构建阶段补丁 `.docker/patch-admin-relay.php` 增加内核下拉、表单字段和 Xray 默认值；补丁保持锚点失败即中止，并按内容 hash 重命名入口产物。

## 1.8.1 管理端白屏热修复

- 修复节点内核选择器的构建期注入缺少一个闭合调用的问题。此前生成的管理端 bundle 会在 Chrome 中报 `Uncaught SyntaxError: Unexpected token '.'`，导致 React 根节点为空并显示白屏。
- 修复后的管理端 bundle 已在当前 Chrome 152 中实际加载验证，登录界面可以正常挂载；补丁脚本重复执行仍保持幂等。

## 1.8.2 管理端节点编辑修复

- 修复节点编辑弹窗标题区域的内核下拉错误使用 `Controller`/`FormItem` 的问题。该区域不在 React Hook Form 的 `FormProvider` 内，点击任意节点编辑时会触发 `Cannot destructure property 'getFieldState' ... as it is null` 并显示 500 错误页。
- 内核下拉现在直接使用当前表单实例的 `watch` 和 `setValue`，仍位于协议选择左侧，默认 Xray；生成的管理端 JavaScript 通过语法检查，补丁重复执行保持幂等。

## 1.8.3 管理端 bundle 语法修复

- 修复 `v1.8.2` 内核选择器补丁在协议选择器尾部多插入一组 `]})` 的问题。Node 语法检查未报告该问题，但 Chrome 和 Acorn 会在节点表单末尾报告 `Unexpected token ']'`，导致页面白屏。
- `v1.8.3` 使用浏览器兼容的 Acorn 解析和 Chrome 运行时解析双重校验管理端 bundle。

## 中转节点兼容约束

- 面板 `1.1.0` 起在节点配置接口增加 `relay` 段，并在上报接口接受 `relay_traffic`；对应 Node 版本为 `v1.13-yz.5`。
- 旧版 Node 会忽略 `relay` 段，也不会上报 `relay_traffic`，因此升级面板但未升级 Node 时中转拓扑不会生效，普通节点行为不变。
- Xray 中转入口使用 VLESS 路由值能力（认证前清零 UUID 第 7、8 字节，认证后还原并由 `vlessRoute` 规则匹配）；sing-box 入口使用线路认证身份及 `auth_user` 规则。
- `relay_traffic` 只累计到逻辑节点的节点流量，不进入用户套餐扣费，也不套用倍率；用户流量仍只在入口按真实用户身份统计一次。
- 节点表新增 `vless_route` 列，迁移会按 id 顺序回填存量节点并记录分配游标。回滚该迁移会删除列和索引，但不会回收已写入订阅的编号。
- 面板 `1.2.0` 起中转关系存放在新增的 `relay_entry_id` 列，不再借用 `parent_id`；`parent_id` 的行为与上游完全一致。升级后该列对存量节点为空，不会有节点被识别成中转逻辑节点。节点端接口未变，`v1.13-yz.5` 及以上均兼容。
- 管理端的「前置入口」下拉和节点列表的「前置入口」列，由构建阶段补丁 `.docker/patch-admin-relay.php` 注入到 `xboard-admin-dist` 产物。上游管理端产物结构变化会导致补丁锚点失配并使镜像构建失败，此时需要同步更新补丁而不是跳过。
- 面板 `1.3.0` 起管理端节点列表接口 `GET /api/v2/admin/server/manage/getNodes` 增加 `relay_entry_name` 字段，仅用于列表展示，节点端接口未变。
- 面板 `1.4.0` 与 Node `v1.13-yz.10` 起，`relay` child/landing 可以使用 `protocol: vless`，并增加嵌套 `vless` 配置。旧 Node 不认识该结构，VLESS 落地必须成套升级；既有 Shadowsocks relay 的平面 `cipher/password` 结构不变。
- 两端均为 Xray 时，VLESS 中转支持 RAW/TCP、WS、gRPC、XHTTP、HTTPUpgrade、mKCP、Hysteria；Reality 只允许 RAW/TCP、gRPC、XHTTP，Hysteria 只允许 TLS，H2/HTTP 不支持。任一端使用 sing-box 时，内部传输限于 RAW/TCP、WS、gRPC、HTTPUpgrade，并按传输校验安全组合。
- VLESS Encryption 的 `encryption` 只进入入口 child，`decryption`、Reality 私钥和证书配置只进入落地顶层配置。内部 UUID 与 Hysteria transport auth 由面板应用密钥按独立域派生，不落库、不进入订阅。
- `1.4.0` 的管理端构建补丁复用 Reality 的浏览器端 X25519 生成器，为 VLESS Encryption
  提供钥匙按钮并同时填入 `decryption`/`encryption`。生成动作只修改未保存表单，手工填写
  和通过 `xray vlessenc` 生成的 ML-KEM-768 配置继续兼容。

## 1.5.0 节点管理兼容约束

- 节点批量权限组操作复用 `POST /api/v2/admin/server/manage/batchUpdate`，新增 `group_action=add|remove` 与 `group_id` 参数；不改变节点端通信协议。
- 批量添加权限组是集合合并，批量移除权限组只删除指定组；重复调用保持幂等，未选中的节点不受影响。
- Shadowsocks 新建表单默认使用 `2022-blake3-aes-128-gcm`；该默认值只存在于管理端表单，存量节点的 `protocol_settings.cipher` 不会被迁移。
- 管理端补充界面继续由 `.docker/patch-admin-relay.php` 在构建阶段注入。上游管理端产物锚点变化时，构建应失败并更新补丁，不应跳过补丁。

## 历史发布与回滚记录

以下为 `1.8.3` 及更早版本的历史发布记录；当前版本与回滚基线见本文开头：

1. 面板 `v1.8.3` 固定到 `68a08991a6109c93285eb0653cd8d25981acc8d5`，不可变镜像 `1.8.3-68a0899`、版本别名 `1.8.3` 和 `latest` 指向 manifest `sha256:a752b4ce9006407e25b888237e84826ce6a14f9660a8ce11524135935825bfa9`；
2. 面板 [run 33644362438](https://github.com/P0me1oo/YZboard/actions/runs/33644362438) 固定来源构建成功，构建和清单验证均通过，manifest 包含 `linux/amd64` 与 `linux/arm64`；
3. 上一条 `v1.8.2` 生产基线固定到 `50c5118e4681e986adb0edd6b1c6cd1ec49e4618`，不可变镜像 `1.8.2-50c5118`、版本别名 `1.8.2` 和旧 `latest` 指向 manifest `sha256:23b4ae1782d4b08cd70931699756eae32f936f8371339a666a4f487bbfca74bd`；
4. Node `v1.13-yz.15` 固定到 `d821de890769aa20a001ca3f4ef43d24c001c48b`，Release 与镜像由 [run 33592394571](https://github.com/P0me1oo/YZboard-Node/actions/runs/33592394571) 发布；
5. Node Docker manifest 为 `sha256:f3e0895ebc04ac603158a5b96413e7695e5c7a1abd596ee864d7d4852b0b4665`，包含 `linux/amd64` 与 `linux/arm64`；
6. Node 使用 YZ-Xray-core `v26.7.11-yz.2`，固定 commit `26b01717dd8d1fd604de5e23e2868fdef59eba2f` 与 pseudo-version `v0.0.0-20260901175116-26b01717dd8d`；
7. sing-box 请求版本和实际 replacement 版本与上表一致。

上一条 `1.5.0` 镜像 manifest `sha256:0ed171a1e86709b81bd2ecaf0f1f356d0d0d2c470b9768bdcb50a20b5c9accf9` 仍保留用于回滚审计。

推送语义版本 Tag 会自动触发构建，并同时更新“面板版本 + 短 commit”的不可变标签、面板版本别名和 `latest`，中间没有确认环节。**推 `v*` Tag 等同于发布生产**，不要用它做试探性标记。手动 dispatch 仍然可用，适合对固定 Tag 或完整 commit 重新构建，那条路径的 `publish_latest` 默认关闭，需要显式勾选。生产 Compose 长期使用 `latest` 拉取更新，不可变标签和 digest 用于确认实际版本与回滚。

本仓库是 `cedar2025/Xboard` 的 fork，GitHub 对 fork 默认禁用事件触发的工作流，所以 `v1.0.6` 到 `v1.3.0` 五个 Tag 都没有触发构建，那几版实际都由手动 dispatch 发布。该限制已于 2026-07-27 在仓库 Actions 页面解除，`v1.3.1` 是第一个由 Tag 推送自动构建的版本。

回滚时，面板镜像、Node Release 和 Node 的 Xray replace 应成套退回上一条兼容矩阵记录，不要只修改其中一个版本字段。
