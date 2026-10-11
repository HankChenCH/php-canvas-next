<?php

namespace HankChen\CanvasNext\Tests\Expression;

use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 默认求值器（受限插值）：mustache 风格外壳 + 变量点路径子集 + 内建函数调用片段。
 * 上下文形状为契约层钉定（spec §3.2/§3.6）：'row' 键 = 行命名空间、
 * 裸名 = 数据集根键、'$index'/'$root' 保留名。
 * 调用片段钉稿见 .scratch/expression-type-methods/spec.md §1/§2/§5
 */
class InterpolationEvaluatorTest extends CanvasTestCase
{
    private InterpolationEvaluator $evaluator;

    /** 行上下文：行命名空间 + 全局根键混合（根键不得与保留名冲突，由填充器校验） */
    private array $rowContext;

    /** 独立图层上下文 = 全局数据集根 */
    private array $rootContext;

    /** 日期组上下文：3 种可解析形态 + 不可解析/数值/bool/容器/空串各一 */
    private array $dateContext;

    /** 数字组上下文：常规区间数值 + 严格十进制串 + 不可解析/bool/容器/空串各一 */
    private array $numberContext;

    protected function setUp(): void
    {
        $this->evaluator = new InterpolationEvaluator();
        $this->rowContext = [
            'row' => [
                'name' => '张三',
                'user' => ['city' => '杭州'],
                'score' => 98.5,
            ],
            '$index' => 3,
            'orderNo' => 'A2026-001',
        ];
        $this->rootContext = ['orderNo' => 'A2026-001', 'nested' => ['k' => 'v'], 'date' => 'x'];
        $this->dateContext = [
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
        $this->numberContext = [
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
            ],
            '$index' => 3,
            'orderNo' => 'A2026-001',
        ];
    }

    public function testInterpolatesRowFieldAndLiteralMix(): void
    {
        $this->assertSame(
            '姓名：张三（3）',
            $this->evaluator->evaluate('姓名：{{row.name}}（{{$index}}）', $this->rowContext)
        );
    }

    public function testMultipleFragmentsConcatenate(): void
    {
        $this->assertSame(
            'A2026-001-张三',
            $this->evaluator->evaluate('{{orderNo}}-{{row.name}}', $this->rowContext)
        );
    }

    public function testBareNameReadsRootKeyOnly(): void
    {
        // 裸名 = 全局根键，非行字段（命名空间分离，无遮蔽）
        $this->assertSame('A2026-001', $this->evaluator->evaluate('{{orderNo}}', $this->rowContext));
    }

    public function testNestedDotPathDrillsDown(): void
    {
        $this->assertSame('杭州', $this->evaluator->evaluate('{{row.user.city}}', $this->rowContext));
        $this->assertSame('v', $this->evaluator->evaluate('{{nested.k}}', $this->rootContext));
    }

    public function testRootEscapeEqualsBareName(): void
    {
        $this->assertSame(
            $this->evaluator->evaluate('{{orderNo}}', $this->rowContext),
            $this->evaluator->evaluate('{{$root.orderNo}}', $this->rowContext)
        );
    }

    public function testWhitespaceInsideBracesIsTolerated(): void
    {
        $this->assertSame('张三', $this->evaluator->evaluate('{{ row.name }}', $this->rowContext));
    }

    public function testNumericAndFloatValuesAreStringified(): void
    {
        $this->assertSame('98.5', $this->evaluator->evaluate('{{row.score}}', $this->rowContext));
        $this->assertSame('3', $this->evaluator->evaluate('{{$index}}', $this->rowContext));
    }

    public function testFragmentlessMarkedStringIsLiteral(): void
    {
        // 不含 {{ 的标记串 = 整串字面（合法无意义）
        $this->assertSame('纯文本', $this->evaluator->evaluate('纯文本', $this->rowContext));
    }

