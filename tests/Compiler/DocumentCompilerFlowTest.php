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
 * 内容流装箱（spec §10.2–§10.5，ADR 0009，工票 11）：DocumentCompiler 流链路径。
 *
 * 语义源 = spec §10.5 规定用例集 20 例逐条平移（正例断言 canvas graph 序列的
 * 结构事实：帧序×页序、行归属、$index 全局连续、页几何；负例断言单 errorCode——
 * code 是三端锚点，锁 code 字符串本身）；fixture 导出脚本
 * scripts/export-flow-fixtures.php 与本文件同源同数。
 *
 * 行高语义：模板行 auto + 格 auto + 文案 auto（lineHeight 默认 1、无 padding、
 * 不开 autowrap）→ 行高定稿 = ceil(字号 × 1) = 字号，一行一个字号像素。
 */
class DocumentCompilerFlowTest extends CanvasTestCase
{
    // ------------------------------------------------------------------
    // 输入构造 helpers
    // ------------------------------------------------------------------

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

    /** 帧头聚合文案（根键绑定）：跨帧聚合处处正确的观测点 */
    private function aggregateHeader(string $expression = '共 {{itemsTotal}} 条'): TextLayer
    {
        return TextLayer::make(200, 30)->setExpression($expression)->setFont('', 12, '#000');
    }

    /** name 行序列：['name' => '行1'] … */
    private function nameRows(int $count, string $prefix = '行'): array
    {
        return array_map(static fn (int $i) => ['name' => $prefix . $i], range(1, $count));
    }

    /** 数据集：rows 挂到 $rowsPath 点路径下，$extra 为根级附加键 */
    private function dataset(array $rows, string $rowsPath = 'order.items', array $extra = []): array
    {
        $node = $rows;
        foreach (array_reverse(explode('.', $rowsPath)) as $segment) {
            $node = [$segment => $node];
        }

        return array_merge($extra, $node);
    }

    /** 画布上各模板/V1 表的行文案序列（行归属断言用） */
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

    /** 画布顶层文案层文本序列（聚合文案断言用） */
    private function layerTexts(Canvas $canvas): array
    {
        $texts = [];
        foreach ($canvas->getLayers() as $layer) {
            if ($layer instanceof TextLayer) {
                $texts[] = $layer->getText();
            }
        }

        return $texts;
    }

    /** 负例断言：单 errorCode（code 是三端锚点，锁 code 字符串本身）+ 异常载体 */
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

    // ------------------------------------------------------------------
    // 正例 1–9（spec §10.5 用例 1–9）
    // ------------------------------------------------------------------

    public function testCapacitySplitByRowHeight(): void
    {
        // 用例 1 容量切分：行高 auto 定稿 30px、可用区 450−50=400 → 首帧装 13 行
        //（13×30=390 ≤ 400 < 14×30），切分点是装箱产物而非声明；剩余 7 行归 paged 帧一页
        $frameA = Canvas::make(320, 450, $this->templateTable(fontSize: 30));
        $frameB = Canvas::make(320, 450, $this->templateTable(fontSize: 30));
        $chain = [['frame' => 0, 'mode' => 'fixed'], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], $this->dataset($this->nameRows(20)), $chain);

