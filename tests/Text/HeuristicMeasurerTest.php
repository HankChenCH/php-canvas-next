<?php

namespace HankChen\CanvasNext\Tests\Text;

use HankChen\CanvasNext\Text\HeuristicMeasurer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class HeuristicMeasurerTest extends CanvasTestCase
{
    public function testHalfWidthAsciiCountsAsHalfUnit(): void
    {
        // 3 个半角 × 0.55 × 12px
        $measurer = new HeuristicMeasurer(12);

        $this->assertEqualsWithDelta(0.55 * 3 * 12, $measurer->measure('abc'), 0.001);
    }

    public function testFullWidthCjkCountsAsWholeUnit(): void
    {
        $measurer = new HeuristicMeasurer(12);

        $this->assertEqualsWithDelta(2.0 * 12, $measurer->measure('中文'), 0.001);
    }

    public function testMixedTextSumsPerCharacterClass(): void
    {
        $measurer = new HeuristicMeasurer(10);

        $this->assertEqualsWithDelta((0.55 + 1.0) * 10, $measurer->measure('a中'), 0.001);
    }

    public function testEmptyTextMeasuresZero(): void
    {
        $measurer = new HeuristicMeasurer(12);

        $this->assertEqualsWithDelta(0.0, $measurer->measure(''), 0.001);
    }
}
