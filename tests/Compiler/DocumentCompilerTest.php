<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\DefaultCanvasCompiler;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Compiler\DocumentCompiler;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 文档级编译入口（spec §10.1，ADR 0009）：N 帧 × 1 dataset 的无流路径
 * （flowChain null / []，流装箱随工票 11 接入）。
 *
 * 语义源 = spec §10 + 工票 10 验收清单：单帧与单画布 compile() 字节等价、
 * 多帧按帧序拼接、warnings 单一共享通道不带帧标识、空 frames 报错；
 * hydrate / paginate 既有错误语义逐帧原样适用；产物过 graph 往返无损
 */
class DocumentCompilerTest extends CanvasTestCase
{
    /**
     * 最小模板表 fixture（rowsPath + auto 行 + auto 格文案模板）：
     * $expression 换文案形状供 fallback 用例同构复用（仅表达式串不同的场景不再各抄一份）
     */
    private function templateTable(string $rowsPath = 'order.items', string $expression = '姓名：{{row.name}}（{{$index}}）'): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight('auto');

        $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $textCell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setExpression($expression)->setFont('', 12, '#000')
        );
        $row->addCell($textCell);

        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath($rowsPath);
        $table->setTemplate($row);

        return $table;
    }

    private function dataset(): array
    {
        return [
            'orderNo' => 'A2026-001',
            'order' => ['items' => [
                ['name' => '张三'],
                ['name' => '李四'],
            ]],
        ];
    }

    /** 文案标记整串落空的模板表（warnings 用）：与 templateTable 同形状，仅表达式换整串落空 */
    private function fallbackTable(): TableLayer
    {
        return $this->templateTable(expression: '{{row.missing}}');
    }

    /** 固定行高 V1 表（行高 Σ = 400，表顶 y=50）：溢出判定用 */
    private function fixedRowTable(): TableLayer
    {
        $table = TableLayer::make(320, 400, '#fff');
        $table->setPosition(0, 50);
        foreach ([150, 50, 100, 100] as $i => $height) {
            $cell = TableCellLayer::make(320, $height);
            $cell->addContentLayer(TextLayer::make(320, $height)->setText('行' . ($i + 1)));
            $table->addRow(TableRowLayer::make(320, $height)->addCell($cell));
        }

        return $table;
    }

    // ------------------------------------------------------------------
    // 单帧等价：同输入产物字节等价（工票 10 验收）
    // ------------------------------------------------------------------

    public function testSingleFrameMatchesSingleCanvasCompileByteForByte(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $document = (new DocumentCompiler())->compile([$canvas], $this->dataset());
        $single = (new DefaultCanvasCompiler())->compile($canvas, $this->dataset());

        $this->assertCount(1, $document->canvases);
        $this->assertSame($single->canvases[0]->graph(), $document->canvases[0]->graph());
        $this->assertSame(
            array_map(static fn (Diagnostic $d) => [$d->code, $d->message, $d->path], $single->warnings),
            array_map(static fn (Diagnostic $d) => [$d->code, $d->message, $d->path], $document->warnings)
        );
    }

    public function testSingleFrameUnboundIsIdentityPassthrough(): void
    {
        // dataset === null：与单画布编译同款恒等直通（同一实例），含模板表的帧也直通
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $result = (new DocumentCompiler())->compile([$canvas], null);

        $this->assertSame([$canvas], $result->canvases);
        $this->assertSame([], $result->warnings);
    }

    public function testExplicitEmptyFlowChainBehavesAsNull(): void
    {
        // 显式 [] 与 null 同义 = 纯文档级管线（spec §10.2）
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $withEmptyChain = (new DocumentCompiler())->compile([$canvas], $this->dataset(), []);
        $withoutChain = (new DocumentCompiler())->compile([$canvas], $this->dataset());

        $this->assertSame($withoutChain->canvases[0]->graph(), $withEmptyChain->canvases[0]->graph());
    }

    // ------------------------------------------------------------------
    // 多帧拼接与共享 warnings（工票 10 验收）
    // ------------------------------------------------------------------

    public function testMultipleFramesConcatenateInFrameOrder(): void
    {
        // 三帧异版式：表达式封面帧 / 模板表清单帧 / 无绑定封底帧，
        // 产物按帧序扁平拼接（无流路径页序恒 1），帧间互不串扰
        $cover = Canvas::make(320, 100, TextLayer::make(200, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000'));
        $list = Canvas::make(320, 400, $this->templateTable());
        $back = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('字面 {{orderNo}} 文案'));

        $result = (new DocumentCompiler())->compile([$cover, $list, $back], $this->dataset());

        $this->assertCount(3, $result->canvases);

        /** @var TextLayer $coverText */
        $coverText = $result->canvases[0]->getLayers()[0];
        $this->assertSame('A2026-001', $coverText->getText());

        /** @var TableLayer $listTable */
        $listTable = $result->canvases[1]->getLayers()[0];
        $this->assertCount(2, $listTable->getRows());
        $this->assertNull($listTable->getTemplate());

        // 无绑定帧在该帧上恒等直通（数据集已绑但此帧无标记无模板）：同一实例原样产出
        $this->assertSame($back, $result->canvases[2]);
    }

    public function testWarningsAreSharedAcrossFramesWithoutFrameIdentity(): void
    {
        // 两帧各自触发文案兜底：单一共享 collector 汇入同一 CompileResult.warnings，
        // 诊断不带帧标识——定位路径同为帧内路径（CompileResult 形状是 renderPages 消费面，spec §10.1）
        $frameA = Canvas::make(320, 400, $this->fallbackTable());
        $frameB = Canvas::make(320, 400, $this->fallbackTable());
        $data = ['order' => ['items' => [['name' => '张三']]]];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], $data);

        $this->assertCount(2, $result->warnings);
        foreach ($result->warnings as $warning) {
            $this->assertSame('expression_fallback_empty', $warning->code);
            $this->assertSame('layers[0].rows[0].cells[0].content.data.value', $warning->path);
        }
    }

    // ------------------------------------------------------------------
    // 入口校验：空 frames 报错；flowChain 非空先拒绝（工票 11 接入）
    // ------------------------------------------------------------------

    public function testEmptyFramesThrowsInvalidArgumentException(): void
    {
        // 空 frames = 调用方编程错误：InvalidArgumentException 级，不立领域 code（spec §10.4）
        $this->expectException(\InvalidArgumentException::class);

        (new DocumentCompiler())->compile([], ['orderNo' => 'A2026-001']);
    }

    public function testNonEmptyFlowChainTakesFlowPath(): void
    {
        // flowChain 非空路径随工票 11 接入（spec §10.3）：单帧 paged 链 = 按帧高分页的
        // 表达位（spec §10.6）。产物行序列与无流编译语义一致（回写重建后字节面含
        // V1 耦合重放的一次性钉稿，故断言结构而非字节——见工票 10 Comments）
        $row = (new TableRowTemplate())->setHeight('auto');
        $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
        $cell->addTemplateContentLayer(
            TextLayer::make(320, 'auto')->setExpression('{{$index}}')->setFont('', 12, '#000')
        );
        $row->addCell($cell);
        $table = TableLayer::make(320, 120, '#fff');
        $table->setRowsPath('items');
        $table->setTemplate($row);
        $canvas = Canvas::make(320, 200, $table);

        $result = (new DocumentCompiler())->compile(
            [$canvas],
            ['items' => [['name' => 'a'], ['name' => 'b']]],
            [['frame' => 0, 'mode' => 'paged']]
        );

        $this->assertCount(1, $result->canvases);
        /** @var TableLayer $productTable */
        $productTable = $result->canvases[0]->getLayers()[0];
        $this->assertCount(2, $productTable->getRows());
        $this->assertNull($productTable->getTemplate());
        $this->assertSame(200, $result->canvases[0]->getHeight()); // paged 页高 = 帧画布高
        $this->assertSame([], $result->warnings);
    }

    // ------------------------------------------------------------------
    // 既有步骤语义逐帧原样适用；产物不变量
    // ------------------------------------------------------------------

    public function testFrameRowOverflowStillThrowsContentOverflow(): void
    {
        // paginate 逐帧 pageHeight=null：单页 + 溢出判定照常（spec §10.6 链外帧规则）
        // 画布 400 高、表顶 y=50、Σ行高 400 → 底边 450 > 400 → content_overflow
        try {
            (new DocumentCompiler())->compile([Canvas::make(320, 400, $this->fixedRowTable())], null);
            $this->fail('帧内行序列越画布底必须抛 content_overflow');
        } catch (PaginateException $e) {
            $this->assertSame('content_overflow', $e->getErrorCode());
        }
    }

    public function testHydrateErrorsPropagateUnchanged(): void
    {
        // 既有 hydrate 错误语义在文档管线无 flow 特例（spec §10.4）：
        // 第二帧 rows_path_invalid 照常抛，无 CompileResult 逃逸
        $cover = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('封面'));
        $bad = Canvas::make(320, 400, $this->templateTable('missing.path'));

        try {
            (new DocumentCompiler())->compile([$cover, $bad], $this->dataset());
            $this->fail('帧内 rows_path_invalid 必须原样抛出');
        } catch (HydrateException $e) {
            $this->assertSame('rows_path_invalid', $e->getErrorCode());
        }
    }

    public function testEveryProductCanvasSurvivesGraphRoundtrip(): void
    {
        // pass 边界 wire 可表达（spec §2）：产物逐个过 graph()/fromGraph() 往返无损。
        // 具体高度帧（hydrate 恒等直通）锁字节级往返；auto 高度模板帧的产物含
        // autoHeight 标记，首次 fromGraph 按 V1 耦合重放把格内文案高度钉成具体值
        // （共享内部件的既有性质，单画布 compile() 产物同款），此后字节稳定——
        // 故对其锁语义往返（行/格/文案结构与数值保真）+ 二次序列化稳定
        $fixed = Canvas::make(320, 450, $this->fixedRowTable());
        $auto = Canvas::make(320, 400, $this->templateTable());
        $back = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('封底'));

        $result = (new DocumentCompiler())->compile([$fixed, $auto, $back], $this->dataset());

        $this->assertCount(3, $result->canvases);
        // 具体高度帧：产物与输入逐字节一致（hydrate/paginate 恒等直通），且往返稳定
        $this->assertSame($fixed->graph(), $result->canvases[0]->graph());
        $this->assertSame($result->canvases[0]->graph(), Canvas::fromGraph($result->canvases[0]->graph())->graph());
        $this->assertSame($back->graph(), $result->canvases[2]->graph());
        $this->assertSame($result->canvases[2]->graph(), Canvas::fromGraph($result->canvases[2]->graph())->graph());

        $autoProduct = $result->canvases[1];
        $redecoded = Canvas::fromGraph($autoProduct->graph());
        $this->assertSame(Canvas::fromGraph($redecoded->graph())->graph(), $redecoded->graph());

        /** @var TableLayer $autoTable */
        $autoTable = $redecoded->getLayers()[0];
        $this->assertCount(2, $autoTable->getRows());
        $this->assertNull($autoTable->getTemplate());
        /** @var TextLayer $rowText */
        $rowText = $autoTable->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('姓名：张三（1）', $rowText->getText());
    }
}
