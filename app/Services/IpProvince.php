<?php

namespace App\Services;

/** 分类只使用数据库明确返回的地区，不根据运营商或 ASN 推断省份。 */
class IpProvince
{
    private const COUNTRIES =
        '美国|日本|韩国|朝鲜|新加坡|马来西亚|泰国|越南|印度尼西亚|菲律宾|文莱|缅甸|柬埔寨|老挝|东帝汶|蒙古|印度|巴基斯坦|孟加拉国|尼泊尔|不丹|斯里兰卡|马尔代夫|阿富汗|' .
        '哈萨克斯坦|吉尔吉斯斯坦|塔吉克斯坦|土库曼斯坦|乌兹别克斯坦|伊朗|伊拉克|叙利亚|约旦|黎巴嫩|以色列|巴勒斯坦|沙特阿拉伯|阿拉伯联合酋长国|阿联酋|卡塔尔|科威特|巴林|阿曼|也门|' .
        '土耳其|格鲁吉亚|亚美尼亚|阿塞拜疆|塞浦路斯|俄罗斯|乌克兰|白俄罗斯|摩尔多瓦|德国|法国|英国|爱尔兰|荷兰|比利时|卢森堡|瑞士|奥地利|意大利|西班牙|葡萄牙|希腊|马耳他|安道尔|' .
        '摩纳哥|圣马力诺|梵蒂冈|列支敦士登|丹麦|瑞典|挪威|芬兰|冰岛|爱沙尼亚|拉脱维亚|立陶宛|波兰|捷克|斯洛伐克|匈牙利|罗马尼亚|保加利亚|斯洛文尼亚|克罗地亚|波斯尼亚和黑塞哥维那|' .
        '波黑|塞尔维亚|黑山|北马其顿|马其顿|阿尔巴尼亚|科索沃|加拿大|墨西哥|危地马拉|伯利兹|洪都拉斯|萨尔瓦多|尼加拉瓜|哥斯达黎加|巴拿马|古巴|海地|多米尼加|牙买加|巴哈马|巴巴多斯|' .
        '特立尼达和多巴哥|格林纳达|多米尼克|圣卢西亚|圣文森特和格林纳丁斯|安提瓜和巴布达|圣基茨和尼维斯|巴西|阿根廷|智利|乌拉圭|巴拉圭|玻利维亚|秘鲁|厄瓜多尔|哥伦比亚|委内瑞拉|圭亚那|' .
        '苏里南|澳大利亚|新西兰|巴布亚新几内亚|斐济|所罗门群岛|瓦努阿图|萨摩亚|汤加|图瓦卢|瑙鲁|基里巴斯|帕劳|密克罗尼西亚|马绍尔群岛|埃及|利比亚|突尼斯|阿尔及利亚|摩洛哥|苏丹|' .
        '南苏丹|埃塞俄比亚|厄立特里亚|吉布提|索马里|肯尼亚|乌干达|坦桑尼亚|卢旺达|布隆迪|刚果民主共和国|刚果共和国|刚果(金)|刚果(布)|中非|喀麦隆|赤道几内亚|加蓬|圣多美和普林西比|' .
        '乍得|尼日利亚|尼日尔|贝宁|多哥|加纳|科特迪瓦|布基纳法索|利比里亚|塞拉利昂|几内亚比绍|几内亚|冈比亚|塞内加尔|毛里塔尼亚|马里|佛得角|南非|纳米比亚|博茨瓦纳|津巴布韦|赞比亚|' .
        '安哥拉|莫桑比克|马拉维|莱索托|斯威士兰|马达加斯加|科摩罗|毛里求斯|塞舌尔|留尼汪|波多黎各|关岛|百慕大|开曼群岛|英属维尔京群岛|美属维尔京群岛|直布罗陀|格陵兰|法罗群岛|库拉索|' .
        '阿鲁巴|法属波利尼西亚|新喀里多尼亚';
    private const PROVINCES = [
        '北京' => '北京市', '天津' => '天津市', '上海' => '上海市', '重庆' => '重庆市',
        '河北' => '河北省', '山西' => '山西省', '辽宁' => '辽宁省', '吉林' => '吉林省',
        '黑龙江' => '黑龙江省', '江苏' => '江苏省', '浙江' => '浙江省', '安徽' => '安徽省',
        '福建' => '福建省', '江西' => '江西省', '山东' => '山东省', '河南' => '河南省',
        '湖北' => '湖北省', '湖南' => '湖南省', '广东' => '广东省', '海南' => '海南省',
        '四川' => '四川省', '贵州' => '贵州省', '云南' => '云南省', '陕西' => '陕西省',
        '甘肃' => '甘肃省', '青海' => '青海省', '台湾' => '台湾省',
        '内蒙古' => '内蒙古自治区', '广西' => '广西壮族自治区', '西藏' => '西藏自治区',
        '宁夏' => '宁夏回族自治区', '新疆' => '新疆维吾尔自治区',
        '香港' => '香港特别行政区', '澳门' => '澳门特别行政区',
    ];

    public static function fromChinese(string $place): string
    {
        $place = trim($place);
        // 纯真库使用 Unicode 横线分隔国家、省市，兼容普通短横线及其他横线写法。
        $local = preg_replace('/^中国[\s\/\p{Pd}]*/u', '', $place);
        foreach (self::PROVINCES as $prefix => $name) {
            if (str_starts_with($local, $prefix)) return $name;
        }
        if ($place === '' || $local !== $place || preg_match('/^(未知|局域网|保留|本机|共享地址|纯真)/u', $place)) return '未知';
        // 有些境外记录的国家与城市之间没有分隔符，只匹配明确的国家或地区名称。
        foreach (explode('|', self::COUNTRIES) as $country) {
            if (str_starts_with($place, $country)) return $country;
        }
        return preg_split('/[\s\/]/u', $place, 2)[0] ?: '未知';
    }

    public static function fromExternal(array $location): string
    {
        $code = strtoupper($location['country_code'] ?? '');
        if (in_array($code, ['HK', 'MO', 'TW'])) return ['HK' => '香港特别行政区', 'MO' => '澳门特别行政区', 'TW' => '台湾省'][$code];
        $country = $location['country_name'] ?? '';
        if ($code !== 'CN' && !in_array($country, ['China', '中国'])) return $country ?: '未知';
        $province = $location['region_name'] ?? '';
        $aliases = ['Nei Mongol' => '内蒙古自治区', 'Xizang' => '西藏自治区'];
        if (isset($aliases[$province])) return $aliases[$province];
        $english = ['Beijing', 'Tianjin', 'Shanghai', 'Chongqing', 'Hebei', 'Shanxi', 'Liaoning', 'Jilin',
            'Heilongjiang', 'Jiangsu', 'Zhejiang', 'Anhui', 'Fujian', 'Jiangxi', 'Shandong', 'Henan',
            'Hubei', 'Hunan', 'Guangdong', 'Hainan', 'Sichuan', 'Guizhou', 'Yunnan', 'Shaanxi',
            'Gansu', 'Qinghai', 'Taiwan', 'Inner Mongolia', 'Guangxi', 'Tibet', 'Ningxia', 'Xinjiang', 'Hong Kong', 'Macao'];
        foreach ($english as $index => $name) {
            if (preg_match('/^' . preg_quote($name, '/') . '(?:$|\s)/i', $province)) return array_values(self::PROVINCES)[$index];
        }
        $result = self::fromChinese('中国' . $province);
        return $result === '未知' ? '未知' : $result;
    }
}
