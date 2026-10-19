<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 图片图层：只记录原始引用（URL 或本地路径）；
 * 远程资源的下载物化由 ResourceResolver 在渲染前完成
 */
class ImageLayer extends AbstractLayer
{
    public const TYPE = 'ImageLayer';

    /**
     * 适配模式（ADR 0023/0024）：四档已知值——
     * 铺满裁切（cover，缺省）＝等比缩放铺满内容盒、溢出居中裁切；
     * 完整显示（contain）＝等比缩放至恰好完整落入内容盒、按对齐放置，空隙透明；
     * 拉伸铺满（fill）＝非等比缩放至恰好铺满内容盒，不裁切不留空隙（不保源图宽高比）；
     * 平铺（tile）＝按内在尺寸 1:1 原尺寸重复铺放，首块按对齐锚定、向右下无限重复、
     * 止于网格 ∩ 内容盒（末行末列半块截断，见根 CONTEXT.md「平铺（tile）」词条）
     */
    public const FIT_COVER = 'cover';

    public const FIT_CONTAIN = 'contain';

    public const FIT_FILL = 'fill';

    public const FIT_TILE = 'tile';

    protected string $name = self::TYPE;

    private ?string $rawImg = null;

    /**
     * 数据表达式标记（spec §3.1）：非 null = 已标记（valueType='ExpressionValue'），
     * src 由填充阶段按行求值；null = 字面直通
     */
    private ?string $expression = null;

    /** Resolver 物化后的本地路径，渲染端优先使用 */
    private ?string $resolvedSrc = null;

    /**
     * 适配模式声明（ADR 0023/0024）：静态声明不参与数据表达式，只影响绘制、不影响盒子布局；
     * null = cover 缺省；已知值 cover/contain/fill/tile，未知值宽容保存（渲染面按 cover 降级）
     */
    private ?string $fit = null;

    // 取值：left,center,right
    protected string $horizontalAlign = 'center';
    // 取值：top,center,bottom
    protected string $verticalAlign = 'center';

    public function setImage(?string $img): static
    {
        $this->rawImg = ($img === '' || $img === null) ? null : $img;
        $this->resolvedSrc = null;
        $this->expression = null; // 字面赋值解除标记

        return $this;
    }

    /**
     * 标记数据表达式（spec §3.1）：value 恒镜像表达式原文；
     * expression 键条件写键——未标记保持现状两键，保 Go↔PHP 字节 parity
     */
    public function setExpression(string $expression): static
    {
        $this->expression = $expression;
        $this->rawImg = $expression;
        $this->resolvedSrc = null;

        return $this;
    }

    /** 已标记的数据表达式；未标记返回 null */
    public function getExpression(): ?string
    {
        return $this->expression;
    }

    public function getImage(): ?string
    {
        return $this->rawImg;
    }

    /**
     * @internal 仅供 ResourceResolver 回写物化结果
     */
    public function setResolvedSrc(string $src): static
    {
        $this->resolvedSrc = $src;

        return $this;
    }

    /** 渲染实际可用的图片来源：优先物化结果 */
    public function getResolvedSrc(): ?string
    {
        return $this->resolvedSrc ?? $this->rawImg;
    }

    /**
     * 声明适配模式（ADR 0023）：与 src 正交（改图/打标/解标均不扰动）；
     * cover 显式赋值归一为 null 缺省态，保条件写键字节面（name="" 先例）
     */
    public function setFit(?string $fit): static
    {
        $this->fit = $fit === self::FIT_COVER ? null : $fit;

        return $this;
    }

    /** 适配模式声明原文；null = cover 缺省（渲染面未知值按 cover 降级，白名单在渲染臂） */
    public function getFit(): ?string
    {
        return $this->fit;
    }

    /**
     * 内容区内图片的绘制起点（对齐 + padding），纯布局计算
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function getImageOrigin(): array
    {
        return [
            intval($this->alignedX($this->getContentWidth())),
            intval($this->alignedY($this->getContentHeight())),
        ];
    }

    /**
     * contain 收缩矩形（ADR 0023）：按源图内在比例把内容盒收缩成等比矩形——
     * 两维同一缩放系数取 min(内容宽比, 内容高比)，长边贴内容盒；
     * 放置按对齐 + padding 与 getImageOrigin 同门公式。纯布局计算；
     * 内在尺寸由调用方读取（读取失败统一退化 fill 形态，ADR 0024），
     * 非法尺寸（≤0）不进本函数，收缩结果 ≤0 由 blit 后端防护只画盒
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [x, y, width, height]（相对图层盒原点）
     */
    public function getContainRect(int $imgWidth, int $imgHeight): array
    {
        $scale = min($this->getContentWidth() / $imgWidth, $this->getContentHeight() / $imgHeight);
        $width = intval($imgWidth * $scale);
        $height = intval($imgHeight * $scale);

        return [
            intval($this->alignedX($width)),
            intval($this->alignedY($height)),
            $width,
            $height,
        ];
    }

    /**
     * cover 等比居中裁窗（ADR 0024）：源图坐标系里与内容盒同宽高比的窗口，
     * 窗外部分被裁切不绘制——与 getContainRect 同门的纯布局函数（box/scale 公式互为
     * max/min 镜像），cover 窗公式唯一实例落本类静态 coverSourceWindow()。
     * 对齐不进源窗（cover 恒居中裁），padding 经内容盒收缩间接收窄窗口；
     * 内在尺寸由调用方读取，非法尺寸（≤0）不进本函数
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [sx, sy, sw, sh]（源图坐标）
     */
    public function getCoverRect(int $imgWidth, int $imgHeight): array
    {
        return self::coverSourceWindow($this->getContentWidth(), $this->getContentHeight(), $imgWidth, $imgHeight);
    }

