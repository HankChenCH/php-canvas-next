<?php

namespace HankChen\CanvasNext\Layer;

use InvalidArgumentException;

/**
 * 图层工厂：按 graph 节点的 type 标识重建对应图层实例
 */
final class LayerFactory
{
    private const TYPE_TO_CLASS = [
        ImageLayer::TYPE => ImageLayer::class,
        TextLayer::TYPE => TextLayer::class,
        QrCodeLayer::TYPE => QrCodeLayer::class,
        TableLayer::TYPE => TableLayer::class,
        TableRowLayer::TYPE => TableRowLayer::class,
        TableCellLayer::TYPE => TableCellLayer::class,
    ];

    /**
     * @throws InvalidArgumentException 未知图层类型
     */
    public static function fromGraph(array $graph): AbstractLayer
    {
        $type = $graph['type'] ?? '';
        $class = self::TYPE_TO_CLASS[$type]
            ?? throw new InvalidArgumentException("未知图层类型: {$type}");

        return $class::fromGraph($graph);
    }
}
