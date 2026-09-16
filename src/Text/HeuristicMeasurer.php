<?php

namespace HankChen\CanvasNext\Text;

use HankChen\CanvasNext\Contracts\TextMeasurerInterface;

/**
 * 字符数启发式度量器：不看字体文件，按字符分类估算宽度
 *
 * 半角（可打印 ASCII）计 0.55 个字宽，其余（CJK/全角/其他）计 1 个字宽，
 * 再乘以字号——与旧库 php-canvas 的 autowrap 计权一致，保证迁移期观感接近
 */
final class HeuristicMeasurer implements TextMeasurerInterface
{
    private const HALF_WIDTH = 0.55;

    public function __construct(private readonly float $fontSize)
    {
    }

    public function measure(string $text): float
    {
        $units = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $char) {
            $units += preg_match('/^[\x{20}-\x{7e}]$/u', $char) === 1
                ? self::HALF_WIDTH
                : 1.0;
        }

        return $units * $this->fontSize;
    }
}
