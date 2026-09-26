<?php

namespace HankChen\CanvasNext\Contracts;

/**
 * 数据表达式求值器（策略注入点，spec §3.6）：与 LineBreakerInterface /
 * TextMeasurerFactoryInterface / DownloaderInterface 同构的可替换策略
 *
 * 接口形态 = (插值模板串, 上下文) → 求值结果。契约层钉定、不可被替换的部分：
 * - 门控（valueType 标记）与字段类错误策略在填充器（CanvasHydrator）侧施加
 * - 上下文形状（spec §3.2/§3.3）：'row' 键 = 当前行命名空间（{{row.x}} 点路径下钻）；
 *   裸名 = 全局数据集根键；'$index'（自 1 起）与 '$root.' 为保留名。
 *   'row.' 前缀在无 'row' 键的上下文求值必须报错（expression_row_outside_loop）
 * - 缺字段/null 的信号 = 求值为空串（文案兜底 / 资源报错由填充器按字段类施加）
 * - 类型不匹配（数组/对象落到标量位）必须抛
 *   Exception\HydrateException(code: expression_type_mismatch)
 *
 * 默认实现 HankChen\CanvasNext\Expression\InterpolationEvaluator（零依赖受限插值）。
 * 一致性边界：三端共享 fixture 只锁默认实现；自定义求值器的服务端渲染可能与
 * 编辑器预览漂移（与断行/度量注入同款取舍）
 */
interface ExpressionEvaluatorInterface
{
    /**
     * @param array $context 求值上下文（可导航数组；行上下文含 'row' 与 '$index'）
     * @return string 插值产物（片段求值结果按字符串拼接）
     */
    public function evaluate(string $template, array $context): string;
}
