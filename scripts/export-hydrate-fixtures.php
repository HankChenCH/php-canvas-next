<?php

declare(strict_types=1);

/**
 * TableLayer V2 三端共享 fixture 导出（spec §7 / go-canvas 工票 12）。
 * 两个一致性面各一份 JSON，Go 侧 hydrate 包测试为 runner，canvas-web 适配后可复用：
 *
 * - expression-eval.json（contract: expression-eval v2）
 *   默认求值器（受限插值）语义用例：{name, description, group, template, context, expect}
 *   group = value|date|number（v2 增量：内建函数 v1 = date/number/fixed/percent）；
 *   顶层 dateTokens = 日期 token 转译表（v2 增量：{sample, rows:[{token, semantics, expect}]}，
 *   PHP 内置表权威导出，Go 端同构表断言锁漂移）；
 *   expect = 求值结果串 | {errorCode}（spec §5.2 稳定 code，三端一致性抓手）
 *
 * - expand-semantics.json（contract: expand-semantics v1.1）
 *   数据填充（hydrate）语义用例：{name, description, graph, dataset, expect}
 *   管线 = graph → Canvas::fromGraph → CanvasHydrator::hydrate（接软诊断收集器）→ graph
 *   （解码期错误 template_rows_conflict / rows_path_missing 同管线可表达）；
 *   expect = {graph: 填充后 graph（含 V1 高度耦合重放结果）, warnings?: [{code, path}]} | {errorCode}
 *
 * 用例语义源 = php-canvas-next 单测（tests/Expression + tests/Hydrate）逐用例平移；
 * 导出即自检：PHP 实际行为与 authored 期望不符直接失败，防 fixture 与实现漂移。
 *
 * 用法: php scripts/export-hydrate-fixtures.php [输出目录]
 * 默认输出到 ../go-canvas/hydrate/testdata/（JSON 提交进 Go 仓库，
 * 经 go-canvas 的 make hydrate-fixtures 人工触发再生成；Go CI 不装 PHP）。
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Compiler\DiagnosticCollector;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Expression\DateFormat;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;

// ---------------------------------------------------------------------------
// expression-eval v2
// ---------------------------------------------------------------------------

/** 行上下文：行命名空间 + 全局根键混合（语义源 InterpolationEvaluatorTest::rowContext） */
function fixtureRowContext(): array
{
    return [
        'row' => ['name' => '张三', 'user' => ['city' => '杭州'], 'score' => 98.5],
        '$index' => 3,
        'orderNo' => 'A2026-001',
    ];
}

/** 独立图层上下文 = 全局数据集根（语义源 ::rootContext） */
function fixtureRootContext(): array
{
    return ['orderNo' => 'A2026-001', 'nested' => ['k' => 'v']];
}

/** 日期组上下文：3 种可解析形态 + 不可解析/数值/bool/容器/空串各一（语义源 ::dateContext） */
function fixtureDateContext(): array
{
    return [
        'row' => [
            'd' => '2026-10-11 14:30:05',
            'day' => '2026-10-11',
            'isoT' => '2026-10-11T14:30:05',
            'oob' => '2026-02-30',
            'h24' => '2026-10-11 24:00:00',
            'bad' => '2026/10/11',
            'num' => 20261011,
            'empty' => '',
            'flag' => true,
            'tags' => ['a'],
        ],
        '$index' => 3,
        'orderNo' => 'A2026-001',
    ];
}

/** 数字组上下文：常规区间数值 + 严格十进制串 + 不可解析/bool/容器/空串各一（语义源 ::numberContext） */
function fixtureNumberContext(): array
{
    return [
        'row' => [
            'v' => 1234.5,
            'i' => 1234567,
            'z' => 0,
            'n' => -1234.5,
            'f' => 8.9,
            'f2' => 9.9999,
            'p' => 0.826,
            'pn' => -0.826,
            'nz' => -0.4,
            'nt' => -2.5,
            'nf' => -0.85,
            'r' => 2.675,
            'rb' => 2.675000000001,
            'q' => 0.125,
            'w' => 0.123456789,
            's' => '1234.5',
            's007' => '007',
            'sf' => '8.9',
            'bad' => '1e3',
            'empty' => '',
            'flag' => true,
            'tags' => ['a'],
            'day' => '2026-10-11',
        ],
        '$index' => 3,
        'orderNo' => 'A2026-001',
    ];
}

/**
 * 表达式求值用例集。[name, description, group, template, context, expect]
 * group = value|date|number（spec §6）；expect 为串 = 求值成功预期；为 ['errorCode' => ...] = 预期报错
 */
