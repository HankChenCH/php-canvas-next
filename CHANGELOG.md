# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## 1.4.0 - 2026-10-18

- 图片层适配模式 fit（ADR 0023，spec 见 `.scratch/image-layer-fit/spec.md`）：`ImageLayer` 新增静态适配声明 `fit`（`FIT_COVER`/`FIT_CONTAIN` 常量，`setFit()`/`getFit()`），v1 仅 contain 一档（完整显示：等比缩放至恰好完整落入内容盒、按对齐放置、空隙透明露下层）；cover 缺省不动
- wire 条件写键：`spec.fit` 值=cover（缺省）整键不落、落键仅 fit≠cover 时发生且殿后 spec 末位（键序钉死 shape/align/position/fit）；解码宽容读——无键回填 cover、显式 cover 归一缺省态（name="" 先例）、未知值原样透传保往返恒等；无 fit 存量 graph 字节面零变化
- contain 渲染语义：`AbstractRenderer::paintImage` 绘制分派点按物化后源图内在比例（`getimagesize`，绘制分派期 I/O 与 resolve 同批）把目标矩形收缩成等比矩形再调 `drawImage`——原语签名与 cover 语义一字不动（GD/Imagick 后端零改动白得）；收缩矩形纯计算在 `ImageLayer::getContainRect()`（等比收缩 + align/padding 镜像 `getImageOrigin` 同门放置），内在尺寸读取失败/未知 fit 值按 cover 降级，cover 路径逐字节零回归
- fit 为静态声明：不进 hydrate 改写面与表达式白名单（TextLayer.text / ImageLayer.src / QrCodeLayer.value 白名单不变），hydrate 对 fit 仅透传

## 1.3.0 - 2026-10-18

- 画布背景声明化（ADR 0022，spec 见 `.scratch/canvas-background/spec.md`）：canvas 级可选 `background` 字段（`CanvasBackground` 值对象，color + image 两分量可空，`Canvas::setBackground()` 声明）。wire 条件写键——两分量皆空整键不落、落键仅非空分量落键、键序钉死 `canvas{width,height,background}` / `background{color,image}`；无背景存量 graph 字节面零变化，落键/不落键两态 decode→encode 往返恒等，解码宽容读缺键回填空
- 渲染铺装（spec §4）：`AbstractRenderer::render()` 根层循环之前先铺色再铺图（复用 drawRect/drawImage 绘制原语，后端约零改动），背景图 cover 铺满画布矩形、透明区露背景色，背景之下无物（画布初始透明保留）；`renderLayer()` 单图层便捷入口不画背景（无画布矩形语义）
- 背景图惰性物化：`ResourceResolver::resolveBackground()` 画布级入口，与 `resolveLayer` 同语义——物化前检查点、远程下载缓存（与图层共用 `img_layers` 缓存）、本地路径直通、失败即抛 `MaterializeException`、渲染面整体丢弃；不触发渲染器预遍历（`resolve()` 禁预遍历断言保持）
- 分页与填充透传：`CanvasPaginator::split()` 页 canvas 键显式携带 background（spec §5 易漏点，每页均带）；hydrate/expand 对背景仅透传——不进 hydrate 改写面与表达式白名单（静态，ADR 0022 Q3），数据驱动底图继续走图层级，两形态并存合法

## 1.2.0 - 2026-10-15

- 资源表达式单值裸路径门（ADR 0020）：已标记 Image/Qr 的 expression 求值前校验须恰为一个 `{{...}}` 闭合片段 + 裸点路径（`$index`/`$root.` 前缀合法），禁多片段拼接、片段外字面、内建函数与空片段；违例抛 `HydrateException` 新错误码 `expression_resource_single_ref`，先于求值与 `expression_empty_resource` 空串检查（测试钉序）。文本层插值语义与未标记字面直通零变化
- 共享 fixture `expand-semantics` 增资源门用例 8 条（合法 3 + 违例 4 + 顺序钉定 1），`independent-layers-root-context` 改合法形态

## 1.1.0 - 2026-10-11

- 表达式内建函数求值器（v1 方法面，ADR 0018）：mustache 插值内函数调用形态 `{{date(row.d, 'YYYY年MM月DD日')}}`，签名 `date(path[, format])` / `number(path[, n])` / `fixed(path[, n])` / `percent(path[, n])`
- `DateFormat` 日期 token 语言：moment 风格 14 token（含周几中文输出），方括号转义，严格 ISO 解析（3 种无时区形态、wall clock 语义、零时区依赖），token 表为三端契约权威源
- `NumberFormat` 数字族：十进制字符串语义（最短往返归一、half-up 朝 +∞、2.675→2.68 不走二进制原生 round），千分位/定点补零/百分比乘 100
- 错误分轴：缺字段=空串、不可解析/数值=原文直通（值面宽松）；未知函数/参数个数/文法错 fail-fast 并入 `expression_syntax_error`，零新错误码
- 共享 fixture contract v2：104 用例（value/date/number 三组）+ dateTokens 14 行转译表，由 `scripts/export-hydrate-fixtures.php` 导出

## 1.0.0 - 2026-10-07

首个稳定版。

- 图层树 `Canvas` / `Layer`（纯结构，setter 无 I/O）与渲染契约（`AbstractRenderer` 模板方法：资源物化 → 建渲染面 → 遍历分派 → 容器下钻 → 收尾）分离
- `ResourceResolver` 渲染前统一物化远程图片/字体与二维码，渲染端只见本地路径
- `graph()` / `Canvas::fromGraph()` 无损结构树序列化与重建（含表格尺寸同步）
- 文本图层：UAX #14 简化断行（图素簇切分、CJK 字间可断、行首行末禁则），断行器/度量器可注入替换
- 表格图层（`TableLayer → TableRowLayer → TableCellLayer`）与可选行模板
- 内容流文档编译与分页
- QR 图层内切正方形渲染语义：padding 留白即静区、align 参与放置（ADR 0015）
- 渲染取消（`Runtime\Cancellation`）支持

位图输出请配合 `hankchen/php-canvas-image-renderer` 使用。
