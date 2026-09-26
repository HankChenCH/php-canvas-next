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

    /**
     * 模板上下文装配（TableLayer V2，spec §2.3）：只同步内容宽 = 格宽，
     * 高度耦合全豁免——声明高与 autoHeight 标志原样保留，实例高度由展开定稿
     */
    public function addTemplateContentLayer(AbstractLayer $contentLayer): static
    {
        $contentLayer->setWidth($this->getWidth());
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

    /**
     * 模板行内格解码（TableLayer V2）：走高度豁免装配；
     * 普通 fromGraph 沿用 V1 高度耦合
     */
    public static function templateFromGraph(array $graph): static
    {
        $cell = parent::fromGraph($graph);

        if (!empty($graph['content'])) {
            $cell->addTemplateContentLayer(LayerFactory::fromGraph($graph['content']));
        }

        return $cell;
    }
}
