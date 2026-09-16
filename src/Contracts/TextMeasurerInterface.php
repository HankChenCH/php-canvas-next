<?php

namespace HankChen\CanvasNext\Contracts;

/**
 * 文本宽度度量器（已配置好字体/字号等上下文）
 *
 * 度量与断行分离：断行器只关心"这一段放不放得下"，宽度从哪来（启发式估算/
 * 字体度量表/驱动测量）由实现决定
 */
interface TextMeasurerInterface
{
    /**
     * 度量一段文本的渲染宽度（像素）
     */
    public function measure(string $text): float;
}
