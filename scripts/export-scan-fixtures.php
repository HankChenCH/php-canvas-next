<?php

declare(strict_types=1);

/**
 * 表达式片段扫描 fixture 导出（content-completion spec §4 / 工单 01）。
 *
 * contract: expression-scan v1 —— 扫描语义共享用例集（模板串 → 片段切分结果），
 * 钉死 TS 扫描器（canvas-web canvas-next-editor）与 PHP 求值器的扫描面对齐：
 * 未闭合 {{ 剩余全字面、片段 trim、{{}} 不嵌套（首个 }} 收口）、片段外字面直通、
 * 空片段/路径空段 = expression_syntax_error 信号。
 *
 * 语义权威 = HankChen\CanvasNext\Expression\InterpolationEvaluator（只读对齐）。
 * 导出即自检：脚本内扫描器对用例产出切分，再与求值器对整串的实际行为互证——
 * 切分拼装逐段求值的结果必须与整串求值一致（含未闭合尾按字面拼回）；
 * 带错误信号的用例整串求值必须抛同码错误。不符即导出失败，防 fixture 与权威漂移。
 *
 * 用例面（工单 01 验收）：中文内容、多片段、未闭合边界、空段错误、trim 字符集
 * （全角空格不属 PHP trim 六字符集——TS String.trim 与之不同，勿在 TS 侧使用）。
 *
 * 载体与同步（沿用 go-canvas expand-fixtures 先例）：用例定义在本脚本（权威仓），
 * JSON 提交进 canvas-web（packages/canvas-next-editor/tests/fixtures/）由 TS 侧
 * runner 消费；JSON 不携带位置字段（PHP 字节索引与 JS 码元索引不同空间，各端
 * 自行派生）。再生成命令见 fixture meta.notes，人工触发、人审 diff。
 *
 * 用法: php scripts/export-scan-fixtures.php [输出目录]
 * 默认输出到 ../canvas-web/packages/canvas-next-editor/tests/fixtures/
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;

/**
 * 自检上下文：行命名空间 + 全局根键混合（语义源 InterpolationEvaluatorTest）。
 * 键值选取让边界错误可显形——若切分错位导致查到相邻键，求值结果即偏离。
 */
function fixtureScanContext(): array
{
    return [
        'row' => ['name' => '张三', 'user' => ['city' => '杭州']],
        '$index' => 3,
        'orderNo' => 'A2026-001',
        'nested' => ['k' => 'v'],
        '订单号' => 'A2026-CN',
    ];
}

/**
 * 扫描用例集。[name, description, template, expectParts]
 * expectParts 有序切分（空字面一律省略）：
 *   ['literal', text]
 *   ['fragment', raw, expr, error]   error = null | 'expression_syntax_error'
 *   ['open', text, expr]             未闭合 {{ 起的编辑器触发态（求值期按字面）
 */
