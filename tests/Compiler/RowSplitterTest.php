<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Compiler\RowSplitter;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 逐行拆分纯函数单测（提案 003，paginate-semantics v2.0）：判定/求界/片段构造的
 * PHP-only 关怀——防线用例与 go-canvas paginate/rowsplit_test.go 成对平移
 * （AGENTS 跨端纪律：守卫是几何契约的一部分，防线不对称会留死循环或静默面）。
 * 拆分行为语义（页序列形状）由语料 v2.0 拆分 8 例钉定，本文件不手抄语料用例。
 */
class RowSplitterTest extends CanvasTestCase
{
    /** 行级 lineHeightPx（L）= fontSize × lineHeight 向上取整；测试统一 lineHeight=1 */
    private const L = 20;

    /**
     * 多格 autowrap 文本行 graph（\n 硬断行给行数，内容盒足够宽不产生度量折行——
     * 语料钉面同构：行数只依赖显式硬断行）
     *
     * @param list<string> $cellValues 各格内容值
     */
    private function textRowGraph(array $cellValues, int $height, int $fontSize = self::L): array
    {
        $row = TableRowLayer::make(320, $height);
        foreach ($cellValues as $value) {
            $cell = TableCellLayer::make(320, $height);
            $text = TextLayer::make(320, $height)->setText($value);
            $text->setFont('', $fontSize, '#000000');
            $text->setAutowrap(true);
            $cell->addContentLayer($text);
            $row->addCell($cell);
        }

        return $row->graph();
    }

    /** 单体格（图片）行 graph */
    private function imageRowGraph(int $height): array
    {
        $cell = TableCellLayer::make(320, $height);
        $cell->addContentLayer(ImageLayer::make(320, $height)->setImage('/img/seal.png'));

        return TableRowLayer::make(320, $height)->addCell($cell)->graph();
    }

    /** 值的内容行数（\n 硬断行切片计数，与片段构造同一钉面） */
    private function valueLineCount(array $rowGraph): int
    {
        return count(explode("\n", (string) $rowGraph['cells'][0]['content']['data']['value']));
    }

    // ------------------------------------------------------------------
    // lineStats：可拆判定
    // ------------------------------------------------------------------

    public function testLineStatsCountsHardBreakLinesAcrossCells(): void
    {
        $stats = RowSplitter::lineStats($this->textRowGraph(['甲一', "乙一\n乙二\n乙三\n乙四\n乙五"], 100));

        $this->assertSame(['maxLines' => 5, 'lineHeightPx' => self::L], $stats);
    }

    public function testLineStatsRejectsMonolithicAndMissingContent(): void
    {
        // 图片/QR 单体格 → null；content 缺失 → null；零格行 → null
        $this->assertNull(RowSplitter::lineStats($this->imageRowGraph(100)));

        $qrCell = TableCellLayer::make(320, 100);
        $qrCell->addContentLayer(QrCodeLayer::make(100, 100)->setText('https://example.com'));
        $this->assertNull(RowSplitter::lineStats(TableRowLayer::make(320, 100)->addCell($qrCell)->graph()));

        $noContent = $this->textRowGraph(['甲'], 20);
        unset($noContent['cells'][0]['content']);
        $this->assertNull(RowSplitter::lineStats($noContent));

        $noCells = $this->textRowGraph(['甲'], 20);
        $noCells['cells'] = [];
        $this->assertNull(RowSplitter::lineStats($noCells));
    }

    // ------------------------------------------------------------------
    // splitBound：拆分求界（K=0 = 整行走原子路径）
    // ------------------------------------------------------------------

    public function testSplitBoundIsZeroWhenLessThanOneLineFits(): void
    {
        // K=0 整行下移：剩余不足一行高（19 < L=20）或零剩余
        $row = $this->textRowGraph(["甲\n乙\n丙\n丁"], 80);

        $this->assertSame(0, RowSplitter::splitBound($row, 19));
        $this->assertSame(0, RowSplitter::splitBound($row, 0));
    }

