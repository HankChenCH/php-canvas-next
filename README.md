# php-canvas-next

`hankchen/php-canvas` 的下一代实现：**图层树（纯结构）与渲染器（可插拔后端）彻底分离**。

- `Canvas` / `Layer` 是纯数据容器，只承载设定与纯布局函数，不含任何渲染状态；
- 渲染由 Renderer 驱动：`Renderer::render(Canvas)` 主动拉取结构树，按图层类型分派绘制；
- 内置 `ImageRenderer`（intervention/image v4），PDF 等其他后端实现同一契约即可接入。

设计文档与对象交互图见 [docs/design/layer-renderer-separation.md](docs/design/layer-renderer-separation.md)。

## 环境要求

- PHP `^8.3`
- GD 或 Imagick 扩展（Imagick 优先）
- 文字渲染建议提供真实 TTF/TTC 字体文件（Imagick 驱动无内置字体，必须提供）

## 安装

```sh
composer require hankchen/php-canvas-next
```

## 快速上手

```php
use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\Image\ImageRenderer;

$canvas = Canvas::make(400, 300,
    // priority 越大越先渲染（越垫底），与 php-canvas 一致
    ImageLayer::make(400, 300, '#ffffff')->setPriority(10),
    (new TextLayer())
        ->setText('Hello, php-canvas-next')
        ->setFont('/path/to/font.ttf', 24, '#333333')
        ->setPosition(20, 40)
        ->setPriority(1)
);

$image = (new ImageRenderer())->render($canvas); // 返回 intervention/image v4 ImageInterface
$image->save('/tmp/out.png');
```

单图层渲染：

```php
$image = (new ImageRenderer())->renderLayer($layer);
```

## 核心概念

### 结构与渲染分离

```
Canvas / Layer（纯数据，设定 + 纯布局函数）
        ↓  Renderer::render(Canvas) 主动拉取
AbstractRenderer（模板方法：资源物化 → 建渲染面 → 遍历分派 → 容器下钻 → 收尾）
        ↓  绘制原语 drawRect / drawImage / drawText
ImageRenderer（intervention/image）  …  PdfRenderer（规划中）
```

- 图层 setter 不产生任何 I/O；远程图片/字体与二维码的下载、生成由
  `ResourceResolver` 在渲染前统一物化，渲染端只见本地路径；
- 容器图层（`TableLayer → TableRowLayer → TableCellLayer`）在 `addRow/addCell/addContentLayer`
  时同步父子尺寸（结构侧契约）；
- 布局计算（断行、autoHeight、对齐原点）是图层上的纯函数，所有渲染端共享同一结果。

### 文本断行与度量

默认断行器为 UAX #14 简化实现：图素簇切分（emoji 不拆碎）、CJK 字间可断、
拉丁词边界优先、禁则处理（行首不出现 `。，、；：！？」』）】` 等，行末不出现 `「『（【`）。
默认度量器为字符启发式（半角 0.55 字宽），两者均可注入替换：

```php
$layer->setLineBreaker($myBreaker);            // HankChen\CanvasNext\Contracts\LineBreakerInterface
$layer->setMeasurerFactory($myFactory);        // HankChen\CanvasNext\Contracts\TextMeasurerFactoryInterface
```

自定义远程资源下载器（代理、鉴权等）：

```php
$renderer = new ImageRenderer(new ResourceResolver($myDownloader)); // DownloaderInterface
```

### 结构树序列化

```php
$spec = $canvas->graph();                       // 无损结构树，可 JSON 持久化
$canvas = Canvas::fromGraph($spec);             // 完整重建（含表格尺寸同步）
```

`graph()` 无损携带全部行/单元格/内容与字体完整路径，跨渲染端复用同一份结构。

## 从 php-canvas 迁移

| 旧（php-canvas） | 新（php-canvas-next） |
| --- | --- |
| `Canvas::make(...)->render()->save($p)` | `(new ImageRenderer())->render($canvas)->save($p)` |
| `$layer->render()` | `(new ImageRenderer())->renderLayer($layer)` |
| `Canvas::getCore()` | `render()` 的返回值即图像 |
| `setImage()`/`setFont()` 立即下载 | setter 只存引用，渲染前由 Resolver 统一物化 |
| 自定义下载器 `$layer->setDownloader()` | `new ImageRenderer(new ResourceResolver($downloader))` |
| `QrCodeLayer::generateQrCodeLayerFromContent($c)` | `QrCodeLayer::setText($c)`（物化自动进行） |
| `graph()` 的 `rowTemplate`/`cellTemplates` | `rows`（全量）/`cells`；字体保存完整路径 |
| — | 新增 `Canvas::fromGraph()` 反序列化 |
| 远程资源缓存 `sys_temp/canvas/` | `sys_temp/canvas-next/`（可与旧库共存） |
| autowrap 字符数断行 | UAX #14 简化断行（词边界 + 禁则），同一文本断行位置可能与旧库不同 |

旧库 `hankchen/php-canvas` 冻结维护、保持可用；新库不依赖旧库。

## 开发

```sh
composer install
composer test            # PHPUnit
php scripts/visual-check.php [out.png]   # 渲染目验样图
```

测试说明见 [AGENTS.md](AGENTS.md)。
