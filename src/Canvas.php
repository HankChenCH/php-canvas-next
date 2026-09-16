<?php

namespace HankChen\CanvasNext;

use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Layer\LayerFactory;

/**
 * 画布：纯结构容器（尺寸 + 有序图层），不含任何渲染状态。
 * 渲染交给各 Renderer 后端：$renderer->render($canvas)
 */
class Canvas
{
    private int $width;
    private int $height;

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
     * 序列化为结构树（无损），可 JSON 持久化、跨渲染端复用
     */
    public function graph(): array
    {
        return [
            'canvas' => [
                'width' => $this->width,
                'height' => $this->height,
            ],
            'layers' => array_map(
                static fn (AbstractLayer $layer) => $layer->graph(),
                $this->layers
            ),
        ];
    }

    /**
     * 从 graph() 结构重建画布（含全部图层与尺寸同步）
     */
    public static function fromGraph(array $graph): self
    {
        $canvas = new self(
            intval($graph['canvas']['width'] ?? 0),
            intval($graph['canvas']['height'] ?? 0)
        );

        foreach ($graph['layers'] ?? [] as $layerGraph) {
            $canvas->addLayer(LayerFactory::fromGraph($layerGraph));
        }

        return $canvas;
    }
}
