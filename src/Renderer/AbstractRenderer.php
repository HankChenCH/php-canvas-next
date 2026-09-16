<?php

namespace HankChen\CanvasNext\Renderer;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\RendererInterface;
use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use InvalidArgumentException;

/**
 * 渲染器模板基类：通用逻辑（资源物化 → 建渲染面 → 遍历/分派 → 容器下钻 → 收尾）
 *
 * 遍历时维护坐标偏移栈，所有图层直接绘制在同一渲染面上；
 * 绘制原语（drawRect/drawImage/drawText）由具体后端实现
 */
abstract class AbstractRenderer implements RendererInterface
{
    public function __construct(private readonly ResourceResolver $resolver)
    {
    }

    /**
     * 渲染整棵结构树
     */
    final public function render(Canvas $canvas): mixed
    {
        $this->resolver->resolve($canvas);

        $this->begin($canvas->getWidth(), $canvas->getHeight());
        foreach ($canvas->getLayers() as $layer) {
            $this->paint($layer, 0, 0, $canvas->getWidth(), $canvas->getHeight());
        }

        return $this->end();
    }

    /**
     * 便捷：以图层自身尺寸为渲染面渲染单个图层
     */
    public function renderLayer(AbstractLayer $layer): mixed
    {
        $this->resolver->resolveLayer($layer);

        $this->begin($layer->getWidth(), $layer->getHeight());
        $this->paint($layer, 0, 0, $layer->getWidth(), $layer->getHeight());

        return $this->end();
    }

    /**
     * 在绝对坐标 (originX, originY) 处按图层自身定位设定绘制
     * （锚点相对 parentWidth×parentHeight 解析）
     */
    private function paint(AbstractLayer $layer, int $originX, int $originY, int $parentWidth, int $parentHeight): void
    {
        [$anchor, $x, $y] = $layer->getPosition();
        [$anchorX, $anchorY] = PositionResolver::resolve(
            $anchor,
            $parentWidth,
            $parentHeight,
            $layer->getWidth(),
            $layer->getHeight()
        );

        $absX = $originX + $anchorX + $x;
        $absY = $originY + $anchorY + $y;

        match (true) {
            $layer instanceof TableLayer => $this->paintTable($layer, $absX, $absY),
            $layer instanceof TableRowLayer => $this->paintRow($layer, $absX, $absY),
            $layer instanceof TableCellLayer => $this->paintCell($layer, $absX, $absY),
            $layer instanceof ImageLayer => $this->paintImage($layer, $absX, $absY),
            $layer instanceof TextLayer => $this->paintText($layer, $absX, $absY),
            $layer instanceof QrCodeLayer => $this->paintQrCode($layer, $absX, $absY),
            default => throw new InvalidArgumentException('未知图层类型: ' . get_class($layer)),
        };
    }

    private function paintTable(TableLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $posy = $y;
        foreach ($layer->getRows() as $row) {
            $this->paint($row, $x, $posy, $layer->getWidth(), $layer->getHeight());
            $posy += $row->getHeight();
        }
    }

    private function paintRow(TableRowLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $posx = $x;
        foreach ($layer->getCells() as $cell) {
            $this->paint($cell, $posx, $y, $layer->getWidth(), $layer->getHeight());
            $posx += $cell->getWidth();
        }
    }

    private function paintCell(TableCellLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        if ($layer->getContentLayer() !== null) {
            $this->paint($layer->getContentLayer(), $x, $y, $layer->getWidth(), $layer->getHeight());
        }
    }

    private function paintImage(ImageLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $src = $layer->getResolvedSrc();
        if ($src !== null) {
            [$originX, $originY] = $layer->getImageOrigin();
            $this->drawImage($src, $x + $originX, $y + $originY, $layer->getContentWidth(), $layer->getContentHeight());
        }
    }

    private function paintText(TextLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $padding = $layer->getPadding();
        [$originX, $originY] = $layer->getTextOrigin();
        $posx = $x + $padding['left'] + $originX;
        $posy = $y + $padding['top'] + $originY;

        foreach ($layer->getLines() as $line) {
            $this->drawText(
                $line,
                $posx,
                $posy,
                $layer->getResolvedFont(),
                $layer->getFontSize(),
                $layer->getFontColor(),
                $layer->getHorizontalAlign(),
                $layer->getVerticalAlign(),
                $layer->getAngle()
            );
            $posy += $layer->lineHeightPx();
        }
    }

    private function paintQrCode(QrCodeLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $src = $layer->getResolvedSrc();
        if ($src !== null) {
            // 二维码按宽度正方形铺放（与旧库 QrCodeLayer 行为一致）
            $this->drawImage($src, $x, $y, $layer->getWidth(), $layer->getWidth());
        }
    }

    /** 创建渲染面（位图 / PDF 文档等） */
    abstract protected function begin(int $width, int $height): void;

    /** 收尾并返回渲染产物 */
    abstract protected function end(): mixed;

    /**
     * 绘制矩形盒：背景色 + 四边边框
     *
     * @param array{top: ?array, bottom: ?array, left: ?array, right: ?array} $border
     */
    abstract protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void;

    /** 绘制图片：缩放裁切至 width×height 后放置于 (x, y) */
    abstract protected function drawImage(string $src, int $x, int $y, int $width, int $height): void;

    /** 绘制单行文本，(x, y) 为 align 语义下的锚点 */
    abstract protected function drawText(
        string $line,
        int $x,
        int $y,
        string $fontFile,
        int $fontSize,
        string $fontColor,
        string $horizontalAlign,
        string $verticalAlign,
        int $angle
    ): void;
}
