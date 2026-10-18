<?php

namespace HankChen\CanvasNext;

use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Layer\LayerFactory;

/**
 * 画布：纯结构容器（尺寸 + 有序图层），不含任何渲染状态。
 * 渲染交给各 Renderer 后端：$renderer->render(new NullCancellation(), $canvas)
 */
class Canvas
{
    private int $width;
    private int $height;

    /** 画布背景（ADR 0022）：null = 无背景（wire 整键不落）；渲染语义字段，不属图层树 */
    private ?CanvasBackground $background = null;

    /** @var AbstractLayer[] 按 priority 降序（等优先级保持插入序） */
    private array $layers = [];

    public static function make(int $width, int $height, AbstractLayer ...$layers): self
    {
        $canvas = new self($width, $height);
        foreach ($layers as $layer) {
            $canvas->addLayer($layer);
        }

        return $canvas;
    }

    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * 声明画布背景（ADR 0022）：null 或空背景（两分量皆空）与无背景同态——wire 整键不落
     */
    public function setBackground(?CanvasBackground $background): static
    {
        $this->background = ($background !== null && $background->isEmpty()) ? null : $background;

        return $this;
    }

    public function getBackground(): ?CanvasBackground
    {
        return $this->background;
    }

    public function addLayer(AbstractLayer $layer): static
    {
        $this->layers[] = $layer;
        usort($this->layers, static fn (AbstractLayer $a, AbstractLayer $b) => $b->getPriority() <=> $a->getPriority());

        return $this;
    }

    /**
     * 按 priority 降序返回图层（等优先级保持插入序）。
     * 普通数组可重复消费——graph() 与多次渲染互不影响
     *
     * @return AbstractLayer[]
     */
    public function getLayers(): array
    {
        return $this->layers;
    }

    /**
     * 序列化为结构树（无损），可 JSON 持久化、跨渲染端复用。
     * canvas 段键序钉死 width, height, background（background 条件写键，ADR 0022）
     */
    public function graph(): array
    {
        $canvas = [
            'width' => $this->width,
            'height' => $this->height,
        ];
        $background = $this->background?->graph();
        if ($background !== null) {
            $canvas['background'] = $background;
        }

        return [
            'canvas' => $canvas,
            'layers' => array_map(
                static fn (AbstractLayer $layer) => $layer->graph(),
                $this->layers
            ),
        ];
    }

    /**
     * 从 graph() 结构重建画布（含全部图层与尺寸同步）；background 宽容读，缺键回填空
     */
    public static function fromGraph(array $graph): self
    {
        $canvas = new self(
            intval($graph['canvas']['width'] ?? 0),
            intval($graph['canvas']['height'] ?? 0)
        );
        $canvas->setBackground(CanvasBackground::fromGraph($graph['canvas']['background'] ?? null));

        foreach ($graph['layers'] ?? [] as $layerGraph) {
            $canvas->addLayer(LayerFactory::fromGraph($layerGraph));
        }

        return $canvas;
    }
}
