<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\CoordinateRecordingRenderer;
use HankChen\CanvasNext\Tests\Support\FakeCancellation;
use HankChen\CanvasNext\Tests\Support\FakeDownloader;
use HankChen\CanvasNext\Tests\Support\RecordingTestRenderer;
use InvalidArgumentException;
use RuntimeException;

/**
 * 多页 walk 模板契约（工票 01，镜像 go renderer.RenderPages 定稿语义）：
 * 逐页 复用单页页内段（begin → 背景铺装 → 逐层 paint），全部页完成后统一 end 一次
 * （n 页 = n 次 begin + 1 次 end）；页间 ctx 检查点一处（i > 0、下一页 begin 前，
 * 首页前不另设）；visible 逐页过滤；背景逐页显式携带（ADR 0022）；
 * 空页序列 = 调用侧用法错误普通异常不设 code；任一页失败 end 不达、整文档无产物逃逸。
 *
 * 对拍基准 = 本包单页 render()（存量测试保绿 = 单页路径零行为变化红线）；
 * 检查点位置用"绘制中途武装"驱动（与 RendererCancellationTest 同款手法）：
 * 既证明检查点存在，也证明其落点
 */
class RendererMultipageTest extends CanvasTestCase
{
    /**
     * 坐标记录型后端（绘制参数留痕），见 Support\CoordinateRecordingRenderer
     */
    private function coordinateRenderer(?ResourceResolver $resolver = null): CoordinateRecordingRenderer
    {
        return new CoordinateRecordingRenderer($resolver ?? new ResourceResolver());
    }

    /**
     * 页内 walk 与单页 render 同构比对的对拍画布：表容器下钻（表→行→单元格→内容层）+ 文本行
     */
    private function buildTableCanvas(): Canvas
    {
        $content = ImageLayer::make(30, 20, '#0f0');
        $row1 = (new TableRowLayer(100, 20))
            ->addCell(TableCellLayer::make(50, 20))
            ->addCell(TableCellLayer::make(50, 20)->addContentLayer($content));
        $row2 = (new TableRowLayer(100, 15))
            ->addCell(TableCellLayer::make(100, 15));
        $table = TableLayer::make(100, 35, '#fff')->addRow($row1)->addRow($row2);
        $text = TextLayer::make(40, 12)->setText('ab')->setFont('', 12, '#000');

        return Canvas::make(200, 100, $table, $text);
    }

    /**
     * 页内 walk 同构比对的对拍画布：普通定位图层
     */
    private function buildPlainCanvas(): Canvas
    {
        return Canvas::make(80, 60, ImageLayer::make(20, 10, '#f00')->setPosition(2, 3));
    }

    public function testThreePagesBeginThriceEndOnce(): void
    {
        // 三页三尺寸：每页以自身画布尺寸建面，end 全部页完成后恰好一次；产物透传
        $pages = [
            Canvas::make(320, 240, ImageLayer::make(20, 10, '#0f0')),
            Canvas::make(100, 80, ImageLayer::make(30, 20, '#f00')),
            Canvas::make(50, 40, ImageLayer::make(10, 10, '#00f')),
        ];
        $renderer = $this->coordinateRenderer();

        $product = $renderer->renderPages(new NullCancellation(), $pages);

        $this->assertSame('fake-product', $product);
        $this->assertEquals(['begin', 'rect', 'begin', 'rect', 'begin', 'rect', 'end'], $renderer->records);
        $this->assertSame([[320, 240], [100, 80], [50, 40]], $renderer->begins, '逐页建面尺寸 = 各页画布尺寸');
    }

    public function testPageWalkIsomorphicToSingleRender(): void
    {
        // 页内段原样抽取的回归证明：多页第 i 页的原语序与坐标 = 单独 render 第 i 画布
        // （begin/end 剥除后逐项相等）；页 1 覆盖表容器下钻 + 文本行，页 2 覆盖定位图层
        $single1 = $this->coordinateRenderer();
        $single1->render(new NullCancellation(), $this->buildTableCanvas());
        $single2 = $this->coordinateRenderer();
        $single2->render(new NullCancellation(), $this->buildPlainCanvas());

        $multi = $this->coordinateRenderer();
        $multi->renderPages(new NullCancellation(), [$this->buildTableCanvas(), $this->buildPlainCanvas()]);

        $walk1 = array_values(array_diff($single1->records, ['begin', 'end']));
        $walk2 = array_values(array_diff($single2->records, ['begin', 'end']));
        $this->assertEquals(['begin', ...$walk1, 'begin', ...$walk2, 'end'], $multi->records);
        // rect 坐标序逐项同构（定位/下钻几何零漂移）；text 序逐项同构
        $this->assertSame([...$single1->rects, ...$single2->rects], $multi->rects);
        $this->assertSame([...$single1->images, ...$single2->images], $multi->images);
    }