function scanCases(): array
{
    return [
        ['empty-template', '空串无片段', '', []],
        ['plain-literal-no-braces', '不含 {{ 的串整串字面（合法无意义）', '纯文本',
            [['literal', '纯文本']]],
        ['close-without-open-literal', '孤立 }} 无开标记 = 字面直通', 'a}}b',
            [['literal', 'a}}b']]],
        ['single-fragment', '单片段（根键）', '{{orderNo}}',
            [['fragment', 'orderNo', 'orderNo', null]]],
        ['literal-fragment-mix-chinese', '字面与片段混排（中文前后缀 + $index）',
            '姓名：{{row.name}}（{{$index}}）',
            [
                ['literal', '姓名：'],
                ['fragment', 'row.name', 'row.name', null],
                ['literal', '（'],
                ['fragment', '$index', '$index', null],
                ['literal', '）'],
            ]],
        ['adjacent-fragments', '相邻片段之间不产空字面', '{{orderNo}}{{row.name}}',
            [
                ['fragment', 'orderNo', 'orderNo', null],
                ['fragment', 'row.name', 'row.name', null],
            ]],
        ['ascii-whitespace-trimmed', '花括号内 ASCII 空白容忍（trim 六字符集：空格/制表/换行/回车/NUL/垂直制表）',
            "{{\t row.name \n}}",
            [['fragment', "\t row.name \n", 'row.name', null]]],
        ['fullwidth-space-not-trimmed', '全角空格不属 trim 字符集（PHP trim 六字符；TS 勿用 String.trim）',
            '{{　row.name　}}',
            [['fragment', '　row.name　', '　row.name　', null]]],
        ['unclosed-tail-literal', '未闭合 {{ 剩余全字面（前置字面 + open 触发态）', '价格 {{row.name',
            [
                ['literal', '价格 '],
                ['open', '{{row.name', 'row.name'],
            ]],
        ['unclosed-after-closed-fragment', '片段之后未闭合：闭合片段照常，其后全字面', 'A{{orderNo}}B{{unclosed',
            [
                ['literal', 'A'],
                ['fragment', 'orderNo', 'orderNo', null],
                ['literal', 'B'],
                ['open', '{{unclosed', 'unclosed'],
            ]],
        ['unclosed-open-at-end', '以 {{ 结尾（补全触发态，expr 为空）', 'x{{',
            [
                ['literal', 'x'],
                ['open', '{{', ''],
            ]],
        ['unclosed-without-prefix', '未闭合 {{ 起于串首（无前置字面）', '{{unclosed',
            [['open', '{{unclosed', 'unclosed']]],
        ['unclosed-swallows-inner-open', '未闭合吞并其后的 {{（无 }} 可收口，直到串尾）', '{{a{{b',
            [['open', '{{a{{b', 'a{{b']]],
        ['triple-open-brace', '三连开括号：首 {{ 为外壳，{ 落入内容', '{{{orderNo}}',
            [['fragment', '{orderNo', '{orderNo', null]]],
        ['inner-open-no-nesting', '{{}} 不嵌套：片段内容含 {{，首个 }} 收口', '{{a{{b}}}}',
            [
                ['fragment', 'a{{b', 'a{{b', null],
                ['literal', '}}'],
            ]],
        ['inner-open-outer-content', '四连开括号：内容以前导 {{ 开头', '{{{{orderNo}}}}',
            [
                ['fragment', '{{orderNo', '{{orderNo', null],
                ['literal', '}}'],
            ]],
        ['extra-close-brace-literal', '片段后尾随 }} 为字面', '{{orderNo}}}}',
            [
                ['fragment', 'orderNo', 'orderNo', null],
                ['literal', '}}'],
            ]],
        ['root-explicit', '$root 显式取根', '{{$root.orderNo}}',
            [['fragment', '$root.orderNo', '$root.orderNo', null]]],
        ['dollar-index-bare', '$index 按裸键分类', '{{$index}}',
            [['fragment', '$index', '$index', null]]],
        ['chinese-bare-key', '中文裸键', '单号：{{订单号}}',
            [
                ['literal', '单号：'],
                ['fragment', '订单号', '订单号', null],
            ]],
        ['multi-byte-split', '多字节字面与片段交错（索引空间各端自行派生，只钉内容）',
            '{{row.name}}在{{orderNo}}单',
            [
                ['fragment', 'row.name', 'row.name', null],
                ['literal', '在'],
                ['fragment', 'orderNo', 'orderNo', null],
                ['literal', '单'],
            ]],
        ['empty-fragment-syntax-error', '空片段 {{}} = 语法错误', '名：{{}}',
            [
                ['literal', '名：'],
                ['fragment', '', '', 'expression_syntax_error'],
            ]],
        ['whitespace-only-fragment-syntax-error', '纯空白片段 trim 后为空 = 语法错误', '名：{{   }}尾',
            [
                ['literal', '名：'],
                ['fragment', '   ', '', 'expression_syntax_error'],
                ['literal', '尾'],
            ]],
        ['empty-path-segment-mid', '路径中部空段 = 语法错误', '{{row..name}}',
            [['fragment', 'row..name', 'row..name', 'expression_syntax_error']]],
        ['empty-path-segment-leading', '前导点空段 = 语法错误', '{{.name}}',
            [['fragment', '.name', '.name', 'expression_syntax_error']]],
        ['empty-path-segment-trailing', '尾点空段 = 语法错误', '{{row.name.}}',
            [['fragment', 'row.name.', 'row.name.', 'expression_syntax_error']]],
        ['error-fragment-after-valid', '合法片段之后的错误片段照常切分', '{{orderNo}}{{row..x}}尾',
            [
                ['fragment', 'orderNo', 'orderNo', null],
                ['fragment', 'row..x', 'row..x', 'expression_syntax_error'],
                ['literal', '尾'],
            ]],
    ];
}

