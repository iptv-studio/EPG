<?php
/**
 * EPG 分类处理器
 * 目录结构优化版
 */

$inputBaseDir = __DIR__ . '/list/';
$outputBaseDir = __DIR__ . '/EPG/';

ini_set('memory_limit', '1024M');

// 定义分类
$categories = ['CN', 'HK', 'TW'];

foreach ($categories as $cat) {
    $inputDir = $inputBaseDir . $cat . '/';
    $outputDir = $outputBaseDir . $cat . '/';

    echo "📂 正在处理分类: [$cat]\n";

    if (!is_dir($inputDir)) {
        echo "   ⚠️ 跳过：未找到输入目录 $inputDir\n";
        continue;
    }

    if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

    // 清理旧数据
    $oldFiles = glob($outputDir . '*.json');
    if (!empty($oldFiles)) array_map('unlink', $oldFiles);

    $xmlFiles = glob($inputDir . '*.xml');
    if (empty($xmlFiles)) continue;

    $channels = [];
    $channelNames = [];

    foreach ($xmlFiles as $file) {
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$xml) continue;

        // 频道 ID 转中文名映射
        if (isset($xml->channel)) {
            foreach ($xml->channel as $ch) {
                $id = trim((string)$ch['id']);
                $name = trim((string)$ch->{'display-name'});
                if ($id && $name) $channelNames[$id] = $name;
            }
        }

        // 提取节目
        if (isset($xml->programme)) {
            foreach ($xml->programme as $prog) {
                $chId = trim((string)$prog['channel']);
                $start = (string)$prog['start'];
                $stop = (string)$prog['stop'];
                $channels[$chId][] = [
                    'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                    'startTime' => substr($start, 0, 14),
                    'stopTime'  => substr($stop, 0, 14),
                    'program'   => (string)$prog->title
                ];
            }
        }
        unset($xml);
    }

    // 写入分类 JSON
    $fileCount = 0;
    foreach ($channels as $id => $progList) {
        $displayName = isset($channelNames[$id]) ? $channelNames[$id] : $id;
        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($displayName));
        
        usort($progList, function($a, $b) { return strcmp($a['startTime'], $b['startTime']); });
        $progList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));

        $filePath = $outputDir . $safeName . '.json';
        if (file_put_contents($filePath, json_encode($progList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            $fileCount++;
        }
    }
    echo "   ✅ 完成：在 EPG/$cat/ 下生成了 $fileCount 个文件。\n";
}
