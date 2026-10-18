<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Compiler\DocumentCompiler;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Exception\FlowException;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 内容流装箱（DocumentCompiler 流链路径）PHP-only 关怀（工票 06 消费翻转）。
 *
 * 流装箱语义用例（spec §10.5 规定 20 例 + fit 保真/mode 枚举/软诊断）已归拢进语料仓
 * corpus/flow-semantics/v1.1/，由 tests/Corpus/FlowCorpusTest.php 数据驱动消费；
 * 本文件只余语料钉不住的语料缺口补锁与对象级往返不变量，清点见 tests/Corpus/README.md。
 *
 * 行高语义：模板行 auto + 格 auto + 文案 auto（lineHeight 默认 1、无 padding、
 * 不开 autowrap）→ 行高定稿 = ceil(字号 × 1) = 字号，一行一个字号像素。
 */
class DocumentCompilerFlowTest extends CanvasTestCase
{
    /**
     * 模板表 fixture：auto 行 + auto 格 + auto 文案，行高定稿 = 字号。
     * $y 表壳顶 y（决定可用区 = 画布高 − y）
     */
    private function templateTable(string $rowsPath = 'order.items', int $fontSize = 20, int $y = 50): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight('auto');

        $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
        $cell->addTemplateContentLayer(
            TextLayer::make(320, 'auto')->setExpression('{{$index}}.{{row.name}}')->setFont('', $fontSize, '#000')
        );
        $row->addCell($cell);

        $table = TableLayer::make(320, 400, '#fff');
        $table->setPosition(0, $y);
        $table->setRowsPath($rowsPath);
        $table->setTemplate($row);

        return $table;
    }

    /** V1 固定行表（非模板态）：链校验负例与链外共存用 */
    private function fixedRowTable(string $rowText = '固定行', int $height = 60, int $y = 50): TableLayer
    {
        $table = TableLayer::make(320, 2 * $height, '#fff');
        $table->setPosition(0, $y);
        foreach ([$rowText . '1', $rowText . '2'] as $text) {
            $cell = TableCellLayer::make(320, $height);
            $cell->addContentLayer(TextLayer::make(320, $height)->setText($text));
            $table->addRow(TableRowLayer::make(320, $height)->addCell($cell));
        }

        return $table;
    }

    /** name 行序列：['name' => '行1'] … */
    private function nameRows(int $count, string $prefix = '行'): array
    {
        return array_map(static fn (int $i) => ['name' => $prefix . $i], range(1, $count));
    }

    /** 数据集：rows 挂到 $rowsPath 点路径下 */
    private function dataset(array $rows, string $rowsPath = 'order.items'): array
    {
        $node = $rows;
        foreach (array_reverse(explode('.', $rowsPath)) as $segment) {
            $node = [$segment => $node];
        }

        return $node;
    }

    /** 画布上各模板表的行文案序列（行归属断言用） */
    private function rowTexts(Canvas $canvas): array
    {
        $texts = [];
        foreach ($canvas->getLayers() as $layer) {
            if (!$layer instanceof TableLayer) {
                continue;
            }
            foreach ($layer->getRows() as $row) {
                $content = $row->getCells()[0]->getContentLayer();
                $texts[] = $content instanceof TextLayer ? $content->getText() : '';
            }
        }

        return $texts;
    }

    /** 负例断言：单 errorCode（code 是三端锚点）+ 异常载体 */
    private function assertFlowError(array $frames, mixed $dataset, array $chain, string $code, string $exceptionClass): void
    {
        try {
            (new DocumentCompiler())->compile($frames, $dataset, $chain);
            $this->fail("预期抛出 {$code}");
        } catch (CanvasNextExceptionInterface $e) {
            $this->assertSame($exceptionClass, get_class($e));
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function testRowsPathCollidesOffChainEvenOnV1Table(): void
    {
        // 语料缺口补锁（语料 rows-path-collides-off-chain 两帧均为模板态）：链外帧若有顶层表，
        // 其 rowsPath 不得与链相同（spec §10.2）不限定模板态——V1 表被 setRowsPath 标注同路径同样拒绝
        $frameA = Canvas::make(320, 450, $this->templateTable('order.items'));
        $v1Table = $this->fixedRowTable();
        $v1Table->setRowsPath('order.items');
        $v1Frame = Canvas::make(320, 450, $v1Table);

        $this->assertFlowError(
            [$frameA, $v1Frame],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            'flow_rows_path_inconsistent',
            FlowException::class
        );
    }

    public function testQuotaOverflowAdjudicatedByPaginate(): void
    {
        // 语料缺口补锁：fixed+quota 超容不钳制（quota 是声明非许可，spec §10.3）：分配期不报错，
        // 多装行由帧内 paginate 溢出语义裁决——25 行 × 20 + 表顶 50 = 550 > 画布 450
        $frameA = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA],
            $this->dataset($this->nameRows(30)),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 25]],
            'content_overflow',
            PaginateException::class
        );
    }

    public function testUnboundDatasetWithFlowKeepsTemplateFrames(): void
    {
        // 语料缺口补锁：dataset null = 未绑恒等直通在流路径原样适用——无实例行可分配，
        // 链上模板帧按声明态零行空壳单页直通（模板表以声明态呈现，不重建不换形态）
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());
        $chain = [['frame' => 0, 'mode' => 'fixed'], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], null, $chain);

        $this->assertCount(2, $result->canvases);
        $this->assertSame($frameA, $result->canvases[0]);
        $this->assertSame($frameB, $result->canvases[1]);
        $this->assertSame([], $result->warnings);
    }

    public function testEveryFlowProductSurvivesGraphRoundtrip(): void
    {
        // pass 边界 wire 可表达（对象级不变量）：流路径产物全部经 fromGraph 重建（回写/分页），
        // 逐页 graph()/fromGraph() 往返字节稳定
        $frameA = Canvas::make(
            320,
            450,
            TextLayer::make(200, 30)->setExpression('共 {{itemsTotal}} 条')->setFont('', 12, '#000'),
            $this->templateTable()
        );
        $frameB = Canvas::make(320, 120, $this->templateTable(y: 20));
        $offChain = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('封底'));
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 4], ['frame' => 1, 'mode' => 'paged']];

        $dataset = array_merge(
            ['itemsTotal' => 5],
            $this->dataset($this->nameRows(11))
        );
        $result = (new DocumentCompiler())->compile([$frameA, $frameB, $offChain], $dataset, $chain);

        $this->assertCount(4, $result->canvases);
        foreach ($result->canvases as $i => $canvas) {
            $this->assertSame($canvas->graph(), Canvas::fromGraph($canvas->graph())->graph(), "产物 {$i} 往返不稳定");
        }

        // 诊断形状不变：Diagnostic 三元组，warnings 不带帧标识
        foreach ($result->warnings as $warning) {
            $this->assertInstanceOf(Diagnostic::class, $warning);
        }
    }
}