function expressionCases(): array
{
    $row = fixtureRowContext();
    $root = fixtureRootContext();
    $date = fixtureDateContext();
    $num = fixtureNumberContext();
    $rootWithDateKey = ['date' => 'x'];

    return [
        // ---- value 组：既有取值用例（21 条，语义源 InterpolationEvaluatorTest 路径面） ----
        ['row-field-and-literal-mix', '行字段与字面混排 + $index 插值',
            'value', '姓名：{{row.name}}（{{$index}}）', $row, '姓名：张三（3）'],
        ['multiple-fragments-concatenate', '多片段拼接（根键 + 行字段）',
            'value', '{{orderNo}}-{{row.name}}', $row, 'A2026-001-张三'],
        ['bare-name-reads-root-key', '裸名只读全局根键（命名空间分离，无遮蔽）',
            'value', '{{orderNo}}', $row, 'A2026-001'],
        ['nested-dot-path-row', '行字段嵌套点路径下钻',
            'value', '{{row.user.city}}', $row, '杭州'],
        ['nested-dot-path-root', '根键嵌套点路径下钻',
            'value', '{{nested.k}}', $root, 'v'],
        ['root-escape-equals-bare-name', '$root 显式取根与裸名等价',
            'value', '{{$root.orderNo}}', $row, 'A2026-001'],
        ['whitespace-inside-braces-tolerated', '花括号内空白容忍',
            'value', '{{ row.name }}', $row, '张三'],
        ['numeric-float-stringified', '浮点字符串化（常规区间）',
            'value', '{{row.score}}', $row, '98.5'],
        ['index-stringified', '$index 整数字符串化',
            'value', '{{$index}}', $row, '3'],
        ['fragmentless-marked-string-literal', '不含 {{ 的标记串 = 整串字面（合法无意义）',
            'value', '纯文本', $row, '纯文本'],
        ['unclosed-brace-stays-literal', '未闭合 {{ 剩余全字面（含空白片段起点）',
            'value', '价格 {{ 未知', $row, '价格 {{ 未知'],
        ['unclosed-brace-no-close-stays-literal', '未闭合 {{ 只有开标记时全字面',
            'value', '价格 {{row.name', $row, '价格 {{row.name'],
        ['missing-row-field-empty-signal', '行缺字段 = 空串信号（字段类策略由展开器施加）',
            'value', '姓名：{{row.nickname}}', $row, '姓名：'],
        ['missing-root-field-empty-signal', '根缺字段 = 空串信号',
            'value', '{{nickname}}', ['other' => 'x'], ''],
        ['null-mid-path-empty-signal', '中途 null = 空串信号',
            'value', '{{row.a}}', ['row' => ['a' => null, 'b' => ['c' => null]], 'orderNo' => 'x'], ''],
        ['null-terminal-empty-signal', '末端 null = 空串信号',
            'value', '{{row.b.c}}', ['row' => ['a' => null, 'b' => ['c' => null]], 'orderNo' => 'x'], ''],
        ['type-mismatch-array-at-scalar', '数组落到标量位 = 报错',
            'value', '{{row.user}}', $row, ['errorCode' => 'expression_type_mismatch']],
        ['type-mismatch-scalar-drill', '标量中途下钻 = 报错',
            'value', '{{row.name.x}}', $row, ['errorCode' => 'expression_type_mismatch']],
        ['row-prefix-outside-row-context', 'row. 前缀出现在非行上下文 = 报错（模板复制到表外）',
            'value', '{{row.name}}', $root, ['errorCode' => 'expression_row_outside_loop']],
        ['empty-fragment-syntax-error', '空表达式片段 = 语法错误',
            'value', '名：{{}}', $row, ['errorCode' => 'expression_syntax_error']],
        ['malformed-path-syntax-error', '路径含空段 = 语法错误',
            'value', '{{row..name}}', $row, ['errorCode' => 'expression_syntax_error']],

        // ---- value 组：片段分类与调用文法（与族无关的通用文法面，语义源 05 钉稿） ----
        ['unknown-function-fails-fast', '未知函数 fail-fast（不在白名单）',
            'value', '{{foo(row.x)}}', $row, ['errorCode' => 'expression_syntax_error']],
        ['unknown-function-case-sensitive', '函数名大小写敏感：DATE 是未知函数',
            'value', '{{DATE(row.d)}}', $date, ['errorCode' => 'expression_syntax_error']],
        ['unknown-function-non-ascii', '非 ASCII 名按调用分类 → 未知函数',
            'value', '{{日期(row.d)}}', $date, ['errorCode' => 'expression_syntax_error']],
        ['call-classified-with-space', 'token 后可选空白即调用位：foo (bar) 按调用分类',
            'value', '{{foo (row.x)}}', $row, ['errorCode' => 'expression_syntax_error']],
        ['path-with-paren-stays-path', '首 token 后无左括号仍按路径（含 ( 键名，字节兼容）',
            'value', '{{row.a(b}}', $row, ''],
        ['bare-name-date-key-is-path', '无 ( 恒为路径：数据集根键叫 date 合法',
            'value', '{{date}}', $rootWithDateKey, 'x'],
        ['call-structure-arg-garbage', '实参后期望 , 或 )：字符串实参后跟另一实参 = 语法错',
            'value', "{{date(row.d, 'a' 'b')}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['call-structure-unterminated-string', '字符串实参未闭合 = 语法错',
            'value', "{{date(row.d, 'a)}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['call-structure-trailing-content', '调用收口后有多余内容 = 语法错',
            'value', "{{date(row.d, 'a')x}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['close-braces-in-string-truncates', '片段内禁 }} 字面：首个 }} 收口截断 → 字符串未闭合',
            'value', "{{date(row.d, 'a}}b')}}", $date, ['errorCode' => 'expression_syntax_error']],

        // ---- date 组：token 渲染 / 转义 / 预设 / 输入矩阵（语义源 06 钉稿 + spec §3） ----
        ['date-default-format', '单参缺省格式 = YYYY-MM-DD（等价预设 date）',
            'date', '{{date(row.day)}}', $date, '2026-10-11'],
        ['date-datetime-sample-default', '含时间输入单参调用 → 缺省格式取日期部分',
            'date', '{{date(row.d)}}', $date, '2026-10-11'],
        ['date-preset-date', '预设 date 展开与缺省等价',
            'date', "{{date(row.d, 'date')}}", $date, '2026-10-11'],
        ['date-preset-datetime', '预设 datetime → YYYY-MM-DD HH:mm:ss',
            'date', "{{date(row.d, 'datetime')}}", $date, '2026-10-11 14:30:05'],
        ['date-input-iso-t-form', 'ISO T 形态可解析（仅大写 T）',
            'date', "{{date(row.isoT, 'datetime')}}", $date, '2026-10-11 14:30:05'],
        ['date-preset-whole-match', '预设整体等值才命中：datetime X 按 token 串解析 → X 白名单外报错',
            'date', "{{date(row.d, 'datetime X')}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['date-escape-preset-name', '要输出字面 date 用转义段 [date]',
            'date', "{{date(row.d, '[date]')}}", $date, 'date'],
        ['date-chinese-literal-mix', '非 ASCII 字母字面直通：中文格式写即所得',
            'date', "{{date(row.d, 'YYYY年MM月DD日')}}", $date, '2026年10月11日'],
        ['date-weekday-tokens', '周几中文输出（dddd 全称 / ddd 简称）',
            'date', "{{date(row.d, 'dddd ddd')}}", $date, '星期日 周日'],
        ['date-full-combo', '全 token 组合 + 中文字面混排',
            'date', "{{date(row.d, 'YYYY-MM-DD HH:mm:ss dddd')}}", $date, '2026-10-11 14:30:05 星期日'],
        ['date-token-case-sensitive', 'token 大小写敏感：MM 月 ≠ mm 分',
            'date', "{{date(row.d, 'MM月mm分')}}", $date, '10月30分'],
        ['date-escape-literal-segments', '方括号转义段内容字面直通（token 不渲染）',
            'date', "{{date(row.d, '[YYYY]年[MM]月')}}", $date, 'YYYY年MM月'],
        ['date-escape-two-layer-orthogonal', '单引号翻倍转义与方括号转义两层正交',
            'date', "{{date(row.d, '[it''s]')}}", $date, "it's"],
        ['date-escape-unclosed-bracket', '未配对 [ 按普通字符字面输出（含 [ 自身）',
            'date', "{{date(row.d, '第[')}}", $date, '第['],
        ['date-escape-orphan-bracket', '孤立 ] 字面输出',
            'date', "{{date(row.d, ']]')}}", $date, ']]'],
        ['date-escape-nested-bracket', '转义段至首个 ] 止，段内无嵌套无再次转义',
            'date', "{{date(row.d, '[a[b]')}}", $date, 'a[b'],
        ['date-escape-then-whitelist', '未配对 [ 之后字符仍走 token 白名单 → fail-fast',
            'date', "{{date(row.d, '[today')}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['date-empty-format', '空格式串 = 零 token 零输出',
            'date', "{{date(row.d, '')}}", $date, ''],
        ['date-unknown-token', '白名单外 ASCII 字母 fail-fast（数据无关的确定性错误）',
            'date', "{{date(row.d, 'YYYYQ')}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['date-lowercase-token', 'token 大小写敏感：小写 yyyy 无对应 token',
            'date', "{{date(row.d, 'yyyy')}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['date-unparseable-passthrough', '非空字符串不可解析（斜杠格式）= 原文直通',
            'date', '{{date(row.bad)}}', $date, '2026/10/11'],
        ['date-numeric-passthrough', '数值输入 = 字符串化后原文直通（时间戳不收）',
            'date', '{{date(row.num)}}', $date, '20261011'],
        ['date-overflow-day-passthrough', '越界日期 2026-02-30 不可解析 = 原文直通',
            'date', '{{date(row.oob)}}', $date, '2026-02-30'],
        ['date-overflow-hour-passthrough', '24:00:00 不收（严格解析）= 原文直通',
            'date', '{{date(row.h24)}}', $date, '2026-10-11 24:00:00'],
        ['date-empty-value-empty-output', '空串输入 = 空串信号',
            'date', 'x{{date(row.empty)}}', $date, 'x'],
        ['date-missing-field-empty-output', '字段缺失 = 空串信号',
            'date', '{{date(row.nope)}}', $date, ''],
        ['date-bool-type-mismatch', 'bool 落参数位 = 报错（字符串化双端不一致）',
            'date', '{{date(row.flag)}}', $date, ['errorCode' => 'expression_type_mismatch']],
        ['date-container-type-mismatch', '数组落参数位 = 报错',
            'date', '{{date(row.tags)}}', $date, ['errorCode' => 'expression_type_mismatch']],
        ['date-zero-args-syntax-error', '零参调用文法合法但签名层拒 = 参数个数不符',
            'date', '{{date()}}', $date, ['errorCode' => 'expression_syntax_error']],
        ['date-three-args-syntax-error', '≥3 参 = 参数个数不符',
            'date', "{{date(row.d, 'YYYY', 2)}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['date-second-arg-number-syntax-error', 'date 第二参须为字符串字面量',
            'date', '{{date(row.d, 5)}}', $date, ['errorCode' => 'expression_syntax_error']],
        ['date-first-arg-string-syntax-error', '首参文法层钉死为路径：字符串实参 = 语法错',
            'date', "{{date('2026-10-11')}}", $date, ['errorCode' => 'expression_syntax_error']],
        ['date-whitespace-tolerated', '调用内空白容忍：实参间 WS* 合法',
            'date', "{{ date( row.d , 'YYYY-MM-DD' ) }}", $date, '2026-10-11'],
        ['date-row-outside-loop', '调用首参 row. 前缀在非行上下文 = 报错（同门声明）',
            'date', '{{date(row.d)}}', $root, ['errorCode' => 'expression_row_outside_loop']],

        // ---- number 组：三函数语义 / 舍入边界 / 输入矩阵（语义源 07 钉稿 + spec §4） ----
        ['number-thousands-default', 'number 缺省不定点：小数原样直出，仅整数部分分组',
            'number', '{{number(row.v)}}', $num, '1,234.5'],
        ['number-integer-grouping', '纯整数只分组',
            'number', '{{number(row.i)}}', $num, '1,234,567'],
        ['number-zero', '零 → 0（无符号无小数）',
            'number', '{{number(row.z)}}', $num, '0'],
        ['number-negative-outside-group', '负号在分组符之外',
            'number', '{{number(row.n)}}', $num, '-1,234.5'],
        ['number-with-n-rounds-then-groups', 'number 带 n：先定点 n 位补零再分组',
            'number', '{{number(row.f, 2)}}', $num, '8.90'],
        ['number-money-form', '千分位+定点组合 → 「-1,234.50」金额形态（05 禁组合下唯一可达路径）',
            'number', '{{number(row.n, 2)}}', $num, '-1,234.50'],
        ['number-with-n-pads-zero', 'number 带 n 整数补零',
            'number', '{{number(row.z, 2)}}', $num, '0.00'],
        ['fixed-default-n2', 'fixed 缺省 n=2（amis round 无参默认两位先例）',
            'number', '{{fixed(row.f)}}', $num, '8.90'],
        ['fixed-n3-pads', 'fixed 定点补零不分组',
            'number', '{{fixed(row.f, 3)}}', $num, '8.900'],
        ['fixed-n0-no-decimal-point', 'fixed n=0 无小数点',
            'number', '{{fixed(row.f, 0)}}', $num, '9'],
        ['fixed-integer-carry', '整数进位：9.9999 n3 → 10.000',
            'number', '{{fixed(row.f2, 3)}}', $num, '10.000'],
        ['fixed-decimal-rounding', '十进制字符串舍入：2.675 → 2.68（不走二进制原生 round）',
            'number', '{{fixed(row.r, 2)}}', $num, '2.68'],
        ['fixed-near-boundary', '近界 2.675000000001 → 2.68',
            'number', '{{fixed(row.rb, 2)}}', $num, '2.68'],
        ['fixed-tie-up', '精确 tie 正数进位：0.125 n2 → 0.13',
            'number', '{{fixed(row.q, 2)}}', $num, '0.13'],
        ['fixed-negative-tie-toward-zero', 'tie 时负数朝零：-2.5 n0 → -2（half-up 朝 +∞）',
            'number', '{{fixed(row.nt, 0)}}', $num, '-2'],
        ['fixed-negative-tie-fraction', 'tie 时负数朝零：-0.85 n1 → -0.8',
            'number', '{{fixed(row.nf, 1)}}', $num, '-0.8'],
        ['fixed-no-negative-zero', '定点结果为 0 输出 0 不输出 -0',
            'number', '{{fixed(row.nz, 0)}}', $num, '0'],
        ['fixed-n8-upper-bound', 'n 范围上界 8：0.123456789 n8 → 0.12345679',
            'number', '{{fixed(row.w, 8)}}', $num, '0.12345679'],
        ['percent-times-100-default', 'percent 乘 100 缺省 0 位：0.826 → 83%',
            'number', '{{percent(row.p)}}', $num, '83%'],
        ['percent-with-n', 'percent 带 n：0.826 n2 → 82.60%',
            'number', '{{percent(row.p, 2)}}', $num, '82.60%'],
        ['percent-negative', '负百分比：-0.826 → -83%',
            'number', '{{percent(row.pn)}}', $num, '-83%'],
        ['percent-shift-two-decimals', '×100 = 小数点右移两位（十进制字符串语义，无二进制误差）',
            'number', '{{percent(row.v)}}', $num, '123450%'],
        ['number-strict-decimal-string', '严格十进制串收：解析为值后同管线格式化',
            'number', '{{number(row.s)}}', $num, '1,234.5'],
        ['number-leading-zeros-normalized', '前导零按值归一：007 → 7',
            'number', '{{number(row.s007)}}', $num, '7'],
        ['fixed-strict-string', '严格十进制串进定点管线',
            'number', '{{fixed(row.sf, 3)}}', $num, '8.900'],
        ['number-unparseable-passthrough', '非空不可解析（科学计数法）= 原文直通',
            'number', '{{number(row.bad)}}', $num, '1e3'],
        ['number-empty-value-empty-output', '空串输入 = 空串信号',
            'number', 'x{{number(row.empty)}}', $num, 'x'],
        ['number-missing-field-empty-output', '字段缺失 = 空串信号',
            'number', '{{number(row.nope)}}', $num, ''],
        ['number-bool-type-mismatch', 'bool 落参数位 = 报错',
            'number', '{{number(row.flag)}}', $num, ['errorCode' => 'expression_type_mismatch']],
        ['number-container-type-mismatch', '数组落参数位 = 报错',
            'number', '{{number(row.tags)}}', $num, ['errorCode' => 'expression_type_mismatch']],
        ['number-n-out-of-range', 'n 超界（>8）= 语义层 fail-fast',
            'number', '{{number(row.v, 9)}}', $num, ['errorCode' => 'expression_syntax_error']],
        ['number-n-non-integer', '非整数 n = 语法错',
            'number', '{{fixed(row.f, 2.5)}}', $num, ['errorCode' => 'expression_syntax_error']],
        ['number-n-negative', '数字字面量无符号：负数 n 在文法层即错',
            'number', '{{number(row.v, -1)}}', $num, ['errorCode' => 'expression_syntax_error']],
        ['number-zero-args-syntax-error', '零参调用 = 参数个数不符',
            'number', '{{number()}}', $num, ['errorCode' => 'expression_syntax_error']],
        ['number-three-args-syntax-error', '≥3 参 = 参数个数不符',
            'number', '{{number(row.v, 1, 2)}}', $num, ['errorCode' => 'expression_syntax_error']],
        ['number-second-arg-string', '数字族第二参须为数字字面量',
            'number', "{{number(row.v, '2')}}", $num, ['errorCode' => 'expression_syntax_error']],
        ['number-second-arg-path', '第二参不接受路径（v1 签名只消费字符串与数字字面量）',
            'number', '{{number(row.v, row.n)}}', $num, ['errorCode' => 'expression_syntax_error']],
        ['number-first-arg-string-syntax-error', '首参文法层钉死为路径：格式化常量是伪场景',
            'number', "{{number('123')}}", $num, ['errorCode' => 'expression_syntax_error']],

        // ---- value 组：调用与既有面混排（双族混合上下文） ----
        ['calls-mix-with-literals-and-paths', '调用片段与字面/路径片段混排',
            'value', "编号：{{number(row.i, 0)}}（{{date(row.day, 'YYYY年')}}）", $num, '编号：1,234,567（2026年）'],
    ];
}