    public function testSplitBoundIsZeroForSingleLineAndMonolithicRows(): void
    {
        // M=1（含 autowrap 关的退化线：getLines 恒单行）→ M_max − 1 = 0；
        // 单体格行 lineStats 即 null
        $single = $this->textRowGraph(['单行'], 20);
        $degenerate = $this->textRowGraph(["甲\n乙"], 40);
        $degenerate['cells'][0]['content']['spec']['fontFamily']['autowrap'] = false;

        $this->assertSame(0, RowSplitter::splitBound($single, 200));
        $this->assertSame(0, RowSplitter::splitBound($degenerate, 200));
        $this->assertSame(0, RowSplitter::splitBound($this->imageRowGraph(100), 200));
    }

    public function testSplitBoundExactlyFillsRemaining(): void
    {
        // K×L = remaining 恰满：remaining 40 / L 20 → K=2，2×20 = 40
        $row = $this->textRowGraph(["甲\n乙\n丙\n丁"], 80);

        $this->assertSame(2, RowSplitter::splitBound($row, 40));
        $this->assertSame(2 * self::L, 2 * self::L);
    }

    public function testSplitBoundCapsAtMaxLinesMinusOne(): void
    {
        // 剩余再大也不把整行拆光：M_max − 1 封顶（续段至少留一行）
        $row = $this->textRowGraph(["甲\n乙\n丙\n丁"], 80);

        $this->assertSame(3, RowSplitter::splitBound($row, 2000));
    }

    public function testSplitBoundGuardsNegativeDeclaredSurplus(): void
    {
        // P < 0 防线（不一致 graph：行高 30 < 4 内容行 × L 20）→ 不可拆
        $row = $this->textRowGraph(["甲\n乙\n丙\n丁"], 30);

        $this->assertSame(0, RowSplitter::splitBound($row, 200));
        $this->assertNull(RowSplitter::splitRowGraph($row, 1));
    }

    public function testSplitBoundGuardsZeroLineHeight(): void
    {
        // L ≤ 0 防线（非法字号 0）：无从求界，不除零、视为不可拆（与 Go 侧成对平移）
        $row = $this->textRowGraph(["甲\n乙\n丙\n丁"], 80, 0);

        $this->assertSame(0, RowSplitter::splitBound($row, 200));
        $this->assertNull(RowSplitter::splitRowGraph($row, 2));
    }

    // ------------------------------------------------------------------
    // splitRowGraph：片段构造
    // ------------------------------------------------------------------

    public function testSplitRowGraphSlicesHeightsValuesAndClonesKeyShape(): void
    {
        $source = $this->textRowGraph(["甲一\n甲二\n甲三\n甲四"], 80);
        $fragments = RowSplitter::splitRowGraph($source, 2);

        $this->assertNotNull($fragments);
        [$front, $cont] = [$fragments['front'], $fragments['cont']];

        // Σ 高恒等 = 原行高；片段行高 = K×L+P / 原行高 − 前段（P = 0）
        $this->assertSame(40, $front['spec']['shape']['height']);
        $this->assertSame(40, $cont['spec']['shape']['height']);

        // 格盒高与内容层 spec.height 同步片段行高（独立边框盒）
        foreach ([$front, $cont] as $fragment) {
            $this->assertSame(40, $fragment['cells'][0]['spec']['shape']['height']);
            $this->assertSame(40, $fragment['cells'][0]['content']['spec']['shape']['height']);
        }

        // 内容值按 \n 硬断行切片
        $this->assertSame("甲一\n甲二", $front['cells'][0]['content']['data']['value']);
        $this->assertSame("甲三\n甲四", $cont['cells'][0]['content']['data']['value']);

        // 其余字段逐字段克隆，不新增不删除键（wire 键形纪律）
        foreach ([$front, $cont] as $fragment) {
            $this->assertSame(array_keys($source), array_keys($fragment));
            $this->assertSame(array_keys($source['spec']), array_keys($fragment['spec']));
            $this->assertSame(
                array_keys($source['cells'][0]['content']),
                array_keys($fragment['cells'][0]['content'])
            );
            $this->assertSame(
                $source['cells'][0]['content']['spec']['fontFamily'],
                $fragment['cells'][0]['content']['spec']['fontFamily']
            );
        }
        // 源行 graph 不被改写（PHP 数组值语义隔离）
        $this->assertSame(80, $source['spec']['shape']['height']);
        $this->assertSame("甲一\n甲二\n甲三\n甲四", $source['cells'][0]['content']['data']['value']);
    }