    public function testVisibleFilteringAppliesPerPage(): void
    {
        // 显隐过滤逐页进行（隐藏 = 最终输出排除，与单页 render 同语义）：
        // 首页可见+隐藏各一，次页仅隐藏——隐藏层不触达任何原语与物化
        $downloader = new FakeDownloader('fake-image-bytes');
        $renderer = $this->coordinateRenderer(new ResourceResolver($downloader));
        $pages = [
            Canvas::make(
                40,
                20,
                ImageLayer::make(40, 20, '#0f0'),
                ImageLayer::make(40, 20)->setImage('https://cdn.example.com/hidden-' . uniqid() . '.png')->setVisible(false),
            ),
            Canvas::make(
                40,
                20,
                ImageLayer::make(40, 20)->setImage('https://cdn.example.com/hidden-' . uniqid() . '.png')->setVisible(false),
            ),
        ];

        $renderer->renderPages(new NullCancellation(), $pages);

        $this->assertEquals(['begin', 'rect', 'begin', 'end'], $renderer->records);
        $this->assertSame('#0f0', $renderer->rects[0][4], '只应绘制可见层');
        $this->assertSame([], $downloader->calls, '隐藏层不得触达物化');
    }

    public function testBackgroundPaintedPerPage(): void
    {
        // 背景逐页显式携带（ADR 0022 分页页产物自带 background）：每页先铺自己的背景
        // （先色后图）再走图层；第 1 页仅色，第 2 页色+图（本地路径不在盘上 →
        // 内在尺寸读取失败统一退化 fill 形态，srcRect 零哨兵、dstRect 铺满本页画布矩形）
        $missingPath = '/tmp/canvas-next-multipage-bg-' . uniqid() . '.png';
        $p1 = Canvas::make(320, 240)->setBackground(CanvasBackground::make('#ffffff'));
        $p2 = Canvas::make(100, 80, ImageLayer::make(20, 10, '#0f0'))
            ->setBackground(CanvasBackground::make('#eee', $missingPath));
        $renderer = $this->coordinateRenderer();

        $renderer->renderPages(new NullCancellation(), [$p1, $p2]);

        $this->assertEquals(['begin', 'rect', 'begin', 'rect', 'image', 'rect', 'end'], $renderer->records);
        $this->assertSame([[0, 0, 320, 240, '#ffffff']], array_slice($renderer->rects, 0, 1), '第 1 页背景色整幅铺装');
        $this->assertSame([0, 0, 100, 80, '#eee'], $renderer->rects[1], '第 2 页背景色铺装');
        $this->assertSame([[$missingPath, 0, 0, 0, 0, 0, 0, 100, 80]], $renderer->images, '第 2 页背景图 fill 形态铺满本页');
        $this->assertSame([0, 0, 20, 10, '#0f0'], $renderer->rects[2], '第 2 页图层盒在背景之后');
    }

