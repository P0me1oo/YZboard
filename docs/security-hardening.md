# 接口限流与浏览器安全头

本功能在 `1.20.0` 开发阶段引入，合入面板 `1.20.1` 正式发布。不改数据库结构，不改节点通信协议，不需要配套更新 Node。

## 接口限流

限流按端点分档，不加在整个 API 上。节点通信（`api/v1/server/*`、`api/v2/server/*`，含 `machine/*`）、支付回调和 Telegram Webhook 全部不限流，节点数量增多或多台节点共用出口 IP 不会被误伤。

| 端点 | 上限 | 计数维度 |
| --- | --- | --- |
| 登录、两步验证第二步 | 5 次 / 5 分钟 | IP + 邮箱 |
| 注册 | 10 次 / 小时 | IP + 邮箱 |
| 找回密码 | 3 次 / 小时 | IP + 邮箱 |
| 发送邮箱验证码、邮件链接登录 | 5 次 / 小时 | IP + 邮箱 |
| 免密登录、快速登录链接 | 30 次 / 分钟 | IP |
| 用户端接口 | 60 次 / 分钟 | 账号（未登录时按 IP） |
| 管理端接口 | 300 次 / 分钟 | 账号（未登录时按 IP） |
| 访客接口（套餐列表、站点配置、邀请码计数） | 120 次 / 分钟 | IP |

认证类端点按 IP 和邮箱一起计数：只按邮箱会让任何人靠故意输错密码把别人的账号锁死，只按 IP 会让同一出口下的用户互相影响。后台原有的“密码错误次数限制”也改成了同样的双键计数，登录成功后清零。

注册按 IP 限制数量仍由后台“注册限制”中的既有开关控制，这里的限流只挡对邮箱验证码的穷举。

超限时返回 HTTP 429，结构与其他接口一致，`message` 为“请求过于频繁，请稍后再试”。上限写在 `app/Providers/RouteServiceProvider.php` 的 `configureRateLimiting()` 里，各路由在 `app/Http/Routes/` 下按名称挂载。

### 真实 IP 与反向代理

限流依赖真实客户端 IP。面板内置信任 Cloudflare 网段、内网网段和容器内的 Caddy，这些场景不需要额外配置。

如果面板前面还有一层既不是 Cloudflare、也不在内网网段的反向代理，它转发的所有请求会被算成同一个来源，按 IP 的额度会被所有用户共用。这种部署在 `.env` 中把该代理的地址加入信任列表：

```
TRUSTED_PROXIES=203.0.113.10,198.51.100.0/24
```

只接受具体 IP 或 CIDR，多个用逗号分隔；不接受 `*`。信任任意来源意味着任何人都能伪造 `X-Forwarded-For` 绕过按 IP 的限制。

## 浏览器安全头

所有响应下发 `X-Content-Type-Options: nosniff`、`Referrer-Policy: strict-origin-when-cross-origin` 和 `X-Frame-Options: DENY`；HTTPS 请求另下发 `Strict-Transport-Security`。

内容安全策略（CSP）只对 HTML 页面下发，分两档：

- 管理端页面使用完整策略，脚本只允许本站文件和带随机 nonce 的内联脚本。管理端登录令牌保存在浏览器本地存储中，这层策略的目的是让页面里即使出现注入点，脚本也无法执行并外发令牌。
- 用户前台会加载管理员上传的主题，主题里可能有任意内联脚本，只下发不限制脚本来源的基础策略（禁止插件对象、限制 `<base>`、禁止被嵌入 iframe），不会让现有主题白屏。

### 配置项

全部通过环境变量控制，写在 `.env` 中；使用 `config:cache` 的部署改完后需要重新生成缓存。

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `SECURITY_HEADERS_ENABLE` | `true` | 总开关，关闭后不下发任何安全头 |
| `SECURITY_CSP_ENABLE` | `true` | 是否下发 CSP |
| `SECURITY_CSP_REPORT_ONLY` | `false` | 仅上报模式，只发 `Content-Security-Policy-Report-Only`，不拦截 |
| `SECURITY_FRAME_ANCESTORS` | `'none'` | 允许哪些来源嵌入本站；`'self'` 允许同源嵌入 |
| `SECURITY_HSTS_MAX_AGE` | `31536000` | HSTS 有效期（秒），`0` 关闭 |
| `SECURITY_HSTS_INCLUDE_SUBDOMAINS` | `false` | HSTS 是否覆盖子域名，子域名还有纯 HTTP 服务时保持关闭 |
| `SECURITY_REFERRER_POLICY` | `strict-origin-when-cross-origin` | Referrer-Policy 取值 |

自定义主题或插件页面怀疑被 CSP 影响时，先开启 `SECURITY_CSP_REPORT_ONLY` 观察浏览器控制台，确认后再决定关闭或调整。

## 同版本的其他修复

- 邮箱验证码、订单号、随机字符串、礼品卡兑换码和盲盒抽奖改用密码学安全随机源。
- 找回密码成功后吊销该账号所有已签发的登录令牌；订阅 token 不自动轮换，需要时由用户在“重置订阅信息”中主动触发。
- 两步验证挑战在验证码输错时只保留剩余有效期，不再每次输错都重置为 5 分钟。
- 快速登录链接和后台员工接口解析令牌时补上过期校验，已过期的登录令牌不能再换取新的登录态。
- 管理端节点列表展示的 Shadowsocks 2022 服务端密钥按加密套件取正确长度，`aes-256-gcm` 与 `chacha20-poly1305` 之前显示的是 16 字节的错误值；不影响实际下发给节点和客户端的配置。
