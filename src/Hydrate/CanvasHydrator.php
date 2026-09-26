<?php

namespace HankChen\CanvasNext\Hydrate;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;

/**
 * hydrate 步骤的固定编排器（数据填充，spec §3）：编译期纯结构步骤，hydrate(Canvas, dataset): Canvas
 *
 * 【与 React SSR hydration 无关】hydrate = 数据填充（更名自「展开 / expand」）。
 *
 * - 全画布标记字段求值（valueType 门控；独立图层 = 根上下文，行循环 = 行上下文）
 * - 模板表实例化（rowsPath 取行 → 逐行求值 → V1 高度耦合重放，宽在声明态同步、高在此定稿）
 * - 产出具体树：无模板、无表达式、高度定稿；求值结果永不回写源 graph
 *
 * 数据集裁决（spec 未定义态的裁定）：dataset === null = 未绑数据集，全画布恒等直通——
 * 含模板表的画布不填充，模板表以声明态进入渲染、按零行空壳呈现
 * （与「空数组 = 合法零行、模板空数据不特殊」同精神）；传 [] 或具体数据 = 已绑，
 * 填充语义全量生效（含 rows_path_invalid 等结构性报错）
 *
 * 实现走 graph 层：对 canvas->graph() 做求值改写后经 Canvas::fromGraph 重建，
 * 尺寸耦合重放由既有解码路径承担。嵌套模板表（模板格内容含 TableLayer）
 * 的 rowsPath 以当前行数据为取数范围（行相对），行字段指向最内层行。
 *
 * 断行/度量不做注入：TextLayer 自携带 LineBreaker/Measurer 策略，
 * 高度定稿经 TextLayer::getHeight() 纯布局函数完成
 */
class CanvasHydrator
{
    public function __construct(private ?ExpressionEvaluatorInterface $evaluator = null)
    {
        $this->evaluator ??= new InterpolationEvaluator();
    }

    public function hydrate(Canvas $canvas, mixed $dataset): Canvas
    {
        // 未绑数据集：不跑求值器，全字面（标记字段按 value 镜像显示原文），存量行为零变化
        if ($dataset === null) {
            return $canvas;
        }

        $graph = $canvas->graph();
        if (!$this->graphHasBindings($graph['layers'] ?? [])) {
            return $canvas; // 无标记无模板：恒等直通
        }

        $root = $this->normalizeDataset($dataset);
        $this->assertReservedRootKeys($root);

        foreach ($graph['layers'] as &$node) {
            $this->hydrateNode($node, $root, null);
        }
        unset($node);

        return Canvas::fromGraph($graph);
    }

