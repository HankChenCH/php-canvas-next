<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class AbstractLayerTest extends CanvasTestCase
{
    /**
     * 基类已是纯数据（无抽象 render），匿名具体类即可测
     */
    private function layer(): AbstractLayer
    {
        return new class extends AbstractLayer {};
    }

    public function testDefaultState(): void
    {
        $layer = $this->layer();

        $this->assertSame(0, $layer->getWidth());
        $this->assertSame(0, $layer->getHeight());
        $this->assertSame('top-left', $layer->getPosition()[0]);
        $this->assertSame(0, $layer->getPriority());
        $this->assertNull($layer->getBackground());
    }

    public function testWidthHeightCastToInt(): void
    {
        $layer = $this->layer()->setWidth('30')->setHeight('40.7');

        $this->assertSame(30, $layer->getWidth());
        $this->assertSame(40, $layer->getHeight());
    }

    public function testAutoFlags(): void
    {
        $layer = $this->layer()->setWidth('auto')->setHeight('auto');

        $this->assertSame(0, $layer->getWidth());
        $this->assertSame(0, $layer->getHeight());
        $graph = $layer->graph();
        $this->assertTrue($graph['spec']['shape']['autoWidth']);
        $this->assertTrue($graph['spec']['shape']['autoHeight']);
    }

    public function testContentSizeSubtractsPadding(): void
    {
        $layer = $this->layer()->setWidth(100)->setHeight(50)->setPadding(10, 20);

        $this->assertSame(60, $layer->getContentWidth());
        $this->assertSame(30, $layer->getContentHeight());
    }

    public function testPaddingCssStyles(): void
    {
        $one = $this->layer()->setPadding(1)->getPadding();
        $this->assertSame(['top' => 1.0, 'bottom' => 1.0, 'left' => 1.0, 'right' => 1.0], $one);

        $two = $this->layer()->setPadding(1, 2)->getPadding();
        $this->assertSame(['top' => 1.0, 'bottom' => 1.0, 'left' => 2.0, 'right' => 2.0], $two);

        $three = $this->layer()->setPadding(1, 2, 3)->getPadding();
        $this->assertSame(['top' => 1.0, 'bottom' => 3.0, 'left' => 2.0, 'right' => 2.0], $three);

        $four = $this->layer()->setPadding(1, 2, 3, 4)->getPadding();
        $this->assertSame(['top' => 1.0, 'right' => 2.0, 'bottom' => 3.0, 'left' => 4.0], $four);
    }

    public function testBorderSetAllAndClearWithZero(): void
    {
        $layer = $this->layer()->setBorder(2, '#f00');

        foreach (['top', 'bottom', 'left', 'right'] as $side) {
            $this->assertSame(['width' => 2, 'color' => '#f00'], $layer->getBorder()[$side]);
        }

        $layer->setBorderTop(0);
        $this->assertNull($layer->getBorder()['top']);
        $this->assertNotNull($layer->getBorder()['left']);
    }

    public function testSetPosition(): void
    {
        $layer = $this->layer()->setPosition(5, 6, 'bottom-right');

        $this->assertSame(['bottom-right', 5, 6], $layer->getPosition());
    }

    public function testSettersAreFluent(): void
    {
        $layer = $this->layer();
        $this->assertSame($layer, $layer->setWidth(1)->setHeight(1)->setBackground('#fff')
            ->setPadding(1)->setBorder(1)->setPosition(0, 0)->setHorizontalAlign('center')
            ->setVerticalAlign('center')->setPriority(3)->setLineHeight(1.5));
        $this->assertSame(3, $layer->getPriority());
    }

    public function testGraphStructure(): void
    {
        $graph = $this->layer()->setWidth(10)->setHeight(20)->setPriority(2)->graph();

        $this->assertSame([
            'type' => 'AbstractLayer',
            'priority' => 2,
            'spec' => [
                'shape' => [
                    'width' => 10,
                    'height' => 20,
                    'autoWidth' => false,
                    'autoHeight' => false,
                    'lineHeight' => 1.0,
                    'padding' => ['top' => 0.0, 'bottom' => 0.0, 'left' => 0.0, 'right' => 0.0],
                    'border' => ['top' => null, 'bottom' => null, 'left' => null, 'right' => null],
                    'backgroundColor' => null,
                ],
                'align' => ['horizontal' => 'left', 'vertical' => 'top'],
                'position' => ['x' => 0, 'y' => 0, 'position' => 'top-left'],
            ],
        ], $graph);
    }

    public function testFromGraphRoundtripBase(): void
    {
        $layer = $this->layer()->setWidth(30)->setHeight(40)
            ->setPadding(1, 2)->setBorder(2, '#123456')
            ->setPosition(3, 4, 'bottom-center')->setPriority(7);

        // 匿名类不能经抽象基类名静态调用，用实例类名解析
        $rebuilt = $layer::fromGraph($layer->graph());

        $this->assertSame($layer->graph(), $rebuilt->graph());
    }

    /**
     * name/visible 契约（layer-panel-ux 工单 01）：缺省态（name 空、visible true）不写键，
     * 缺省图层的 graph 与旧版字节面完全一致
     */
    public function testNameVisibleDefaultOmitsKeys(): void
    {
        $layer = $this->layer();

        $this->assertSame('', $layer->getDisplayName());
        $this->assertTrue($layer->isVisible());

        $graph = $layer->graph();
        $this->assertArrayNotHasKey('name', $graph);
        $this->assertArrayNotHasKey('visible', $graph);
    }

    /** 条件写键：name 仅非空写、visible 仅 false 写；键序钉在 type 之后、priority 之前 */
    public function testNameVisibleConditionalKeysAndOrder(): void
    {
        $nameOnly = $this->layer()->setDisplayName('标题层')->graph();
        $this->assertSame(['type', 'name', 'priority', 'spec'], array_keys($nameOnly));
        $this->assertSame('标题层', $nameOnly['name']);

        $visibleOnly = $this->layer()->setVisible(false)->graph();
        $this->assertSame(['type', 'visible', 'priority', 'spec'], array_keys($visibleOnly));
        $this->assertFalse($visibleOnly['visible']);

        $both = $this->layer()->setDisplayName('页眉')->setVisible(false)->graph();
        $this->assertSame(['type', 'name', 'visible', 'priority', 'spec'], array_keys($both));
    }

    /** 字节面：带键形态的 JSON 逐字节锁定（Go/TS 按同一键序对齐） */
    public function testNameVisibleByteFace(): void
    {
        $layer = $this->layer()->setDisplayName('标题层')->setVisible(false);

        $this->assertSame(
            '{"type":"AbstractLayer","name":"标题层","visible":false,"priority":0,'
            . '"spec":{"shape":{"width":0,"height":0,"autoWidth":false,"autoHeight":false,"lineHeight":1,'
            . '"padding":{"top":0,"bottom":0,"left":0,"right":0},'
            . '"border":{"top":null,"bottom":null,"left":null,"right":null},"backgroundColor":null},'
            . '"align":{"horizontal":"left","vertical":"top"},'
            . '"position":{"x":0,"y":0,"position":"top-left"}}}',
            json_encode($layer->graph(), JSON_UNESCAPED_UNICODE)
        );
    }

    /** decode 读取 name/visible，graph → 重建 → graph 往返恒等 */
    public function testNameVisibleFromGraphRoundtrip(): void
    {
        $layer = $this->layer()->setDisplayName('页眉')->setVisible(false)->setPriority(3);

        $rebuilt = $layer::fromGraph($layer->graph());

        $this->assertSame('页眉', $rebuilt->getDisplayName());
        $this->assertFalse($rebuilt->isVisible());
        $this->assertSame(3, $rebuilt->getPriority());
        $this->assertSame($layer->graph(), $rebuilt->graph());
    }

    /** 第三方写入缺省值（name 空、visible true）→ 重建后归一为缺省，再序列化键省略 */
    public function testNameVisibleNonDefaultWireNormalizedAway(): void
    {
        $graph = ['name' => '', 'visible' => true] + $this->layer()->graph();

        $rebuilt = $this->layer()::fromGraph($graph);

        $this->assertSame('', $rebuilt->getDisplayName());
        $this->assertTrue($rebuilt->isVisible());
        $this->assertArrayNotHasKey('name', $rebuilt->graph());
        $this->assertArrayNotHasKey('visible', $rebuilt->graph());
    }
}
