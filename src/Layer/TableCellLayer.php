<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 表格单元格图层：包装一个内容图层
 */
class TableCellLayer extends AbstractLayer
{
    public const TYPE = 'TableCellLayer';

    protected string $name = self::TYPE;

    private ?AbstractLayer $contentLayer = null;

    public function addContentLayer(AbstractLayer $contentLayer): static
    {
        $contentLayer->setWidth($this->getWidth());
        if ($this->autoHeight) {
            $this->setHeight($contentLayer->getHeight());
        } else {
            $contentLayer->setHeight($this->getHeight())
                ->setAutoHeight(false);
        }

        $this->contentLayer = $contentLayer;

        return $this;
    }

    public function getContentLayer(): ?AbstractLayer
    {
        return $this->contentLayer;
    }

    public function graph(): array
    {
        $graph = parent::graph();

        $graph['content'] = $this->contentLayer?->graph();

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $cell = parent::fromGraph($graph);

        if (!empty($graph['content'])) {
            $cell->addContentLayer(LayerFactory::fromGraph($graph['content']));
        }

        return $cell;
    }
}
