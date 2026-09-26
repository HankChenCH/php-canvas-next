<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 表格行模板（TableLayer V2，spec §2）：单行循环体声明，
 * 仅合法出现在 TableLayer 的 template 字段内（出现在 canvas 图层序列 /
 * rows / cells / content 属非法 wire，工厂虽能解码，契约禁止）
 *
 * 装配语义（spec §2.3）：宽度耦合沿用（行宽=表宽、内容宽=格宽，列版式与数据无关）、
 * 高度耦合全豁免（行/格/内容的声明高与 autoHeight 标志原样保留——
 * 占位内容不参与 V1 高度耦合，实例高度由填充阶段按真实数据定稿）
 */
class TableRowTemplate extends AbstractLayer
{
    public const TYPE = 'TableRowTemplate';

    protected string $name = self::TYPE;

    /** @var TableCellLayer[] */
    private array $cells = [];

    /**
     * 模板装配：只挂格，不做行高耦合（区别于 TableRowLayer::addCell 的行高取最高格）
     */
    public function addCell(TableCellLayer $cell): static
    {
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
            $row->addCell(TableCellLayer::templateFromGraph($cellGraph));
        }

        return $row;
    }
}
