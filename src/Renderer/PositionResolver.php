<?php

namespace HankChen\CanvasNext\Renderer;

/**
 * 锚点定位解析：把 top-left/center/bottom-right 等锚点串
 * 换算为子层在父盒内的偏移（纯函数，各渲染端共用）
 */
final class PositionResolver
{
    /**
     * @return array{0: int, 1: int} [x, y]
     */
    public static function resolve(
        string $anchor,
        int $parentWidth,
        int $parentHeight,
        int $childWidth,
        int $childHeight
    ): array {
        $x = match ($anchor) {
            'top-right', 'right', 'bottom-right' => $parentWidth - $childWidth,
            'top', 'center', 'bottom' => intval(($parentWidth - $childWidth) / 2),
            default => 0,
        };

        $y = match ($anchor) {
            'bottom-left', 'bottom', 'bottom-right' => $parentHeight - $childHeight,
            'left', 'center', 'right' => intval(($parentHeight - $childHeight) / 2),
            default => 0,
        };

        return [$x, $y];
    }
}
