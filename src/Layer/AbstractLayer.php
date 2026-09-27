<?php

namespace HankChen\CanvasNext\Layer;

use InvalidArgumentException;

/**
 * 图层基类：纯数据容器
 *
 * 只承载"设定 + 纯布局函数"（盒模型、对齐、位置、graph 序列化），
 * 不渲染任何像素——渲染由 HankChen\CanvasNext\Renderer 下的各后端按设定完成
 */
abstract class AbstractLayer
{
    /** 序列化时的图层类型标识，子类必须覆写 */
    public const TYPE = 'AbstractLayer';

    protected string $name = self::TYPE;

    /**
     * 用户命名（wire 键 name，layer-panel-ux 工单 01）：与 $name（type 标识）无关，
     * 缺省空串且序列化键省略（仅非空写键，保往返恒等）
     */
    protected string $displayName = '';

    /** 显隐设定（wire 键 visible）：缺省 true 且序列化键省略（仅 false 写键）；渲染循环跳过 false 的根图层 */
    protected bool $visible = true;

    protected int $width = 0;
    protected int $height = 0;
    protected bool $autoWidth = false;
    protected bool $autoHeight = false;
    protected float $lineHeight = 1;

    /** @var array{top: float, bottom: float, left: float, right: float} */
    protected array $padding = [
        'top' => 0.0,
        'bottom' => 0.0,
        'left' => 0.0,
        'right' => 0.0,
    ];

    /** @var array{top: ?array, bottom: ?array, left: ?array, right: ?array} 每边 ['width' => int, 'color' => string] */
    protected array $border = [
        'top' => null,
        'bottom' => null,
        'left' => null,
        'right' => null,
    ];

    // 取值：left,center,right
    protected string $horizontalAlign = 'left';
    // 取值：top,center,bottom
    protected string $verticalAlign = 'top';

    protected ?string $bgColor = null;

    // 取值：top-left, top, top-right, left, center, right, bottom-left, bottom, bottom-right
    protected string $position = 'top-left';
    protected int $x = 0;
    protected int $y = 0;
    protected int $priority = 0;

    public static function make(int|string $width = 'auto', int|string $height = 'auto', ?string $background = null): static
    {
        return (new static())
            ->setWidth($width)
            ->setHeight($height)
            ->setBackground($background);
    }

    /**
     * 由 graph() 结构重建图层实例（子类补充自身字段后经容器方法复现尺寸同步）
     */
    public static function fromGraph(array $graph): static
    {
        $layer = new static();
        $layer->applyGraph($graph);

        return $layer;
    }

    protected function applyGraph(array $graph): void
    {
        $spec = $graph['spec'] ?? [];
        $shape = $spec['shape'] ?? [];
        $align = $spec['align'] ?? [];
        $position = $spec['position'] ?? [];

        if (array_key_exists('width', $shape)) {
            $shape['autoWidth'] ?? false ? $this->setWidth('auto') : $this->setWidth($shape['width']);
        }
        if (array_key_exists('height', $shape)) {
            $shape['autoHeight'] ?? false ? $this->setHeight('auto') : $this->setHeight($shape['height']);
        }
        if (isset($shape['lineHeight'])) {
            $this->setLineHeight((float) $shape['lineHeight']);
        }
        if (isset($shape['padding'])) {
            $this->padding = array_map(fn ($v) => (float) $v, $shape['padding']);
        }
        if (isset($shape['border'])) {
            $this->border = $shape['border'];
        }
        if (array_key_exists('backgroundColor', $shape)) {
            $this->setBackground($shape['backgroundColor']);
        }
        if (isset($align['horizontal'])) {
            $this->setHorizontalAlign($align['horizontal']);
        }
        if (isset($align['vertical'])) {
            $this->setVerticalAlign($align['vertical']);
        }
        if (isset($position['x'], $position['y'])) {
            $this->setPosition($position['x'], $position['y'], $position['position'] ?? 'top-left');
        }
        if (isset($graph['priority'])) {
            $this->setPriority((int) $graph['priority']);
        }

        // name/visible（layer-panel-ux 工单 01）：isset 门与 PHP 语义一致——null 不算在场，
        // 缺省值形态（空串 name、true visible）合法但序列化侧会归一省略
        if (isset($graph['name'])) {
            $this->displayName = (string) $graph['name'];
        }
        if (isset($graph['visible'])) {
            $this->visible = (bool) $graph['visible'];
        }
    }

    /**
     * 序列化为结构树节点：type/name?/visible?/priority/spec（形状、对齐、位置）/data（业务数据，子类补充）。
     * 键省略契约：name 仅非空写键、visible 仅 false 写键——缺省态字节面与无字段版本一致，
     * graph → fromGraph → graph 往返恒等不受扰动
     */
    public function graph(): array
    {
        $graph = ['type' => $this->name];
        if ($this->displayName !== '') {
            $graph['name'] = $this->displayName;
        }
        if (!$this->visible) {
            $graph['visible'] = false;
        }
        $graph['priority'] = $this->priority;
        $graph['spec'] = [
                'shape' => [
                    'width' => $this->width,
                    'height' => $this->height,
                    'autoWidth' => $this->autoWidth,
                    'autoHeight' => $this->autoHeight,
                    'lineHeight' => $this->lineHeight,
                    'padding' => $this->padding,
                    'border' => $this->border,
                    'backgroundColor' => $this->bgColor,
                ],
                'align' => [
                    'horizontal' => $this->horizontalAlign,
                    'vertical' => $this->verticalAlign,
                ],
                'position' => [
                    'x' => $this->x,
                    'y' => $this->y,
                    'position' => $this->position,
                ],
        ];

        return $graph;
    }

