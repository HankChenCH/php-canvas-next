# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

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