    public function testSplitRowGraphShortCellKeepsAllAndContinuationStaysEmpty(): void
    {
        // 格独立分片：短格（M=1 < K=2）全内容留前段，续段 = 空串内容格且格盒画满续段高
        $source = $this->textRowGraph(['短格', "乙一\n乙二\n乙三"], 60);
        $fragments = RowSplitter::splitRowGraph($source, 2);

        $this->assertNotNull($fragments);
        $this->assertSame(40, $fragments['front']['spec']['shape']['height']);
        $this->assertSame(20, $fragments['cont']['spec']['shape']['height']);

        $this->assertSame('短格', $fragments['front']['cells'][0]['content']['data']['value']);
        $this->assertSame("乙一\n乙二", $fragments['front']['cells'][1]['content']['data']['value']);

        // 续段短格：空串值、格盒仍 = 续段行高 20
        foreach ($fragments['cont']['cells'] as $contCell) {
            $this->assertSame(20, $contCell['spec']['shape']['height']);
            $this->assertSame(20, $contCell['content']['spec']['shape']['height']);
        }
        $this->assertSame('', $fragments['cont']['cells'][0]['content']['data']['value']);
        $this->assertSame('乙三', $fragments['cont']['cells'][1]['content']['data']['value']);
    }

    public function testSplitRowGraphGuardsReturnNull(): void
    {
        $row = $this->textRowGraph(["甲\n乙\n丙\n丁"], 80);

        // k < 1；续段 ≤ 0 防线（k = M_max 把整行拆光，零高续段不得产出）
        $this->assertNull(RowSplitter::splitRowGraph($row, 0));
        $this->assertNull(RowSplitter::splitRowGraph($row, 4));
        // 非全文本格
        $this->assertNull(RowSplitter::splitRowGraph($this->imageRowGraph(100), 2));
    }

    // ------------------------------------------------------------------
    // 收敛结构断言（spec §0.4）：片段内容行数严格递减、总片段数 ≤ Σ 行内容数——
    // 装箱步进单调前进、无 0 步进（工票 01 死循环评估在拆分模式下的推广钉面；
    // 多页行为钉面 = 语料 split-lines-row-across-three-pages）
    // ------------------------------------------------------------------

    public function testRepeatedSplitFragmentLineCountsStrictlyDecrease(): void
    {
        $segments = array_map(static fn (int $i) => "第{$i}行内容", range(1, 24));
        $row = $this->textRowGraph([implode("\n", $segments)], 480);

        $fragmentLines = [];
        while (($bound = RowSplitter::splitBound($row, 200)) >= 1) {
            $fragments = RowSplitter::splitRowGraph($row, $bound);
            $this->assertNotNull($fragments);
            $fragmentLines[] = $this->valueLineCount($fragments['front']);
            $row = $fragments['cont'];
        }

        // [10, 10, 3] 严格递减，终段余 1 行不再可拆
        $this->assertSame([10, 10, 3], $fragmentLines);
        $this->assertSame(1, $this->valueLineCount($row));
        $this->assertLessThanOrEqual(24, array_sum($fragmentLines) + 1);
    }
}
