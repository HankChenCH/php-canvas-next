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
use HankChen\CanvasNext\Runtime\Cancellation;
use InvalidArgumentException;

/**
 * 渲染器模板基类：通用逻辑（建渲染面 → 遍历/分派 → 容器下钻 → 收尾）
 *
 * 遍历时维护坐标偏移栈，所有图层直接绘制在同一渲染面上；
 * 绘制原语（drawRect/drawImage/drawText）由具体后端实现。
 * 资源物化（spec §4.3）：渲染器无预遍历，绘制分派前按需惰性物化——
 * 失败即抛、渲染面丢弃、无产物逃逸（begin 已开的渲染面随异常丢弃）
 *
 * 渲染期控制面（ADR 0008）：$ctx 以显式首参随调用向下穿线（paint* 与 Go 同位），
 * 检查点轮询 throwIfRequested()——根层循环每图层前、paintTable 行循环每行前
 */
abstract class AbstractRenderer implements RendererInterface
{
    public function __construct(private readonly ResourceResolver $resolver)
    {
    }

    /**
     * 渲染整棵结构树
     *
     * 根层渲染循环跳过 visible=false（layer-panel-ux 工单 01 隐藏语义 = 最终输出排除）：
     * 跳过在绘制分派之前，隐藏层的惰性物化不会发生；容器子层不下钻显隐（显隐面仅根图层）
     */
    final public function render(Cancellation $ctx, Canvas $canvas): mixed
    {
        $this->begin($canvas->getWidth(), $canvas->getHeight());
        $this->paintBackground($ctx, $canvas);
        foreach ($canvas->getLayers() as $layer) {
            // 根层循环检查点：每图层分派前轮询取消信号
            $ctx->throwIfRequested();
            if (!$layer->isVisible()) {
                continue;
            }
            $this->paint($ctx, $layer, 0, 0, $canvas->getWidth(), $canvas->getHeight());
        }

        return $this->end();
    }

    /**
     * 画布背景铺装（ADR 0022 / spec §4）：插入点 = 根层循环之前，先色后图——
     * 背景之下无物（画布初始透明保留），背景图透明区露背景色。
     * 背景图画布级惰性物化（绘制分派前、失败即抛、产物整体丢弃），cover 铺满画布矩形
     * （drawImage 原语自带 cover 语义）；无背景零开销。
     * renderLayer() 单图层便捷入口不画背景（无画布矩形语义）
     */
    private function paintBackground(Cancellation $ctx, Canvas $canvas): void
    {
        $background = $canvas->getBackground();
        if ($background === null) {
            return;
        }

        $color = $background->getColor();
        if ($color !== null) {
            // 先铺色：复用 drawRect 原语，背景无边框
            $this->drawRect(0, 0, $canvas->getWidth(), $canvas->getHeight(), $color, []);
        }

        $image = $background->getImage();
        if ($image !== null) {
            $src = $this->resolver->resolveBackground($ctx, $image);
            $this->drawImage($src, 0, 0, $canvas->getWidth(), $canvas->getHeight());
        }
    }

    /**
     * 便捷：以图层自身尺寸为渲染面渲染单个图层
     * （不做显隐过滤——visible 语义属画布渲染循环，单图层渲染所见即所得）
     */
    public function renderLayer(Cancellation $ctx, AbstractLayer $layer): mixed
    {
        $this->begin($layer->getWidth(), $layer->getHeight());
        $this->paint($ctx, $layer, 0, 0, $layer->getWidth(), $layer->getHeight());

        return $this->end();
    }

    /**
     * 在绝对坐标 (originX, originY) 处按图层自身定位设定绘制
     * （锚点相对 parentWidth×parentHeight 解析）
     */
    private function paint(Cancellation $ctx, AbstractLayer $layer, int $originX, int $originY, int $parentWidth, int $parentHeight): void
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
            $layer instanceof TableLayer => $this->paintTable($ctx, $layer, $absX, $absY),
            $layer instanceof TableRowLayer => $this->paintRow($ctx, $layer, $absX, $absY),
            $layer instanceof TableCellLayer => $this->paintCell($ctx, $layer, $absX, $absY),
            $layer instanceof ImageLayer => $this->paintImage($ctx, $layer, $absX, $absY),
            $layer instanceof TextLayer => $this->paintText($ctx, $layer, $absX, $absY),
            $layer instanceof QrCodeLayer => $this->paintQrCode($ctx, $layer, $absX, $absY),
            default => throw new InvalidArgumentException('未知图层类型: ' . get_class($layer)),
        };
    }

    private function paintTable(Cancellation $ctx, TableLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $posy = $y;
        foreach ($layer->getRows() as $row) {
            // 行循环检查点：大表逐行轮询（真实长杆）
            $ctx->throwIfRequested();
            $this->paint($ctx, $row, $x, $posy, $layer->getWidth(), $layer->getHeight());
            $posy += $row->getHeight();
        }
    }

    private function paintRow(Cancellation $ctx, TableRowLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $posx = $x;
        foreach ($layer->getCells() as $cell) {
            $this->paint($ctx, $cell, $posx, $y, $layer->getWidth(), $layer->getHeight());
            $posx += $cell->getWidth();
        }
    }

    private function paintCell(Cancellation $ctx, TableCellLayer $layer, int $x, int $y): void
    {
        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        if ($layer->getContentLayer() !== null) {
            $this->paint($ctx, $layer->getContentLayer(), $x, $y, $layer->getWidth(), $layer->getHeight());
        }
    }

    private function paintImage(Cancellation $ctx, ImageLayer $layer, int $x, int $y): void
    {
        // 绘制分派前按需物化（spec §4.3）：失败即抛，渲染面丢弃
        $this->resolver->resolveLayer($ctx, $layer);

        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $src = $layer->getResolvedSrc();
        if ($src !== null) {
            if ($layer->getFit() === ImageLayer::FIT_CONTAIN) {
                // contain（ADR 0023）：按物化后源图内在比例收缩目标矩形再调原语
                // （getimagesize 属绘制分派期 I/O，与 resolve 同批，@ 抑制损坏图告警——
                // 读取失败按 cover 走，损坏图不因 fit 崩渲染）；未知 fit 值不在白名单，自然落 cover 分支
                $size = @getimagesize($src);
                if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
                    [$cx, $cy, $cw, $ch] = $layer->getContainRect($size[0], $size[1]);
                    $this->drawImage($src, $x + $cx, $y + $cy, $cw, $ch);

                    return;
                }
            }

            // cover 路径（缺省/未知值降级/尺寸读取失败共用）：原语自带 cover 语义，零回归
            [$originX, $originY] = $layer->getImageOrigin();
            $this->drawImage($src, $x + $originX, $y + $originY, $layer->getContentWidth(), $layer->getContentHeight());
        }
    }

    private function paintText(Cancellation $ctx, TextLayer $layer, int $x, int $y): void
    {
        $this->resolver->resolveLayer($ctx, $layer);

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

    private function paintQrCode(Cancellation $ctx, QrCodeLayer $layer, int $x, int $y): void
    {
        $this->resolver->resolveLayer($ctx, $layer);

        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $src = $layer->getResolvedSrc();
        if ($src !== null) {
            // 二维码内切于内容盒：边长 = min(内容区宽高)（quiet zone 语义——padding 留白
            // 即码外静区，露图层盒背景），对齐 + padding 定位（镜像 paintImage 语义）；
            // 边长 ≤0 由 drawImage 防护只画盒
            [$originX, $originY] = $layer->getQrOrigin();
            $side = $layer->getContentSide();
            $this->drawImage($src, $x + $originX, $y + $originY, $side, $side);
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
