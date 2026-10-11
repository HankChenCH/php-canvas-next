<?php

namespace HankChen\CanvasNext\Tests\Expression;

use HankChen\CanvasNext\Expression\NumberFormat;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 数字族格式化：十进制字符串语义（spec §4）——舍入定义在值的最短往返十进制表示上，
 * half-up 朝 +∞（tie 时负数朝零）；2.675 类二进制浮点边界不喂原生 round
 */
class NumberFormatTest extends CanvasTestCase
{
    public function testFromNumberCanonicalizesShortestRoundTrip(): void
    {
        $this->assertSame('1234', NumberFormat::fromNumber(1234));
        $this->assertSame('1234.5', NumberFormat::fromNumber(1234.5));
        $this->assertSame('0', NumberFormat::fromNumber(0));
        $this->assertSame('-1234.5', NumberFormat::fromNumber(-1234.5));
        $this->assertSame('12', NumberFormat::fromNumber(12.0)); // 整值浮点去小数
        $this->assertSame('0.826', NumberFormat::fromNumber(0.826));
        $this->assertSame('2.675', NumberFormat::fromNumber(2.675));
        $this->assertSame('0.125', NumberFormat::fromNumber(0.125));
        $this->assertSame('9.9999', NumberFormat::fromNumber(9.9999));
        $this->assertSame('2.675000000001', NumberFormat::fromNumber(2.675000000001));
        $this->assertSame('0.123456789', NumberFormat::fromNumber(0.123456789));
    }

    public function testFromStrictStringNormalizesByValue(): void
    {
        $this->assertSame('7', NumberFormat::fromStrictString('007'));
        $this->assertSame('12.3', NumberFormat::fromStrictString('12.30'));
        $this->assertSame('1234.5', NumberFormat::fromStrictString('1234.5'));
        $this->assertSame('-0.4', NumberFormat::fromStrictString('-0.4'));
        $this->assertSame('0', NumberFormat::fromStrictString('-0'));
        $this->assertSame('0', NumberFormat::fromStrictString('0.0'));
        $this->assertSame('7.5', NumberFormat::fromStrictString('007.500'));
    }

    public function testFromStrictStringRejectsNonStrictForms(): void
    {
        // 科学计数法、带千分位、含空白等 → null（求值器按原文直通）
        $this->assertNull(NumberFormat::fromStrictString('1e3'));
        $this->assertNull(NumberFormat::fromStrictString('1,234.5'));
        $this->assertNull(NumberFormat::fromStrictString(' 1.5'));
        $this->assertNull(NumberFormat::fromStrictString('1.5 '));
        $this->assertNull(NumberFormat::fromStrictString(''));
        $this->assertNull(NumberFormat::fromStrictString('abc'));
        $this->assertNull(NumberFormat::fromStrictString('+1.5'));
        $this->assertNull(NumberFormat::fromStrictString('.5'));
        $this->assertNull(NumberFormat::fromStrictString('5.'));
        $this->assertNull(NumberFormat::fromStrictString('--1'));
    }

    public function testGroupThousandsOnlyInIntegerPart(): void
    {
        $this->assertSame('1,234.5', NumberFormat::group('1234.5', null));
        $this->assertSame('1,234,567', NumberFormat::group('1234567', null));
        $this->assertSame('123,456,789', NumberFormat::group('123456789', null));
        $this->assertSame('100', NumberFormat::group('100', null));
        $this->assertSame('0', NumberFormat::group('0', null));
        // 负号在分组符之外
        $this->assertSame('-1,234.5', NumberFormat::group('-1234.5', null));
    }

    public function testGroupWithNQuantizesThenGroups(): void
    {
        $this->assertSame('1,234.57', NumberFormat::group('1234.567', 2));
        $this->assertSame('12.00', NumberFormat::group('12', 2));
        $this->assertSame('0.00', NumberFormat::group('0', 2));
        $this->assertSame('-1,234.50', NumberFormat::group('-1234.5', 2)); // 金额形态唯一可达路径
    }

    public function testFixedPointPadsWithoutGrouping(): void
    {
        $this->assertSame('8.900', NumberFormat::fixedPoint('8.9', 3));
        $this->assertSame('8.90', NumberFormat::fixedPoint('8.9', 2));
        $this->assertSame('9', NumberFormat::fixedPoint('8.9', 0)); // n=0 无小数点
        $this->assertSame('10.000', NumberFormat::fixedPoint('9.9999', 3)); // 整数进位
        $this->assertSame('0.00', NumberFormat::fixedPoint('0', 2));
    }

    public function testRoundingHalfUpTowardPositiveInfinity(): void
    {
        // 十进制字符串语义：2.675 → 2.68（不是二进制 2.6749… → 2.67）
        $this->assertSame('2.68', NumberFormat::fixedPoint('2.675', 2));
        $this->assertSame('2.68', NumberFormat::fixedPoint('2.675000000001', 2)); // 近界
        $this->assertSame('0.13', NumberFormat::fixedPoint('0.125', 2));
        $this->assertSame('3', NumberFormat::fixedPoint('2.5', 0));
        $this->assertSame('1.3', NumberFormat::fixedPoint('1.25', 1));
        $this->assertSame('0.12345679', NumberFormat::fixedPoint('0.123456789', 8));
    }

    public function testTieNegativeRoundsTowardZero(): void
    {
        $this->assertSame('-2', NumberFormat::fixedPoint('-2.5', 0));
        $this->assertSame('-0.8', NumberFormat::fixedPoint('-0.85', 1));
        $this->assertSame('-3', NumberFormat::fixedPoint('-2.6', 0)); // 非 tie 仍常规四舍五入
    }

    public function testZeroResultDropsSignKeepsPadding(): void
    {
        $this->assertSame('0', NumberFormat::fixedPoint('-0.4', 0)); // 不输出 -0
        $this->assertSame('0.00', NumberFormat::fixedPoint('-0.004', 2));
        $this->assertSame('-0.01', NumberFormat::fixedPoint('-0.006', 2));
    }

    public function testPercentShiftsDecimalPointByTwo(): void
    {
        $this->assertSame('83%', NumberFormat::percent('0.826', 0));
        $this->assertSame('82.60%', NumberFormat::percent('0.826', 2));
        $this->assertSame('-83%', NumberFormat::percent('-0.826', 0));
        $this->assertSame('100%', NumberFormat::percent('1', 0));
        $this->assertSame('12.5%', NumberFormat::percent('0.125', 1));
        $this->assertSame('123450%', NumberFormat::percent('1234.5', 0));
        $this->assertSame('0%', NumberFormat::percent('0.001', 0));
        $this->assertSame('0.00%', NumberFormat::percent('0', 2));
        $this->assertSame('0%', NumberFormat::percent('-0.005', 0)); // -0.5 tie 朝零 → 0%
        $this->assertSame('1%', NumberFormat::percent('0.006', 0));
        $this->assertSame('-1%', NumberFormat::percent('-0.006', 0));
    }
}
