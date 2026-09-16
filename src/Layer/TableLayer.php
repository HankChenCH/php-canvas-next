<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 表格图层：行容器，addRow 时同步行宽
 */
class TableLayer extends AbstractLayer
{
    public const TYPE = 'TableLayer';

    protected string $name = self::TYPE;

    /** @var TableRowLayer[] */
    private array $rows = [];

    private int $contentBoxHeight = 0;

    public function isOverHeight(TableRowLayer $row): bool
    {
        return $this->contentBoxHeight + $row->getHeight() > $this->getHeight();
    }

    public function addRow(TableRowLayer $row): static
    {
        $row->setWidth($this->getWidth());
        $this->rows[] = $row;
        $this->contentBoxHeight += $row->getHeight();

        return $this;
    }

    /** @return TableRowLayer[] */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function graph(): array
    {
        $graph = parent::graph();

        // 无损：全量行结构（旧库此处只保留首行 rowTemplate）
        $graph['rows'] = array_map(
            static fn (TableRowLayer $row) => $row->graph(),
            $this->rows
        );

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $table = parent::fromGraph($graph);

        foreach ($graph['rows'] ?? [] as $rowGraph) {
            $table->addRow(TableRowLayer::fromGraph($rowGraph));
        }

        return $table;
    }
}