    public function testInterPageCheckpointCutsBeforeNextPageBegin(): void
    {
        // 第 1 页最后一笔绘制武装取消：页内检查点已全部通过，下一处检查点即页间
        // （第二页 begin 前）——页间检查点缺失时本测试必失败（第二页照画且 end 达）
        $ctx = new FakeCancellation();
        $pages = [
            Canvas::make(100, 100, ImageLayer::make(20, 10, '#0f0')),
            Canvas::make(80, 60, ImageLayer::make(20, 10, '#f00')),
        ];
        $renderer = new RecordingTestRenderer();
        $rects = 0;
        $renderer->afterRect = function () use (&$rects, $ctx): void {
            if (++$rects === 1) {
                $ctx->requested = true; // 第 1 页最后一笔画完后武装
            }
        };

        try {
            $renderer->renderPages($ctx, $pages);
            $this->fail('页间取消必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
        }

        $this->assertEquals(['begin', 'rect'], $renderer->records, '页间检查点应先于第二页 begin');
    }

    public function testEmptyFirstPageMakesInterPageCheckpointTheFirstCut(): void
    {
        // 首页零背景零图层（页内零检查点）：页间检查点是首个掐断点——
        // 证明「首页之后、下一页 begin 之前」的检查点真实存在且恰为一处
        $pages = [
            Canvas::make(100, 100),
            Canvas::make(80, 60, ImageLayer::make(20, 10)),
        ];
        $renderer = new RecordingTestRenderer();

        try {
            $renderer->renderPages(new TimeoutCancellation(0.0), $pages);
            $this->fail('页间超时必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_deadline_exceeded', $e->getErrorCode());
        }

        $this->assertEquals(['begin'], $renderer->records, '第二页 begin 未达：检查点先于下一页建面');
        $this->assertNotContains('end', $renderer->records, '渲染面必须丢弃（end 不达）');
    }

    public function testEmptyPageSequenceIsUsageErrorWithoutCode(): void
    {
        // 空页序列属调用侧用法错误：普通异常不设 code（「0 begin + 1 end」的
        // 空文档语义不由模板代答），且不触达任何原语
        $renderer = new RecordingTestRenderer();

        try {
            $renderer->renderPages(new NullCancellation(), []);
            $this->fail('空页序列必须报错');
        } catch (InvalidArgumentException $e) {
            $this->assertNotInstanceOf(CanvasNextExceptionInterface::class, $e, '用法错误不落绘制期错误分类面（不设 code）');
        }

        $this->assertSame([], $renderer->records, '空页序列不应触达任何原语');
    }

    public function testNonCanvasPageElementIsUsageErrorWithoutCode(): void
    {
        // 元素类型守卫与空序列守卫同面：异类元素属调用侧用法错误，普通异常不设
        // code、零原语触达（防线对称：PHP 数组无 go []*canvas.Canvas 的编译期类型面，
        // 非法输入防线随期望值矩阵平移——否则异类在 paintPage 深处才 TypeError）
        $renderer = new RecordingTestRenderer();

        try {
            $renderer->renderPages(new NullCancellation(), [Canvas::make(10, 10), 'not-a-canvas']);
            $this->fail('非 Canvas 元素必须报错');
        } catch (InvalidArgumentException $e) {
            $this->assertNotInstanceOf(CanvasNextExceptionInterface::class, $e, '用法错误不落绘制期错误分类面（不设 code）');
            $this->assertStringContainsString('string', $e->getMessage(), '错误应点名实际元素类型');
        }

        $this->assertSame([], $renderer->records, '元素守卫不应触达任何原语');
    }

    public function testPageFailureDropsWholeDocumentWithoutEnd(): void
    {
        // 第 2 页首笔绘制失败：异常即抛、end 不达、第 1 页已画内容与整文档产物一并作废
        $pages = [
            Canvas::make(100, 100, ImageLayer::make(20, 10, '#0f0')),
            Canvas::make(80, 60, ImageLayer::make(20, 10, '#f00')),
        ];
        $renderer = new RecordingTestRenderer();
        $rects = 0;
        $renderer->afterRect = function () use (&$rects): void {
            if (++$rects === 2) {
                throw new RuntimeException('第二页绘制失败');
            }
        };

        try {
            $renderer->renderPages(new NullCancellation(), $pages);
            $this->fail('第 2 页绘制失败必须抛错');
        } catch (RuntimeException $e) {
            $this->assertSame('第二页绘制失败', $e->getMessage());
        }

        // 第 1 页完整走完；第 2 页首笔 rect 记录后注入异常即抛（afterRect 注入点在
        // 调用序记录之后，与 go failFromPageBackend 的记录前拦截形态不同、语义同构
        // = 第 2 页首笔原语失败），end 永不触达、整文档无产物逃逸
        $this->assertEquals(['begin', 'rect', 'begin', 'rect'], $renderer->records);
        $this->assertNotContains('end', $renderer->records);
    }
}
