<?php
/**
 * Ando EPG 分类处理器 - 根目录版
 */

$inputBaseDir  = __DIR__ . '/list/';
$outputBaseDir = __DIR__ . '/'; // 修改：直接输出到根目录

ini_set('memory_limit', '1024M');

$categories = ['CN', 'HK', 'TW'];

foreach ($categories as $cat) {
    $inputDir = $inputBaseDir . $cat . '/';
    $outputDir = $outputBaseDir . $cat . '/';

    echo "📂 正在处理分类: [$cat]\n";

    if (!is_dir($inputDir)) {
        echo "   ⚠️ 跳过：未找到输入目录 $inputDir\n";
        continue;
    }

    // 确保根目录下的分类文件夹存在
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
                    'program'   => (string)$prog->title
                ];
            }
        }
        unset($xml);
    }

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
    echo "   ✅ 完成：[$cat] 目录已更新。\n";
}
