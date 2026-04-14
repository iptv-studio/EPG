<?php
/**
 * IPTV EPG 分类处理器 - 优化增强版
 */

// 路径配置
$inputBaseDir  = __DIR__ . '/list/';
$outputBaseDir = __DIR__ . '/'; 

// 提高内存限制以处理大型 XML 文件
ini_set('memory_limit', '1024M');
// 设置时区（建议与服务器/Action保持一致）
date_default_timezone_set('Asia/Shanghai');

$categories = ['CN', 'HK', 'TW'];

foreach ($categories as $cat) {
    $inputDir  = $inputBaseDir . $cat . '/';
    $outputDir = $outputBaseDir . $cat . '/';

    echo "📂 正在处理分类: [$cat]\n";

    if (!is_dir($inputDir)) {
        echo "    ⚠️ 跳过：未找到输入目录 $inputDir\n";
        continue;
    }

    // 1. 确保根目录下的分类文件夹存在
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    } else {
        // 2. 只有在目录存在时才清理旧的 .json 文件
        // 使用更安全的方式遍历删除，避免 glob 返回 false 导致报错
        $oldFiles = glob($outputDir . '*.json');
        if (is_array($oldFiles)) {
            foreach ($oldFiles as $f) @unlink($f);
        }
    }

    $xmlFiles = glob($inputDir . '*.xml');
    if (empty($xmlFiles)) {
        echo "    ℹ️ 该分类下没有 XML 文件，跳过。\n";
        continue;
    }

    $channels = [];
    $channelNames = [];

    foreach ($xmlFiles as $file) {
        echo "    📄 正在解析: " . basename($file) . "\n";
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$xml) continue;

        // 解析频道信息
        if (isset($xml->channel)) {
            foreach ($xml->channel as $ch) {
                $id = trim((string)$ch['id']);
                $name = trim((string)$ch->{'display-name'});
                if ($id && $name) $channelNames[$id] = $name;
            }
        }

        // 解析节目单
        if (isset($xml->programme)) {
            foreach ($xml->programme as $prog) {
                $chId = trim((string)$prog['channel']);
                $start = (string)$prog['start'];
                $stop = (string)$prog['stop'];
                
                // 仅抓取基本信息，减少内存占用
                $channels[$chId][] = [
                    'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                    'startTime' => substr($start, 0, 14),
                    'stopTime'  => substr($stop, 0, 14),
                    'program'   => trim((string)$prog->title)
                ];
            }
        }
        unset($xml); // 显式释放内存
    }

    // 3. 写入 JSON 文件
    $fileCount = 0;
    foreach ($channels as $id => $progList) {
        // 优先使用 display-name，否则使用 id，若都没有则跳过
        $displayName = $channelNames[$id] ?? $id;
        if (empty($displayName)) continue;

        // 过滤文件名非法字符
        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($displayName));
        
        // 排序：按开始时间
        usort($progList, function($a, $b) { 
            return strcmp($a['startTime'], $b['startTime']); 
        });

        // 去重：防止不同 XML 来源包含重复节目
        $progList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));

        $filePath = $outputDir . $safeName . '.json';
        if (file_put_contents($filePath, json_encode($progList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            $fileCount++;
        }
    }
    echo "    ✅ 完成：[$cat] 目录已处理，生成了 $fileCount 个 JSON 文件。\n";
    
    // 释放当前分类占用内存
    unset($channels, $channelNames);
}

echo "\n✨ 所有分类处理完毕！\n";