    public function testUnclosedBraceStaysLiteral(): void
    {
        $this->assertSame('价格 {{ 未知', $this->evaluator->evaluate('价格 {{ 未知', $this->rowContext));
        $this->assertSame('价格 {{row.name', $this->evaluator->evaluate('价格 {{row.name', $this->rowContext));
    }

    public function testMissingFieldEvaluatesToEmptyString(): void
    {
        // 缺字段信号 = 空串（填充器按字段类施加兜底/报错）
        $this->assertSame('姓名：', $this->evaluator->evaluate('姓名：{{row.nickname}}', $this->rowContext));
        $this->assertSame('', $this->evaluator->evaluate('{{nickname}}', []));
    }

    public function testNullMidPathAndTerminalEvaluateToEmptyString(): void
    {
        $context = ['row' => ['a' => null, 'b' => ['c' => null]]];
        $this->assertSame('', $this->evaluator->evaluate('{{row.a}}', $context));
        $this->assertSame('', $this->evaluator->evaluate('{{row.b.c}}', $context));
    }

    public function testTypeMismatchThrows(): void
    {
        // 数组/对象落到标量位 → 报错
        try {
            $this->evaluator->evaluate('{{row.user}}', $this->rowContext);
            $this->fail('数组落到标量位必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_type_mismatch', $e->getErrorCode());
        }

        // 标量中途下钻 → 报错
        try {
            $this->evaluator->evaluate('{{row.name.x}}', $this->rowContext);
            $this->fail('标量中途下钻必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_type_mismatch', $e->getErrorCode());
        }
    }

    public function testRowPrefixOutsideRowContextThrows(): void
    {
        // row. 在非行上下文 = 报错（row 命名空间不存在，模板复制到表外是典型错误）
        try {
            $this->evaluator->evaluate('{{row.name}}', $this->rootContext);
            $this->fail('非行上下文的 row. 前缀必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_row_outside_loop', $e->getErrorCode());
        }
    }

    public function testEmptyFragmentThrowsSyntaxError(): void
    {
        try {
            $this->evaluator->evaluate('名：{{}}', $this->rowContext);
            $this->fail('空表达式片段必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_syntax_error', $e->getErrorCode());
        }
    }

    public function testMalformedPathThrowsSyntaxError(): void
    {
        try {
            $this->evaluator->evaluate('{{row..name}}', $this->rowContext);
            $this->fail('空路径段必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_syntax_error', $e->getErrorCode());
        }
    }

    // -----------------------------------------------------------------------
    // 内建函数调用片段（钉稿 .scratch/expression-type-methods/spec.md §1/§2/§5）
    // -----------------------------------------------------------------------

    public function testDateCallDefaultFormatAndPresets(): void
    {
        // 单参缺省 = YYYY-MM-DD，等价预设 date
        $this->assertSame('2026-10-11', $this->evaluator->evaluate('{{date(row.day)}}', $this->dateContext));
        $this->assertSame('2026-10-11', $this->evaluator->evaluate('{{date(row.d)}}', $this->dateContext));
        $this->assertSame('2026-10-11', $this->evaluator->evaluate("{{date(row.d, 'date')}}", $this->dateContext));
        $this->assertSame('2026-10-11 14:30:05', $this->evaluator->evaluate("{{date(row.d, 'datetime')}}", $this->dateContext));
        $this->assertSame('2026-10-11 14:30:05', $this->evaluator->evaluate("{{date(row.isoT, 'datetime')}}", $this->dateContext));
    }

    public function testDateCallPresetIsWholeMatchOnly(): void
    {
        // 预设整体等值才展开：'datetime X' 不命中 → 按 token 串解析 → X 白名单外 fail-fast
        $this->assertSyntaxError("{{date(row.d, 'datetime X')}}", $this->dateContext);
        // 要输出字面 date 用转义段
        $this->assertSame('date', $this->evaluator->evaluate("{{date(row.d, '[date]')}}", $this->dateContext));
    }

