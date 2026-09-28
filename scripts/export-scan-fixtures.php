<?php

declare(strict_types=1);

/**
 * 表达式片段扫描 fixture 导出（content-completion spec §4 / 工单 01 定稿、工单 06 同跑）。
 *
 * contract: expression-scan v1 —— 扫描语义共享用例集（模板串 → 片段切分结果），
 * 钉死 TS 扫描器（canvas-web canvas-next-editor）与 PHP 求值器的扫描面对齐。
 *
 * 用例定义、扫描器与自检契约单点 authored 在 scripts/scan-fixtures.php（零副作用，
 * 与 CI 永久 runner tests/Expression/ExpressionScanFixtureTest.php 共消费）；
 * 本脚本只负责导出壳：自检 → JSON 载体落盘。
 *
 * 载体与同步（沿用 go-canvas expand-fixtures 先例）：用例定义在权威仓（本包），
 * JSON 提交进 canvas-web（packages/canvas-next-editor/tests/fixtures/）由 TS 侧
 * runner 消费；JSON 不携带位置字段（PHP 字节索引与 JS 码元索引不同空间，各端
 * 自行派生）。再生成命令见 fixture meta.notes，人工触发、人审 diff。
 *
 * 用法: php scripts/export-scan-fixtures.php [输出目录]
 * 默认输出到 ../canvas-web/packages/canvas-next-editor/tests/fixtures/
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/scan-fixtures.php';

/** 导出单个用例：先自检（切分/求值互证），再映射为 JSON 载体形态 */
function exportScanCase(array $case): array
{
    verifyScanCase($case);

    return scanFixtureExportShape($case);
}

$outDir = $argv[1] ?? __DIR__ . '/../../canvas-web/packages/canvas-next-editor/tests/fixtures';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "输出目录不可创建: {$outDir}\n");
    exit(1);
}

$document = [
    'meta' => [
        'contract' => 'expression-scan v1',
        'generator' => 'php-canvas-next/scripts/export-scan-fixtures.php',
        'notes' => [
            '表达式片段扫描共享用例（content-completion 工单 01）：模板串 → 片段切分结果，钉死 TS 扫描器与求值器扫描面对齐；',
            '语义权威 = InterpolationEvaluator::evaluate 扫描循环（只读对齐）：未闭合 {{ 剩余全字面、片段 trim、{{}} 不嵌套、片段外字面直通、空片段/路径空段 = expression_syntax_error；',
            'open 切分 = 未闭合 {{ 的编辑器补全触发态（spec §3 输入 {{ 自动弹出），求值期按字面拼回，TS 扫描器同款；',
            'part 形态：literal{text} | fragment{raw,expr,error} | open{text,expr}；不含位置字段（PHP 字节索引与 JS 码元索引不同空间，各端自行派生）；',
            'trim = PHP trim() 默认六字符（" \\t\\n\\r\\0\\x0B"）；全角空格不属该集（fullwidth-space-not-trimmed 用例钉死），TS 侧禁用 String.trim；',
            '空字面一律省略；光标所在片段判定是编辑器侧语义（PHP 无光标概念），不入本用例集；',
            '再生成: php php-canvas-next/scripts/export-scan-fixtures.php（需本机 PHP 8.3），人审 git diff 后随代码一并提交',
        ],
    ],
    'cases' => array_map('exportScanCase', scanCases()),
];

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, '序列化失败: ' . json_last_error_msg() . "\n");
    exit(1);
}

$outFile = $outDir . '/expression-scan.json';
file_put_contents($outFile, $json . "\n");
printf("已导出 %d 个用例 → %s（导出自检全绿）\n", count($document['cases']), $outFile);