/**
 * 脚本内扫描器（与求值器 evaluate() 扫描循环同构；编辑器 TS 侧同款）。
 * 未闭合 {{ 除按字面外另立 open 切分（编辑器补全触发态），拼回语义与字面等价。
 */
function scanParts(string $template): array
{
    $parts = [];
    $pos = 0;
    $length = strlen($template);

    while (($open = strpos($template, '{{', $pos)) !== false) {
        $close = strpos($template, '}}', $open + 2);
        if ($close === false) {
            if ($open > $pos) {
                $parts[] = ['literal', substr($template, $pos, $open - $pos)];
            }
            $text = substr($template, $open);
            $parts[] = ['open', $text, trim(substr($text, 2))];
            return $parts;
        }

        if ($open > $pos) {
            $parts[] = ['literal', substr($template, $pos, $open - $pos)];
        }
        $raw = substr($template, $open + 2, $close - $open - 2);
        $expr = trim($raw);
        $error = ($expr === '' || in_array('', explode('.', $expr), true))
            ? 'expression_syntax_error'
            : null;
        $parts[] = ['fragment', $raw, $expr, $error];
        $pos = $close + 2;
    }

    if ($pos < $length) {
        $parts[] = ['literal', substr($template, $pos)];
    }

    return $parts;
}

/**
 * 导出单个用例并自检：切分拼装逐段求值 ≡ 整串求值（权威 = InterpolationEvaluator）。
 * 带错误片段的用例：整串求值必须抛 expression_syntax_error。
 */
function exportScanCase(array $case): array
{
    [$name, $description, $template, $expectParts] = $case;
    $context = fixtureScanContext();
    $evaluator = new InterpolationEvaluator();
    $parts = scanParts($template);

    if ($parts !== $expectParts) {
        throw new RuntimeException(sprintf(
            "用例 %s：扫描切分与 authored 期望不符\n authored: %s\n scanned:  %s",
            $name,
            json_encode($expectParts, JSON_UNESCAPED_UNICODE),
            json_encode($parts, JSON_UNESCAPED_UNICODE),
        ));
    }

    $hasError = false;
    $composed = '';
    foreach ($parts as $part) {
        if ($part[0] === 'literal') {
            $composed .= $part[1];
            continue;
        }
        if ($part[0] === 'open') {
            $composed .= $part[1]; // 未闭合尾求值期按字面拼回
            continue;
        }
        if ($part[3] !== null) {
            $hasError = true;
            continue;
        }
        $composed .= $evaluator->evaluate('{{' . $part[2] . '}}', $context);
    }

    try {
        $evaluated = $evaluator->evaluate($template, $context);
        if ($hasError) {
            throw new RuntimeException("用例 {$name}：authored 错误信号但整串求值成功（{$evaluated}）");
        }
        if ($composed !== $evaluated) {
            throw new RuntimeException(sprintf(
                "用例 %s：切分拼装求值「%s」与整串求值「%s」不符（切分与权威漂移）",
                $name,
                $composed,
                $evaluated,
            ));
        }
    } catch (CanvasNextExceptionInterface $e) {
        if (!$hasError || $e->getErrorCode() !== 'expression_syntax_error') {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与 authored 期望不符");
        }
    }

    $expectOut = [];
    foreach ($expectParts as $part) {
        $expectOut[] = $part[0] === 'literal'
            ? ['kind' => 'literal', 'text' => $part[1]]
            : ($part[0] === 'open'
                ? ['kind' => 'open', 'text' => $part[1], 'expr' => $part[2]]
                : ['kind' => 'fragment', 'raw' => $part[1], 'expr' => $part[2], 'error' => $part[3]]);
    }

    return [
        'name' => $name,
        'description' => $description,
        'template' => $template,
        'expect' => ['parts' => $expectOut],
    ];
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
