<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Renderer\PositionResolver;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class PositionResolverTest extends CanvasTestCase
{
    private const PARENT_W = 100;
    private const PARENT_H = 80;
    private const CHILD_W = 20;
    private const CHILD_H = 10;

    public function testNineAnchors(): void
    {
        $cases = [
            'top-left' => [0, 0],
            'top' => [40, 0],
            'top-right' => [80, 0],
            'left' => [0, 35],
            'center' => [40, 35],
            'right' => [80, 35],
            'bottom-left' => [0, 70],
            'bottom' => [40, 70],
            'bottom-right' => [80, 70],
        ];

        foreach ($cases as $anchor => $expected) {
            $this->assertSame(
                $expected,
                PositionResolver::resolve($anchor, self::PARENT_W, self::PARENT_H, self::CHILD_W, self::CHILD_H),
                "锚点 {$anchor} 偏移不符"
            );
        }
    }

    public function testChildLargerThanParentAllowsNegativeOverflow(): void
    {
        // 与旧库 intervention insert 语义一致：不做钳位，负偏移即溢出摆放
        $this->assertSame(
            [-40, -40],
            PositionResolver::resolve('bottom-right', 10, 10, 50, 50)
        );
    }
}
