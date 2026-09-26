<?php

namespace HankChen\CanvasNext\Layer;

use HankChen\CanvasNext\Exception\DecodeException;

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
        TableRowTemplate::TYPE => TableRowTemplate::class,
        TableCellLayer::TYPE => TableCellLayer::class,
    ];

    /**
     * @throws DecodeException 未知图层类型（code: unknown_layer_type）
     */
    public static function fromGraph(array $graph): AbstractLayer
    {
        $type = $graph['type'] ?? '';
        $class = self::TYPE_TO_CLASS[$type]
            ?? throw new DecodeException('unknown_layer_type', "unknown_layer_type: {$type}");

        return $class::fromGraph($graph);
    }
}
