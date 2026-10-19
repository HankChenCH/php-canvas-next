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

    /** @var list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}> [src, sx, sy, sw, sh, dx, dy, dw, dh] */
    public array $images = [];

    protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        $this->rects[] = [$x, $y, $width, $height, $bgColor];
        parent::drawRect($x, $y, $width, $height, $bgColor, $border);
    }

    protected function drawImage(
        string $src,
        int $sx,
        int $sy,
        int $sw,
        int $sh,
        int $dx,
        int $dy,
        int $dw,
        int $dh
    ): void {
        $this->images[] = [$src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh];
        parent::drawImage($src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh);
    }
}
