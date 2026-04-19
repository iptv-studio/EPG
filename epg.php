<?php
/**
 * Ando EPG 分类处理器 - 路径修复版
 */

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

$scriptDir = str_replace('\\', '/', dirname(__FILE__));
$baseDir = rtrim($scriptDir, '/') . '/EPG/';
$xmlSourceDir = $baseDir . 'xml_source/'; // XML 存放的新位置

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
$xmlFilesToProcess = ['t.xml', 'pl.xml' , 'boss.xml','hk.xml', 'tw.xml'];
$channels = [];
$channelNames = [];
$lockedChannelIds = [];
$globalFileCount = 0;
$filesPerFolder = 900;

foreach ($xmlFilesToProcess as $fileName) {
    // 这里的路径改为从 xml_source 读取
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

    $currentFileChannels = [];
    if (isset($xml->channel)) {
        foreach ($xml->channel as $ch) {
            $id = trim((string)$ch['id']);
            $name = trim((string)$ch->{"display-name"});
            if (!$id || !$name || isset($lockedChannelIds[$name])) continue;
            $channelNames[$id] = $name;
            $currentFileChannels[$id] = $name;
            $lockedChannelIds[$name] = true;
        }
    }

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
}

// --- 3. 写入逻辑 ---
// (这部分和你之前的逻辑一致，无需修改分箱逻辑)
foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    usort($progList, function($a, $b) { return strcmp($a['startTime'], $b['startTime']); });
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
    $targetDir = $baseDir . $folderIdx . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($displayName));
    if (file_put_contents($targetDir . $safeFileName . '.json', $jsonEncoded)) {
        $globalFileCount++;
    }
}
echo "📊 Done: $globalFileCount files generated.\n";