    /** 图上是否存在表达式标记或模板表（决定是否进入填充流程） */
    private function graphHasBindings(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $data = $node['data'] ?? null;
            if (is_array($data) && ($data['valueType'] ?? null) === 'ExpressionValue') {
                return true;
            }
            if (isset($node['template'])) {
                return true;
            }

            foreach (['rows', 'cells'] as $childrenKey) {
                if (isset($node[$childrenKey]) && is_array($node[$childrenKey])
                    && $this->graphHasBindings($node[$childrenKey])) {
                    return true;
                }
            }
            if (isset($node['content']) && is_array($node['content'])
                && $this->graphHasBindings([$node['content']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 填充单个 graph 节点：求值标记字段 + 结构子树递归（行/格/内容同作用域传递）
     *
     * @param array|null $rowNamespace 行上下文（'row' + '$index'），null = 非行上下文
     */
    private function hydrateNode(array &$node, array $root, ?array $rowNamespace): void
    {
        if (($node['type'] ?? '') === 'TableLayer'
            && isset($node['template']) && is_array($node['template'])) {
            $this->hydrateTemplateNode($node, $root, $rowNamespace);

            return;
        }

        $this->evaluateNode($node, $root, $rowNamespace);

        foreach (['rows', 'cells'] as $childrenKey) {
            if (isset($node[$childrenKey]) && is_array($node[$childrenKey])) {
                foreach ($node[$childrenKey] as &$child) {
                    $this->hydrateNode($child, $root, $rowNamespace);
                }
                unset($child);
            }
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $this->hydrateNode($node['content'], $root, $rowNamespace);
        }
    }

    /**
     * 模板表实例化：rowsPath 定位行数组 → 每行求值 → 模板行改写为具体行。
     * 行高定稿由 Canvas::fromGraph 的 V1 耦合重放完成（声明态豁免在此闭环）
     */
    private function hydrateTemplateNode(array &$node, array $root, ?array $rowNamespace): void
    {
        $rowsPath = $node['data']['rowsPath'] ?? null;
        if (!is_string($rowsPath) || $rowsPath === '') {
            throw new HydrateException('rows_path_invalid', 'rows_path_invalid: 模板表缺少有效 data.rowsPath');
        }

        // 取数范围：顶层 = 数据集根；嵌套模板表 = 当前行数据（行相对）
        $rows = $rowNamespace !== null ? $rowNamespace['row'] : $root;
        foreach (explode('.', $rowsPath) as $segment) {
            if (!is_array($rows) || !array_key_exists($segment, $rows)) {
                throw new HydrateException('rows_path_invalid', "rows_path_invalid: {$rowsPath}");
            }
            $rows = $rows[$segment];
        }
        if (!is_array($rows)) {
            throw new HydrateException('rows_path_invalid', "rows_path_invalid: {$rowsPath}");
        }

        $template = $node['template'];
        $instances = [];
        $serial = 0;
        foreach ($rows as $item) {
            $rowNode = $template;
            $this->hydrateNode($rowNode, $root, ['row' => $item, '$index' => ++$serial]);
            $rowNode['type'] = TableRowLayer::TYPE;
            $instances[] = $rowNode;
        }

        $node['rows'] = $instances;
        unset($node['template'], $node['data']);
    }

    /**
     * 标记字段求值：求值结果替换为字面值并解除标记（填充产物无表达式）。
     * 缺字段信号（空串）由本层按字段类施加策略：文案兜底空串、资源类报错
     */
    private function evaluateNode(array &$node, array $root, ?array $rowNamespace): void
    {
        $data = $node['data'] ?? null;
        if (!is_array($data)
            || ($data['valueType'] ?? null) !== 'ExpressionValue'
            || !isset($data['expression'])) {
            return;
        }

        $type = $node['type'] ?? '';
        if (!in_array($type, [TextLayer::TYPE, ImageLayer::TYPE, QrCodeLayer::TYPE], true)) {
            return; // 绑定面仅三类内容层（spec §3.1）
        }

        $context = $rowNamespace === null ? $root : $rowNamespace + $root;
        $result = $this->evaluator->evaluate((string) $data['expression'], $context);

        if (($type === ImageLayer::TYPE || $type === QrCodeLayer::TYPE) && $result === '') {
            // 资源类缺字段/null/求值空串一律报错：空 URL/空码无意义，fail-fast 优于错误后移到物化
            throw new HydrateException('expression_empty_resource', "expression_empty_resource: {$data['expression']}");
        }

        $data['value'] = $result;
        $data['valueType'] = 'StaticValue';
        if ($type === TextLayer::TYPE) {
            $data['expression'] = '';   // TextLayer 恒写 expression 键（现状形态）
        } else {
            unset($data['expression']); // Image/Qr 条件写键
        }
        $node['data'] = $data;
    }

    /**
     * 数据集规范化：数组/stdClass 视为可导航对象，嵌套对象统一转数组；
     * 其余形状按空数据集处理（查找全部落空，rowsPath 报 rows_path_invalid）
     */
    private function normalizeDataset(mixed $dataset): array
    {
        if ($dataset instanceof \stdClass) {
            $dataset = (array) $dataset;
        }

        return is_array($dataset) ? $this->normalizeArray($dataset) : [];
    }

    private function normalizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \stdClass) {
                $data[$key] = $this->normalizeArray((array) $value);
            } elseif (is_array($value)) {
                $data[$key] = $this->normalizeArray($value);
            }
        }

        return $data;
    }

    /** 数据集根级键名不得为 row 或 $ 前缀，否则 {{row.x}} 的 row 段歧义（spec §3.2） */
    private function assertReservedRootKeys(array $root): void
    {
        foreach ($root as $key => $_) {
            if (is_string($key) && ($key === 'row' || str_starts_with($key, '$'))) {
                throw new HydrateException('reserved_root_key', "reserved_root_key: 数据集根级键名不得为 row 或 \$ 前缀（{$key}）");
            }
        }
    }
}
