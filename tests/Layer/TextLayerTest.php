<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Contracts\LineBreakerInterface;
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
}
