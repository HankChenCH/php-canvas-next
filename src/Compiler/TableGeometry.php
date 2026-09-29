<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Renderer\PositionResolver;

/**
 * 表几何共享纯函数（spec §10.3）：分页装箱（CanvasPaginator）与流装箱（FrameCapacity /
 * CapacityTake）消费同一套「表顶 / 行高」读取实现，防两处数学漂移。
 * 读取键位与 CanvasPaginator 逐字一致（纯提取，非重写）
 */
final class TableGeometry
{
    /**
     * 表壳顶 y：锚点按给定页/画布尺寸解析 + 声明 y 偏移。
     * 分页侧（页宽 = 源画布宽、页高 = pageHeight）与流侧（fixed 帧单页、
     * paged 帧页高 = 帧画布高）页底基准不同，但锚点解析基准一致——
     * 均与该页渲染期 paint 的锚点解析相同
     *
     * @param array<string, mixed> $tableGraph 表图层 graph（spec.shape / spec.position）
     */
    public static function shellTop(array $tableGraph, int $pageWidth, int $pageHeight): int
    {
        $shape = $tableGraph['spec']['shape'] ?? [];
        $position = $tableGraph['spec']['position'] ?? [];

        [, $anchorY] = PositionResolver::resolve(
            (string) ($position['position'] ?? 'top-left'),
            $pageWidth,
            $pageHeight,
            (int) ($shape['width'] ?? 0),
            (int) ($shape['height'] ?? 0)
        );

        return $anchorY + (int) ($position['y'] ?? 0);
    }

    /**
     * 行 graph 的定稿行高：hydrate 产物行高已定稿（声明态豁免在 hydrate 的
     * fromGraph 耦合重放闭环），与 CanvasPaginator 装箱读取同一键位同一强转
     *
     * @param array<string, mixed> $rowGraph 行图层 graph
     */
    public static function rowHeight(array $rowGraph): int
    {
        return (int) ($rowGraph['spec']['shape']['height'] ?? 0);
    }
}
