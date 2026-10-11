<?php

namespace HankChen\CanvasNext\Tests\Expression;

use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Expression\DateFormat;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 日期格式化：moment 风格 token 子集（14 个，语义自含）+ 方括号转义 + 严格 ISO 解析。
 * 钉稿见 .scratch/expression-type-methods/spec.md §3；token 表为三端契约权威源
 * （fixture dateTokens 由此导出，Go 端同构表断言锁漂移）
 */
class DateFormatTest extends CanvasTestCase
{
    private array $dt;

    protected function setUp(): void
    {
        // 样例钉死 2026-10-11 14:30:05（星期日，spec §3.1）
        $this->dt = DateFormat::parseIso('2026-10-11 14:30:05');
    }

    public function testTokenTableIsAuthoritativeSource(): void
    {
        // 14 个 token，顺序与语义串逐字对应 spec §3.1 表
        $expects = [
            'YYYY' => '2026', 'YY' => '26',
            'MM' => '10', 'M' => '10',
            'DD' => '11', 'D' => '11',
            'HH' => '14', 'H' => '14',
            'mm' => '30', 'm' => '30',
            'ss' => '05', 's' => '5',
            'dddd' => '星期日', 'ddd' => '周日',
        ];

        $table = DateFormat::tokenTable();
        $this->assertCount(14, $table);
        $this->assertSame(array_keys($expects), array_column($table, 'token'));

        foreach ($table as $row) {
            $this->assertNotSame('', $row['semantics']);
            $this->assertSame($expects[$row['token']], DateFormat::format($row['token'], $this->dt));
        }
    }

    public function testLongestMatchFirst(): void
    {
        $this->assertSame('202626', DateFormat::format('YYYYYY', $this->dt));
        $this->assertSame('星期日 周日', DateFormat::format('dddd ddd', $this->dt));
    }

    public function testTokenCaseSensitive(): void
    {
        // mm 分 ≠ MM 月；小写 yyyy 无 y token → fail-fast
        $this->assertSame('10月30分', DateFormat::format('MM月mm分', $this->dt));
        $this->assertSyntaxError('yyyy');
        $this->assertSyntaxError('YYYYQ');
    }

    public function testNonLetterAndNonAsciiPassThrough(): void
    {
        $this->assertSame('2026年10月11日', DateFormat::format('YYYY年MM月DD日', $this->dt));
        $this->assertSame('2026-10-11 14:30:05', DateFormat::format('YYYY-MM-DD HH:mm:ss', $this->dt));
    }

    public function testSquareBracketEscaping(): void
    {
        $this->assertSame('YYYY年', DateFormat::format('[YYYY]年', $this->dt));
        $this->assertSame('date', DateFormat::format('[date]', $this->dt));
        // 段内无嵌套、无再次转义：内容直到首个 ]
        $this->assertSame('a[b', DateFormat::format('[a[b]', $this->dt));
        // 未配对 [ 按普通字符字面输出（含 [ 自身）
        $this->assertSame('第[', DateFormat::format('第[', $this->dt));
        // 孤立 ] 字面输出
        $this->assertSame(']]', DateFormat::format(']]', $this->dt));
    }

    public function testEscapedSegmentBypassesTokenWhitelist(): void
    {
        // 转义段内字母不进 token 白名单
        $this->assertSame("it's", DateFormat::format("[it's]", $this->dt));
        // 但未配对 [ 之后的字母仍走白名单 → fail-fast
        $this->assertSyntaxError('[today');
    }

    public function testEmptyFormatOutputsEmptyString(): void
    {
        $this->assertSame('', DateFormat::format('', $this->dt));
    }

    public function testParseIsoAcceptsThreeNoTimezoneForms(): void
    {
        $this->assertSame(
            ['y' => 2026, 'm' => 10, 'd' => 11, 'h' => 0, 'i' => 0, 's' => 0],
            DateFormat::parseIso('2026-10-11')
        );
        $this->assertSame(
            ['y' => 2026, 'm' => 10, 'd' => 11, 'h' => 14, 'i' => 30, 's' => 5],
            DateFormat::parseIso('2026-10-11 14:30:05')
        );
        $this->assertSame(
            DateFormat::parseIso('2026-10-11 14:30:05'),
            DateFormat::parseIso('2026-10-11T14:30:05')
        );
    }

    public function testParseIsoIsStrict(): void
    {
        // 越界即不可解析
        $this->assertNull(DateFormat::parseIso('2026-02-30'));
        $this->assertNull(DateFormat::parseIso('2026-13-45'));
        $this->assertNull(DateFormat::parseIso('2026-10-11 24:00:00'));
        $this->assertNull(DateFormat::parseIso('2026-10-11 12:60:00'));
        $this->assertNull(DateFormat::parseIso('2023-02-29')); // 非闰年
        $this->assertNotNull(DateFormat::parseIso('2024-02-29')); // 闰年
        // 位数与形态严格
        $this->assertNull(DateFormat::parseIso('2026-1-1'));
        $this->assertNull(DateFormat::parseIso('2026/10/11'));
        $this->assertNull(DateFormat::parseIso('2026-10-11t14:30:05')); // 仅大写 T
        $this->assertNull(DateFormat::parseIso('2026-10-11T14:30:05.5'));
        $this->assertNull(DateFormat::parseIso('2026-10-11Z'));
        $this->assertNull(DateFormat::parseIso('2026-10-11 +08:00'));
        $this->assertNull(DateFormat::parseIso(' 2026-10-11'));
        $this->assertNull(DateFormat::parseIso('2026-10-11 '));
    }

    public function testWeekdayFromPureArithmetic(): void
    {
        $cases = [
            '2026-10-11' => '星期日', // 钉稿样例（06 稿笔误更正后）
            '2026-10-12' => '星期一',
            '2024-02-29' => '星期四',
            '2000-01-01' => '星期六',
            '2026-01-01' => '星期四',
        ];
        foreach ($cases as $date => $weekday) {
            $this->assertSame($weekday, DateFormat::format('dddd', DateFormat::parseIso($date)), $date);
        }
    }

    public function testNamedPresetsExpandWholeMatch(): void
    {
        $this->assertSame('YYYY-MM-DD', DateFormat::expandPreset('date'));
        $this->assertSame('YYYY-MM-DD HH:mm:ss', DateFormat::expandPreset('datetime'));
        // 非整体等值不命中预设，按 token 串解析
        $this->assertSame('datetime X', DateFormat::expandPreset('datetime X'));
        $this->assertSame('', DateFormat::expandPreset(''));
    }

    private function assertSyntaxError(string $format): void
    {
        try {
            DateFormat::format($format, $this->dt);
            $this->fail("格式串「{$format}」必须报语法错");
        } catch (HydrateException $e) {
            $this->assertSame('expression_syntax_error', $e->getErrorCode());
        }
    }
}
