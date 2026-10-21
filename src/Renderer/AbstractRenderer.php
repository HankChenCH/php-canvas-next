<?php

namespace HankChen\CanvasNext\Renderer;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\RendererInterface;
use HankChen\CanvasNext\Contracts\ShapeCapableRenderer;
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
 * 检查点轮询 throwIfRequested()——根层循环每图层前、paintTable 行循环每行前；
 * 多页 walk 模板 renderPages 另设页间检查点一处（i > 0、下一页 begin 前，工票 01）
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
     * 跳过在绘制分派之前，隐藏层的惰性物化不会发生；容器子层不下钻显隐（显隐面仅根图层）。
     * 页内段（建面 → 背景铺装 → 逐层 paint）抽取为 paintPage 与多页 renderPages 共享
     * （工票 01）——本方法 = paintPage + end 两段式，调用序与抽取前逐项一致
     */
    final public function render(Cancellation $ctx, Canvas $canvas): mixed
    {
        $this->paintPage($ctx, $canvas);

        return $this->end();
    }

    /**
     * 多页 walk 模板（工票 01，镜像 go renderer.RenderPages 定稿语义）：消费已分页
     * 页序列（DocumentCompiler::compile(...)->canvases 同构形态，帧序×页序扁平），
     * 逐页复用页内段 paintPage（begin → 背景铺装 → 逐层 paint），全部页完成后统一
     * end() 一次（n 页 = n 次 begin + 1 次 end）。背景逐页显式携带（ADR 0022 分页页
     * 产物自带 background）；visible 逐页过滤（与单页 render 同语义，隐藏层不触达
     * 任何原语与物化）。
     * ctx 检查点：页间一处新增（i > 0 时、下一页 begin 前）——首页前不另设（与单页
     * render 同构）；页内既有检查点（根层每图层前/表行每行前/物化入口）不动，逐页生效。
     * 失败语义：任一页任一原语/物化失败即抛、end 不达、整文档无产物逃逸（无半成品）。
     * 空页序列属调用侧用法错误：普通异常不设 code（「0 begin + 1 end」的空文档语义
     * 不由模板代答，调用方 CompileResult::canvases 恒非空）
     *
     * @param list<Canvas> $pages 已分页页序列（帧序×页序）
     */
    final public function renderPages(Cancellation $ctx, array $pages): mixed
    {
        if ($pages === []) {
            throw new InvalidArgumentException('renderPages: 页序列为空，至少需要一页');
        }

        $i = 0;
        foreach ($pages as $page) {
            if ($i > 0) {
                // 页间检查点（本模板唯一新增）：先于下一页的建面与背景物化掐断已取消/到期的 ctx
                $ctx->throwIfRequested();
            }
            $this->paintPage($ctx, $page);
            $i++;
        }

        return $this->end();
    }

    /**
     * 页内段（单页编排）：建面 → 背景铺装 → 根层逐层 paint（不含 end 收尾）。
     * 单页 render() 与多页 renderPages() 共享的页内编排——多页是本段的页循环外扩；
     * 原样抽取自 render()（工票 01 零行为变化红线：调用序与抽取前逐项一致）。
     * 根层循环跳过 visible=false（layer-panel-ux 工单 01 隐藏语义 = 最终输出排除）：
     * 跳过在绘制分派之前，隐藏层的惰性物化不会发生；容器子层不下钻显隐（显隐面仅根图层）
     */
    private function paintPage(Cancellation $ctx, Canvas $canvas): void
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
    }

    /**
     * 画布背景铺装（ADR 0022 / spec §4）：插入点 = 根层循环之前，先色后图——
     * 背景之下无物（画布初始透明保留），背景图透明区露背景色。
     * 背景图画布级惰性物化（绘制分派前、失败即抛、产物整体丢弃）；铺装恒 cover（ADR 0022）：
     * 分派点读内在尺寸（与 resolve 同批 I/O）算等比居中裁窗（与图片臂共用同一公式，ADR 0024）
     * 后 blit 进画布矩形，读取失败统一退化 fill 形态（真损坏图在后端解码抛，结局 parity）。
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
            $size = $this->intrinsicSize($src);
            [$sx, $sy, $sw, $sh] = $size !== null
                ? ImageLayer::coverSourceWindow($canvas->getWidth(), $canvas->getHeight(), $size[0], $size[1])
                : [0, 0, 0, 0];
            $this->drawImage($src, $sx, $sy, $sw, $sh, 0, 0, $canvas->getWidth(), $canvas->getHeight());
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

    /**
     * 图片臂全 fit 分派（ADR 0024 / spec §4）：原语是双矩形 blit、零 fit 语义，
     * 四档 fit 的几何全部在此算定（白名单分派，未知值落 cover 臂）——
     * cover 等比居中裁窗 / fill 整图（与 cover 同门 dstRect，唯一差别在 srcRect）/
     * contain 收缩（ADR 0023 语义不动）/ tile 可见块逐块 blit。
     * clip 声明（ADR 0026）改写绘制形态：circle 档且后端实现形状能力口走圆形三步
     * （背景填进内切圆、内容按 fit 绘出后遮罩 blit、边框沿圆周描环），矩形盒
     * rect/drawImage 路径不参与；未知 clip 值与后端缺能力口降级矩形
     * （fit 未知值纪律同门）。
     * 内在尺寸读取为全 fit 前置：绘制分派点一次（getimagesize 属绘制分派期 I/O，
     * 与 resolve 同批，@ 抑制损坏图告警），读取失败统一退化 fill 形态——
     * 真损坏图在后端解码处照旧抛（结局不变）；「解码可过而尺寸探测不识」的
     * 窄缝源（如部分 SVG）静默呈现 fill 形态，为 ADR 0024 明示接受面。
     * EXIF 方向图返回观察方向尺寸（07 票裁决），分派几何与解码归正位图同系
     */
    private function paintImage(Cancellation $ctx, ImageLayer $layer, int $x, int $y): void
    {
        // 绘制分派前按需物化（spec §4.3）：失败即抛，渲染面丢弃
        $this->resolver->resolveLayer($ctx, $layer);

        // 圆形档（ADR 0026）：clip 白名单 + 后端能力口探测双门，否则矩形路径照旧
        if ($layer->getClip() === ImageLayer::CLIP_CIRCLE && $this instanceof ShapeCapableRenderer) {
            $this->paintImageCircle($layer, $x, $y);

            return;
        }

        $this->drawRect($x, $y, $layer->getWidth(), $layer->getHeight(), $layer->getBackground(), $layer->getBorder());

        $src = $layer->getResolvedSrc();
        if ($src === null) {
            return;
        }

        // 矩形路径：fit 分派层算定几何、drawImage 原语直绘
        $this->paintFitBlits($layer, $src, $x, $y, fn (
            string $src,
            int $sx,
            int $sy,
            int $sw,
            int $sh,
            int $dx,
            int $dy,
            int $dw,
            int $dh
        ) => $this->drawImage($src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh));
    }

    /**
     * 圆形档三步（ADR 0026，完整圆形框——整层渲染面裁进内切圆）：
     * ① 背景色填充进内切圆（矩形盒 drawRect 不画——背景/边框都不落矩形盒）；
     * ② 内容按 fit 绘出后遮罩 blit 进圆（fit 几何照旧，只换成掩码形态原语，
     * 掩码矩形 = 内切圆矩形，内在尺寸不可用退化 fill 形态再裁圆）；
     * ③ 边框沿圆周描成环（描边参数解析规则见 ImageLayer::getCircleStroke）。
     * 内切圆边长 ≤0（内容区被 padding 吃满）零几何零绘制。
     * 本方法私有且仅由 paintImage 的能力口探测（instanceof）命中臂调用——
     * drawEllipse/drawImageMasked 只在实现了 ShapeCapableRenderer 的后端上存在
     */
    private function paintImageCircle(ImageLayer $layer, int $x, int $y): void
    {
        [$cx, $cy, $side] = $layer->getInscribedCircleRect();
        if ($side <= 0) {
            return;
        }

        $circleX = $x + $cx;
        $circleY = $y + $cy;
        $this->drawEllipse($circleX, $circleY, $side, $side, $layer->getBackground(), null);

        $src = $layer->getResolvedSrc();
        if ($src !== null) {
            $this->paintFitBlits($layer, $src, $x, $y, fn (
                string $src,
                int $sx,
                int $sy,
                int $sw,
                int $sh,
                int $dx,
                int $dy,
                int $dw,
                int $dh
            ) => $this->drawImageMasked($src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $circleX, $circleY, $side, $side));
        }

        $stroke = $layer->getCircleStroke();
        if ($stroke !== null) {
            $this->drawEllipse($circleX, $circleY, $side, $side, null, $stroke);
        }
    }

    /**
     * 全 fit 白名单分派（cover/fill/contain/tile + 内在尺寸退化），blit 落点由
     * 调用臂注入（矩形路径 drawImage / 圆形路径 drawImageMasked）——
     * 两形态共用同一份分派几何防数学漂移（先绘后裁，fit 与 clip 正交）
     *
     * @param \Closure(string, int, int, int, int, int, int, int, int): void $blit
     */
    private function paintFitBlits(ImageLayer $layer, string $src, int $x, int $y, \Closure $blit): void
    {
        // cover/fill 共用目标矩形（同门）：getImageOrigin + 内容宽高
        [$originX, $originY] = $layer->getImageOrigin();
        $dstX = $x + $originX;
        $dstY = $y + $originY;
        $dstWidth = $layer->getContentWidth();
        $dstHeight = $layer->getContentHeight();

        $size = $this->intrinsicSize($src);
        if ($size === null) {
            // 内在尺寸不可用：整图拉伸进目标矩形（fill 形态），srcRect 零哨兵由后端取归正位图整幅
            $blit($src, 0, 0, 0, 0, $dstX, $dstY, $dstWidth, $dstHeight);

            return;
        }
        [$imgWidth, $imgHeight] = $size;

        $fit = $layer->getFit();
        if ($fit === ImageLayer::FIT_CONTAIN) {
            // contain（ADR 0023）：按内在比例收缩目标矩形再整图 blit
            [$cx, $cy, $cw, $ch] = $layer->getContainRect($imgWidth, $imgHeight);
            $blit($src, 0, 0, $imgWidth, $imgHeight, $x + $cx, $y + $cy, $cw, $ch);

            return;
        }

        if ($fit === ImageLayer::FIT_TILE) {
            // tile：可见块逐块 blit（src/dst 可见子矩形 1:1），裁切纯几何完成、原语零裁剪参数
            foreach ($layer->getTileRects($imgWidth, $imgHeight) as $block) {
                [$sx, $sy, $sw, $sh] = $block['src'];
                [$dx, $dy, $dw, $dh] = $block['dst'];
                $blit($src, $sx, $sy, $sw, $sh, $x + $dx, $y + $dy, $dw, $dh);
            }

            return;
        }

        if ($fit === ImageLayer::FIT_FILL) {
            // fill：整图进与 cover 相同的目标矩形（非等比拉伸，不裁切不留空隙）
            $blit($src, 0, 0, $imgWidth, $imgHeight, $dstX, $dstY, $dstWidth, $dstHeight);

            return;
        }

        // cover（缺省/显式 cover 归一/未知值白名单外降级）：等比居中裁窗
        [$sx, $sy, $sw, $sh] = $layer->getCoverRect($imgWidth, $imgHeight);
        $blit($src, $sx, $sy, $sw, $sh, $dstX, $dstY, $dstWidth, $dstHeight);
    }

    /**
     * 源图内在尺寸（全 fit 前置，ADR 0024）：绘制分派点一次读取，
     * 不可用（损坏/非图文件/零维）返回 null——调用方统一退化 fill 形态。
     * EXIF 方向图返回观察方向尺寸（orientation 5..8 宽高互换，07 票裁决）：
     * 位图后端解码即归正（intervention v4 autoOrientation 双驱动默认），
     * 分派几何须与归正位图同系；getimagesize 返回的是原始存储尺寸。
     * exif 扩展缺席时宽容退回原始尺寸（与损坏图同一退化臂）
     *
     * @return array{0: int, 1: int}|null [width, height]
     */
    private function intrinsicSize(string $src): ?array
    {
        $size = @getimagesize($src);
        if (!is_array($size) || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        if (($size[2] ?? null) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            if ($orientation >= 5 && $orientation <= 8) {
                [$size[0], $size[1]] = [$size[1], $size[0]];
            }
        }

        return [$size[0], $size[1]];
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
            // QR 产物恒正方形（ADR 0015）、目标恒正方形——cover 窗 = 整图，srcRect 零哨兵
            // 整图 blit、零内在尺寸读取（ADR 0024）；边长 ≤0 由 blit 后端防护只画盒
            [$originX, $originY] = $layer->getQrOrigin();
            $side = $layer->getContentSide();
            $this->drawImage($src, 0, 0, 0, 0, $x + $originX, $y + $originY, $side, $side);
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

    /**
     * 绘制图片（双矩形 blit，ADR 0024）：把源图中 (sx, sy) 起的 sw×sh 矩形
     * （与分派层 intrinsicSize 同坐标系——EXIF 方向图为观察方向，07 票裁决）
     * 拉伸搬运至目标 (dx, dy) 处的 dw×dh 矩形。
     * 原语零 fit 语义、零内在尺寸读取——cover 裁窗 / contain 收缩 / fill 整图 /
     * tile 网格全部是分派层纯几何（PHP 权威端 paintImage，三端同构）。
     * sw 或 sh ≤ 0 为整图哨兵：取解码归正位图整幅（分派层内在尺寸不可用的
     * 退化形态，接口 major 契约的组成部分）；dw/dh ≤0 后端防护不画
     */
    abstract protected function drawImage(string $src, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh): void;

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
