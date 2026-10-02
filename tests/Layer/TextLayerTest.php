<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Contracts\LineBreakerInterface;
use HankChen\CanvasNext\Contracts\TextMeasurerFactoryInterface;
use HankChen\CanvasNext\Contracts\TextMeasurerInterface;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class TextLayerTest extends CanvasTestCase
{
    public function testFixedHeightReturnsDeclaredHeight(): void
    {
        $layer = TextLayer::make(100, 40)->setText('内容');

        $this->assertSame(40, $layer->getHeight());
    }

    public function testAutoHeightSingleLineUsesLineHeight(): void
    {
        // 字号 20、行高 1 → 单行 20px
        $layer = TextLayer::make(100, 'auto')->setText('内容')->setFont('', 20, '#000');

        $this->assertSame(20, $layer->getHeight());
    }

    public function testAutoHeightPaddingOnlyWhenTextEmpty(): void
    {
        $layer = TextLayer::make(100, 'auto')->setPadding(10);

        $this->assertSame(20, $layer->getHeight());
    }

    public function testAutoHeightRespectsLineHeight(): void
    {
        $layer = TextLayer::make(100, 'auto')->setText('内容')
            ->setFont('', 20, '#000')->setLineHeight(1.5);

        $this->assertSame(30, $layer->getHeight());
    }

    public function testAutowrapHeightFollowsBrokenLines(): void
    {
        // 内容盒宽 50，字号 10：每字 10px → '一二三四五六七' 断为 '一二三四五' + '六七'
        $layer = TextLayer::make(50, 'auto')->setText('一二三四五六七')
            ->setFont('', 10, '#000')->setAutowrap(true);

        $this->assertSame(['一二三四五', '六七'], $layer->getLines());
        $this->assertSame(20, $layer->getHeight());
    }

    public function testAutowrapKeepsExplicitNewlines(): void
    {
        $layer = TextLayer::make(500, 'auto')->setText("ab\ncd")->setAutowrap(true);

        $this->assertSame(['ab', 'cd'], $layer->getLines());
    }

    public function testGetLinesWithoutAutowrapReturnsSingleLine(): void
    {
        $layer = TextLayer::make(50, 'auto')->setText('很长很长很长很长很长很长很长很长');

        $this->assertSame(['很长很长很长很长很长很长很长很长'], $layer->getLines());
    }

    public function testLineBreakerInjectable(): void
    {
        $breaker = new class implements LineBreakerInterface {
            public function breakText(string $text, float $boxWidth, TextMeasurerInterface $measurer): array
            {
                return ['x', 'y', 'z'];
            }
        };

        $layer = TextLayer::make(100, 'auto')->setText('任意')->setFont('', 10, '#000')
            ->setAutowrap(true)->setLineBreaker($breaker);

        $this->assertSame(30, $layer->getHeight());
    }

    public function testAutowrapGetterReflectsSetter(): void
    {
        $layer = TextLayer::make(100, 'auto');

        $this->assertFalse($layer->getAutowrap());

        $layer->setAutowrap(true);
        $this->assertTrue($layer->getAutowrap());
    }

    public function testGraphKeepsFullFontValue(): void
    {
        // 无损：font 保留完整原始值（旧库只存 basename）
        $graph = TextLayer::make(10, 10)->setText('文本')
            ->setFont('https://cdn.example.com/fonts/msyh.ttf', 12, '#f00')
            ->graph();

        $this->assertSame('https://cdn.example.com/fonts/msyh.ttf', $graph['spec']['fontFamily']['font']);
        $this->assertSame('文本', $graph['data']['value']);
    }

    public function testFromGraphRoundtrip(): void
    {
        $layer = TextLayer::make(100, 50, '#fff')->setText('正文内容')
            ->setFont('https://cdn.example.com/fonts/msyh.ttf', 14, '#333')
            ->setAutowrap(true)->setAngle(90)->setPadding(2)
            ->setPosition(3, 4)->setPriority(5);

        $rebuilt = TextLayer::fromGraph($layer->graph());

        $this->assertSame($layer->graph(), $rebuilt->graph());
        $this->assertSame('正文内容', $rebuilt->getText());
        $this->assertSame(14, $rebuilt->getFontSize());
    }

    public function testAutoWidthSingleLineNaturalWidth(): void
    {
        // 启发式度量：半角 0.55 字宽 × 字号 → 'abcd' @10 = 4 × 5.5 = 22
        $layer = TextLayer::make('auto', 20)->setText('abcd')->setFont('', 10, '#000');

        $this->assertSame(22, $layer->getWidth());
    }

    public function testAutoWidthTakesMaxSegmentAcrossExplicitNewlines(): void
    {
        // 显式换行拆段取最大段宽：'ab' @10 = 11，'一二三' @10 = 30 → 30
        $layer = TextLayer::make('auto', 'auto')->setText("ab\n一二三")->setFont('', 10, '#000');

        $this->assertSame(30, $layer->getWidth());
    }

    public function testAutoWidthEmptyTextKeepsHorizontalPaddingOnly(): void
    {
        // 空文本 = 0 + 横向 padding（镜像 autoHeight 空文本只剩纵向 padding）
        $layer = TextLayer::make('auto', 'auto')->setPadding(10);

        $this->assertSame(20, $layer->getWidth());
    }

    public function testAutoWidthCeilsFractionalMeasure(): void
    {
        // 浮点段宽 ceil：'abc' @10 = 3 × 0.55 × 10 = 16.5 → 17
        $layer = TextLayer::make('auto', 'auto')->setText('abc')->setFont('', 10, '#000');

        $this->assertSame(17, $layer->getWidth());
    }

    public function testAutoWidthWithAutowrapDegradesToNoWrap(): void
    {
        // autoWidth 优先：内容盒宽即自然宽（70）→ 断行不再切分，组合退化为不折行；
        // autowrap 标志留在结构不动
        $layer = TextLayer::make('auto', 'auto')->setText('一二三四五六七')
            ->setFont('', 10, '#000')->setAutowrap(true);

        $this->assertSame(70, $layer->getWidth());
        $this->assertSame(['一二三四五六七'], $layer->getLines());
        $this->assertTrue($layer->getAutowrap());
        $this->assertTrue($layer->graph()['spec']['shape']['autoWidth']);
    }

    public function testAutoWidthWithAutowrapKeepsExplicitNewlines(): void
    {
        // 三组合（autoWidth + autowrap + 显式换行）：宽度取最大段宽（80），
        // 断行只落在显式换行处、段内不再折行
        $layer = TextLayer::make('auto', 'auto')->setText("一二三四五六七\n一二三四五六七八")
            ->setFont('', 10, '#000')->setAutowrap(true);

        $this->assertSame(80, $layer->getWidth());
        $this->assertSame(['一二三四五六七', '一二三四五六七八'], $layer->getLines());
    }

    public function testAutoWidthPaddingTruncatesSumTowardZero(): void
    {
        // 横向 padding trunc 求和（镜像 autoHeight 的 intval 纵向和）：
        // 1.2 + 2.7 = 5.4 → 5；'一二' @10 = 20 → 25
        $layer = TextLayer::make('auto', 'auto')->setText('一二')
            ->setFont('', 10, '#000')->setPadding(1.2, 2.7);

        $this->assertSame(25, $layer->getWidth());
    }

    public function testAutoWidthNegativePaddingTruncatesTowardZero(): void
    {
        // 负值沿 normZero 惯例（PHP 整数域无 -0，intval 向零截断）：
        // -5 + -5 = -10；'一二' @10 = 20 → 10
        $layer = TextLayer::make('auto', 'auto')->setText('一二')
            ->setFont('', 10, '#000')->setPadding(0, -5);

        $this->assertSame(10, $layer->getWidth());
    }

    public function testAutoWidthGoesThroughMeasurerSeam(): void
    {
        // 宽度求值经层内既有度量缝：注入增强度量器即得增强自然宽
        $measurer = new class implements TextMeasurerInterface {
            public function measure(string $text): float
            {
                return mb_strlen($text) * 100.0;
            }
        };

        $layer = TextLayer::make('auto', 'auto')->setText('ab')
            ->setMeasurerFactory(new class($measurer) implements TextMeasurerFactoryInterface {
                public function __construct(private readonly TextMeasurerInterface $measurer)
                {
                }

                public function create(string $fontFile, float $fontSize): TextMeasurerInterface
                {
                    return $this->measurer;
                }
            });

        $this->assertSame(200, $layer->getWidth());
    }

    public function testAutoWidthGraphRoundtripByteFaceUnchanged(): void
    {
        // wire 字节面零改动：autoWidth flag 照旧无条件写键、width 照旧落 0，往返恒等
        $layer = TextLayer::make('auto', 20)->setText('内容')->setFont('', 14, '#000');

        $graph = $layer->graph();
        $this->assertSame(0, $graph['spec']['shape']['width']);
        $this->assertTrue($graph['spec']['shape']['autoWidth']);

        $rebuilt = TextLayer::fromGraph($graph);
        $this->assertSame($graph, $rebuilt->graph());
    }
}
