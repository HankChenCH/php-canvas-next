<?php

namespace HankChen\CanvasNext\Tests\Expression;

use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 默认求值器（受限插值）：mustache 风格外壳 + 变量点路径子集。
 * 上下文形状为契约层钉定（spec §3.2/§3.6）：'row' 键 = 行命名空间、
 * 裸名 = 数据集根键、'$index'/'$root' 保留名
 */
class InterpolationEvaluatorTest extends CanvasTestCase
{
    private InterpolationEvaluator $evaluator;

    /** 行上下文：行命名空间 + 全局根键混合（根键不得与保留名冲突，由填充器校验） */
    private array $rowContext;

    /** 独立图层上下文 = 全局数据集根 */
    private array $rootContext;

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
        $this->rootContext = ['orderNo' => 'A2026-001', 'nested' => ['k' => 'v']];
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
}