/**
 * 日期 token 转译表（fixture dateTokens，v2 增量）：PHP 内置表（DateFormat）权威导出，
 * sample 钉死 spec §3.1 样例（星期日），逐 token 断言期望输出并自检
 */
function dateTokensSection(): array
{
    $sample = '2026-10-11 14:30:05';
    $expects = [
        'YYYY' => '2026', 'YY' => '26',
        'MM' => '10', 'M' => '10',
        'DD' => '11', 'D' => '11',
        'HH' => '14', 'H' => '14',
        'mm' => '30', 'm' => '30',
        'ss' => '05', 's' => '5',
        'dddd' => '星期日', 'ddd' => '周日',
    ];

    $dt = DateFormat::parseIso($sample);
    if ($dt === null) {
        throw new RuntimeException("dateTokens 样例 {$sample} 解析失败");
    }

    $rows = [];
    foreach (DateFormat::tokenTable() as $entry) {
        $token = $entry['token'];
        $actual = DateFormat::format($token, $dt);
        if ($actual !== $expects[$token]) {
            throw new RuntimeException("token {$token}：实际输出「{$actual}」与钉稿期望「{$expects[$token]}」不符");
        }
        $rows[] = ['token' => $token, 'semantics' => $entry['semantics'], 'expect' => $actual];
    }

    return ['sample' => $sample, 'rows' => $rows];
}

