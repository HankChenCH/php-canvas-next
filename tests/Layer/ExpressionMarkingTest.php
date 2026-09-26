<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 数据表达式载体（spec §3.1）：valueType 门控 + expression 承载 + value 恒镜像原文。
 * 未标记字段保持现状字节面（Text 三键恒写、Image/Qr 两键，expression 条件写键）
 */
class ExpressionMarkingTest extends CanvasTestCase
{
    public function testTextLayerMarkedRoundtrip(): void
    {
        $layer = TextLayer::make(100, 30)->setExpression('姓名：{{row.name}}（{{$index}}）');

        $data = $layer->graph()['data'];
        $this->assertSame('ExpressionValue', $data['valueType']);
        $this->assertSame('姓名：{{row.name}}（{{$index}}）', $data['expression']);
        // value 恒镜像表达式原文（旧端降级可见、审计可读的求值源记录）
        $this->assertSame($data['expression'], $data['value']);

        $rebuilt = TextLayer::fromGraph($layer->graph());
        $this->assertSame($layer->graph(), $rebuilt->graph());
        $this->assertSame('姓名：{{row.name}}（{{$index}}）', $rebuilt->getExpression());
    }

    public function testTextLayerUnmarkedKeepsLegacyBytes(): void
    {
        $data = TextLayer::make(100, 30)->setText('普通文案')->graph()['data'];

        // 现状恒写三键形态，字节面零差异
        $this->assertSame(['valueType' => 'StaticValue', 'expression' => '', 'value' => '普通文案'], $data);
        $this->assertNull(TextLayer::fromGraph(['data' => $data])->getExpression());
    }

    public function testSetTextClearsMarking(): void
    {
        $layer = TextLayer::make(100, 30)->setExpression('{{row.name}}');
        $layer->setText('字面文案');

        $this->assertNull($layer->getExpression());
        $this->assertSame('StaticValue', $layer->graph()['data']['valueType']);
        $this->assertSame('字面文案', $layer->graph()['data']['value']);
    }

    public function testImageLayerMarkedWritesExpressionKeyConditionally(): void
    {
        $marked = ImageLayer::make(60, 60)->setExpression('{{row.avatar}}');
        $data = $marked->graph()['data'];

        $this->assertSame('ExpressionValue', $data['valueType']);
        $this->assertSame('{{row.avatar}}', $data['expression']);
        $this->assertSame('{{row.avatar}}', $data['value']);
        $this->assertSame($marked->graph(), ImageLayer::fromGraph($marked->graph())->graph());

        // 未标记保持现状两键（条件写键，保 Go↔PHP 字节 parity）
        $plain = ImageLayer::make(60, 60)->setImage('https://cdn.example.com/a.png');
        $this->assertSame(
            ['valueType' => 'StaticValue', 'value' => 'https://cdn.example.com/a.png'],
            $plain->graph()['data']
        );
        $this->assertNull(ImageLayer::fromGraph($plain->graph())->getExpression());
    }

    public function testImageSetImageClearsMarking(): void
    {
        $layer = ImageLayer::make(60, 60)->setExpression('{{row.avatar}}');
        $layer->setImage('/tmp/local.png');

        $this->assertNull($layer->getExpression());
        $this->assertSame('/tmp/local.png', $layer->graph()['data']['value']);
    }

    public function testQrCodeLayerMarkedWritesExpressionKeyConditionally(): void
    {
        $marked = QrCodeLayer::make(60, 60)->setExpression('{{row.code}}');
        $data = $marked->graph()['data'];

        $this->assertSame('ExpressionValue', $data['valueType']);
        $this->assertSame('{{row.code}}', $data['expression']);
        $this->assertSame('{{row.code}}', $data['value']);
        $this->assertSame($marked->graph(), QrCodeLayer::fromGraph($marked->graph())->graph());

        $plain = QrCodeLayer::make(60, 60)->setText('https://example.com');
        $this->assertSame(
            ['valueType' => 'StaticValue', 'value' => 'https://example.com'],
            $plain->graph()['data']
        );
    }

    public function testQrCodeSetTextClearsMarking(): void
    {
        $layer = QrCodeLayer::make(60, 60)->setExpression('{{row.code}}');
        $layer->setText('literal-text');

        $this->assertNull($layer->getExpression());
        $this->assertSame('literal-text', $layer->graph()['data']['value']);
    }
}
