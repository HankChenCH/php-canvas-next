<?php

namespace HankChen\CanvasNext\Text;

/**
 * 图素簇（grapheme cluster）切分助手
 *
 * 断行与度量的最小单元应是"用户感知字符"：emoji 的 ZWJ 组合序列、组合字符
 * 都算一个单元（UAX #29）。有 ext-intl 时用 grapheme_extract 精确切分；
 * 没有时退化为码点切分（组合序列会被拆开，仅作降级）
 */
final class Graphemes
{
    /**
     * @return string[] 图素簇序列
     */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (function_exists('grapheme_extract')) {
            $chars = [];
            $start = 0;
            $next = 0;
            while (($glyph = grapheme_extract($text, 1, GRAPHEME_EXTR_COUNT, $start, $next)) !== false) {
                $chars[] = $glyph;
                $start = $next;
            }

            return $chars;
        }

        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