/** 导出单个求值用例并自检：PHP 实际行为与 authored 期望不符直接失败 */
function exportExpressionCase(array $case): array
{
    [$name, $description, $group, $template, $context, $expect] = $case;

    try {
        $result = (new InterpolationEvaluator())->evaluate($template, $context);
        $got = is_array($expect) ? "预期报错 {$expect['errorCode']} 但求值成功（{$result}）" : null;
        if ($got !== null) {
            throw new RuntimeException("用例 {$name}：{$got}");
        }
        if ($result !== $expect) {
            throw new RuntimeException("用例 {$name}：求值结果「{$result}」与预期「{$expect}」不符");
        }
        $expectOut = $expect;
    } catch (CanvasNextExceptionInterface $e) {
        if (!is_array($expect) || $expect['errorCode'] !== $e->getErrorCode()) {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与预期不符");
        }
        $expectOut = ['errorCode' => $e->getErrorCode()];
    }

    return [
        'name' => $name,
        'description' => $description,
        'group' => $group,
        'template' => $template,
        'context' => $context,
        'expect' => $expectOut,
    ];
}

// ---------------------------------------------------------------------------
// expand-semantics v1
// ---------------------------------------------------------------------------

/**
 * 主模板表（语义源 CanvasHydratorTest::templateTable）：
 * auto 文本格（行上下文求值 + $index）+ 固定图片格（资源类求值），rowsPath = order.items
 */