    public function setWidth(int|string $width): static
    {
        if (is_string($width) && strtolower($width) === 'auto') {
            $this->autoWidth = true;
            $this->width = 0;

            return $this;
        }

        $this->autoWidth = false;
        $this->width = intval($width);

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setAutoWidth(bool $autoWidth): static
    {
        if ($autoWidth) {
            return $this->setWidth('auto');
        }

        $this->autoWidth = false;

        return $this;
    }

    public function setHeight(int|string $height): static
    {
        if (is_string($height) && strtolower($height) === 'auto') {
            $this->autoHeight = true;
            $this->height = 0;

            return $this;
        }

        $this->autoHeight = false;
        $this->height = intval($height);

        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setAutoHeight(bool $autoHeight): static
    {
        if ($autoHeight) {
            return $this->setHeight('auto');
        }

        $this->autoHeight = false;

        return $this;
    }

    /** 内容区宽度 = 宽度 - 左右 padding */
    public function getContentWidth(): int
    {
        return intval($this->getWidth() - $this->padding['left'] - $this->padding['right']);
    }

    /** 内容区高度 = 高度 - 上下 padding */
    public function getContentHeight(): int
    {
        return intval($this->getHeight() - $this->padding['top'] - $this->padding['bottom']);
    }

    public function setLineHeight(float $lineHeight): static
    {
        $this->lineHeight = $lineHeight;

        return $this;
    }

    public function setBackground(?string $bgColor): static
    {
        $this->bgColor = $bgColor;

        return $this;
    }

    public function getBackground(): ?string
    {
        return $this->bgColor;
    }

    public function setPosition(int|float $x = 0, int|float $y = 0, string $position = 'top-left'): static
    {
        $this->x = intval($x);
        $this->y = intval($y);
        $this->position = $position;

        return $this;
    }

    /** @return array{0: string, 1: int, 2: int} [锚点, x, y] */
    public function getPosition(): array
    {
        return [$this->position, $this->x, $this->y];
    }

    public function setBorder(int $width, string $color = '#000'): static
    {
        foreach (['top', 'bottom', 'left', 'right'] as $side) {
            $this->border[$side] = $width === 0
                ? null
                : ['width' => $width, 'color' => $color];
        }

        return $this;
    }

    public function setBorderTop(int $width, string $color = '#000'): static
    {
        return $this->setBorderSide('top', $width, $color);
    }

    public function setBorderBottom(int $width, string $color = '#000'): static
    {
        return $this->setBorderSide('bottom', $width, $color);
    }

    public function setBorderLeft(int $width, string $color = '#000'): static
    {
        return $this->setBorderSide('left', $width, $color);
    }

    public function setBorderRight(int $width, string $color = '#000'): static
    {
        return $this->setBorderSide('right', $width, $color);
    }

    private function setBorderSide(string $side, int $width, string $color): static
    {
        $this->border[$side] = $width === 0
            ? null
            : ['width' => $width, 'color' => $color];

        return $this;
    }

    public function getBorder(): array
    {
        return $this->border;
    }

    /**
     * CSS 风格 padding：1 值全边、2 值上下/左右、3 值 上/左右/下、4 值 上右下左
     */
    public function setPadding(float ...$args): static
    {
        $this->padding = match (count($args)) {
            1 => [
                'top' => $args[0], 'bottom' => $args[0], 'left' => $args[0], 'right' => $args[0],
            ],
            2 => [
                'top' => $args[0], 'bottom' => $args[0], 'left' => $args[1], 'right' => $args[1],
            ],
            3 => [
                'top' => $args[0], 'bottom' => $args[2], 'left' => $args[1], 'right' => $args[1],
            ],
            4 => [
                'top' => $args[0], 'right' => $args[1], 'bottom' => $args[2], 'left' => $args[3],
            ],
            default => throw new InvalidArgumentException('setPadding 需要 1~4 个参数'),
        };

        return $this;
    }

    public function getPadding(): array
    {
        return $this->padding;
    }

    public function setHorizontalAlign(string $horizontal): static
    {
        $this->horizontalAlign = $horizontal;

        return $this;
    }

    public function getHorizontalAlign(): string
    {
        return $this->horizontalAlign;
    }

    public function setVerticalAlign(string $vertical): static
    {
        $this->verticalAlign = $vertical;

        return $this;
    }

    public function getVerticalAlign(): string
    {
        return $this->verticalAlign;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /** 用户命名（wire 键 name）；缺省空串 = 未命名，序列化键省略 */
    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /** 显隐设定（wire 键 visible）；false 时渲染循环跳过该根图层 */
    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }
}
