<?php

/**
 * 覆盖率门禁：解析 PHPUnit 生成的 clover 报告，语句覆盖率低于阈值时以非 0 退出
 *
 * 用法: php scripts/check-coverage.php [阈值百分比] [clover 文件路径]
 * 默认: 阈值 80，报告路径 coverage.xml
 */

$threshold = isset($argv[1]) ? (float) $argv[1] : 80.0;
$cloverPath = isset($argv[2]) ? $argv[2] : 'coverage.xml';

if (!is_file($cloverPath)) {
    fwrite(STDERR, "覆盖率报告不存在: {$cloverPath}（先运行 composer test:coverage 生成）\n");
    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "覆盖率报告解析失败: {$cloverPath}\n");
    exit(1);
}

$total = 0;
$covered = 0;
foreach ($xml->xpath('/coverage/project/file/metrics') as $metrics) {
    $total += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}

if ($total === 0) {
    fwrite(STDERR, "覆盖率报告中没有任何语句统计，请检查 phpunit.xml 的 <coverage><include> 配置\n");
    exit(1);
}

$percent = $covered / $total * 100;
printf("语句覆盖率: %.2f%% (%d/%d)，门禁阈值: %.2f%%\n", $percent, $covered, $total, $threshold);

if ($percent < $threshold) {
    fwrite(STDERR, sprintf(
        "覆盖率未达标: %.2f%% < %.2f%%，还差 %d 条语句需要覆盖\n",
        $percent,
        $threshold,
        (int) ceil($threshold / 100 * $total - $covered)
    ));
    exit(1);
}

echo "覆盖率达标\n";