function fixtureTemplateTable(): TableLayer
{
    $row = (new TableRowTemplate())->setHeight('auto');

    $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
    $textCell->addTemplateContentLayer(
        TextLayer::make(160, 'auto')->setExpression('姓名：{{row.name}}（{{$index}}）')->setFont('', 12, '#000')
    );
    $imageCell = (new TableCellLayer())->setWidth(160)->setHeight(40);
    $imageCell->addTemplateContentLayer(ImageLayer::make(160, 'auto')->setExpression('{{row.avatar}}'));

    $row->addCell($textCell)->addCell($imageCell);

    $table = TableLayer::make(320, 200, '#fff');
    $table->setRowsPath('order.items');
    $table->setTemplate($row);

    return $table;
}

/** 主数据集（语义源 ::dataset，含两行） */
function fixtureDataset(): array
{
    return [
        'orderNo' => 'A2026-001',
        'order' => ['items' => [
            ['name' => '张三', 'avatar' => '/tmp/a.png'],
            ['name' => '李四', 'avatar' => '/tmp/b.png'],
        ]],
    ];
}

/** 单文本格模板表 helper：rowsPath 可变，用于取数路径/类型不匹配等聚焦用例 */
function singleTextTemplateTable(string $expression, string $rowsPath): TableLayer
{
    $row = (new TableRowTemplate())->setHeight('auto');
    $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $cell->addTemplateContentLayer(
        TextLayer::make(320, 'auto')->setExpression($expression)->setFont('', 12, '#000')
    );
    $row->addCell($cell);

    $table = TableLayer::make(320, 200);
    $table->setRowsPath($rowsPath);
    $table->setTemplate($row);

    return $table;
}

/** 单图片格模板表 helper：资源单值门合法形态（行上下文 $index）聚焦用例 */
function singleImageTemplateTable(string $expression): TableLayer
{
    $row = (new TableRowTemplate())->setHeight(40);
    $cell = (new TableCellLayer())->setWidth(160)->setHeight(40);
    $cell->addTemplateContentLayer(ImageLayer::make(160, 40)->setExpression($expression));
    $row->addCell($cell);

    $table = TableLayer::make(320, 200);
    $table->setRowsPath('items');
    $table->setTemplate($row);

    return $table;
}

/** 独立图片图层画布 helper：资源单值门根上下文聚焦用例（语源 CanvasHydratorTest 违例表） */
function singleImageCanvas(string $expression): Canvas
{
    return Canvas::make(320, 100, ImageLayer::make(60, 60)->setExpression($expression));
}

/** QR 绑定面用例表：锁 expression 条件写键（求值后键移除） */
function qrTemplateTable(): TableLayer
{
    $row = (new TableRowTemplate())->setHeight(40);
    $cell = (new TableCellLayer())->setWidth(320)->setHeight(40);
    $cell->addTemplateContentLayer(
        (new QrCodeLayer())->setWidth(320)->setHeight(40)->setExpression('{{row.code}}')
    );
    $row->addCell($cell);

    $table = TableLayer::make(320, 200);
    $table->setRowsPath('items');
    $table->setTemplate($row);

    return $table;
}

