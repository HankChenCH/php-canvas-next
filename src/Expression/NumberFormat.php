<?php

namespace HankChen\CanvasNext\Expression;

/**
 * 数字族格式化：十进制字符串语义（spec §4）——舍入定义在值的最短往返十进制表示上，
 * 不把二进制浮点喂给原生 round（2.675 → 2.68 而非 2.67）；half-up 朝 +∞
 * （正数常规四舍五入，tie 时负数朝零）。全部运算在十进制字符串上进行：
 * 乘 100 = 小数点右移，不引入二进制误差。零渲染依赖
 */
final class NumberFormat
{
    /** n 参数上界（0–8，spec §2；语义层校验在求值器，超界 fail-fast） */
    public const N_MAX = 8;

    /**
     * 数值 → 最短往返十进制串（常规区间，spec §4.3）。json_encode 在
     * serialize_precision=-1 下给出最短往返表示；指数形态（常规区间之外）
     * 展开为十进制串保证行为总定义——极端浮点不入 contract、不承诺双端一致
     */
    public static function fromNumber(int|float $value): string
    {
        if (is_int($value)) {
            return self::normalize((string) $value);
        }

        $s = is_finite($value) ? (string) json_encode($value) : (string) $value;

        return self::normalize($s);
    }

    /**
     * 严格十进制串 → 归一十进制串（前导零/尾零按值归一，'007' → '7'，spec §4.3）；
     * 非严格形态（科学计数法/千分位/含空白等）→ null，由求值器按原文直通
     */
    public static function fromStrictString(string $value): ?string
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return self::normalize($value);
    }

    /**
     * number（千分位）：只在整数部分插 , 分组符；n 给定先定点 n 位补零再分组，
     * 缺省小数原样直出不补零不舍入（spec §4.1②）
     */
    public static function group(string $dec, ?int $n): string
    {
        $parts = self::split($dec);
        if ($n !== null) {
            $parts = self::quantize($parts, $n);
        }

        return self::join($parts[0], self::groupInt($parts[1]), $parts[2]);
    }

    /** fixed（定点补零）：不分组，仅定点补零；n=0 无小数点（spec §4.1③） */
    public static function fixedPoint(string $dec, int $n): string
    {
        [$neg, $int, $frac] = self::quantize(self::split($dec), $n);

        return self::join($neg, $int, $frac);
    }

    /** percent（百分比）：×100（小数点右移两位）后定点 n 位再追加 %（spec §4.1④） */
    public static function percent(string $dec, int $n): string
    {
        [$neg, $int, $frac] = self::split($dec);
        $digits = ltrim($int . substr($frac . '00', 0, 2), '0');
        $rest = strlen($frac) > 2 ? substr($frac, 2) : '';

        [$neg, $int, $frac] = self::quantize([$neg, $digits === '' ? '0' : $digits, $rest], $n);

        return self::join($neg, $int, $frac) . '%';
    }

    /**
     * 定点 n 位（half-up 朝 +∞ = floor(v·10ⁿ + 0.5)·10⁻ⁿ）：丢弃首位 >5 或
     * （=5 且尾部有非零）进位；=5 精确 tie 时正数进位、负数朝零（朝 +∞）；
     * 小数位补零到恰好 n 位（spec §4.2）
     *
     * @param array{bool, string, string} $parts split 产物
     * @return array{bool, string, string}
     */
    private static function quantize(array $parts, int $n): array
    {
        [$neg, $int, $frac] = $parts;
        $kept = substr($frac . str_repeat('0', $n), 0, $n);
        $rest = strlen($frac) > $n ? substr($frac, $n) : '';

        if ($rest !== '') {
            $first = $rest[0];
            $tail = substr($rest, 1);
            $exactTie = $first === '5' && strpbrk($tail, '123456789') === false;
            $up = $first > '5' || ($first === '5' && (!$exactTie || !$neg));
            if ($up) {
                $combined = self::addOne($int . $kept);
                $int = $n > 0 ? substr($combined, 0, -$n) : $combined;
                $kept = $n > 0 ? substr($combined, -$n) : '';
            }
        }

        return [$neg, $int, $kept];
    }

    /** 拆解 canonical 十进制串 → [负号, 整数位, 小数位] */
    private static function split(string $dec): array
    {
        $neg = str_starts_with($dec, '-');
        $body = $neg ? substr($dec, 1) : $dec;
        if (str_contains($body, '.')) {
            [$int, $frac] = explode('.', $body, 2);
        } else {
            $int = $body;
            $frac = '';
        }

        return [$neg, $int, $frac];
    }

    /** 组装输出：整数位去前导零；定点结果为 0 时去符号保留补零形态（不输出 -0，spec §4.1③） */
    private static function join(bool $neg, string $int, string $frac): string
    {
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        if ($int === '0' && rtrim($frac, '0') === '') {
            return $frac === '' ? '0' : '0.' . $frac;
        }

        return ($neg ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
    }

    /** 归一：指数展开（如有）→ 前导零/尾零剥离 → 零去符号；非数值形态原样返回（防御出 contract 输入） */
    private static function normalize(string $s): string
    {
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $s, $m)) {
            $s = self::expandExponent($m[1], $m[2], $m[3] ?? '', (int) $m[4]);
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $s, $m)) {
            return $s;
        }

        $int = ltrim($m[2], '0');
        if ($int === '') {
            $int = '0';
        }
        $frac = isset($m[3]) ? rtrim($m[3], '0') : '';
        if ($int === '0' && $frac === '') {
            return '0';
        }

        return ($m[1] === '-' ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
    }

    /** 科学计数法展开为普通十进制串（json_encode 大/小值端的指数形态） */
    private static function expandExponent(string $sign, string $int, string $frac, int $exp): string
    {
        $digits = $int . $frac;
        $point = strlen($int) + $exp;
        if ($point <= 0) {
            $body = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $body = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $body = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }

        return $sign . $body;
    }

    /** 十进制串 +1：保留宽度逐位进位，溢出时扩展一位 */
    private static function addOne(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
            $digits[$i] = '0';
            $i--;
        }

        return '1' . $digits;
    }

    /** 整数部分千分位分组：自右每 3 位插 ,（负号由调用方在分组符之外处理） */
    private static function groupInt(string $int): string
    {
        return strrev(implode(',', str_split(strrev($int), 3)));
    }
}
