<?php
/**
 * Ando EPG 分类处理器 - 2026 优化版
 * * 功能：
 * 1. 自动读取 EPG/ 目录下的 cn.xml, hk.xml, tw.xml, all.xml
 * 2. 对节目单进行排序与去重
 * 3. 自动处理 CCTV5+ 等频道的别名 (plus)
 * 4. 按照每文件夹 900 个文件的规格，分箱存储至 01-10 目录
 */

// --- 路径与性能配置 ---
$baseDir = __DIR__ . '/EPG/'; 
ini_set('memory_limit', '1024M'); // 应对 all.xml 较大的情况
date_default_timezone_set('Asia/Shanghai');

// 对应 GitHub Actions 下载的文件名
$xmlFilesToProcess = ['cn.xml', 'hk.xml', 'tw.xml', 'all.xml'];

// 分箱逻辑参数
$globalFileCount = 0;
$filesPerFolder = 900;

echo "🚀 EPG 处理器启动...\n";

$channels = [];
$channelNames = [];

// --- 1. 数据解析阶段 ---
foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    if (!file_exists($filePath)) {
        echo "⏭️ 跳过：文件 $fileName 不存在\n";
        continue;
    }

    echo "📖 正在解析：$fileName\n";
    // 使用 LIBXML_COMPACT 以节省内存
    $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$xml) {
        echo "❌ 错误：无法解析 $fileName\n";
        continue;
    }

    // 提取频道 ID 与名称映射
    if (isset($xml->channel)) {
        foreach ($xml->channel as $ch) {
            $id = trim((string)$ch['id']);
            $name = trim((string)$ch->{'display-name'});
            if ($id && $name) {
                $channelNames[$id] = $name;
            }
        }
    }

    // 提取节目单
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
    unset($xml); // 及时释放内存
}

// --- 2. 写入与分箱阶段 ---
echo "层处理中，准备生成 JSON 文件...\n";

foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    if (empty($displayName)) continue;

    // --- 性能优化：在生成别名前完成数据处理 ---
    // 1. 排序
    usort($progList, function($a, $b) { 
        return strcmp($a['startTime'], $b['startTime']); 
    });
    // 2. 去重 (序列化去重法)
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    // 3. 预先转为 JSON 字符串，避免重复编码
    $jsonContent = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // 定义需要生成的文件名
    $originalName = trim($displayName);
    $namesToGenerate = [$originalName];
    
    // 别名逻辑：例如 CCTV5+ 同时生成 CCTV5Plus.json
    if (strcasecmp($originalName, 'CCTV5+') === 0) {
        $namesToGenerate[] = str_replace('+', 'Plus', $originalName);
    }

    foreach ($namesToGenerate as $nameItem) {
        // 计算目标子目录索引 (如 01, 02...)
        $folderIndex = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIndex . '/';

        // 确保子目录存在
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // 文件名安全过滤
        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $nameItem);
        $filePath = $targetDir . $safeName . '.json';
        
        // 写入文件
        if (file_put_contents($filePath, $jsonContent)) {
            $globalFileCount++;
        }
    }
}

echo "\n✨ 处理完成！\n";
echo "📊 统计信息：\n";
echo "- 解析频道总数：" . count($channels) . " 个\n";
echo "- 生成 JSON 总数：$globalFileCount 个\n";
echo "- 存储目录：$baseDir (包含 01-" . str_pad(ceil($globalFileCount / $filesPerFolder), 2, '0', STR_PAD_LEFT) . ")\n";
