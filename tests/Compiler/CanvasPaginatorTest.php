<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\CanvasPaginator;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * paginate 步骤（分页，spec §4.2）：Canvas → Canvas[] 编译期纯函数。
 *
 * 语义源 = spec §4.2.1–4.2.7（工票 05 钉稿）与工票 06 验收清单：
 * 单页直通字节等价、行装箱切分、表壳每页重画、非表图层逐页复制、
 * 溢出两分支（默认报错 / 显式截断）、零行空壳、目标与页几何校验
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

    /** 驱动表：y=50、宽 320、声明高 given，行高 Σ = 400（150+50+100+100） */
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

    // ------------------------------------------------------------------
    // 分页关（默认配置）：单元素直通 + 溢出判定仍执行（spec §4.2.3）
    // ------------------------------------------------------------------

    public function testDefaultConfigIsSingleElementPassthrough(): void
    {
        // 未给页高 = 分页关：单元素直通，页与输入同一实例、graph 字节等价（工票 06 验收）
        $canvas = Canvas::make(320, 450, $this->standardTable());

        $pages = (new CanvasPaginator())->paginate($canvas);

        $this->assertSame([$canvas], $pages);
        $this->assertSame($canvas->graph(), $pages[0]->graph());
    }

    public function testPaginationOffThrowsWhenRowSequenceBeyondCanvasBottom(): void
    {
        // 分页关：判定式 = 表顶 y + Σ行高 > 画布高（页底 = 源画布高，spec §4.2.3）
        // 画布 400 高、表顶 y=50、Σ行高 400 → 底边 450 > 400 → content_overflow
        $canvas = Canvas::make(320, 400, $this->standardTable());

        try {
            (new CanvasPaginator())->paginate($canvas);
            $this->fail('分页关 + 行序列越画布底必须抛 content_overflow');
        } catch (PaginateException $e) {
            $this->assertSame('content_overflow', $e->getErrorCode());
            $this->assertStringContainsString('layers[0]', $e->getMessage());
            $this->assertStringContainsString('450', $e->getMessage());
        }
    }

    public function testPaginationOffIgnoresNonTableLayersAndShellHeight(): void
    {
        // 判定对象只含顶层表行序列：装饰层贴底摆放（y=440，盒子越画布底）、
        // 表壳声明高 500（壳底 550 越界）都不判（spec §4.2.3 判定对象不含），
        // 行序列底边 450 ≤ 450 → 直通
        $decoration = TextLayer::make(100, 30)->setText('贴底装饰')->setPosition(10, 440);
        $canvas = Canvas::make(320, 450, $this->table(500, $this->row('行1', 400)), $decoration);

        $pages = (new CanvasPaginator())->paginate($canvas);

        $this->assertSame([$canvas], $pages);
    }

    // ------------------------------------------------------------------
    // 分页开：行装箱、页几何、表壳重画、非表图层复制（spec §4.2.1/§4.2.2）
    // ------------------------------------------------------------------

    public function testRowPackingSplitsAcrossPagesWithShellRedrawn(): void
    {
        // 页高 250、表顶 y=50 → 单页可用区 200；行高 150+50+100+100：
        // 页1 = [行1,行2]（恰好 200）、页2 = [行3,行4]（恰好 200），行保序不切开
        $canvas = Canvas::make(320, 450, $this->standardTable());
        $tableGraph = $canvas->graph()['layers'][0];

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        $this->assertCount(2, $pages);
        $this->assertSame(['行1', '行2'], $this->rowTexts($pages[0]));
        $this->assertSame(['行3', '行4'], $this->rowTexts($pages[1]));

        foreach ($pages as $page) {
            // 页几何：页宽 = 源画布宽，全页等高 = pageHeight，末页留白不收缩
            $this->assertSame(320, $page->getWidth());
            $this->assertSame(250, $page->getHeight());
        }

        // 表壳每页按声明原样重画：壳 spec/优先级与源逐键一致（行切片之外不变）
        foreach ($pages as $page) {
            $pageTableGraph = $page->graph()['layers'][0];
            $this->assertSame($tableGraph['spec'], $pageTableGraph['spec']);
            $this->assertSame($tableGraph['priority'], $pageTableGraph['priority']);
        }
    }

    public function testNonTableLayersReplicatedAtOriginalCoordinatesOnEveryPage(): void
    {
        // 非驱动图层逐页原样复制（spec §4.2.1 / fixture 用例 non-table-layers-replicated）：
        // 页眉装饰（y=5）与落款（y=420，超出页可视区）都按源坐标出现在每一页，
        // 页界外部分由渲染面位图裁剪自理
        $header = TextLayer::make(120, 30)->setText('页眉装饰')->setPosition(10, 5);
        $footer = TextLayer::make(120, 30)->setText('落款')->setPosition(10, 420);
        $canvas = Canvas::make(320, 450, $this->standardTable(), $header, $footer);
        $headerGraph = $canvas->graph()['layers'][1];
        $footerGraph = $canvas->graph()['layers'][2];

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        $this->assertCount(2, $pages);
        foreach ($pages as $page) {
            $pageGraph = $page->graph();
            $this->assertSame($headerGraph, $pageGraph['layers'][1]);
            $this->assertSame($footerGraph, $pageGraph['layers'][2]);
        }
    }

    public function testExactFillYieldsNoTrailingEmptyPage(): void
    {
        // 恰好填满 k 页 = 恰好 k 页，不产第 k+1 空页（spec §4.2.4）
        $canvas = Canvas::make(320, 450, $this->table(200, $this->row('行1', 100), $this->row('行2', 100)));

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        $this->assertCount(1, $pages);
        $this->assertSame(['行1', '行2'], $this->rowTexts($pages[0]));
    }

    public function testZeroRowsYieldsSingleShellPage(): void
    {
        // 零行驱动表 = 1 页空壳：壳按声明画，行区零高（spec §4.2.4）
        $canvas = Canvas::make(320, 450, $this->standardTable());
        $empty = Canvas::make(320, 450, $this->table(400));

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($empty);

        $this->assertCount(1, $pages);
        $this->assertSame([], $this->rowTexts($pages[0]));
        $this->assertSame(250, $pages[0]->getHeight());

        // 壳与源表壳同声明（行切片为空数组）
        $pageTableGraph = $pages[0]->graph()['layers'][0];
        $this->assertSame($canvas->graph()['layers'][0]['spec'], $pageTableGraph['spec']);
        $this->assertSame([], $pageTableGraph['rows']);
    }

    public function testNoTableWithPageHeightIsPassthrough(): void
    {
        // 顶层表 0 张 + 分页开 = 无内容可分，单页直通不报错（spec §4.2.2）
        $canvas = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('仅装饰'));

        $pages = (new CanvasPaginator(pageHeight: 250))->paginate($canvas);

        $this->assertSame([$canvas], $pages);
    }

    // ------------------------------------------------------------------
    // 溢出两分支：默认报错 / 显式截断（spec §4.2.3）
    // ------------------------------------------------------------------

    public function testOversizeRowThrowsWithoutTruncate(): void
    {
        // 分页开唯一残余溢出 = 单行高 > 单页可用区（300 > 200）→ content_overflow
        $canvas = Canvas::make(320, 450, $this->table(300, $this->row('大行', 300)));

        try {
            (new CanvasPaginator(pageHeight: 250))->paginate($canvas);
            $this->fail('单行超页容量且截断关必须抛 content_overflow');
        } catch (PaginateException $e) {
            $this->assertSame('content_overflow', $e->getErrorCode());
            $this->assertStringContainsString('300', $e->getMessage());
        }
    }

    public function testTruncateIsolatesOversizeRowOnItsOwnPage(): void
    {
        // 截断开：超容行独占一页、不报错，超出部分位图裁剪自理（spec §4.2.3）
        // 行高 100+300+100、可用区 200 → 页1=[行1]、页2=[行2]（独占）、页3=[行3]
        $canvas = Canvas::make(
            320,
            550,
            $this->table(500, $this->row('行1', 100), $this->row('行2', 300), $this->row('行3', 100))
        );

        $pages = (new CanvasPaginator(pageHeight: 250, truncate: true))->paginate($canvas);

        $this->assertCount(3, $pages);
        $this->assertSame(['行1'], $this->rowTexts($pages[0]));
        $this->assertSame(['行2'], $this->rowTexts($pages[1]));
        $this->assertSame(['行3'], $this->rowTexts($pages[2]));
    }

    // ------------------------------------------------------------------
    // 目标与页几何校验（spec §4.2.2/§4.2.4）
    // ------------------------------------------------------------------

    public function testMultipleTopLevelTablesThrowPaginateTargetInvalid(): void
    {
        // 分页开且顶层 TableLayer ≥ 2 张 → paginate_target_invalid（fail-fast）
        $canvas = Canvas::make(320, 450, $this->table(200, $this->row('甲', 100)), $this->table(200, $this->row('乙', 100)));

        try {
            (new CanvasPaginator(pageHeight: 250))->paginate($canvas);
            $this->fail('多张顶层表必须抛 paginate_target_invalid');
        } catch (PaginateException $e) {
            $this->assertSame('paginate_target_invalid', $e->getErrorCode());
        }
    }

    public function testNestedTablesDoNotDrivePagination(): void
    {
        // 嵌套表（表中之表）不驱动分页：外层表是唯一顶层驱动表（其行含内层表整行装箱），
        // 内层表不参与目标判定与装箱（spec §4.2.2）
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

    public function testPageGeometryInvalidThrowsAtConstruction(): void
    {
        // pageHeight ≤ 0 或非 int → page_geometry_invalid（构造期 fail-fast，spec §4.2.4）
        foreach ([0, -5, '250', 250.5, true] as $badPageHeight) {
            try {
                new CanvasPaginator(pageHeight: $badPageHeight);
                $this->fail("pageHeight " . var_export($badPageHeight, true) . ' 必须抛 page_geometry_invalid');
            } catch (PaginateException $e) {
                $this->assertSame('page_geometry_invalid', $e->getErrorCode());
            }
        }
    }

    // ------------------------------------------------------------------
    // 产物不变量：页间零共享可变结构、往返无损（spec §4.2.1/§2）
    // ------------------------------------------------------------------

    public function testPagesShareNoMutableStructure(): void
    {
        // 页间零共享可变结构：改一页的图层不串扰其他页与源画布
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
    // 选项入口：fromOptions（编译器 paginateOptions 的承载形态）
    // ------------------------------------------------------------------

    public function testFromOptionsMapsPageHeightAndTruncate(): void
    {
        $canvas = Canvas::make(320, 550, $this->table(500, $this->row('行1', 100), $this->row('行2', 300), $this->row('行3', 100)));

        $strict = CanvasPaginator::fromOptions(['pageHeight' => 250]);
        try {
            $strict->paginate($canvas);
            $this->fail('fromOptions 默认截断关：超容行必须报错');
        } catch (PaginateException $e) {
            $this->assertSame('content_overflow', $e->getErrorCode());
        }

        $truncating = CanvasPaginator::fromOptions(['pageHeight' => 250, 'truncate' => true]);
        $this->assertCount(3, $truncating->paginate($canvas));

        // 缺省键 = 分页关直通
        $off = CanvasPaginator::fromOptions([]);
        $this->assertSame([$canvas], $off->paginate($canvas));
    }
}
