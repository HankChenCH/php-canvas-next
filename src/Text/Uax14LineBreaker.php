<?php

namespace HankChen\CanvasNext\Text;

use HankChen\CanvasNext\Contracts\LineBreakerInterface;
use HankChen\CanvasNext\Contracts\TextMeasurerInterface;

/**
 * UAX #14 简化版断行器（默认实现）
 *
 * - 以图素簇（grapheme cluster，PCRE \X）为最小单元，emoji 等组合序列不被拆碎；
 * - CJK 字间皆可断；拉丁文本优先在空格/连字符处断行（词边界优先，装不下再逐字硬断）；
 * - 禁则处理（kinsoku）：行首不出现收尾类标点（上移到上一行，允许轻微溢出），
 *   行末不出现起始类标点（下移到下一行行首）；
 * - 断点处剔除行尾与行首的空白。
 *
 * 完整的 UAX #14 规则表与 Knuth–Plass 全局最优断行不在本实现范围内，
 * 需要时可通过 LineBreakerInterface 注入更强实现
 */
final class Uax14LineBreaker implements LineBreakerInterface
{
    /** 行首禁则：收尾类标点不能出现在行首 */
    private const LINE_START_FORBIDDEN = [
        '，', '。', '、', '；', '：', '！', '？',
        '」', '』', '）', '】', '》', '〉', '…', '—', '～', '·',
        '!', '?', '%', ',', '.', ';', ':', ')', ']', '}',
    ];

    /** 行末禁则：起始类标点不能出现在行末 */
    private const LINE_END_FORBIDDEN = [
        '「', '『', '（', '【', '《', '〈', '“', '‘',
        '(', '[', '{',
    ];

    public function breakText(string $text, float $boxWidth, TextMeasurerInterface $measurer): array
    {
        if ($text === '') {
            return [];
        }

        $lines = [];
        foreach (explode("\n", $text) as $segment) {
            $chars = Graphemes::split($segment);
            if ($chars === []) {
                // 空段保留为空行，尊重显式换行的版式意图
                $lines[] = '';
                continue;
            }

            $current = '';
            foreach ($chars as $char) {
                // 行首第一个字符无条件收录，保证断行过程必然前进
                if ($current === '' || $this->fits($current . $char, $boxWidth, $measurer)) {
                    $current .= $char;
                    continue;
                }

                [$line, $rest] = $this->resolveBreak($current, $char);
                if ($line !== '') {
                    $lines[] = $line;
                }
                $current = $rest;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }

    /**
     * 在 current 末尾放不下 char 时确定断点，返回 [上一行, 余下内容]
     */
    private function resolveBreak(string $current, string $char): array
    {
        $line = $current;
        $rest = $char;

        $breakAt = $this->findWordBoundary($current);
        if ($breakAt !== null) {
            $boundaryChar = mb_substr($current, $breakAt, 1);
            // 空格断点弃在行尾，连字符断点保留在行尾
            $head = mb_substr($current, 0, $breakAt + ($boundaryChar === '-' ? 1 : 0));
            $tail = mb_substr($current, $breakAt + 1);
            if ($head !== '') {
                $line = $head;
                $rest = $tail . $char;
            }
        }

        $line = rtrim($line, " \t");
        $rest = ltrim($rest, " \t");

        return $this->applyKinsoku($line, $rest);
    }

    /**
     * 返回词边界断点（空格/连字符所在下标），没有则 null
     */
    private function findWordBoundary(string $line): ?int
    {
        $length = mb_strlen($line);
        for ($i = $length - 1; $i > 0; $i--) {
            $char = mb_substr($line, $i, 1);
            if ($char === ' ' || $char === '-') {
                return $i;
            }
        }

        return null;
    }

    /**
     * 禁则修正：收尾类标点上移到上一行行尾，起始类标点下移到下一行行首
     *
     * @return string[] [修正后的行, 修正后的余下内容]
     */
    private function applyKinsoku(string $line, string $rest): array
    {
        while ($rest !== '' && $this->inStartForbidden(mb_substr($rest, 0, 1))) {
            $line .= mb_substr($rest, 0, 1);
            $rest = mb_substr($rest, 1);
        }

        while ($line !== '' && $this->inEndForbidden(mb_substr($line, -1))) {
            $rest = mb_substr($line, -1) . $rest;
            $line = mb_substr($line, 0, -1);
        }

        return [$line, $rest];
    }

    private function fits(string $text, float $boxWidth, TextMeasurerInterface $measurer): bool
    {
        return $measurer->measure($text) <= $boxWidth;
    }

    private function inStartForbidden(string $char): bool
    {
        return in_array($char, self::LINE_START_FORBIDDEN, true);
    }

    private function inEndForbidden(string $char): bool
    {
        return in_array($char, self::LINE_END_FORBIDDEN, true);
    }
}
