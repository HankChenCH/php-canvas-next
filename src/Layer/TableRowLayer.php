<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 表格行图层：单元格容器，addCell 时行高取最高单元格
 */
class TableRowLayer extends AbstractLayer
{
    public const TYPE = 'TableRowLayer';

    protected string $name = self::TYPE;

    /** @var TableCellLayer[] */
    private array $cells = [];

    public function addCell(TableCellLayer $cell): static
    {
        $cellHeight = $cell->getHeight();
        if ($this->getHeight() < $cellHeight) {
            $this->setHeight($cellHeight);
        }

        $this->cells[] = $cell;

        return $this;
    }

    /** @return TableCellLayer[] */
    public function getCells(): array
    {
        return $this->cells;
    }

    public function graph(): array
    {
        $graph = parent::graph();

        $graph['cells'] = array_map(
            static fn (TableCellLayer $cell) => $cell->graph(),
            $this->cells
        );

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $row = parent::fromGraph($graph);

        foreach ($graph['cells'] ?? [] as $cellGraph) {
            $row->addCell(TableCellLayer::fromGraph($cellGraph));
        }

        return $row;
    }
}
