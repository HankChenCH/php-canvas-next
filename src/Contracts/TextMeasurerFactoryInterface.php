<?php

namespace HankChen\CanvasNext\Contracts;

/**
 * 度量器工厂（策略注入点）
 *
 * 图层字号各不相同，注入工厂而非度量器实例，保证所有图层按自身字体/字号
 * 得到同一种度量策略（例如统一切换为字体度量表实现）
 */
interface TextMeasurerFactoryInterface
{
    /**
     * 按字体文件与字号创建配置好的度量器
     */
    public function create(string $fontFile, float $fontSize): TextMeasurerInterface;
}
