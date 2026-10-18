# 本地 IP 数据库

## 纯真 IPv4

`qqwry.dat` 来自 [metowolf/qqwry.dat 的 20260930 版本](https://github.com/metowolf/qqwry.dat/releases/tag/20260930)，用于中国大陆 IPv4 地区查询。

- 文件大小：27,274,499 字节。
- SHA-256：`46cc2175f60cf8d62e24774bc4ecefdffb8047f60877dc95d85cd543d34e780a`。
- 格式说明：[protocol.md](https://github.com/metowolf/qqwry.dat/blob/65433eb0496055e2fdbdf970f4afeb22105e3cb2/protocol.md)。

## ip2region IPv4、IPv6

PHP 包 `zoujingli/ip2region v3.0.16` 固定到 `19ef70eefcd0c84ea3a9f4302aded22b5156cb7b`。数据库直接来自 [官方固定提交](https://github.com/lionsoul2014/ip2region/tree/f8b0792b0cd729f5b2593f690aeed225e49c1967/data)，采用 XDB v3 格式。

| 文件 | 生成日期（UTC） | 大小 |
| --- | --- | --- |
| `ip2region_v4.xdb` | 2026-10-14 | 11,122,642 字节 |
| `ip2region_v6.xdb` | 2026-08-14 | 37,277,813 字节 |

完整下载地址、Git 文件摘要、SHA-256 和文件头信息见 [ip2region.json](ip2region.json)。下载后已与固定提交中的 Git 文件摘要核对，不采用查询包内较旧的默认 IPv4 数据库。

数据库随源码及镜像交付，运行时不下载移动版本。更新时选定完整提交或具体版本、核对摘要、运行读取测试，并同步本文。地区来源规则见 [IP 位置查询](../../docs/ip-location.md)；ASN 和风险属性仍由现有外部服务提供。
