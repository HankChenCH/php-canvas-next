<?php

namespace HankChen\CanvasNext\Tests\Text;

use HankChen\CanvasNext\Contracts\LineBreakerInterface;
use HankChen\CanvasNext\Text\HeuristicMeasurer;
use HankChen\CanvasNext\Text\Uax14LineBreaker;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class Uax14LineBreakerTest extends CanvasTestCase
{
    private LineBreakerInterface $breaker;

    protected function setUp(): void
    {
        $this->breaker = new Uax14LineBreaker();
    }

    /**
     * 度量器按字号 10 估算：半角字符 5.5px，全角字符 10px
     */
    private function measurer(float $fontSize = 10): HeuristicMeasurer
    {
        return new HeuristicMeasurer($fontSize);
    }

    public function testCjkBreaksAtAnyCharacter(): void
    {
        // 每字 10px，盒宽 20px → 每行两字
        $this->assertSame(
            ['一二', '三四', '五'],
            $this->breaker->breakText('一二三四五', 20, $this->measurer())
        );
    }

    public function testEnglishPrefersWordBoundaryOverMidWordBreak(): void
    {
        // "hello w" 放到 40px 时 'o' 溢出，回退到空格断点
        $this->assertSame(
            ['hello', 'world'],
            $this->breaker->breakText('hello world', 40, $this->measurer())
        );
    }

    public function testHyphenBreakKeepsHyphenAtLineEnd(): void
    {
        $this->assertSame(
            ['co-', 'op'],
            $this->breaker->breakText('co-op', 20, $this->measurer())
        );
    }

    public function testLineStartForbiddenPunctuationMovesUp(): void
    {
        // '，' 不能落到行首 → 上移到上一行行尾，允许上一行轻微溢出
        $this->assertSame(
            ['一二三，', '四五六'],
            $this->breaker->breakText('一二三，四五六', 30, $this->measurer())
        );
    }

    public function testLineEndForbiddenPunctuationMovesDown(): void
    {
        // '（' 不能留在行尾 → 下移到下一行行首；'（三四' 恰好 30px 放得下
        $this->assertSame(
            ['一二', '（三四'],
            $this->breaker->breakText('一二（三四', 30, $this->measurer())
        );
    }

    public function testTrailingWhitespaceTrimmedAtBreak(): void
    {
        $this->assertSame(
            ['ab', 'cd'],
            $this->breaker->breakText('ab cd', 20, $this->measurer())
        );
    }

    public function testExplicitNewlinesPreserved(): void
    {
        $this->assertSame(
            ['ab', 'cd'],
            $this->breaker->breakText("ab\ncd", 100, $this->measurer())
        );
    }

    public function testEmptySegmentKeptAsEmptyLine(): void
    {
        $this->assertSame(
            ['a', '', 'b'],
            $this->breaker->breakText("a\n\nb", 100, $this->measurer())
        );
    }

    public function testEmptyTextProducesNoLines(): void
    {
        $this->assertSame([], $this->breaker->breakText('', 100, $this->measurer()));
    }

    public function testLongWordHardBreaksWhenNoBoundary(): void
    {
        $this->assertSame(
            ['abc', 'def', 'ghi', 'j'],
            $this->breaker->breakText('abcdefghij', 20, $this->measurer())
        );
    }

    public function testSingleCharacterWiderThanBoxStillRendered(): void
    {
        $this->assertSame(
            ['一'],
            $this->breaker->breakText('一', 5, $this->measurer())
        );
    }

    public function testEmojiZWJSequenceKeptAsOneUnit(): void
    {
        // 家庭 emoji（多人 ZWJ 组合）按一个图素簇计宽与断行，不拆碎
        $family = "👨‍👩‍👧";
        $this->assertSame(
            [$family, '👍'],
            $this->breaker->breakText($family . '👍', 15, $this->measurer())
        );
    }
}
