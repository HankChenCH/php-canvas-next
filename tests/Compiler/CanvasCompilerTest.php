<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\CanvasCompiler;
use HankChen\CanvasNext\Compiler\CompileContext;
use HankChen\CanvasNext\Compiler\DefaultCanvasCompiler;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 编译器骨架（spec §2）：compile() 为 final 模板方法，钉死顺序 hydrate → paginate。
 * 直通条件矩阵 + 串联行为；hydrate 委托 CanvasHydrator 的深层语义由
 * Hydrate\CanvasHydratorTest 承担，此处只锁编译面契约
 */
class CanvasCompilerTest extends CanvasTestCase
{
    /** 与 CanvasHydratorTest 同构的最小模板表 fixture */
    private function templateTable(): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight('auto');

        $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $textCell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setExpression('姓名：{{row.name}}（{{$index}}）')->setFont('', 12, '#000')
        );

        $row->addCell($textCell);

        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath('order.items');
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

    public function testUnboundDatasetIsIdentityPassthrough(): void
    {
        // 直通条件①：dataset === null（未绑），含模板表的画布也恒等直通（spec §2 表）
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $result = (new DefaultCanvasCompiler())->compile($canvas, null);

        $this->assertSame([$canvas], $result->canvases);
        $this->assertSame([], $result->warnings);
    }

    public function testCanvasWithoutBindingsIsIdentityPassthrough(): void
    {
        // 直通条件②：无标记无模板，已绑数据集也恒等直通（存量文案里的字面 {{ 不误伤）
        $canvas = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('字面 {{orderNo}} 文案'));

        $result = (new DefaultCanvasCompiler())->compile($canvas, ['orderNo' => 'A2026-001']);

        $this->assertSame([$canvas], $result->canvases);
        $this->assertSame([], $result->warnings);
    }

    public function testCompileChainsHydrateIntoSinglePageResult(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $graphBefore = $canvas->graph();

        $result = (new DefaultCanvasCompiler())->compile($canvas, $this->dataset());

        // hydrate 生效：模板表实例化为两行具体行，产物单页直通
        $this->assertCount(1, $result->canvases);
        /** @var TableLayer $table */
        $table = $result->canvases[0]->getLayers()[0];
        $this->assertCount(2, $table->getRows());
        $this->assertNull($table->getTemplate());

        // 编译不回写源图：源仍是声明态
        $this->assertSame($graphBefore, $canvas->graph());
        $this->assertSame([], $result->warnings);
    }

    public function testEvaluatorInjectionReachesHydrateDelegate(): void
    {
        // 注入链（spec §3.2）：compiler 构造器 → hydrate 委托（CanvasHydrator），
        // 用户视角只见 compiler 构造器上的 evaluator 参数
        $evaluator = new class implements ExpressionEvaluatorInterface {
            public function evaluate(string $template, array $context): string
            {
                return 'STUB:' . $template;
            }
        };

        $canvas = Canvas::make(
            320,
            100,
            TextLayer::make(200, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000')
        );

        $result = (new DefaultCanvasCompiler(evaluator: $evaluator))->compile($canvas, ['orderNo' => 'A2026-001']);

        /** @var TextLayer $text */
        $text = $result->canvases[0]->getLayers()[0];
        $this->assertSame('STUB:{{orderNo}}', $text->getText());
    }

    public function testBaseHooksDefaultToNoOpPassthrough(): void
    {
        // 基类默认 hook 全直通：带绑定的画布也原样单页产出
        // （新步骤兼容路径的基底语义：新增 hook 默认直通，默认子类才启用真实步骤）
        $compiler = new class extends CanvasCompiler {};

        $canvas = Canvas::make(320, 400, $this->templateTable());

        $result = $compiler->compile($canvas, $this->dataset());

        $this->assertSame([$canvas], $result->canvases);
    }

    public function testStepOrderIsHydrateThenPaginate(): void
    {
        // 钉死顺序：paginate 收到的必是 hydrate 产物（行已实例化），而非声明态入参。
        // DefaultCanvasCompiler 为 final，此处以与它同构的匿名子类（真实 hydrate）检验模板方法的串联
        $compiler = new class extends CanvasCompiler {
            /** @var list<Canvas> paginate hook 实际收到的画布 */
            public array $paginateInput = [];

            protected function hydrate(CompileContext $context): Canvas
            {
                return (new CanvasHydrator())->hydrate($context->canvas, $context->dataset);
            }

            protected function paginate(Canvas $canvas): array
            {
                $this->paginateInput[] = $canvas;

                return parent::paginate($canvas);
            }
        };

        $canvas = Canvas::make(320, 400, $this->templateTable());
        $result = $compiler->compile($canvas, $this->dataset());

        $this->assertCount(1, $compiler->paginateInput);
        $paginatedIn = $compiler->paginateInput[0];
        $this->assertNotSame($canvas, $paginatedIn);
        $this->assertSame($paginatedIn, $result->canvases[0]);

        /** @var TableLayer $table */
        $table = $paginatedIn->getLayers()[0];
        $this->assertCount(2, $table->getRows());
    }

    public function testHooksReceiveClosedCompileContext(): void
    {
        // 步骤间通信经由封闭的 CompileContext：canvas + dataset + options 三字段定向传递
        $options = ['pageSize' => 'a4'];
        $dataset = ['orderNo' => 'A2026-001'];

        $compiler = new class($options) extends CanvasCompiler {
            public ?CompileContext $seen = null;

            public function __construct(array $paginateOptions)
            {
                parent::__construct(paginateOptions: $paginateOptions);
            }

            protected function hydrate(CompileContext $context): Canvas
            {
                $this->seen = $context;

                return parent::hydrate($context);
            }
        };

        $canvas = Canvas::make(320, 100, TextLayer::make(100, 30)->setText('x'));
        $compiler->compile($canvas, $dataset);

        $this->assertNotNull($compiler->seen);
        $this->assertSame($canvas, $compiler->seen->canvas);
        $this->assertSame($dataset, $compiler->seen->dataset);
        $this->assertSame($options, $compiler->seen->options);
    }

    public function testCompileContractIsPinnedByLanguage(): void
    {
        // 步骤顺序不可被子类重排：compile 为 final；基类 abstract（Compiler 非第三方扩展点）
        $ref = new \ReflectionClass(CanvasCompiler::class);

        $this->assertTrue($ref->isAbstract());
        $this->assertTrue($ref->getMethod('compile')->isFinal());
    }

    public function testDiagnosticShape(): void
    {
        // 软诊断值对象（spec §5.1）：发射面随工票 04 落地，形态先钉定
        $diagnostic = new Diagnostic('expression_fallback_empty', '字段求值落空，兜底空串', 'layers.0');

        $this->assertSame('expression_fallback_empty', $diagnostic->code);
        $this->assertSame('字段求值落空，兜底空串', $diagnostic->message);
        $this->assertSame('layers.0', $diagnostic->path);
        $this->assertNull((new Diagnostic('code', 'message'))->path);
    }

    /** 文案标记整串落空的最小模板表 fixture（工票 04 验收：{{row.missing}} + 已绑数据集） */
    private function fallbackTemplateTable(): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight('auto');

        $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $textCell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setExpression('{{row.missing}}')->setFont('', 12, '#000')
        );
        $row->addCell($textCell);

        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        return $table;
    }

    private function fallbackDataset(): array
    {
        return ['order' => ['items' => [['name' => '张三']]]];
    }

    public function testTextFallbackEmptyEmitsExpressionFallbackWarning(): void
    {
        // 工票 04 / spec §3.3：文案类求值空串兜底（策略性软失败）发射 Diagnostic，
        // 编译仍成功，产物与现状行为字节等价
        $result = (new DefaultCanvasCompiler())
            ->compile(Canvas::make(320, 400, $this->fallbackTemplateTable()), $this->fallbackDataset());

        $this->assertCount(1, $result->canvases);
        /** @var TableLayer $table */
        $table = $result->canvases[0]->getLayers()[0];
        $this->assertCount(1, $table->getRows());

        /** @var TextLayer $text */
        $text = $table->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('', $text->getText());

        // 产物字节等价：整层序列化与字面构造的静态文案层逐键一致（兜底空串落 value，解除标记）
        $this->assertSame(
            TextLayer::make(160, 'auto')->setText('')->setFont('', 12, '#000')->graph(),
            $text->graph()
        );

        // warnings 恰含一条：带表达式原文与定位路径
        $this->assertCount(1, $result->warnings);
        $warning = $result->warnings[0];
        $this->assertInstanceOf(Diagnostic::class, $warning);
        $this->assertSame('expression_fallback_empty', $warning->code);
        $this->assertStringContainsString('{{row.missing}}', $warning->message);
        $this->assertSame('layers[0].rows[0].cells[0].content.data.value', $warning->path);
    }

    public function testResourceEmptyStillFailsFastWithoutResultEscape(): void
    {
        // 资源类缺字段 fail-fast 不变：expression_empty_resource 抛出，无 CompileResult 逃逸
        $row = (new TableRowTemplate())->setHeight('auto');
        $imageCell = (new TableCellLayer())->setWidth(160)->setHeight(40);
        $imageCell->addTemplateContentLayer(ImageLayer::make(160, 'auto')->setExpression('{{row.avatar}}'));
        $row->addCell($imageCell);

        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        try {
            (new DefaultCanvasCompiler())->compile(Canvas::make(320, 400, $table), $this->fallbackDataset());
            $this->fail('资源类空串必须抛 expression_empty_resource');
        } catch (HydrateException $e) {
            $this->assertSame('expression_empty_resource', $e->getErrorCode());
        }
    }

    // ------------------------------------------------------------------
    // paginate hook 接线（工票 06）：paginateOptions 承载页几何与截断选项
    // ------------------------------------------------------------------

    /** 固定行高 V1 表（无绑定，hydrate 恒等直通，分页语义单变量可测）：行高 150+50+100+100 */
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

    public function testPaginateOptionsDrivePageSplittingThroughCompile(): void
    {
        // 分页开：页高 250、表顶 y=50 → 可用区 200，行装箱切两页（spec §4.2.2）
        $result = (new DefaultCanvasCompiler(paginateOptions: ['pageHeight' => 250]))
            ->compile(Canvas::make(320, 450, $this->fixedRowTable()), null);

        $this->assertCount(2, $result->canvases);
        foreach ($result->canvases as $page) {
            $this->assertSame(320, $page->getWidth());
            $this->assertSame(250, $page->getHeight());
        }

        // 页 1 行归属：可用区 200 装 行1(150)+行2(50)，行3 开新页
        /** @var TableLayer $page0Table */
        $page0Table = $result->canvases[0]->getLayers()[0];
        $this->assertCount(2, $page0Table->getRows());
        /** @var TableLayer $page1Table */
        $page1Table = $result->canvases[1]->getLayers()[0];
        $this->assertCount(2, $page1Table->getRows());
    }

    public function testInvalidPageGeometryFailsAtCompilerConstruction(): void
    {
        // page_geometry_invalid 构造期 fail-fast：编译器构造（经 fromOptions 建 Paginator）即抛
        try {
            new DefaultCanvasCompiler(paginateOptions: ['pageHeight' => 0]);
            $this->fail('pageHeight ≤ 0 必须在构造期抛 page_geometry_invalid');
        } catch (PaginateException $e) {
            $this->assertSame('page_geometry_invalid', $e->getErrorCode());
        }
    }
}
