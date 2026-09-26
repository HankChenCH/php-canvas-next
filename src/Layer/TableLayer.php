<?php

namespace HankChen\CanvasNext\Layer;

use HankChen\CanvasNext\Exception\DecodeException;
use InvalidArgumentException;

/**
 * 表格图层：行容器，addRow 时同步行宽
 *
 * V2 模板态（spec §2）：声明 TableRowTemplate + data.rowsPath，
 * 与 rows XOR 互斥——wire 上 template/data 为条件写键（模板态不写 rows，
 * V1 态不写 template/data，字节面保三端 parity）；填充（hydrate）在渲染前的独立
 * 纯结构步骤完成，填充产物不回写本图层（往返恒等对象 = 声明态）
 */
class TableLayer extends AbstractLayer
{
    public const TYPE = 'TableLayer';

    protected string $name = self::TYPE;

    /** @var TableRowLayer[] */
    private array $rows = [];

    private int $contentBoxHeight = 0;

    private ?TableRowTemplate $template = null;

    /** 取行路径（点路径字符串），仅模板态参与填充（spec §3.3） */
    private string $rowsPath = '';

    public function isOverHeight(TableRowLayer $row): bool
    {
        return $this->contentBoxHeight + $row->getHeight() > $this->getHeight();
    }

    /**
     * @throws InvalidArgumentException 模板态下禁止追加具体行（template ⊕ rows 互斥，
     *                                   API 误用属调用期错误，不占解码期异常类）
     */
    public function addRow(TableRowLayer $row): static
    {
        if ($this->template !== null) {
            throw new InvalidArgumentException('template_rows_conflict: 模板态表格不得追加具体行，请先 setTemplate(null)');
        }

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

    /**
     * 声明模板行（V2）：与 rows 互斥，采用清空切换语义——设置模板即清空既有行；
     * 宽度耦合沿用（行宽 = 表宽，关 autoWidth），高度耦合全豁免（spec §2.2/§2.3）。
     * 传 null 清除模板回到 V1 形态
     */
    public function setTemplate(?TableRowTemplate $template): static
    {
        if ($template === null) {
            $this->template = null;
            $this->rowsPath = '';

            return $this;
        }

        $template->setWidth($this->getWidth());
        $this->rows = [];
        $this->contentBoxHeight = 0;
        $this->template = $template;

        return $this;
    }

    public function getTemplate(): ?TableRowTemplate
    {
        return $this->template;
    }

    /**
     * 取行路径（点路径字符串）：填充时从数据集（嵌套模板表则从当前行数据）
     * 定位行数组；仅模板态参与填充与 wire 写键（spec §3.3）
     */
    public function setRowsPath(string $rowsPath): static
    {
        $this->rowsPath = $rowsPath;

        return $this;
    }

    public function getRowsPath(): string
    {
        return $this->rowsPath;
    }

    public function graph(): array
    {
        $graph = parent::graph();

        if ($this->template !== null) {
            // 模板态：条件写键 data/template，不写 rows（XOR，spec §2.2）；
            // rowsPath 未设时不写 data 键——不产出违反自身约束的中间 wire 形态
            if ($this->rowsPath !== '') {
                $graph['data'] = ['rowsPath' => $this->rowsPath];
            }
            $graph['template'] = $this->template->graph();

            return $graph;
        }

        // 无损：全量行结构（旧库此处只保留首行 rowTemplate）
        $graph['rows'] = array_map(
            static fn (TableRowLayer $row) => $row->graph(),
            $this->rows
        );

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $hasTemplate = array_key_exists('template', $graph) && $graph['template'] !== null;

        if ($hasTemplate && array_key_exists('rows', $graph)) {
            throw new DecodeException('template_rows_conflict', 'template_rows_conflict: template 与 rows 键不得同时出现');
        }

        if ($hasTemplate) {
            $rowsPath = $graph['data']['rowsPath'] ?? null;
            if (!is_string($rowsPath) || $rowsPath === '') {
                throw new DecodeException('rows_path_missing', 'rows_path_missing: 模板态表格缺少 data.rowsPath');
            }

            $table = parent::fromGraph($graph);
            $table->setTemplate(TableRowTemplate::fromGraph($graph['template']));
            $table->setRowsPath($rowsPath);

            return $table;
        }

        $table = parent::fromGraph($graph);

        foreach ($graph['rows'] ?? [] as $rowGraph) {
            $table->addRow(TableRowLayer::fromGraph($rowGraph));
        }

        return $table;
    }
}
