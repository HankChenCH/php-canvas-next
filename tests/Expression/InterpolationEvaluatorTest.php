<?php

namespace HankChen\CanvasNext\Tests\Expression;

use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 默认求值器（受限插值）PHP-only 关怀（工票 06 消费翻转）。
 *
 * 语义用例已归拢进语料仓 corpus/expression-eval/v2.0/，由
 * tests/Corpus/ExpressionEvalCorpusTest.php 数据驱动消费；本文件只余
 * 语料尚未钉定的文法边角补锁（升格语料走提案通道），清点见 tests/Corpus/README.md。
 */
class InterpolationEvaluatorTest extends CanvasTestCase
{
    public function testMissingCommaInCallIsKeynameNotStructureError(): void
    {
        // 路径实参至 ,/) 止、段内空白属于键名（既有文法逐字节沿用）：缺逗号不构成结构错，
        // `row.d 'YYYY'` 是键名 `d 'YYYY'`（缺失 → 空串，与独立路径片段同语义）——语料缺口，PHP 侧补锁
        $this->assertSame('', (new InterpolationEvaluator())->evaluate("{{date(row.d 'YYYY')}}", ['row' => ['d' => '2026-10-11']]));
    }
}