    public function testDateCallChineseLiteralMixAndWeekday(): void
    {
        $this->assertSame('2026年10月11日', $this->evaluator->evaluate("{{date(row.d, 'YYYY年MM月DD日')}}", $this->dateContext));
        $this->assertSame('星期日 周日', $this->evaluator->evaluate("{{date(row.d, 'dddd ddd')}}", $this->dateContext));
        $this->assertSame('2026-10-11 14:30:05 星期日', $this->evaluator->evaluate("{{date(row.d, 'YYYY-MM-DD HH:mm:ss dddd')}}", $this->dateContext));
        $this->assertSame('10月30分', $this->evaluator->evaluate("{{date(row.d, 'MM月mm分')}}", $this->dateContext));
    }

    public function testDateCallEscapingForms(): void
    {
        $this->assertSame('YYYY年MM月', $this->evaluator->evaluate("{{date(row.d, '[YYYY]年[MM]月')}}", $this->dateContext));
        // 单引号翻倍转义与 token 层方括号转义两层正交
        $this->assertSame("it's", $this->evaluator->evaluate("{{date(row.d, '[it''s]')}}", $this->dateContext));
        $this->assertSame('第[', $this->evaluator->evaluate("{{date(row.d, '第[')}}", $this->dateContext));
        $this->assertSame(']]', $this->evaluator->evaluate("{{date(row.d, ']]')}}", $this->dateContext));
        $this->assertSame('a[b', $this->evaluator->evaluate("{{date(row.d, '[a[b]')}}", $this->dateContext));
        $this->assertSame('', $this->evaluator->evaluate("{{date(row.d, '')}}", $this->dateContext));
    }

    public function testDateCallValueMatrix(): void
    {
        // 不可解析 → 原文直通；数值（时间戳不收）→ 字符串化后原文直通
        $this->assertSame('2026/10/11', $this->evaluator->evaluate('{{date(row.bad)}}', $this->dateContext));
        $this->assertSame('20261011', $this->evaluator->evaluate('{{date(row.num)}}', $this->dateContext));
        // 越界即不可解析（严格解析）
        $this->assertSame('2026-02-30', $this->evaluator->evaluate('{{date(row.oob)}}', $this->dateContext));
        $this->assertSame('2026-10-11 24:00:00', $this->evaluator->evaluate('{{date(row.h24)}}', $this->dateContext));
        // 缺失/null/空串 → 空串
        $this->assertSame('x', $this->evaluator->evaluate('x{{date(row.empty)}}', $this->dateContext));
        $this->assertSame('', $this->evaluator->evaluate('{{date(row.nope)}}', $this->dateContext));
        // bool / 容器落参数位 → type_mismatch
        $this->assertErrorCode('{{date(row.flag)}}', $this->dateContext, 'expression_type_mismatch');
        $this->assertErrorCode('{{date(row.tags)}}', $this->dateContext, 'expression_type_mismatch');
    }

    public function testNumberCallGrouping(): void
    {
        $this->assertSame('1,234.5', $this->evaluator->evaluate('{{number(row.v)}}', $this->numberContext));
        $this->assertSame('1,234,567', $this->evaluator->evaluate('{{number(row.i)}}', $this->numberContext));
        $this->assertSame('0', $this->evaluator->evaluate('{{number(row.z)}}', $this->numberContext));
        $this->assertSame('-1,234.5', $this->evaluator->evaluate('{{number(row.n)}}', $this->numberContext));
        $this->assertSame('8.90', $this->evaluator->evaluate('{{number(row.f, 2)}}', $this->numberContext));
        $this->assertSame('-1,234.50', $this->evaluator->evaluate('{{number(row.n, 2)}}', $this->numberContext));
        $this->assertSame('0.00', $this->evaluator->evaluate('{{number(row.z, 2)}}', $this->numberContext));
    }

