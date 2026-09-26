<?php

namespace HankChen\CanvasNext\Tests\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\CanvasCompiler;
use HankChen\CanvasNext\Compiler\CompileContext;
use HankChen\CanvasNext\Compiler\DefaultCanvasCompiler;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
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
}
