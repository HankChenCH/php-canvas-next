# AGENTS.md

## Purpose

PHP library `hankchen/php-canvas-next`（`hankchen/php-canvas` 的下一代）：图层树（纯结构）与渲染器（可插拔后端）分离。**本包是核心包**：只含结构、渲染契约与资源物化，`require` 仅 `php + endroid/qr-code`，不依赖任何图像扩展/库。位图后端在伴随包 `hankchen/php-canvas-image-renderer`（本地同级目录，经 path repository 互相引用）。命名空间 `HankChen\CanvasNext\`（PSR-4 → `src/`），PHP `^8.3`。中文是代码注释的工作语言；commit 用 conventional-commit 前缀 + 中文 subject。

## Layout

- `src/Canvas.php` — 纯结构容器：尺寸 + 按 priority 降序的普通数组图层（等优先级保持插入序），可重复遍历；`graph()`/`fromGraph()` 无损序列化。
- `src/Layer/AbstractLayer.php` — 图层基类：纯数据（盒模型 width/height incl. `'auto'`、padding、border、对齐、position 锚点、priority）+ 纯布局函数 + `graph()`/`fromGraph()`。**没有 render，没有 setDownloader，不 import 任何图像库**。
- `src/Layer/*.php` — `ImageLayer`（只存引用，`setResolvedSrc` 供 Resolver 回写，可标记数据表达式）、`TextLayer`（断行/度量可注入，`getLines()`/`getTextOrigin()`/`lineHeightPx()` 是布局纯函数，`getWidth()` 覆写 autoWidth 自然宽求值（ADR 0014，仅文本层有义），可标记数据表达式）、`QrCodeLayer`（只存 text，可标记数据表达式）、`TableLayer → TableRowLayer → TableCellLayer`（add* 时同步父子尺寸，容器暴露 `getRows/getCells/getContentLayer`）、`TableRowTemplate`（V2 模板行：宽度耦合沿用、高度耦合全豁免的专用装配路径 `addCell`/`TableCellLayer::addTemplateContentLayer`，仅合法出现在 TableLayer 的 template 字段内）。
- `src/Layer/LayerFactory.php` — 按 graph 的 `type` 标识重建图层。
- `src/Contracts/` — `RendererInterface`（`render(Canvas): mixed`，唯一公开渲染入口，`@api`）、`LineBreakerInterface`、`TextMeasurerInterface`（已配置的宽度度量器）与 `TextMeasurerFactoryInterface`（策略注入点）、`DownloaderInterface`、`ExpressionEvaluatorInterface`（数据表达式求值策略注入点）。
- `src/Hydrate/CanvasHydrator.php` — hydrate 步骤的固定编排器（数据填充，渲染前独立纯结构步骤）：`hydrate(Canvas, dataset, ?DiagnosticCollector $warnings = null): Canvas`，全画布标记字段求值 + 模板表实例化，产出具体树（无模板、无表达式、高度定稿）；文案类兜底空串经收集器发射软诊断 `expression_fallback_empty`（spec §3.3），null = 静默兜底；实现走 graph 层改写后 `Canvas::fromGraph` 重建，源 graph 永不回写。嵌套模板表 rowsPath 行相对取数。更名自「展开 / CanvasExpander」，与 React SSR hydration 无关。
- `src/Compiler/` — 编译入口（模板方法骨架）：`CanvasCompiler`（abstract，`compile()` final 钉死 hydrate → paginate，非第三方扩展点）+ 包内唯一子类 `DefaultCanvasCompiler`；产物 `CompileResult{canvases, warnings}` 与软诊断 `Diagnostic{code, message, path}`（spec §5.1）；`CompileContext`（canvas + dataset + options + warnings 侧信道）承载步骤间通信与诊断收集（`DiagnosticCollector`）。`DocumentCompiler` — 文档级编译入口（spec §10.1，ADR 0009）：`compile(Canvas[] $frames, mixed $dataset, ?array $flowChain = null): CompileResult` 独立类（与单画布编译共享 CanvasHydrator/CanvasPaginator，不共享模板方法）；无流路径（null / []）= 逐帧 hydrate（全帧共享单一 warnings collector，诊断不带帧标识）→ 逐帧 paginate（pageHeight=null 单页 + 溢出判定）；流路径 = 0 链校验（fail-fast）→ 1 全量 hydrate → 2 flow 分配（共享 remaining 权威序列，逐节点 take 后 array_splice 切份额）→ 3 回写（槽位 rows 整体替换 → fromGraph 重建）+ 逐帧分页（fixed/链外帧 pageHeight=null，paged 帧页高 = 帧画布高），产物按帧序 × 页序扁平拼接；空 frames 抛 InvalidArgumentException。`CanvasPaginator` — paginate 步骤实现（分页，spec §4.2）：`Canvas → Canvas[]` 编译期纯函数，构造参数 `pageHeight`（null = 分页关直通 + 溢出判定）/`truncate`；只拆顶层驱动表行序列（0 表直通、≥2 表报错、嵌套表不驱动），表壳每页重画、非驱动图层逐页原样复制，产物页经 graph 重组 + `fromGraph` 重建（页间零共享、往返无损）；语义与 12 规定用例见 `.scratch/canvas-compiler/spec.md` §4.2 与 `go-canvas/paginate/testdata/paginate-semantics.json`。`TableGeometry` — 表顶/行高共享纯函数（spec §10.3）：分页装箱与流装箱消费同一实现防数学漂移。`Compiler\Flow\` — 内容流装箱机（spec §10.2–§10.3，ADR 0009）：`FlowChainValidator`（链校验 fail-fast，返回 frame → 槽位表图层下标映射）、`FrameCapacity`（可用区 = 页底 − 表顶）、`FlowConsumer` + 三实现 `CapacityTake`（容量型前缀，首行超容分配期抛 content_overflow）/`QuotaTake`（min(quota,count) 超容不钳制）/`PagedTake`（吃尽剩余）与封闭工厂 `FlowConsumerFactory`（按 mode 分发，LayerFactory 同款）；flow fixture = `go-canvas/paginate/testdata/flow-semantics.json`（20 规定用例，`make flow-fixtures` 再生成，导出脚本 `scripts/export-flow-fixtures.php`）。
- `src/Expression/InterpolationEvaluator.php` — 默认求值器：mustache 风格外壳 + 变量点路径子集（`row.` 命名空间/裸名根键/`$index` 自 1 起/`$root`），零依赖；缺字段=空串信号、类型不匹配 fail-fast。三端共享 fixture 只锁默认实现。
- `src/Exception/` — 错误分类面（五阶段模型，spec §5.2/§10.4）：`DecodeException`（解码期）/`HydrateException`（填充期）/`FlowException`（文档期：`flow_chain_invalid`/`flow_rows_path_inconsistent`——分配期超容不落本类，复用 paginate 段 content_overflow）/`PaginateException`（分页期：`content_overflow`/`paginate_target_invalid`/`page_geometry_invalid`）/`MaterializeException`（绘制期），统一承载稳定 code（`getErrorCode()`），消息 = code 前缀 + 上下文。
- `src/Renderer/AbstractRenderer.php` — 渲染模板：begin → 遍历图层 instanceof 分派 → 容器递归（坐标偏移栈，全部直画同一渲染面）→ end；后端只实现 `begin/end/drawRect/drawImage/drawText` 五个原语。**渲染器无预遍历**：Image/Text/QrCode 分支开头按需 `resolveLayer` 惰性物化（ADR 0005），失败即抛、渲染面丢弃。**本包不含任何具体后端**。
- `src/Renderer/PositionResolver.php` — 锚点串 → 父盒内偏移（纯函数，不做钳位，负值即溢出）。
- `src/Runtime/` — 渲染期控制面值对象族（ADR 0008，render-cancellation 工票 02）：`Cancellation` 信号接口（advisory only，`isRequested`/`throwIfRequested`/`deadline`）+ `NullCancellation` 零值 + `CancellationSource` 取消源（cancel 幂等 first-wins 一次性，信号端只可观察）+ `TimeoutCancellation`（构造即换算 deadline，pull-based 零 loop）+ `CompositeCancellation`（任一源触发即触发，空 = NullCancellation 语义）。检查点抛 `MaterializeException`，code `render_cancelled`/`render_deadline_exceeded`；deadline 余量换算 socket 超时在 `DefaultDownloader`。
- `src/ResourceManagers/ResourceResolver.php` — 资源物化：远程图片/字体下载（失败抛 code `resource_download_failed`）、QR 经 endroid 生成 PNG，回写图层 `setResolvedSrc/setResolvedFont`。由渲染器绘制分支按需调用（角色=绘制时物化，非渲染前预遍历）。是核心包的一部分（任何后端都需要它）。
- `src/Text/` — `Uax14LineBreaker`（图素簇/词边界/禁则，默认断行器）、`HeuristicMeasurer`（半角 0.55 字宽启发式，默认度量器）、`Graphemes`（图素切分助手）。
- 伴随包 `../php-canvas-image-renderer`：`Renderer\Image\ImageRenderer` + `Renderer\Image\ImageManagerFactory` + 像素测试与目验脚本；命名空间根 `HankChen\CanvasNext\Renderer\Image\`。

## Commands

```sh
export PATH="$(brew --prefix)/opt/php@8.3/bin:$PATH"   # Homebrew PHP 是 keg-only
composer install
composer test          # vendor/bin/phpunit
php -l src/...         # 改动文件语法检查
```

本机 composer 依赖解析走 **packagist 直连**（全局 aliyun 镜像已移除——其元数据滞后，曾解析不到 intervention/image 4.x 与 endroid/qr-code 6.x 稳定版）。如需恢复国内镜像自行执行 `composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/`，注意滞后风险。安装依赖用仓库内 `composer.lock` 直接 `composer install` 即可。

## Rules and gotchas

- **依赖方向单向**：Renderer → Canvas → Layer；结构侧（Canvas/Layer）不得 import 渲染侧与图像库，**核心包 composer require 保持 `php + endroid/qr-code`**——把 intervention 等渲染依赖引回核心是回归。图层 setter 禁止 I/O，物化只发生在 `ResourceResolver`。
- **标准管线**：`$renderer->render(new NullCancellation(), (new CanvasHydrator())->hydrate($canvas, $dataset))`——渲染期控制面以 `Cancellation $ctx` 显式首参穿线（ADR 0008，严格非空，不受控即传零值 `NullCancellation`）；hydrate（数据填充，数据维度，渲染前纯结构）与物化（资源维度，绘制时惰性）两维分离；未绑数据集（null）或无绑定画布，两步均恒等直通。编译入口走 `DefaultCanvasCompiler::compile()`（工票 01 骨架，hydrate → paginate 钉序）。
- **graph 条件写键（V2 新增面）**：模板态 TableLayer 写 `data`/`template`、不写 `rows`；V1 态恒写 `rows`、不写 `template`/`data`（template ⊕ rows XOR，双键同现 `fromGraph` 抛 `template_rows_conflict`）。表达式标记：TextLayer 恒写三键 data（现状形态），Image/Qr 仅标记态写 `expression` 键（保 Go↔PHP 字节 parity）；`data.value` 恒镜像表达式原文，求值结果永不落 graph。
- **错误 code 是三端契约**：新增错误必须归入五阶段之一（解码/填充/文档/分页/绘制，spec §5.2/§10.4）并挂稳定 code，消息可改、code 不可改；求值器内部错误（语法等）code 归求值器 spec。
- **priority 语义**：越大越先渲染（越垫底），与旧库一致。
- **`AbstractRenderer` 属于核心**：遍历/分派/定位只依赖结构类型；具体后端（Image/Pdf）永远在独立包，只实现绘制原语。
- **PCRE `\X` 不可用于切分**：本机 PCRE 10.45 + jit 下 `preg_split('/\X/u')` 返回空串数组（bug），图素切分统一走 `Text\Graphemes`（ext-intl `grapheme_extract`，缺失时降级码点切分）。
- **endroid/qr-code v6**：只读值对象，命名参数构造；输出用 `(new PngWriter())->write($qr)->getString()` 原始字节落盘。
- **缓存目录** `sys_get_temp_dir()/canvas-next/{img_layers,text_layers,qr_layers}`：两包测试都依赖该路径，勿改；按 URL basename 缓存、命中跳过、无失效机制。
- **graph() 是无损公共序列化面**：新增图层字段必须同时补 `graph()` 与 `fromGraph()`，并保持所有图层往返恒等（测试有 graph→fromGraph→graph 恒等用例）；type 标识 = 类常量 `TYPE`，同时登记进 `LayerFactory::TYPE_TO_CLASS`。
- **断行行为变更属行为面**：改 `Uax14LineBreaker`/`HeuristicMeasurer` 需同步更新 `TextLayerTest` 断言。
- **TDD**：行为变化先写失败测试（红）再实现（绿）；核心包测试只测纯数据/布局/序列化，像素测试在 image-renderer 包。
- 旧库 `../php-canvas` 已冻结：不要把新库改动同步回旧库，也不要让新库依赖旧库。
- intervention/image v4 API、Imagick 无内置字体、PCRE 之外的渲染侧注意项见 image-renderer 包的 AGENTS.md。
