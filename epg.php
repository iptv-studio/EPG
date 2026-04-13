<?php
/**
 * EPG 综合处理脚本 - 多源拼合版
 * 功能：自动扫描 list/ 目录下所有 XML 文件，提取节目信息并分发为 JSON
 */

$inputDir = __DIR__ . '/list/';
$outputDir = __DIR__ . '/EPG/';

// 确保输出目录存在
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// 提升内存限制以应对多个大型 XML 拼合
ini_set('memory_limit', '1024M');

// 1. 获取目录下所有的 XML 文件
$xmlFiles = glob($inputDir . '*.xml');

if (empty($xmlFiles)) {
    echo "⚠️ 提示：在 $inputDir 未找到任何 .xml 文件，请检查下载步骤。\n";
    exit(0);
}

// 2. 清理旧数据（仅在开始处理新一批数据前执行一次）
echo "正在清理旧 EPG 数据...\n";
$oldFiles = glob($outputDir . '*.json');
if ($oldFiles) {
    foreach ($oldFiles as $f) {
        if (is_file($f)) unlink($f);
    }
}

$channels = [];

// 3. 循环解析所有 XML 文件
foreach ($xmlFiles as $file) {
    echo "正在加载: " . basename($file) . " ...\n";
    
    // 使用 LIBXML_COMPACT 提升性能
    $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    
    if (!$xml) {
        echo "   ❌ 错误：解析 " . basename($file) . " 失败，跳过。\n";
        continue;
    }

    echo "   正在提取节目信息...\n";
    foreach ($xml->programme as $prog) {
        $chId = trim((string)$prog['channel']);
        if (!$chId) continue;

        $start = (string)$prog['start'];
        $stop = (string)$prog['stop'];
        $title = (string)$prog->title;

        // 存入数组（如果是多个源中有相同的 chId，数据会自动追加到该频道下）
        $channels[$chId][] = [
            'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2), // HH:mm
            'startTime' => substr($start, 0, 14), // YYYYmmddHHMMSS
            'stopTime'  => substr($stop, 0, 14),
            'program'   => $title
        ];
    }
    // 释放内存
    unset($xml);
}

// 4. 生成 JSON 文件
echo "正在生成 JSON 文件...\n";
$count = 0;

foreach ($channels as $id => $progList) {
    // 过滤非法文件名字符
    $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($id));
    if (empty($safeFileName)) continue;

    // 对同一个频道的节目单按开始时间排序（防止多源拼合后时间轴混乱）
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });

    $filePath = $outputDir . $safeFileName . '.json';
    $jsonContent = json_encode($progList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($filePath, $jsonContent)) {
        $count++;
    }

    // 特殊副本处理
    if ($safeFileName === 'CCTV5+') {
        file_put_contents($outputDir . 'CCTV5PLUS.json', $jsonContent);
    }
}

echo "----------------------------------------------------\n";
echo "✅ 任务完成！\n";
echo "📂 处理源码: " . count($xmlFiles) . " 个文件\n";
echo "📊 总频道数: " . count($channels) . "\n";
echo "💾 生成 JSON: $count 个\n";
echo "📅 更新时间: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------------------\n";
