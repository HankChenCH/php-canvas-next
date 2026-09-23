<?php

namespace HankChen\CanvasNext\Layer;

use HankChen\CanvasNext\Contracts\LineBreakerInterface;
use HankChen\CanvasNext\Contracts\TextMeasurerFactoryInterface;
use HankChen\CanvasNext\Text\HeuristicMeasurerFactory;
use HankChen\CanvasNext\Text\Uax14LineBreaker;

/**
 * 文本图层：断行与度量通过可注入策略完成（布局纯函数，渲染端共享同一结果）
 */
class TextLayer extends AbstractLayer
{
    public const TYPE = 'TextLayer';

    protected string $name = self::TYPE;

    protected string $text = '';
    /** 字体文件路径/URL；空串或纯数字表示使用渲染端内置默认字体 */
    protected string $font = '';
    protected int $fontSize = 12;
    protected string $fontColor = '#000000';
    protected int $textAngle = 0;
    protected bool $autowrap = false;

    private LineBreakerInterface $lineBreaker;
    private TextMeasurerFactoryInterface $measurerFactory;

    /** Resolver 物化后的本地字体路径，渲染端优先使用 */
    private ?string $resolvedFont = null;

    // 取值：left,center,right
    protected string $horizontalAlign = 'left';
    // 取值：top,center,bottom
    protected string $verticalAlign = 'bottom';

    public function __construct()
    {
        $this->lineBreaker = new Uax14LineBreaker();
        $this->measurerFactory = new HeuristicMeasurerFactory();
    }

    /**
     * 覆写默认断行器（Canvas 级统一切换度量/断行策略时使用，
     * 保证多个渲染端拿到同一排版结果）
     */
    public function setLineBreaker(LineBreakerInterface $lineBreaker): static
    {
        $this->lineBreaker = $lineBreaker;

        return $this;
    }

    public function setMeasurerFactory(TextMeasurerFactoryInterface $factory): static
    {
        $this->measurerFactory = $factory;

        return $this;
    }

    public function getHeight(): int
    {
        if (!$this->autoHeight) {
            return $this->height;
        }

        $paddingHeight = intval($this->padding['top'] + $this->padding['bottom']);

        if ($this->autowrap) {
            return $this->lineHeightPx() * count($this->getLines()) + $paddingHeight;
        }

        if ($this->text !== '') {
            return $this->lineHeightPx() + $paddingHeight;
        }

        return $paddingHeight;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @param string $font 字体文件路径或 URL；空串/纯数字表示渲染端内置默认字体
     */
    public function setFont(string $font, int $fontSize, string $fontColor): static
    {
        $this->font = $font;
        $this->fontSize = $fontSize;
        $this->fontColor = $fontColor;
        $this->resolvedFont = null;

        return $this;
    }

    public function getFont(): string
    {
        return $this->font;
    }

    /**
     * @internal 仅供 ResourceResolver 回写物化结果
     */
    public function setResolvedFont(string $path): static
    {
        $this->resolvedFont = $path;

        return $this;
    }

    /** 渲染实际使用的字体文件：优先物化结果；空串/纯数字表示渲染端内置默认字体 */
    public function getResolvedFont(): string
    {
        return $this->resolvedFont ?? $this->font;
    }

    public function getFontSize(): int
    {
        return $this->fontSize;
    }

    public function getFontColor(): string
    {
        return $this->fontColor;
    }

    public function setAutowrap(bool $autowrap): static
    {
        $this->autowrap = $autowrap;

        return $this;
    }

    public function getAutowrap(): bool
    {
        return $this->autowrap;
    }

    public function setAngle(int $angle): static
    {
        $this->textAngle = $angle;

        return $this;
    }

    public function getAngle(): int
    {
        return $this->textAngle;
    }

    /**
     * 断行结果（纯布局函数）：autowrap 开启时按内容盒宽断行，否则整段单行
     *
     * @return string[]
     */
    public function getLines(): array
    {
        if (!$this->autowrap) {
            return [$this->text];
        }

        return $this->lineBreaker->breakText(
            $this->text,
            (float) $this->getContentWidth(),
            $this->measurerFactory->create($this->font, $this->fontSize)
        );
    }

    /** 单行高度（像素）= 字号 × 行高倍数，向上取整 */
    public function lineHeightPx(): int
    {
        return intval(ceil($this->fontSize * $this->lineHeight));
    }

    /**
     * 文本在内容盒内的绘制基准点（对齐 + 行数），纯布局计算，渲染端共用
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function getTextOrigin(): array
    {
        $lineCount = max(count($this->getLines()), 1);

        $posx = match ($this->horizontalAlign) {
            'left' => 0,
            'center' => $this->getContentWidth() / 2,
            'right' => $this->getContentWidth(),
            default => 0,
        };

        $posy = match ($this->verticalAlign) {
            'top' => 0,
            'center' => $this->autoHeight
                ? (int) ($this->lineHeightPx() / 2)
                : intval(($this->getContentHeight() - $this->lineHeightPx() * ($lineCount - 1)) / 2),
            'bottom' => $this->autowrap
                ? $this->getContentHeight()
                    - $this->lineHeightPx() * ($lineCount - 1)
                    - round($this->fontSize * 0.1)
                : $this->getContentHeight(),
            default => 0,
        };

        return [intval($posx), intval($posy)];
    }

    public function graph(): array
    {
        $graph = parent::graph();

        // 无损：font 保留完整原始值（路径/URL），旧库此处只存 basename
        $graph['spec']['fontFamily'] = [
            'font' => $this->font,
            'fontSize' => $this->fontSize,
            'fontColor' => $this->fontColor,
            'angle' => $this->textAngle,
            'autowrap' => $this->autowrap,
        ];

        $graph['data'] = [
            'valueType' => 'StaticValue',
            'expression' => '',
            'value' => $this->text,
        ];

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $layer = parent::fromGraph($graph);

        $font = $graph['spec']['fontFamily'] ?? [];
        $layer->setFont(
            $font['font'] ?? '',
            intval($font['fontSize'] ?? 12),
            $font['fontColor'] ?? '#000000'
        );
        if (isset($font['angle'])) {
            $layer->setAngle((int) $font['angle']);
        }
        if (isset($font['autowrap'])) {
            $layer->setAutowrap((bool) $font['autowrap']);
        }

        return $layer->setText($graph['data']['value'] ?? '');
    }
}
