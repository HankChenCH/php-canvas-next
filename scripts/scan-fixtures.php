<?php

declare(strict_types=1);

/**
 * 表达式片段扫描 fixture 共享定义（content-completion 工单 01 定稿 / 工单 06 同跑）。
 *
 * contract: expression-scan v1 —— 扫描语义共享用例集（模板串 → 片段切分结果），
 * 钉死 TS 扫描器（canvas-web canvas-next-editor）与 PHP 求值器的扫描面对齐：
 * 未闭合 {{ 剩余全字面、片段 trim、{{}} 不嵌套（首个 }} 收口）、片段外字面直通、
 * 空片段/路径空段 = expression_syntax_error 信号。
 *
 * 语义权威 = HankChen\CanvasNext\Expression\InterpolationEvaluator（只读对齐）。
 * 本文件只含定义、零副作用，两处消费：
 *   - scripts/export-scan-fixtures.php：导出 JSON 进 canvas-web（导出即自检）；
 *   - tests/Expression/ExpressionScanFixtureTest.php：CI 永久 runner（工单 06）。
 * 用例在本处单点 authored，双消费避免二次手抄漂移。
 *
 * 自检契约：脚本内扫描器对用例产出切分，再与求值器对整串的实际行为互证——
 * 切分拼装逐段求值的结果必须与整串求值一致（含未闭合尾按字面拼回）；
 * 带错误信号的用例整串求值必须抛同码错误。
 */

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
 * 导出自检：扫描切分与 authored 期望互证 + 切分拼装逐段求值 ≡ 整串求值（权威 =
 * InterpolationEvaluator）。带错误片段的用例：整串求值必须抛 expression_syntax_error。
 * 任一不符抛 RuntimeException（防 fixture 与权威漂移）。
 */
function verifyScanCase(array $case): void
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
}

/**
 * authored 用例 → JSON 载体形态（contract: expression-scan v1，TS runner 消费面）。
 */
function scanFixtureExportShape(array $case): array
{
    [$name, $description, $template, $expectParts] = $case;

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
