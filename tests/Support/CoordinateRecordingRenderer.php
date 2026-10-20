<?php

namespace HankChen\CanvasNext\Tests\Support;

use HankChen\CanvasNext\Contracts\ShapeCapableRenderer;

/**
 * 坐标记录型测试后端：在 RecordingTestRenderer 之上追加绘制参数留痕，
 * 供核心包断言绘制分派层算出的矩形几何（无像素断言，非像素测试）。
 * 实现形状能力口（ADR 0026）并留痕椭圆/掩码 blit 参数——
 * 圆形档分派几何断言走本后端；缺能力降级断言走未实现的 RecordingTestRenderer
 */
class CoordinateRecordingRenderer extends RecordingTestRenderer implements ShapeCapableRenderer
{
    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: ?string}> */
    public array $rects = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}> [src, sx, sy, sw, sh, dx, dy, dw, dh] */
    public array $images = [];

    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: ?string, 5: array{width: int, color: string}|null}> [x, y, w, h, fill, stroke] */
    public array $ellipses = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int}> [src, sx, sy, sw, sh, dx, dy, dw, dh, maskX, maskY, maskW, maskH] */
    public array $maskedImages = [];

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

    public function drawEllipse(int $x, int $y, int $width, int $height, ?string $fillColor, ?array $stroke): void
    {
        $this->ellipses[] = [$x, $y, $width, $height, $fillColor, $stroke];
        $this->records[] = 'ellipse';
    }

    public function drawImageMasked(
        string $src,
        int $sx,
        int $sy,
        int $sw,
        int $sh,
        int $dx,
        int $dy,
        int $dw,
        int $dh,
        int $maskX,
        int $maskY,
        int $maskW,
        int $maskH
    ): void {
        $this->maskedImages[] = [$src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $maskX, $maskY, $maskW, $maskH];
        $this->records[] = 'masked';
    }
}
