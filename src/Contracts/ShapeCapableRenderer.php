<?php

namespace HankChen\CanvasNext\Contracts;

/**
 * 形状能力口（ADR 0026）：渲染后端可选实现的加法原语对——
 * 椭圆绘制（fill + stroke 一体）与椭圆掩码 blit（掩码 = 内切于给定矩形的椭圆，
 * 分派层传正方形即圆）。原语零形状语义、零 fit 语义，几何全在分派层
 * （ADR 0024 方向延续）；分派层以 instanceof 探测本接口，未实现本接口的
 * 后端对 clip=circle 声明降级矩形路径——既有后端的既有五原语面与无 clip
 * 声明的渲染字节面零改动
 */
interface ShapeCapableRenderer
{
    /**
     * 椭圆绘制原语：在 (x, y) 起的 width×height 外接矩形内画内切椭圆。
     * fillColor 填充（null/空串跳过）、stroke 描边（null 不描，形态同边框边目）
     *
     * @param array{width: int, color: string}|null $stroke
     */
    public function drawEllipse(int $x, int $y, int $width, int $height, ?string $fillColor, ?array $stroke): void;

    /**
     * 掩码 blit 原语（drawImage 的掩码形态）：双矩形 blit（语义与 drawImage
     * 完全一致，含 sw/sh ≤ 0 整图哨兵）后，以 (maskX, maskY) 起的
     * maskW×maskH 外接矩形的内切椭圆为掩码裁切——掩码外透明。
     * 掩码矩形是画布绝对坐标，与目标矩形独立（分派层传正方形即圆）
     */
    public function drawImageMasked(
        string $src,
        int $sx,
        int $sy,
        int $sw,
        int $sh,
        int $dx,
        int $dy,
        int $dw,
        int $dh,
        int $maskX,
        int $maskY,
        int $maskW,
        int $maskH
    ): void;
}
