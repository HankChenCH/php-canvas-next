<?php

namespace HankChen\CanvasNext\Text;

use HankChen\CanvasNext\Contracts\TextMeasurerFactoryInterface;
use HankChen\CanvasNext\Contracts\TextMeasurerInterface;

/**
 * 启发式度量器工厂（默认注入点）
 *
 * 启发式估算与具体字体文件无关，只依赖字号
 */
final class HeuristicMeasurerFactory implements TextMeasurerFactoryInterface
{
    public function create(string $fontFile, float $fontSize): TextMeasurerInterface
    {
        return new HeuristicMeasurer($fontSize);
    }
}