        $this->assertCount(2, $result->canvases);
        $expectedA = array_map(static fn (int $i) => $i . '.行' . $i, range(1, 13));
        $expectedB = array_map(static fn (int $i) => $i . '.行' . $i, range(14, 20));
        $this->assertSame($expectedA, $this->rowTexts($result->canvases[0]));
        $this->assertSame($expectedB, $this->rowTexts($result->canvases[1]));
        $this->assertSame(450, $result->canvases[0]->getHeight());
        $this->assertSame(450, $result->canvases[1]->getHeight());
    }

    public function testQuotaExplicitSplit(): void
    {
        // 用例 2 fixed+quota 显式切分：quota 7 与容量（20 行）无关，退化为声明式切分；
        // 剩余 3 行归 paged 帧
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 7], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], $this->dataset($this->nameRows(10)), $chain);

        $this->assertCount(2, $result->canvases);
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(1, 7)),
            $this->rowTexts($result->canvases[0])
        );
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(8, 10)),
            $this->rowTexts($result->canvases[1])
        );
    }

    public function testQuotaZeroDeclaresNoIntake(): void
    {
        // 用例 3 quota:0 显式不吃行 → 首帧 1 页零行空壳，剩余 3 行全归 paged 帧
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 0], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], $this->dataset($this->nameRows(3)), $chain);

        $this->assertCount(2, $result->canvases);
        $this->assertSame([], $this->rowTexts($result->canvases[0]));
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(1, 3)),
            $this->rowTexts($result->canvases[1])
        );
    }

    public function testOmitIfEmptySkipsFrame(): void
    {
        // 用例 4 omitIfEmpty 触发：分得零行的帧跳帧——不产页、不进产物序列
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());
        $chain = [
            ['frame' => 0, 'mode' => 'fixed', 'quota' => 0, 'omitIfEmpty' => true],
            ['frame' => 1, 'mode' => 'paged'],
        ];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], $this->dataset($this->nameRows(3)), $chain);

        $this->assertCount(1, $result->canvases);
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(1, 3)),
            $this->rowTexts($result->canvases[0])
        );
    }

    public function testPagedTerminalMultiPage(): void
    {
        // 用例 5 paged 链尾：页高 = 帧画布高（120）、帧内多页出图。
        // 首帧 quota 4，剩 7 行按可用区 120−20=100 装箱 [5][2] → 产物 3 页
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 120, $this->templateTable(y: 20));
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 4], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB], $this->dataset($this->nameRows(11)), $chain);

        $this->assertCount(3, $result->canvases);
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(1, 4)),
            $this->rowTexts($result->canvases[0])
        );
        $this->assertSame(450, $result->canvases[0]->getHeight());
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(5, 9)),
            $this->rowTexts($result->canvases[1])
        );
        $this->assertSame(120, $result->canvases[1]->getHeight());
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(10, 11)),
            $this->rowTexts($result->canvases[2])
        );
        $this->assertSame(120, $result->canvases[2]->getHeight());
    }

    public function testGlobalIndexAcrossFrames(): void
    {
        // 用例 6 跨帧 $index 全局连续：全量 hydrate 在前、分配在后——
        // 2+2+1 切分后行文案携带的序号仍是全数据集序号（1..5），不逐帧重计
        $frames = [
            Canvas::make(320, 450, $this->templateTable()),
            Canvas::make(320, 450, $this->templateTable()),
            Canvas::make(320, 450, $this->templateTable()),
        ];
        $chain = [
            ['frame' => 0, 'mode' => 'fixed', 'quota' => 2],
            ['frame' => 1, 'mode' => 'fixed', 'quota' => 2],
            ['frame' => 2, 'mode' => 'paged'],
        ];

        $result = (new DocumentCompiler())->compile($frames, $this->dataset($this->nameRows(5)), $chain);

        $this->assertCount(3, $result->canvases);
        $this->assertSame(['1.行1', '2.行2'], $this->rowTexts($result->canvases[0]));
        $this->assertSame(['3.行3', '4.行4'], $this->rowTexts($result->canvases[1]));
        $this->assertSame(['5.行5'], $this->rowTexts($result->canvases[2]));
    }

    public function testCrossFrameAggregation(): void
    {
        // 用例 7 跨帧聚合：根键绑定「共 n 条」在每帧都取到全数据集聚合值，
        // 不因行被切走而失真（全量 hydrate 的直接后果）
        $frameA = Canvas::make(320, 450, $this->aggregateHeader(), $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->aggregateHeader(), $this->templateTable());
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 2], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile(
            [$frameA, $frameB],
            $this->dataset($this->nameRows(5), extra: ['itemsTotal' => 5]),
            $chain
        );

        $this->assertCount(2, $result->canvases);
        $this->assertSame(['共 5 条'], $this->layerTexts($result->canvases[0]));
        $this->assertSame(['共 5 条'], $this->layerTexts($result->canvases[1]));
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(1, 2)),
            $this->rowTexts($result->canvases[0])
        );
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(3, 5)),
            $this->rowTexts($result->canvases[1])
        );
    }

    public function testNonChainFramesCoexist(): void
    {
        // 用例 8 链外共存：无表帧 + 异 rowsPath 模板帧 + V1 表帧与链帧同文档编译，
        // 产物按帧序扁平拼接；链外模板帧吃自己的 rowsPath、V1 表帧行原样保留
        $cover = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('封面'));
        $chainFrame = Canvas::make(320, 450, $this->templateTable('order.items'));
        $offChainTemplate = Canvas::make(320, 450, $this->templateTable('gift.items'));
        $offChainV1 = Canvas::make(320, 450, $this->fixedRowTable());
        $chain = [['frame' => 1, 'mode' => 'paged']];

        $dataset = $this->dataset($this->nameRows(3), extra: ['gift' => ['items' => $this->nameRows(2, '赠')]]);
        $result = (new DocumentCompiler())->compile([$cover, $chainFrame, $offChainTemplate, $offChainV1], $dataset, $chain);

        $this->assertCount(4, $result->canvases);
        $this->assertSame($cover, $result->canvases[0]); // 无绑定帧恒等直通
        $this->assertSame(
            array_map(static fn (int $i) => $i . '.行' . $i, range(1, 3)),
            $this->rowTexts($result->canvases[1])
        );
        $this->assertSame(['1.赠1', '2.赠2'], $this->rowTexts($result->canvases[2]));
        $this->assertSame(['固定行1', '固定行2'], $this->rowTexts($result->canvases[3]));
    }

    public function testEmptyDatasetMatrix(): void
    {
        // 用例 9 空数据集矩阵（spec §10.3 第 5 条）：全链 0 行 → 每链帧各产 1 页
        // 零行空壳，omitIfEmpty 逐节点可关（帧 2 跳帧）
        $frames = [
            Canvas::make(320, 450, $this->templateTable()),
            Canvas::make(320, 450, $this->templateTable()),
            Canvas::make(320, 450, $this->templateTable()),
        ];
        $chain = [
            ['frame' => 0, 'mode' => 'fixed'],
            ['frame' => 1, 'mode' => 'fixed', 'quota' => 2],
            ['frame' => 2, 'mode' => 'paged', 'omitIfEmpty' => true],
        ];

        $result = (new DocumentCompiler())->compile($frames, $this->dataset([]), $chain);

        $this->assertCount(2, $result->canvases);
        $this->assertSame([], $this->rowTexts($result->canvases[0]));
        $this->assertSame([], $this->rowTexts($result->canvases[1]));
    }

    // ------------------------------------------------------------------
    // 负例 10–20（spec §10.5 用例 10–20，断言单 errorCode）
    // ------------------------------------------------------------------

    public function testChainFrameOutOfRange(): void
    {
        // 用例 10 frame 越界（2 帧链上 frame:2）→ flow_chain_invalid
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA, $frameB],
            $this->dataset($this->nameRows(3)),
            [['frame' => 2, 'mode' => 'paged']],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testChainFrameDuplicated(): void
    {
        // 用例 11 frame 重复 → flow_chain_invalid
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA, $frameB],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'fixed'], ['frame' => 0, 'mode' => 'paged']],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testPagedNotLast(): void
    {
        // 用例 12 paged 非末位 → flow_chain_invalid（paged 是链尾终止符，至多一个）
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA, $frameB],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged'], ['frame' => 1, 'mode' => 'fixed']],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testPagedWithQuota(): void
    {
        // 用例 13 paged 带 quota → flow_chain_invalid（paged 吃尽剩余，无配额位）
        $frameA = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged', 'quota' => 2]],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testUnknownKeyRejected(): void
    {
        // 用例 14 未知键拒绝（封闭数据通道，CompileContext.options 同款）→ flow_chain_invalid
        $frameA = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'fixed', 'pageHeight' => 100]],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testChainFrameWithoutTemplateTable(): void
    {
        // 用例 15 链上帧无模板表：V1 表非模板态 → flow_chain_invalid
        $frameA = Canvas::make(320, 450, $this->fixedRowTable());

        $this->assertFlowError(
            [$frameA],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            'flow_chain_invalid',
            FlowException::class
        );

        // 同规则补例：链上帧连表都没有（规则 3 = 恰有 1 张模板态顶层表）
        $noTable = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('装饰'));
        $frameB = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$noTable, $frameB],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testChainOrderViolated(): void
    {
        // 用例 16 链序乱序回跳（frame 1 → 0）→ flow_chain_invalid
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $frameB = Canvas::make(320, 450, $this->templateTable());

        $this->assertFlowError(
            [$frameA, $frameB],
            $this->dataset($this->nameRows(3)),
            [['frame' => 1, 'mode' => 'fixed'], ['frame' => 0, 'mode' => 'paged']],
            'flow_chain_invalid',
            FlowException::class
        );
    }

    public function testRowsPathMismatchInChain(): void
    {
        // 用例 17 链内 rowsPath 不一致 → flow_rows_path_inconsistent
        $frameA = Canvas::make(320, 450, $this->templateTable('order.items'));
        $frameB = Canvas::make(320, 450, $this->templateTable('gift.items'));

        $this->assertFlowError(
            [$frameA, $frameB],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'fixed'], ['frame' => 1, 'mode' => 'paged']],
            'flow_rows_path_inconsistent',
            FlowException::class
        );
    }

    public function testRowsPathCollidesOffChain(): void
    {
        // 用例 18 链外同 rowsPath：同 rowsPath 的表游离链外 = 分配权威被架空（幽灵行）
        $frameA = Canvas::make(320, 450, $this->templateTable('order.items'));
        $ghost = Canvas::make(320, 450, $this->templateTable('order.items'));

        $this->assertFlowError(
            [$frameA, $ghost],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            'flow_rows_path_inconsistent',
            FlowException::class
        );
    }

    public function testRowsPathCollidesOffChainEvenOnV1Table(): void
    {
        // spec §10.2「链外帧若有顶层表，其 rowsPath 不得与链相同」不限定模板态：
        // V1 表被 setRowsPath 标注同路径同样拒绝
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

    public function testMultiTableInvalid(): void
    {
        // 用例 19 任意帧 ≥2 顶层表 → 既有 paginate_target_invalid 原样抛
        //（载体是分页段 PaginateException，非 FlowException——不换 code 不换段）
        $doubleTable = Canvas::make(320, 450, $this->templateTable(), $this->fixedRowTable());

        $this->assertFlowError(
            [$doubleTable],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            'paginate_target_invalid',
            PaginateException::class
        );

        // 「任意帧」含链外帧：链外帧双表同样入口 fail-fast
        $frameA = Canvas::make(320, 450, $this->templateTable());
        $offChainDouble = Canvas::make(320, 450, $this->templateTable('gift.items'), $this->fixedRowTable());

        $this->assertFlowError(
            [$frameA, $offChainDouble],
            $this->dataset($this->nameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            'paginate_target_invalid',
            PaginateException::class
        );
    }

    public function testFirstRowOversizeThrows(): void
    {
        // 用例 20 容量型首行超容（单行高 30 > 可用区 200−180=20）→ 分配期 fail-fast
        // 抛 paginate 段 content_overflow（同一装箱数学同一失败语义，不换载体不换段）
        $frameA = Canvas::make(320, 200, $this->templateTable(fontSize: 30, y: 180));

        $this->assertFlowError(
            [$frameA],
            $this->dataset($this->nameRows(2)),
            [['frame' => 0, 'mode' => 'fixed']],
            'content_overflow',
            PaginateException::class
        );
    }

    // ------------------------------------------------------------------
    // 附加语义（spec 钉稿语义的 PHP 侧补充锁定，不进 fixture 规定用例集）
    // ------------------------------------------------------------------

    public function testQuotaOverflowAdjudicatedByPaginate(): void
    {
        // fixed+quota 超容不钳制（quota 是声明非许可，spec §10.3）：分配期不报错，
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
        // dataset null = 未绑恒等直通（spec §3）在流路径原样适用：无实例行可分配，
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

    public function testFlowWarningsSharedWithoutFrameIdentity(): void
    {
        // warnings 单一共享 collector、不带帧标识（spec §10.1）：两链帧 × 2 行各自
        // 触发文案兜底，4 条诊断定位路径同为帧内路径
        $templateTable = function (): TableLayer {
            $row = (new TableRowTemplate())->setHeight('auto');
            $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
            $cell->addTemplateContentLayer(
                TextLayer::make(320, 'auto')->setExpression('{{row.missing}}')->setFont('', 20, '#000')
            );
            $row->addCell($cell);
            $table = TableLayer::make(320, 400, '#fff');
            $table->setPosition(0, 50);
            $table->setRowsPath('order.items');
            $table->setTemplate($row);

            return $table;
        };
        $frames = [Canvas::make(320, 450, $templateTable()), Canvas::make(320, 450, $templateTable())];
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 1], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile($frames, $this->dataset($this->nameRows(2)), $chain);

        // warnings 数量 = 帧数 × 全数据集行数（hydrate 先于分配，逐帧全量填充）
        $this->assertCount(4, $result->warnings);
        $paths = array_map(static fn (Diagnostic $d) => $d->path, $result->warnings);
        sort($paths);
        $this->assertSame(
            [
                'layers[0].rows[0].cells[0].content.data.value',
                'layers[0].rows[0].cells[0].content.data.value',
                'layers[0].rows[1].cells[0].content.data.value',
                'layers[0].rows[1].cells[0].content.data.value',
            ],
            $paths
        );
        foreach ($result->warnings as $warning) {
            $this->assertSame('expression_fallback_empty', $warning->code); // 不带帧标识
        }
    }

    public function testEveryFlowProductSurvivesGraphRoundtrip(): void
    {
        // pass 边界 wire 可表达（spec §2）：流路径产物全部经 fromGraph 重建（回写/分页），
        // 逐页 graph()/fromGraph() 往返字节稳定
        $frameA = Canvas::make(320, 450, $this->aggregateHeader(), $this->templateTable());
        $frameB = Canvas::make(320, 120, $this->templateTable(y: 20));
        $offChain = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('封底'));
        $chain = [['frame' => 0, 'mode' => 'fixed', 'quota' => 4], ['frame' => 1, 'mode' => 'paged']];

        $result = (new DocumentCompiler())->compile([$frameA, $frameB, $offChain], $this->dataset($this->nameRows(11)), $chain);

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
