<?php

namespace HankChen\CanvasNext\Tests\Hydrate;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Compiler\DiagnosticCollector;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * hydrate 步骤（数据填充）PHP-only 关怀（工票 06 消费翻转）。
 *
 * 填充语义用例已归拢进语料仓 corpus/expand-semantics/v1.1/，由
 * tests/Corpus/ExpandCorpusTest.php 数据驱动消费；本文件只余语料钉不住的
 * 对象级不变量与语料缺口补锁，清点见 tests/Corpus/README.md。
 */
class CanvasHydratorTest extends CanvasTestCase
{
    private CanvasHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new CanvasHydrator();
    }

    private function templateTable(): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight('auto');

        $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $textCell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setExpression('姓名：{{row.name}}（{{$index}}）')->setFont('', 12, '#000')
        );
        $imageCell = (new TableCellLayer())->setWidth(160)->setHeight(40);
        $imageCell->addTemplateContentLayer(ImageLayer::make(160, 'auto')->setExpression('{{row.avatar}}'));

        $row->addCell($textCell)->addCell($imageCell);

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
                ['name' => '张三', 'avatar' => '/tmp/a.png'],
                ['name' => '李四', 'avatar' => '/tmp/b.png'],
            ]],
        ];
    }

    public function testHydrateDoesNotPolluteSourceCanvas(): void
    {
        // 填充产物与源图分离（对象级不变量，语料 wire 面钉不住）：源仍是声明态
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $graphBefore = $canvas->graph();

        $this->hydrator->hydrate($canvas, $this->dataset());

        $this->assertSame($graphBefore, $canvas->graph());
        $this->assertInstanceOf(TableRowTemplate::class, $canvas->getLayers()[0]->getTemplate());
    }

    public function testFitPassesThroughTemplateInstantiation(): void
    {
        // 语料缺口补锁：模板表实例化路径（expand 语义同门）——格内容层 fit 随行实例原样透传
        $cell = (new TableCellLayer())->setWidth(160)->setHeight(40);
        $cell->addTemplateContentLayer(
            ImageLayer::make(160, 40)->setExpression('{{row.avatar}}')->setFit(ImageLayer::FIT_CONTAIN)
        );
        $row = (new TableRowTemplate())->setHeight(40);
        $row->addCell($cell);
        $table = TableLayer::make(320, 100, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        $hydrated = $this->hydrator->hydrate(Canvas::make(320, 100, $table), $this->dataset());

        $rows = $hydrated->getLayers()[0]->getRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $rowNode) {
            /** @var ImageLayer $image */
            $image = $rowNode->getCells()[0]->getContentLayer();
            $this->assertSame('contain', $image->getFit());
        }
        $this->assertSame('contain', $hydrated->graph()['layers'][0]['rows'][0]['cells'][0]['content']['spec']['fit']);
    }

    /** @return array<string, array{string}> 语料缺口的三类违例形态（多片段/尾缀/函数调用/空片段已入语料） */
    public static function resourceViolationProvider(): array
    {
        return [
            '片段外字面（前缀）' => ['https://cdn/{{orderNo}}'],
            '纯空白片段' => ['{{  }}'],
            '未闭合片段' => ['{{orderNo'],
        ];
    }

    /**
     * @dataProvider resourceViolationProvider
     */
    public function testResourceExpressionViolationFailsFast(string $expression): void
    {
        $canvas = Canvas::make(320, 100, ImageLayer::make(60, 60)->setExpression($expression));

        try {
            $this->hydrator->hydrate($canvas, $this->dataset());
            $this->fail("资源表达式「{$expression}」违反单值裸路径必须报错");
        } catch (HydrateException $e) {
            $this->assertSame('expression_resource_single_ref', $e->getErrorCode());
        }
    }

    public function testQrCodeViolationFailsFast(): void
    {
        // 语料缺口补锁：二维码静默错编是该门的首要防线（渲染零报错、扫码才暴露的实测缺陷）
        $canvas = Canvas::make(320, 100, (new QrCodeLayer())->setWidth(60)->setHeight(60)->setExpression('{{orderNo}}-qr'));

        try {
            $this->hydrator->hydrate($canvas, $this->dataset());
            $this->fail('二维码违例表达式必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_resource_single_ref', $e->getErrorCode());
        }
    }

    public function testUnmarkedResourceLiteralPassthroughUnchanged(): void
    {
        // 未标记字面直通不动：含 {{ 的字面 src 在其他图层有绑定时也不被求值、不被校验
        $canvas = Canvas::make(
            320,
            100,
            ImageLayer::make(60, 60)->setImage('https://cdn/{{orderNo}}.png'),
            TextLayer::make(200, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000')
        );

        $hydrated = $this->hydrator->hydrate($canvas, ['orderNo' => 'A2026-001']);

        /** @var ImageLayer $image */
        $image = $hydrated->getLayers()[0];
        $this->assertSame('https://cdn/{{orderNo}}.png', $image->getImage());
    }

    public function testBackgroundPassedThroughUntouchedWhileLayersAreHydrated(): void
    {
        // 语料缺口补锁（语料 background 用例值为普通串）：背景含 mustache 形态也原样透传
        // （数据驱动底图走图层级，两形态并存合法——同形引用在层上被求值、在背景上保持字面）
        $canvas = Canvas::make(
            320,
            100,
            ImageLayer::make(320, 40)->setExpression('{{order.avatar}}'),
        )->setBackground(new CanvasBackground('{{order.color}}', '{{order.avatar}}'));

        $hydrated = $this->hydrator->hydrate($canvas, ['order' => ['avatar' => '/tmp/a.png', 'color' => '#ff0000']]);

        $this->assertSame('/tmp/a.png', $hydrated->getLayers()[0]->getImage());
        $this->assertSame(['color' => '{{order.color}}', 'image' => '{{order.avatar}}'], $hydrated->graph()['canvas']['background']);
        $this->assertSame($canvas->graph()['canvas']['background'], $hydrated->graph()['canvas']['background']);
    }

    public function testFallbackDiagnosticMessageCarriesExpressionText(): void
    {
        // 消息人读不入语料断言面，PHP 侧自钉：诊断 message 携带表达式原文与定位路径
        $row = (new TableRowTemplate())->setHeight('auto');
        $cell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $cell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setExpression('{{row.missing}}')->setFont('', 12, '#000')
        );
        $row->addCell($cell);
        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        $collector = new DiagnosticCollector();
        $this->hydrator->hydrate(Canvas::make(320, 400, $table), ['order' => ['items' => [['name' => '张三']]]], $collector);

        $warnings = $collector->all();
        $this->assertCount(1, $warnings);
        $this->assertSame('expression_fallback_empty', $warnings[0]->code);
        $this->assertStringContainsString('{{row.missing}}', $warnings[0]->message);
        $this->assertSame('layers[0].rows[0].cells[0].content.data.value', $warnings[0]->path);
    }
}