    public function testFixedCallPadsWithoutGrouping(): void
    {
        $this->assertSame('8.90', $this->evaluator->evaluate('{{fixed(row.f)}}', $this->numberContext));
        $this->assertSame('8.900', $this->evaluator->evaluate('{{fixed(row.f, 3)}}', $this->numberContext));
        $this->assertSame('9', $this->evaluator->evaluate('{{fixed(row.f, 0)}}', $this->numberContext));
        $this->assertSame('10.000', $this->evaluator->evaluate('{{fixed(row.f2, 3)}}', $this->numberContext));
        $this->assertSame('0', $this->evaluator->evaluate('{{fixed(row.nz, 0)}}', $this->numberContext)); // 不输出 -0
    }

    public function testPercentCallShiftsAndRounds(): void
    {
        $this->assertSame('83%', $this->evaluator->evaluate('{{percent(row.p)}}', $this->numberContext));
        $this->assertSame('82.60%', $this->evaluator->evaluate('{{percent(row.p, 2)}}', $this->numberContext));
        $this->assertSame('-83%', $this->evaluator->evaluate('{{percent(row.pn)}}', $this->numberContext));
    }

    public function testRoundingBoundaryCasesThroughEvaluator(): void
    {
        // 十进制字符串舍入（half-up 朝 +∞，tie 负数朝零），边界例逐例进 fixture（spec §4.2）
        $this->assertSame('2.68', $this->evaluator->evaluate('{{fixed(row.r, 2)}}', $this->numberContext));
        $this->assertSame('2.68', $this->evaluator->evaluate('{{fixed(row.rb, 2)}}', $this->numberContext));
        $this->assertSame('0.13', $this->evaluator->evaluate('{{fixed(row.q, 2)}}', $this->numberContext));
        $this->assertSame('-2', $this->evaluator->evaluate('{{fixed(row.nt, 0)}}', $this->numberContext));
        $this->assertSame('-0.8', $this->evaluator->evaluate('{{fixed(row.nf, 1)}}', $this->numberContext));
        $this->assertSame('0.12345679', $this->evaluator->evaluate('{{fixed(row.w, 8)}}', $this->numberContext));
    }

    public function testNumberCallValueMatrix(): void
    {
        // 严格十进制串收，前导零按值归一
        $this->assertSame('1,234.5', $this->evaluator->evaluate('{{number(row.s)}}', $this->numberContext));
        $this->assertSame('7', $this->evaluator->evaluate('{{number(row.s007)}}', $this->numberContext));
        $this->assertSame('8.900', $this->evaluator->evaluate('{{fixed(row.sf, 3)}}', $this->numberContext));
        // 非空不可解析 → 原文直通；缺失/null/空串 → 空串
        $this->assertSame('1e3', $this->evaluator->evaluate('{{number(row.bad)}}', $this->numberContext));
        $this->assertSame('x', $this->evaluator->evaluate('x{{number(row.empty)}}', $this->numberContext));
        $this->assertSame('', $this->evaluator->evaluate('{{number(row.nope)}}', $this->numberContext));
        // bool / 容器 → type_mismatch
        $this->assertErrorCode('{{number(row.flag)}}', $this->numberContext, 'expression_type_mismatch');
        $this->assertErrorCode('{{number(row.tags)}}', $this->numberContext, 'expression_type_mismatch');
    }

    public function testUnknownFunctionFailsFast(): void
    {
        $this->assertSyntaxError('{{foo(row.x)}}', $this->rowContext);
        $this->assertSyntaxError('{{DATE(row.d)}}', $this->dateContext); // 大小写敏感
        $this->assertSyntaxError('{{日期(row.d)}}', $this->dateContext); // 非 ASCII 名
        $this->assertSyntaxError('{{foo (row.x)}}', $this->rowContext); // token 后可选空白即调用位
    }

