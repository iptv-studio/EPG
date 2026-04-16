<?php
/**
 * Ando EPG 分类处理器
 * 功能：解析 XML/JSON EPG 数据，处理 Plus 别名，按原始名称分箱存储
 */

// --- 1. 动态路径修正逻辑 ---
// 无论脚本是在根目录运行还是在 EPG 目录运行，都确保指向正确的输出文件夹
$currentPath = str_replace('\\', '/', dirname(__FILE__));
if (basename($currentPath) === 'EPG') {
    $baseDir = $currentPath . '/';
} else {
    $baseDir = $currentPath . '/EPG/';
}

// 确保目录存在
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 对应 YAML 脚本中生成的解压文件名
$xmlFilesToProcess = ['pl.xml', 'hk.xml', 'tw.xml'];
$globalFileCount = 0;
$filesPerFolder = 900; 

echo "🚀 EPG 处理器启动...\n";
echo "📂 扫描目录: $baseDir\n";

$channels = [];
$channelNames = [];

// --- 2. 解析逻辑 ---
foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    if (!file_exists($filePath)) {
        echo "⚠️ 跳过不存在的文件：$filePath\n";
        continue;
    }

    echo "📖 正在解析：$fileName\n";
    $content = file_get_contents($filePath);
    if (empty($content)) continue;

    $firstChar = substr(trim($content), 0, 1);

    // 逻辑 A: 处理 JSON 格式 (针对某些 API 源)
    if ($firstChar === '{' || $firstChar === '[') {
        $data = json_decode($content, true);
        if ($data) {
            $items = $data['epg_data'] ?? (is_array($data) ? $data : []);
            $chId = $data['channel_id'] ?? $fileName;
            $chName = $data['channel_name'] ?? $chId;
            $channelNames[$chId] = $chName;

            foreach ($items as $item) {
                $rawStart = $item['start_time'] ?? ($item['start'] ?? '');
                $rawEnd = $item['end_time'] ?? ($item['end'] ?? '');
                $channels[$chId][] = [
                    'start'     => substr(str_replace([':', ' '], '', $rawStart), 8, 2) . ':' . substr(str_replace([':', ' '], '', $rawStart), 10, 2),
                    'startTime' => str_pad(preg_replace('/\D/', '', $rawStart), 14, '0', STR_PAD_RIGHT),
                    'stopTime'  => str_pad(preg_replace('/\D/', '', $rawEnd), 14, '0', STR_PAD_RIGHT),
                    'program'   => $item['title'] ?? ($item['program'] ?? '精彩节目')
                ];
            }
        }
    } 
    // 逻辑 B: 处理标准 XMLTV 格式
    else {
        $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$xml) {
            echo "❌ 无法解析 XML 内容: $fileName\n";
            continue;
        }

        if (isset($xml->channel)) {
            foreach ($xml->channel as $ch) {
                $id = trim((string)$ch['id']);
                $name = trim((string)$ch->{'display-name'});
                if ($id && $name) $channelNames[$id] = $name;
            }
        }

        if (isset($xml->programme)) {
            foreach ($xml->programme as $prog) {
                $chId = trim((string)$prog['channel']);
                $start = (string)$prog['start'];
                $stop = (string)$prog['stop'];
                $channels[$chId][] = [
                    'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                    'startTime' => substr($start, 0, 14),
                    'stopTime'  => substr($stop, 0, 14),
                    'program'   => trim((string)$prog->title)
                ];
            }
        }
    }
    unset($content, $xml, $data);
}

// --- 3. 写入 JSON 逻辑 ---
echo "⚙️ 正在执行分箱逻辑并写入子目录...\n";

foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    if (empty($displayName)) continue;

    // 排序与去重
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $nameItem = trim($displayName);
    $targets = [$nameItem]; 

    // 特殊处理：如果包含 + 号，自动增加一个 Plus 命名的文件版本
    if (strpos($nameItem, '+') !== false) {
        $targets[] = str_replace('+', 'Plus', $nameItem);
    }

    foreach ($targets as $targetName) {
        // 计算分箱目录索引 (01, 02...)
        $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIdx . '/';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // 过滤系统非法字符，保持 + 号
        $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $targetName);
        $fullPath = $targetDir . $safeFileName . '.json';

        if (file_put_contents($fullPath, $jsonEncoded) !== false) {
            $globalFileCount++;
        }
    }
}

echo "\n✨ 任务圆满完成！";
echo "\n📊 总计生成文件: $globalFileCount 个";
echo "\n📂 根目录: $baseDir\n";
