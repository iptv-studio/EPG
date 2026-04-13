<?php
/**
 * IPTV EPG 多源拼合处理器
 */

$inputDir = __DIR__ . '/list/';
$outputDir = __DIR__ . '/EPG/';

if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);
ini_set('memory_limit', '1024M');

// 1. 扫描所有下载好的 XML
$xmlFiles = glob($inputDir . '*.xml');
if (empty($xmlFiles)) {
    die("⚠️ 错误：未发现 XML 文件，请检查下载步骤。\n");
}

// 2. 清理旧 JSON 缓存
echo "正在清理旧数据...\n";
array_map('unlink', glob($outputDir . '*.json'));

$channels = [];

// 3. 解析所有文件并合并数据
foreach ($xmlFiles as $file) {
    echo "正在解析: " . basename($file) . "\n";
    $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$xml) continue;

    foreach ($xml->programme as $prog) {
        $chId = trim((string)$prog['channel']);
        if (!$chId) continue;

        $start = (string)$prog['start'];
        $stop = (string)$prog['stop'];
        
        // 提取信息到频道数组
        $channels[$chId][] = [
            'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
            'startTime' => substr($start, 0, 14),
            'stopTime'  => substr($stop, 0, 14),
            'program'   => (string)$prog->title
        ];
    }
    unset($xml); // 释放内存
}

// 4. 去重、排序并输出 JSON
echo "正在生成 JSON 文件...\n";
$fileCount = 0;

foreach ($channels as $id => $progList) {
    // 4a. 按照开始时间排序（重要：解决多源合并后的顺序问题）
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });

    // 4b. 简单去重：防止完全重复的条目
    $progList = array_map("unserialize", array_unique(array_map("serialize", $progList)));
    $progList = array_values($progList); // 重置索引

    $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($id));
    $filePath = $outputDir . $safeName . '.json';
    
    if (file_put_contents($filePath, json_encode($progList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
        $fileCount++;
    }

    // CCTV5+ 特殊兼容
    if ($safeName === 'CCTV5+') {
        file_put_contents($outputDir . 'CCTV5PLUS.json', json_encode($progList, JSON_UNESCAPED_UNICODE));
    }
}

echo "----------------------------------------------------\n";
echo "✅ 任务完成！\n";
echo "📊 总频道数: " . count($channels) . "\n";
echo "💾 已生成 JSON: $fileCount 个\n";
echo "📅 时间: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------------------\n";