    public function testCallArityAndSignatureFailsFast(): void
    {
        $this->assertSyntaxError('{{date()}}', $this->dateContext); // 零参（文法合法，签名层拒）
        $this->assertSyntaxError('{{number(row.v, 1, 2)}}', $this->numberContext);
        $this->assertSyntaxError("{{date('2026-10-11')}}", $this->dateContext); // 首参必须为路径
        $this->assertSyntaxError('{{number(3)}}', $this->numberContext);
        $this->assertSyntaxError('{{date(row.d, 5)}}', $this->dateContext); // 第二参须为字符串字面量
        $this->assertSyntaxError("{{number(row.v, '2')}}", $this->numberContext); // 第二参须为数字字面量
        $this->assertSyntaxError('{{number(row.v, row.n)}}', $this->numberContext);
    }

    public function testNumericArgumentRangeFailsFast(): void
    {
        $this->assertSyntaxError('{{number(row.v, 9)}}', $this->numberContext); // n 范围 0–8
        $this->assertSyntaxError('{{fixed(row.f, 2.5)}}', $this->numberContext); // 非整数
        $this->assertSyntaxError('{{number(row.v, -1)}}', $this->numberContext); // 数字字面量无符号
    }

    public function testCallStructureErrorsFailFast(): void
    {
        $this->assertSyntaxError('{{date(row.d}}', $this->dateContext); // 括号不平衡
        $this->assertSyntaxError('{{date(row.d,)}}', $this->dateContext); // 尾逗号
        $this->assertSyntaxError('{{date(,row.d)}}', $this->dateContext); // 空参数位
        $this->assertSyntaxError("{{date(row.d, 'a' 'b')}}", $this->dateContext); // 实参后期望 ,/ )
        $this->assertSyntaxError("{{date(row.d, 'a)}}", $this->dateContext); // 字符串未闭合
        $this->assertSyntaxError("{{date(row.d, 'a')x}}", $this->dateContext); // 调用后多余内容
    }

    public function testClosingBracesInsideStringTruncateFragment(): void
    {
        // 片段内禁 }} 字面：首个 }} 收口（引号不感知）截断 → 字符串未闭合
        $this->assertSyntaxError("{{date(row.d, 'a}}b')}}", $this->dateContext);
    }

    public function testPathFragmentClassificationUnchanged(): void
    {
        // 含 ( 的路径位片段（首 token 后无左括号）仍按路径，行为字节兼容
        $this->assertSame('', $this->evaluator->evaluate('{{row.a(b}}', $this->rowContext));
        // 无 ( 恒为路径：数据集根键叫 date 合法
        $this->assertSame('x', $this->evaluator->evaluate('{{date}}', $this->rootContext));
        // 路径实参至 ,/) 止、段内空白属于键名（既有文法逐字节沿用）：缺逗号不构成结构错，
        // `row.d 'YYYY'` 是键名 `d 'YYYY'`（缺失 → 空串，与独立路径片段同语义）
        $this->assertSame('', $this->evaluator->evaluate("{{date(row.d 'YYYY')}}", $this->dateContext));
    }

    public function testCallRowOutsideLoop(): void
    {
        $this->assertErrorCode('{{date(row.d)}}', $this->rootContext, 'expression_row_outside_loop');
    }

    public function testCallsMixWithLiteralsAndPaths(): void
    {
        $context = $this->numberContext;
        $context['row']['day'] = '2026-10-11';
        $this->assertSame(
            '编号：1,234,567（2026年）',
            $this->evaluator->evaluate("编号：{{number(row.i, 0)}}（{{date(row.day, 'YYYY年')}}）", $context)
        );
        // 空白容忍
        $this->assertSame('2026', $this->evaluator->evaluate("{{ date( row.day , 'YYYY' ) }}", $context));
    }

    private function assertSyntaxError(string $template, array $context): void
    {
        $this->assertErrorCode($template, $context, 'expression_syntax_error');
    }

    private function assertErrorCode(string $template, array $context, string $expectedCode): void
    {
        try {
            $this->evaluator->evaluate($template, $context);
            $this->fail("「{$template}」必须报 {$expectedCode}");
        } catch (HydrateException $e) {
            $this->assertSame($expectedCode, $e->getErrorCode());
        }
    }
}