/** 嵌套模板表：内层 rowsPath 行相对（以当前行数据为取数范围），行字段指向最内层行 */
function nestedTemplateTable(): TableLayer
{
    $innerTemplate = (new TableRowTemplate())->setHeight('auto');
    $innerCell = (new TableCellLayer())->setWidth(200)->setHeight('auto');
    $innerCell->addTemplateContentLayer(
        TextLayer::make(200, 'auto')->setExpression('{{row.name}}@{{$index}}')->setFont('', 12, '#000')
    );
    $innerTemplate->addCell($innerCell);

    $innerTable = TableLayer::make(200, 100);
    $innerTable->setRowsPath('sub');
    $innerTable->setTemplate($innerTemplate);

    $outerTemplate = (new TableRowTemplate())->setHeight('auto');
    $outerCell = (new TableCellLayer())->setWidth(200)->setHeight(100);
    $outerCell->addTemplateContentLayer($innerTable);
    $outerTemplate->addCell($outerCell);

    $outerTable = TableLayer::make(200, 200);
    $outerTable->setRowsPath('items');
    $outerTable->setTemplate($outerTemplate);

    return $outerTable;
}

/** 填充语义用例集。管线 = graph → fromGraph → hydrate；错误码可 authored 自检 */
function hydrateCases(): array
{
    // 用例 graph 一律画布级（Canvas::fromGraph 的输入形态，与 layout-snapshot 同构）
    $mainGraph = Canvas::make(320, 400, fixtureTemplateTable())->graph();
    $mainDataset = fixtureDataset();

    // 手写非法 wire（API 构造不出来，spec §2.2 XOR 由解码期拦截）
    $conflictGraph = $mainGraph;
    $conflictGraph['layers'][0]['rows'] = [];
    $missingPathGraph = $mainGraph;
    unset($missingPathGraph['layers'][0]['data']);

    $qrGraph = Canvas::make(320, 240, qrTemplateTable())->graph();
    $nestedGraph = Canvas::make(240, 400, nestedTemplateTable())->graph();

    $v1Table = TableLayer::make(320, 100, '#fff');
    $v1Cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $v1Cell->addContentLayer(
        TextLayer::make(320, 'auto')->setExpression('{{orderNo}}')->setFont('', 12, '#000')
    );
    $v1Table->addRow(TableRowLayer::make(320, 'auto')->addCell($v1Cell));
    $v1Graph = Canvas::make(320, 400, $v1Table)->graph();

    // 软诊断用例（expand v1.1）：V1 表命中/兜底混合（命中行静默、兜底行发射）
    $v1FallbackTable = TableLayer::make(320, 100, '#fff');
    $v1HitCell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $v1HitCell->addContentLayer(
        TextLayer::make(320, 'auto')->setExpression('{{orderNo}}')->setFont('', 12, '#000')
    );
    $v1FallbackCell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $v1FallbackCell->addContentLayer(
        TextLayer::make(320, 'auto')->setExpression('{{missing}}')->setFont('', 12, '#000')
    );
    $v1FallbackTable->addRow(TableRowLayer::make(320, 'auto')->addCell($v1HitCell));
    $v1FallbackTable->addRow(TableRowLayer::make(320, 'auto')->addCell($v1FallbackCell));
    $v1FallbackGraph = Canvas::make(320, 400, $v1FallbackTable)->graph();

    return [
        [$mainGraph, $mainDataset, null,
            'template-table-instantiation',
            '主用例：两行实例化 + 行上下文求值 + $index 自 1 起 + 标记解除（valueType→StaticValue）'
            . ' + V1 高度耦合重放（行高定稿/固定格内容压平）+ template/data 键消失'],
        [$qrGraph, ['items' => [['code' => 'CODE-A'], ['code' => 'CODE-B']]], null,
            'qr-marked-content',
            'QR 绑定面：求值后 expression 键移除（条件写键，对齐未标记两键形态）'],
        [Canvas::make(
            320,
            100,
            TextLayer::make(200, 30)->setExpression('订单号：{{orderNo}}')->setFont('', 12, '#000'),
            ImageLayer::make(60, 60)->setExpression('{{orderNo}}'),
            (new QrCodeLayer())->setWidth(60)->setHeight(60)->setExpression('{{$root.orderNo}}')
        )->graph(), $mainDataset, null,
            'independent-layers-root-context',
            '独立图层（非行循环）根上下文求值：全绑定面 Text/Image/Qr；'
            . '资源两类表达式为单值裸路径（ADR 0020 单值门合法形态，$root. 前缀放行）'],
        [$v1Graph, $mainDataset, null,
            'v1-table-marked-content-root-scope',
            'V1 表（rows only）标记内容在根作用域求值：行/格/内容同作用域传递'],
        [$mainGraph, null, null,
            'null-dataset-identity',
            'dataset null = 未绑数据集恒等直通（含模板表不展开，渲染按零行空壳呈现）'],
        [Canvas::make(320, 100, TextLayer::make(100, 30)->setText('字面 {{orderNo}} 文案'))->graph(),
            $mainDataset, null,
            'no-bindings-identity',
            '无标记无模板画布恒等直通（存量文案里的字面 {{ 不误伤）'],
        [$mainGraph, ['order' => ['items' => []]], null,
            'empty-row-array-empty-shell',
            '空数组 = 合法零行：rows 键存在且为空数组，template/data 消失（空壳渲染）'],
        [$nestedGraph, ['items' => [
            ['sub' => [['name' => '甲']]],
            ['sub' => [['name' => '乙'], ['name' => '丙']]],
        ]], null,
            'nested-template-table-row-relative',
            '嵌套模板表：内层 rowsPath 行相对（以当前行数据为取数范围），$index 内层自 1 起'],
        [$mainGraph, ['orderNo' => 'A2026-001'], 'rows_path_invalid',
            'rows-path-missing-key',
            'rowsPath 指向缺键 = 报错（结构性错误 fail-fast）'],
        [$mainGraph, ['order' => ['items' => 'not-an-array']], 'rows_path_invalid',
            'rows-path-non-array',
            'rowsPath 指向非数组 = 报错'],
        [Canvas::make(320, 100, TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000'))->graph(),
            ['row' => ['name' => 'x']], 'reserved_root_key',
            'reserved-root-key-row',
            '数据集根级键名 row = 报错（{{row.x}} 的 row 段歧义）'],
        [Canvas::make(320, 100, TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000'))->graph(),
            ['$meta' => 1, 'orderNo' => 'A'], 'reserved_root_key',
            'reserved-root-key-dollar',
            '数据集根级 $ 前缀键名 = 报错'],
        [$mainGraph, ['order' => ['items' => [['name' => '张三']]]], 'expression_empty_resource',
            'empty-resource-expression',
            '资源类字段（Image src）语法合法单路径但求值空串 = 报错（单值门放行后落 empty_resource：'
            . '空 URL 无意义，fail-fast 优于错误后移到物化）'],
        [singleImageCanvas('{{orderNo}}')->graph(), ['orderNo' => 'A2026-001'], null,
            'resource-single-bare-path-legal',
            '资源单值门（ADR 0020）合法形态：恰一个 {{...}} 闭合片段 + 裸点路径，正常求值并解除标记'],
        [singleImageCanvas('{{$root.orderNo}}')->graph(), ['orderNo' => 'A2026-001'], null,
            'resource-root-escape-path-legal',
            '资源单值门合法形态：$root. 前缀同为裸点路径'],
        [Canvas::make(320, 400, singleImageTemplateTable('{{$index}}'))->graph(),
            ['items' => [['x' => 1], ['x' => 2]]], null,
            'resource-index-path-legal-row-context',
            '资源单值门合法形态：$index 保留名（行上下文，自 1 起）'],
        [singleImageCanvas('{{orderNo}}{{orderNo}}')->graph(), ['orderNo' => 'A2026-001'], 'expression_resource_single_ref',
            'resource-multi-fragment-violation',
            '资源单值门违例：多片段拼接（文本插值语义在资源面废除，求值端 fail-fast）'],
        [singleImageCanvas('{{orderNo}}.png')->graph(), ['orderNo' => 'A2026-001'], 'expression_resource_single_ref',
            'resource-literal-outside-violation',
            '资源单值门违例：片段外字面（存量拼接 URL 形态再渲染 fail-fast，0.x 契约窗口接受）'],
        [singleImageCanvas('{{number(orderNo, 2)}}')->graph(), ['orderNo' => 'A2026-001'], 'expression_resource_single_ref',
            'resource-function-call-violation',
            '资源单值门违例：内建函数调用（格式化标量不是数据端备好的资源引用）'],
        [singleImageCanvas('{{}}')->graph(), ['orderNo' => 'A2026-001'], 'expression_resource_single_ref',
            'resource-empty-fragment-violation',
            '资源单值门违例：空片段（门先于求值器，不落 expression_syntax_error）'],
        [singleImageCanvas('{{missing}}.png')->graph(), ['orderNo' => 'A'], 'expression_resource_single_ref',
            'resource-violation-precedes-empty-check',
            '顺序钉定：校验在求值前——违例形态即使求值必空也报 single_ref 而非 empty_resource'],
        [Canvas::make(320, 400, singleTextTemplateTable('{{row.tags}}', 'items'))->graph(),
            ['items' => [['tags' => ['a', 'b']]]], 'expression_type_mismatch',
            'type-mismatch-in-row-context',
            '行上下文中数组落到标量位 = 报错'],
        [$conflictGraph, $mainDataset, 'template_rows_conflict',
            'decode-template-rows-conflict',
            '解码期：template 与 rows 键同现 = 非法 wire（手写，API 构造不出来）'],
        [$missingPathGraph, $mainDataset, 'rows_path_missing',
            'decode-rows-path-missing',
            '解码期：模板态缺 data.rowsPath = 非法 wire（先紧后松，补默认值是非破坏性变更）'],

        // ---- 软诊断发射缝（expand v1.1，spec/pipeline.md §4/§5）：文案类求值空串 =
        // 兜底空串 + 软诊断 expression_fallback_empty；断言面 = code + path + 序列序
        [Canvas::make(
            320,
            100,
            TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000'),
            TextLayer::make(100, 30)->setExpression('{{missing}}')->setFont('', 12, '#000')
        )->graph(), ['orderNo' => 'A2026-001'], null,
            'text-fallback-single-warning-path',
            '软诊断发射缝：命中不发射、兜底才发射（混合双图层）；诊断 path = 产物树根相对'
            . '点路径 layers[1].data.value，序列即发射序（图层文档序）'],
        [Canvas::make(320, 400, singleTextTemplateTable('{{row.nickname}}', 'items'))->graph(),
            ['items' => [['name' => '甲'], ['name' => '乙']]], null,
            'text-fallback-template-row-sequence',
            '模板表实例逐行发射：诊断 path = 产物树落位 rows[实例序].cells[0].content.data.value，'
            . '序列即发射序（行序）'],
        [$v1FallbackGraph, ['orderNo' => 'A2026-001'], null,
            'text-fallback-v1-row-path',
            'V1 表（静态 rows）命中/兜底混合：命中行静默，兜底行 path = '
            . 'rows[1].cells[0].content.data.value'],
    ];
}

/** 软诊断期望序列（工票 05）：断言面只落 code + path（消息人读可本地化、不入断言面，spec §4/§5）；空序列 = 键省略 */
function exportWarnings(DiagnosticCollector $warnings): ?array
{
    if ($warnings->all() === []) {
        return null;
    }

    return array_map(
        static fn (Diagnostic $d) => array_filter(
            ['code' => $d->code, 'path' => $d->path],
            static fn ($v) => $v !== null
        ),
        $warnings->all()
    );
}

/** 导出单个填充用例：管线 = graph → fromGraph → hydrate（接软诊断收集器），authored 错误码自检 */
function exportHydrateCase(array $case): array
{
    [$graph, $dataset, $expectedErrorCode, $name, $description] = $case;

    try {
        $decoded = Canvas::fromGraph($graph);
        $warnings = new DiagnosticCollector();
        $hydrated = (new CanvasHydrator())->hydrate($decoded, $dataset, $warnings);
        if ($expectedErrorCode !== null) {
            throw new RuntimeException("用例 {$name}：预期报错 {$expectedErrorCode} 但填充成功");
        }
        $expect = ['graph' => $hydrated->graph()];
        if (($exported = exportWarnings($warnings)) !== null) {
            $expect['warnings'] = $exported;
        }
    } catch (CanvasNextExceptionInterface $e) {
        if ($expectedErrorCode !== null && $e->getErrorCode() !== $expectedErrorCode) {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与预期 {$expectedErrorCode} 不符");
        }
        $expect = ['errorCode' => $e->getErrorCode()];
    }

    return [
        'name' => $name,
        'description' => $description,
        'graph' => $graph,
        'dataset' => $dataset,
        'expect' => $expect,
    ];
}

// ---------------------------------------------------------------------------
// 输出
// ---------------------------------------------------------------------------

$outDir = $argv[1] ?? __DIR__ . '/../../go-canvas/hydrate/testdata';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "输出目录不可创建: {$outDir}\n");
    exit(1);
}

