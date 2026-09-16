<?php

namespace HankChen\CanvasNext\Contracts;

/**
 * 断行器：把文本按盒宽断行为若干行
 */
interface LineBreakerInterface
{
    /**
     * @param string $text 原始文本；显式换行符保留（空段产出空行）
     * @param float $boxWidth 内容盒宽度（像素）
     * @param TextMeasurerInterface $measurer 已配置的宽度度量器
     *
     * @return string[] 断行后的行内容（不含换行符）
     */
    public function breakText(string $text, float $boxWidth, TextMeasurerInterface $measurer): array;
}
