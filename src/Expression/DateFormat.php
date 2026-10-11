<?php

namespace HankChen\CanvasNext\Expression;

use HankChen\CanvasNext\Exception\HydrateException;

/**
 * 日期格式化：moment 风格 token 子集（14 个，语义自含）+ 方括号字面转义 +
 * 3 种无时区 ISO 输入的严格解析（wall clock 语义，不转时区）。
 * 钉稿见 .scratch/expression-type-methods/spec.md §3；token 表是三端契约的
 * 权威源——fixture dateTokens 由本表导出，Go 端内置同构表、fixture 断言锁漂移
 */
final class DateFormat
{
    /** 单参调用缺省格式（等价预设 date，spec §3.4） */
    public const DEFAULT_FORMAT = 'YYYY-MM-DD';

    /** 具名预设 v1：第二参整体等值命中时先展开为 token 串再走统一管线（纯糖，spec §3.4） */
    public const PRESETS = [
        'date' => 'YYYY-MM-DD',
        'datetime' => 'YYYY-MM-DD HH:mm:ss',
    ];

    /**
     * token 表 v1（权威源）：[token, 语义一句话]，同字母长 token 在先（最长匹配优先）。
     * 语义串与 spec §3.1 逐字对应，随 fixture dateTokens 导出
     */
    private const TOKENS = [
        ['YYYY', '四位年，零填充 4 位'],
        ['YY', '年后两位，零填充 2 位'],
        ['MM', '两位月 01-12'],
        ['M', '月 1-12'],
        ['DD', '两位日 01-31'],
        ['D', '日 1-31'],
        ['HH', '两位时 00-23'],
        ['H', '时 0-23'],
        ['mm', '两位分 00-59'],
        ['m', '分 0-59'],
        ['ss', '两位秒 00-59'],
        ['s', '秒 0-59'],
        ['dddd', '周几中文全称：星期日…星期六'],
        ['ddd', '周几中文简称：周日…周六'],
    ];

    private const WEEK_FULL = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
    private const WEEK_SHORT = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

    /** @return list<array{token: string, semantics: string}> */
    public static function tokenTable(): array
    {
        return array_map(
            static fn (array $row): array => ['token' => $row[0], 'semantics' => $row[1]],
            self::TOKENS
        );
    }

    /** 预设是纯糖：第二参整体等值命中预设名时展开为 token 串，非整体等值按 token 串解析 */
    public static function expandPreset(string $format): string
    {
        return self::PRESETS[$format] ?? $format;
    }

    /**
     * 严格解析 3 种无时区形态（spec §3.3）：YYYY-MM-DD ｜ YYYY-MM-DD HH:mm:ss ｜ YYYY-MM-DDTHH:mm:ss。
     * 位数严格、越界即不可解析（2026-13-45 / 2026-02-30 / 24:00:00 均不收）；
     * 时间戳数字输入不收（双端时区直觉差），带时区后缀不收
     *
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int}|null null = 不可解析
     */
    public static function parseIso(string $value): ?array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?$/', $value, $m)) {
            return null;
        }

        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        $h = isset($m[4]) ? (int) $m[4] : 0;
        $mi = isset($m[5]) ? (int) $m[5] : 0;
        $s = isset($m[6]) ? (int) $m[6] : 0;

        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return ['y' => $y, 'm' => $mo, 'd' => $d, 'h' => $h, 'i' => $mi, 's' => $s];
    }

    /**
     * 按 token 串渲染：最长匹配优先、大小写敏感；非 ASCII 字母字面直通；
     * 方括号转义段至首个 ] 止（段内字面直通，无嵌套无再次转义），未配对 [
     * 按普通字符字面输出（含 [ 自身，单遍扫描无回溯）；白名单外 ASCII 字母 fail-fast
     *
     * @param array{y: int, m: int, d: int, h: int, i: int, s: int} $dt parseIso 产物
     */
    public static function format(string $format, array $dt): string
    {
        $out = '';
        $len = strlen($format);
        $i = 0;
        while ($i < $len) {
            $ch = $format[$i];

            if ($ch === '[') {
                $end = strpos($format, ']', $i + 1);
                if ($end === false) {
                    $out .= '['; // 未配对 [ 字面直通，后续字符继续常规扫描
                    $i++;
                    continue;
                }
                $out .= substr($format, $i + 1, $end - $i - 1);
                $i = $end + 1;
                continue;
            }

            if ($ch === ']') {
                $out .= ']'; // 孤立 ] 字面输出
                $i++;
                continue;
            }

            $isAsciiLetter = ($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z');
            if (!$isAsciiLetter) {
                $out .= $ch; // 中文/标点/数字字面直通
                $i++;
                continue;
            }

            $run = 0;
            while ($i + $run < $len && $format[$i + $run] === $ch) {
                $run++;
            }
            $token = self::matchToken($ch, $run);
            if ($token === null) {
                throw new HydrateException('expression_syntax_error', "expression_syntax_error: 格式串含未知 token {$ch}");
            }
            $out .= self::renderToken($token, $dt);
            $i += strlen($token);
        }

        return $out;
    }

    /** 同字母 run 上取可整段消费的最长白名单 token（表内同字母长者在先）；run 短于最短 token = 白名单外 */
    private static function matchToken(string $letter, int $runLen): ?string
    {
        foreach (self::TOKENS as $row) {
            $token = $row[0];
            if ($token[0] === $letter && strlen($token) <= $runLen) {
                return $token;
            }
        }

        return null;
    }

    private static function renderToken(string $token, array $dt): string
    {
        return match ($token) {
            'YYYY' => sprintf('%04d', $dt['y']),
            'YY' => sprintf('%02d', $dt['y'] % 100),
            'MM' => sprintf('%02d', $dt['m']),
            'M' => (string) $dt['m'],
            'DD' => sprintf('%02d', $dt['d']),
            'D' => (string) $dt['d'],
            'HH' => sprintf('%02d', $dt['h']),
            'H' => (string) $dt['h'],
            'mm' => sprintf('%02d', $dt['i']),
            'm' => (string) $dt['i'],
            'ss' => sprintf('%02d', $dt['s']),
            's' => (string) $dt['s'],
            'dddd' => self::WEEK_FULL[self::weekday($dt['y'], $dt['m'], $dt['d'])],
            'ddd' => self::WEEK_SHORT[self::weekday($dt['y'], $dt['m'], $dt['d'])],
        };
    }

    /** 周几（Sakamoto 算法，纯算术零时区依赖）：0 = 星期日 */
    private static function weekday(int $y, int $m, int $d): int
    {
        $t = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];
        if ($m < 3) {
            $y -= 1;
        }

        return ($y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) + $t[$m - 1] + $d) % 7;
    }
}
