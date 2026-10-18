<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Compiler\CanvasPaginator;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * paginate 步骤（分页）PHP-only 关怀（工票 06 消费翻转）。
 *
 * 分页语义用例已归拢进语料仓 corpus/paginate-semantics/v1.0/，由
 * tests/Corpus/PaginateCorpusTest.php 数据驱动消费；本文件只余语料钉不住的
 * 对象级不变量（别名/零共享/往返）、背景透传与语料缺口补锁，清点见 tests/Corpus/README.md。
 */
class CanvasPaginatorTest extends CanvasTestCase
{
    /** 固定行高的单格文本行（行归属断言以文本区分） */
    private function row(string $text, int $height): TableRowLayer
    {
        $cell = TableCellLayer::make(320, $height);
        $cell->addContentLayer(TextLayer::make(320, $height)->setText($text));

        return TableRowLayer::make(320, $height)->addCell($cell);
    }

    /** 驱动表：y=50、宽 320、声明高 given */
    private function table(int $declaredHeight, TableRowLayer ...$rows): TableLayer
    {
        $table = TableLayer::make(320, $declaredHeight, '#fff');
        $table->setPosition(0, 50);
        foreach ($rows as $row) {
            $table->addRow($row);
        }

        return $table;
    }

    /** 四行标准表（行高 150/50/100/100，Σ=400） */
    private function standardTable(int $declaredHeight = 400): TableLayer
    {
        return $this->table(
            $declaredHeight,
            $this->row('行1', 150),
            $this->row('行2', 50),
            $this->row('行3', 100),
            $this->row('行4', 100),
        );
    }

    /** 页上驱动表的行文本序列（行归属断言用） */
    private function rowTexts(Canvas $page): array
    {
        $texts = [];
        foreach ($page->getLayers() as $layer) {
            if ($layer instanceof TableLayer) {
                foreach ($layer->getRows() as $row) {
                    $texts[] = $this->rowText($row);
                }
            }
        }

        return $texts;
    }

    /** 行首格文本（内容层非文案层时为空串） */
    private function rowText(TableRowLayer $row): string
    {
        $content = $row->getCells()[0]->getContentLayer();

        return $content instanceof TextLayer ? $content->getText() : '';
    }

    public function testPaginationOffIgnoresNonTableLayersAndShellHeight(): void
    {
        // 判定对象只含顶层表行序列：装饰层贴底摆放（y=440，盒子越画布底）、
        // 表壳声明高 500（壳底 550 越界）都不判——语料 passthrough 用例装饰未越底，
        // 「越底也不判」为本文件补锁；直通同实例一并钉死
        $decoration = TextLayer::make(100, 30)->setText('贴底装饰')->setPosition(10, 440);
        $canvas = Canvas::make(320, 450, $this->table(500, $this->row('行1', 400)), $decoration);

        $pages = (new CanvasPaginator())->paginate($canvas);

        $this->assertSame([$canvas], $pages);
    }

    public function testPageGeometryInvalidThrowsAtConstruction(): void
    {
        // 构造期类型/取值门（语料只钉 pageHeight:0 一例）：pageHeight ≤ 0 或非 int 一律报
        foreach ([0, -5, '250', 250.5, true] as $badPageHeight) {
            try {
                new CanvasPaginator(pageHeight: $badPageHeight);
                $this->fail("pageHeight " . var_export($badPageHeight, true) . ' 必须抛 page_geometry_invalid');
            } catch (PaginateException $e) {
                $this->assertSame('page_geometry_invalid', $e->getErrorCode());
            }
        }
    }

    public function testNestedTablesDoNotDrivePagination(): void
    {
        // 嵌套表（表中之表）不驱动分页：外层表是唯一顶层驱动表（其行含内层表整行装箱），
        // 内层表不参与目标判定与装箱——语料缺口补锁
        $innerTable = $this->table(60, $this->row('内行', 60));
        $outerCell = TableCellLayer::make(320, 100);
        $outerCell->addContentLayer($innerTable);
        $outerRow = TableRowLayer::make(320, 100)->addCell($outerCell);
        $outerTable = $this->table(100, $outerRow);

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate(Canvas::make(320, 450, $outerTable));

        $this->assertCount(1, $pages);
        $this->assertSame(250, $pages[0]->getHeight());

        /** @var TableLayer $outerOnPage */
        $outerOnPage = $pages[0]->getLayers()[0];
        $this->assertCount(1, $outerOnPage->getRows());

        // 内层表随所在行原样保留在页上
        $innerOnPage = $outerOnPage->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertInstanceOf(TableLayer::class, $innerOnPage);
        $this->assertSame('内行', $this->rowText($innerOnPage->getRows()[0]));
    }

    public function testPagesShareNoMutableStructure(): void
    {
        // 页间零共享可变结构（对象级不变量，语料 wire 面钉不住）：改一页不串扰其他页与源画布
        $canvas = Canvas::make(320, 450, $this->standardTable());
        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        /** @var TableLayer $page0Table */
        $page0Table = $pages[0]->getLayers()[0];
        $page0Table->setWidth(999);
        /** @var TextLayer $page0RowText */
        $page0RowText = $page0Table->getRows()[0]->getCells()[0]->getContentLayer();
        $page0RowText->setText('被篡改');

        $this->assertSame(320, $pages[1]->getLayers()[0]->getWidth());
        $this->assertSame(['行3', '行4'], $this->rowTexts($pages[1]));
        $this->assertSame(320, $canvas->getLayers()[0]->getWidth());
        $this->assertSame('行1', $this->rowTexts($canvas)[0]);
    }

    public function testEachPageSurvivesGraphRoundtrip(): void
    {
        // pass 边界 wire 可表达：每页过 graph()/fromGraph() 往返无损（spec §2）
        $canvas = Canvas::make(320, 450, $this->standardTable(), TextLayer::make(120, 30)->setText('页眉装饰')->setPosition(10, 5));

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        foreach ($pages as $page) {
            $this->assertSame($page->graph(), Canvas::fromGraph($page->graph())->graph());
        }
    }

    // ------------------------------------------------------------------
    // 画布背景透传（ADR 0022 / spec §5 易漏点 1）：split 手工重组 canvas 键必须显式携带 background
    // （语料 paginate 用例均无背景，本文件补锁）
    // ------------------------------------------------------------------

    public function testSplitCarriesBackgroundToEveryPage(): void
    {
        $canvas = Canvas::make(320, 450, $this->standardTable())
            ->setBackground(new CanvasBackground('#ffffff', 'bg.png'));

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        $this->assertCount(2, $pages);
        foreach ($pages as $page) {
            // 背景随 canvas 字段透传每页，分量与键序原样
            $this->assertSame([
                'width' => 320,
                'height' => 250,
                'background' => ['color' => '#ffffff', 'image' => 'bg.png'],
            ], $page->graph()['canvas']);
        }
    }

    public function testSplitWithoutBackgroundKeepsKeylessCanvasSection(): void
    {
        // 无背景存量分页：canvas 段保持两键形态（条件写键按设计保绿）
        $canvas = Canvas::make(320, 450, $this->standardTable());

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        foreach ($pages as $page) {
            $this->assertSame(['width' => 320, 'height' => 250], $page->graph()['canvas']);
        }
    }
}