$documents = [
    'expression-eval.json' => [
        'meta' => [
            'contract' => 'expression-eval v2',
            'generator' => 'php-canvas-next/scripts/export-hydrate-fixtures.php',
            'notes' => [
                '默认求值器（受限插值）语义用例，语义源 php-canvas-next tests/Expression 逐用例平移；',
                'context 形状为契约层钉定（spec §3.2）：row 键 = 行命名空间、裸名 = 数据集根键、$index（自 1 起）/$root 保留名；',
                'v2 增量 = 每条加 group 字段（value|date|number，既有取值用例归 value）+ 顶层 dateTokens 转译表；内建函数 v1 = date/number/fixed/percent；',
                'dateTokens：PHP 内置表（DateFormat）权威导出，Go 端内置同构表、fixture 断言锁漂移；sample 钉死 2026-10-11 14:30:05（星期日）；',
                '分轴哲学（spec §0）：缺字段/null/空串 = 空串信号、不可解析 = 原文直通（值面宽松）；未知函数/参数个数/结构错/n 超界/格式串白名单外字母 fail-fast，全部并入 expression_syntax_error，零新 code（spec §5.2）；',
                'bool/数组/对象落函数参数位 = expression_type_mismatch（bool 字符串化双端不一致，直通无法对齐）；',
                '日期可解析输入收 3 种无时区 ISO 形态（wall clock），时间戳不收；数字族十进制字符串舍入（half-up 朝 +∞），',
                '数值字符串化/十进制语义只锁常规区间（整数/常规小数）；极端浮点（科学计数法阈值）不入 fixture、不保证双端一致（spec §4.3）；',
                '资源表达式单值门（ADR 0020）不在求值器：文本插值语义零变化，违例/合法形态的 hydrate 级判定锁在 expand-semantics.json（expression_resource_single_ref）；',
                '再生成: go-canvas 仓 make hydrate-fixtures（需本机 PHP 8.3）',
            ],
        ],
        'cases' => array_map('exportExpressionCase', expressionCases()),
        'dateTokens' => dateTokensSection(),
    ],
    'expand-semantics.json' => [
        'meta' => [
            'contract' => 'expand-semantics v1.1',
            'generator' => 'php-canvas-next/scripts/export-hydrate-fixtures.php',
            'notes' => [
                '数据填充（hydrate）语义用例，语义源 php-canvas-next tests/Hydrate/CanvasHydratorTest.php 逐用例平移（另含 QR/嵌套扩覆盖用例）；',
                'v1.1 = 软诊断发射缝语料面（工票 05，纯增用例）：expect 增可选 warnings 键 = 期望诊断序列 [{code, path}]'
                . '（消息人读不入断言面，path 为产物树中立记法 spec/pipeline.md §5；省略 = 空序列），'
                . '现役发射面唯一 = 文案类表达式兜底空串 expression_fallback_empty，序列即发射序；',
                'runner 管线 = graph → 反序列化重建 → hydrate(dataset, 收集器) → graph，与 PHP 导出管线同构（Go: canvas.FromGraph → hydrate）；',
                '解码期错误（template_rows_conflict/rows_path_missing）与填充期错误同管线表达，expect = {graph, warnings?} | {errorCode}（spec §5.2）；',
                'dataset null = 未绑数据集恒等直通（含模板表）；无标记无模板画布恒等直通；',
                '填充产物 graph 已含 V1 高度耦合重放结果（行高定稿/固定格内容压平），填充永不回写源 graph；',
                '资源表达式单值门（ADR 0020）：已标记 Image/Qr 的 expression 必须恰为一个 {{...}} 闭合片段 + 裸点路径'
                . '（$index/$root. 前缀合法），违例 errorCode expression_resource_single_ref，校验先于求值与 empty_resource 空串检查；',
                '再生成: go-canvas 仓 make hydrate-fixtures（需本机 PHP 8.3）',
            ],
        ],
        'cases' => array_map('exportHydrateCase', hydrateCases()),
    ],
];

foreach ($documents as $filename => $document) {
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "{$filename} 序列化失败: " . json_last_error_msg() . "\n");
        exit(1);
    }
    file_put_contents($outDir . '/' . $filename, $json . "\n");
    printf("已导出 %d 个用例 → %s/%s\n", count($document['cases']), $outDir, $filename);
}
