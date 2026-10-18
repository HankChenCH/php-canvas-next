<?php

namespace HankChen\CanvasNext\Tests\Support;

/**
 * 坐标记录型测试后端：在 RecordingTestRenderer 之上追加绘制参数留痕，
 * 供核心包断言绘制分派层算出的矩形几何（无像素断言，非像素测试）
 */
class CoordinateRecordingRenderer extends RecordingTestRenderer
{
    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: ?string}> */
    public array $rects = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: int, 4: int}> */
    public array $images = [];

    protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        $this->rects[] = [$x, $y, $width, $height, $bgColor];
        parent::drawRect($x, $y, $width, $height, $bgColor, $border);
    }

    protected function drawImage(string $src, int $x, int $y, int $width, int $height): void
    {
        $this->images[] = [$src, $x, $y, $width, $height];
        parent::drawImage($src, $x, $y, $width, $height);
    }
}
