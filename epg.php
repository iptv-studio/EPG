<?php
/**
 * EPG 拆分脚本 - 优化版
 * 适配新的下载源：https://epg.iill.top/epg.xml.gz
 */

$xmlFile = __DIR__ . '/list/x.xml';
$outputDir = __DIR__ . '/EPG/';

// 确保目录存在
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// 检查文件是否存在且不为空
if (!file_exists($xmlFile) || filesize($xmlFile) === 0) {
    echo "⚠️ 提示：未找到有效的 $xmlFile，请检查下载步骤。\n";
    exit(0);
}

// 提升内存限制以应对大型 XML
ini_set('memory_limit', '1024M');

echo "开始解析 XML...\n";
// 使用 LIBXML_COMPACT 提升性能
$xml = simplexml_load_file($xmlFile, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);

if (!$xml) {
    echo "❌ 错误：XML 解析失败，可能是格式非法或文件损坏。\n";
    exit(1);
}

// 清理旧数据
echo "正在清理旧 EPG 数据...\n";
$oldFiles = glob($outputDir . '*.json');
if ($oldFiles) {
    foreach ($oldFiles as $f) {
        if (is_file($f)) unlink($f);
    }
}

$channels = [];

echo "正在提取节目信息...\n";
// 遍历所有节目
foreach ($xml->programme as $prog) {
    $chId = trim((string)$prog['channel']);
    if (!$chId) continue;

    $start = (string)$prog['start'];
    $stop = (string)$prog['stop'];
    $title = (string)$prog->title;

    // 格式化时间，保留原始 YmdHis 部分用于前端逻辑
    $channels[$chId][] = [
        'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2), // HH:mm
        'startTime' => substr($start, 0, 14), // YYYYmmddHHMMSS
        'stopTime'  => substr($stop, 0, 14),
        'program'   => $title
    ];
}

echo "正在生成 JSON 文件...\n";
$count = 0;

foreach ($channels as $id => $progList) {
    // 过滤非法文件名字符
    $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($id));
    if (empty($safeFileName)) continue;

    $filePath = $outputDir . $safeFileName . '.json';
    $jsonContent = json_encode($progList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($filePath, $jsonContent)) {
        $count++;
    }

    // 额外兼容处理：如果频道名包含 CCTV5+，生成一个不带特殊符号的副本
    if ($safeFileName === 'CCTV5+') {
        file_put_contents($outputDir . 'CCTV5PLUS.json', $jsonContent);
    }
}

echo "----------------------------------------------------\n";
echo "✅ 任务完成！\n";
echo "📊 总频道数: " . count($channels) . "\n";
echo "💾 生成文件: $count 个\n";
echo "📅 更新时间: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------------------\n";