    /**
     * cover 裁窗公式唯一落点（ADR 0024）：图片臂（内容盒）与画布背景臂（画布矩形）
     * 共用同一公式防数学漂移；boxWidth×boxHeight 为目标矩形（无 padding 语义时传整盒）
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [sx, sy, sw, sh]（源图坐标，恒居中裁）
     */
    public static function coverSourceWindow(int $boxWidth, int $boxHeight, int $imgWidth, int $imgHeight): array
    {
        $scale = max($boxWidth / $imgWidth, $boxHeight / $imgHeight);
        $windowWidth = intval($boxWidth / $scale);
        $windowHeight = intval($boxHeight / $scale);

        return [
            intval(($imgWidth - $windowWidth) / 2),
            intval(($imgHeight - $windowHeight) / 2),
            $windowWidth,
            $windowHeight,
        ];
    }

    /**
     * tile 可见块矩形组（ADR 0024）：单元 = 内在尺寸 1:1 零参数，首块左上锚定于
     * 对齐 + padding（与 getImageOrigin/getContainRect 同一 aligned 公式——小图 center
     * 网格整体居中、大图 center 锚点为负露出图片中部），网格自首块向右向下无限延伸，
     * 绘制面 = 网格 ∩ 内容盒：末行末列半块截断、锚点未覆盖处空隙透明露下层。
     * 每块 src/dst 一一对应（可见子矩形 1:1 映射，src 为源图坐标、dst 为图层盒坐标）；
     * 可见块数不设上限（静默截断是语义意外）。纯布局计算；
     * 内在尺寸由调用方读取（分派层已门控），非法尺寸（≤0）防线返回空集——
     * ≤0 步长会使循环不终止，公开 API 须对第三方直调自防御（Go TileRects 同款）
     *
     * @return list<array{src: array{0: int, 1: int, 2: int, 3: int}, dst: array{0: int, 1: int, 2: int, 3: int}}>（行主序）
     */
    public function getTileRects(int $imgWidth, int $imgHeight): array
    {
        if ($imgWidth <= 0 || $imgHeight <= 0) {
            return [];
        }

        $anchorX = intval($this->alignedX($imgWidth));
        $anchorY = intval($this->alignedY($imgHeight));
        $left = intval($this->padding['left']);
        $top = intval($this->padding['top']);
        $right = $left + $this->getContentWidth();
        $bottom = $top + $this->getContentHeight();

        $blocks = [];
        for ($y = $anchorY; $y < $bottom; $y += $imgHeight) {
            $dstY = max($y, $top);
            $dstHeight = min($y + $imgHeight, $bottom) - $dstY;
            if ($dstHeight <= 0) {
                continue;
            }
            for ($x = $anchorX; $x < $right; $x += $imgWidth) {
                $dstX = max($x, $left);
                $dstWidth = min($x + $imgWidth, $right) - $dstX;
                if ($dstWidth <= 0) {
                    continue;
                }
                $blocks[] = [
                    'src' => [$dstX - $x, $dstY - $y, $dstWidth, $dstHeight],
                    'dst' => [$dstX, $dstY, $dstWidth, $dstHeight],
                ];
            }
        }

        return $blocks;
    }

    /** 对齐放置横向公式（align + padding 同门）：placedWidth 尺寸的内容在整盒内的起点 */
    private function alignedX(float $placedWidth): float
    {
        return match ($this->horizontalAlign) {
            'left' => $this->padding['left'],
            'center' => ($this->getWidth() - $placedWidth) / 2,
            'right' => $this->getWidth() - $placedWidth,
            default => 0,
        };
    }

    /** 对齐放置纵向公式：与 alignedX 同门镜像 */
    private function alignedY(float $placedHeight): float
    {
        return match ($this->verticalAlign) {
            'top' => $this->padding['top'],
            'center' => ($this->getHeight() - $placedHeight) / 2,
            'bottom' => $this->getHeight() - $placedHeight,
            default => 0,
        };
    }

    public function graph(): array
    {
        $graph = parent::graph();

        $graph['data'] = $this->expression !== null
            ? ['valueType' => 'ExpressionValue', 'expression' => $this->expression, 'value' => $this->rawImg]
            // 现状两键形态（条件写键）
            : ['valueType' => 'StaticValue', 'value' => $this->rawImg];

        // 适配模式条件写键（ADR 0023）：缺省(cover)整键不落、缺省态字节面与无字段版本一致，
        // 落键殿后 spec 末位（shape/align/position/fit）；未知值原样透传保往返恒等
        if ($this->fit !== null) {
            $graph['spec']['fit'] = $this->fit;
        }

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $layer = parent::fromGraph($graph);

        // 适配模式宽容读（ADR 0023）：无键回填缺省；isset 门与 PHP 语义一致（null 不算在场），
        // 非字符串值同样归缺省（宽容读不告警）
        if (isset($graph['spec']['fit']) && is_string($graph['spec']['fit'])) {
            $layer->setFit($graph['spec']['fit']);
        }

        $data = $graph['data'] ?? [];
        if (($data['valueType'] ?? null) === 'ExpressionValue' && isset($data['expression'])) {
            return $layer->setExpression((string) $data['expression']);
        }

        return $layer->setImage($data['value'] ?? null);
    }
}
