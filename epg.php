<?php
/**
 * Ando EPG 分类处理器 - 独立生成修复版
 */

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

$scriptDir = str_replace('\\', '/', dirname(__FILE__));
$baseDir = rtrim($scriptDir, '/') . '/EPG/';
$xmlSourceDir = $baseDir . 'xml_source/'; // XML 存放位置

// --- 1. 精准清理旧 JSON ---
$oldFolders = glob($baseDir . '[0-9][0-9]', GLOB_ONLYDIR);
if ($oldFolders) {
    foreach ($oldFolders as $folder) {
        $files = glob($folder . '/*');
        foreach ($files as $file) { @unlink($file); }
        @rmdir($folder);
    }
}

// --- 2. 解析逻辑 ---
$xmlFilesToProcess = ['jack.xml', 't.xml', 'hk.xml', 'tw.xml'];
$channels = [];
$channelNames = [];
$generatedFiles = []; // 新增：用于记录已生成的文件名，防止重复写入
$globalFileCount = 0;
$filesPerFolder = 900;

foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $xmlSourceDir . $fileName;
    
    if (!file_exists($filePath)) {
        echo "⚠️ Skip: $fileName\n";
        continue;
    }

    echo "📖 Parsing: $fileName\n";
    $content = file_get_contents($filePath);
    $content = preg_replace('/<(tv|xmltv)[^>]*xmlns[:="][^>]*>/i', '<$1>', $content);
    $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    
    if (!$xml) continue;

    // 当前文件内的频道映射
    $currentFileChannels = [];
    if (isset($xml->channel)) {
        foreach ($xml->channel as $ch) {
            $id = trim((string)$ch['id']);
            $name = trim((string)$ch->{"display-name"});
            if (!$id || !$name) continue;
            
            // 注意：这里不再使用全局 lockedChannelIds 过滤，
            // 确保每个 XML 的频道都能被读取到自己的作用域
            $channelNames[$id] = $name;
            $currentFileChannels[$id] = $name;
        }
    }

    // 解析当前文件的节目单
    if (isset($xml->programme)) {
        foreach ($xml->programme as $prog) {
            $chId = trim((string)$prog['channel']);
            if (!isset($currentFileChannels[$chId])) continue;
            
            $start = (string)$prog['start'];
            $channels[$chId][] = [
                'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                'startTime' => substr($start, 0, 14),
                'stopTime'  => substr((string)$prog['stop'], 0, 14),
                'program'   => trim((string)$prog->title)
            ];
        }
    }
    // 及时释放内存
    unset($xml, $content);
}

// --- 3. 写入逻辑 ---
foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    
    // 排序与去重
    usort($progList, function($a, $b) { return strcmp($a['startTime'], $b['startTime']); });
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $nameItem = trim($displayName);
    $targets = [$nameItem]; 

    // 处理 Plus 衍生名
    if (strpos($nameItem, '+') !== false) {
        $targets[] = str_replace('+', 'Plus', $nameItem);
    }

    foreach ($targets as $targetName) {
        // 文件名安全过滤
        $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $targetName);
        $fileLower = strtolower($safeFileName);

        // 【核心逻辑】如果这个文件名已经生成过了，就跳过，不合并也不覆盖
        // 这样“青海卫视高清”和“青海卫视”因为名称不同，会分别触发写入
        if (isset($generatedFiles[$fileLower])) continue;

        // 分箱计算
        $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIdx . '/';
        
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $fullPath = $targetDir . $safeFileName . '.json';

        if (file_put_contents($fullPath, $jsonEncoded) !== false) {
            $globalFileCount++;
            $generatedFiles[$fileLower] = true;
        }
    }
}

echo "📊 Done: $globalFileCount files generated.\n";
