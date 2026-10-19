# tests/Corpus/ — 五契约语料 runner（工票 06 消费翻转；扫描面随工票 08 收编）

语义权威 = 语料仓 submodule `canvas-semantics/`（pin 见 `git submodule status`，GOVERNANCE §4：
已发布版本目录冻结）。**语义变更从此语料先行**：改语料 → 移 pin → 更新 runner 的
`CONTRACT` 常量 → 全绿，PHP 是消费实现而非语义源。

## Runner 与契约对应

| Runner | 语料模块文件 | 契约版本 | 用例数 | 管线 |
| --- | --- | --- | --- | --- |
| `ExpressionEvalCorpusTest` | `expression-eval/v3.0/{shell,path,fn-date,fn-number}.json` | `expression-eval v3.0` | 107 | `InterpolationEvaluator::evaluate` |
| `ExpressionScanCorpusTest` | `expression-scan/v1.0/scan.json` | `expression-scan v1.0` | 27 | `InterpolationEvaluator` 扫描面 + 「切分拼装 ≡ 整串求值」互证 |
| `ExpandCorpusTest` | `expand-semantics/v1.1/expand.json` | `expand-semantics v1.1` | 29 | `fromGraph → CanvasHydrator::hydrate` |
| `PaginateCorpusTest` | `paginate-semantics/v1.0/paginate.json` | `paginate-semantics v1.0` | 12 | `fromGraph → CanvasPaginator::fromOptions → paginate` |
| `FlowCorpusTest` | `flow-semantics/v1.1/flow.json` | `flow-semantics v1.1` | 23 | `fromGraph(frames) → DocumentCompiler::compile` |

每个 runner 钉三件事：

1. **契约版本双写互检**：`meta.contract` 与 runner `CONTRACT` 常量精确相等（语料升版本未同步即红）；
2. **用例逐条对账**：产物经 `CorpusDocument::wireBytes()` 编码归一后 `assertSame`——
   json_decode 的数字类型（JSON 不分 int/float）不进断言面，编码串相等 = wire 字节面相等；
3. **错误断言只落 code + 五阶段异常载体**（code → 异常类统一登记在
   `CorpusDocument::EXCEPTION_BY_CODE`，语料可达 14 code，新 code 未登记即红），
   软诊断 warnings 断言 code + path + 序列序，消息人读不入断言面（`spec/pipeline.md` §4/§5）。

求值 runner 另钉 `fn-date.json` 的 `dateTokens` 转译表（语料为权威，`DateFormat::tokenTable()`
向语料对齐）与跨模块文件用例名唯一性。扫描 runner（工票 08 自退役的 `scripts/scan-fixtures.php`
承接）另钉实现仓自检契约：**「切分拼装逐段求值 ≡ 整串求值」互证**（含未闭合尾按字面拼回、
错误片段整串必抛同码错误）——扫描切分与权威求值器漂移即红；语料形状（键集/逐 kind part 键集/
code 白名单）由语料仓 canvasctl validate 严格分派，不在本仓重复。

## PHP-only 关怀用例清点（保留在实现仓的理由）

语料钉不住的用例留在实现仓，分三类：

**A. 对象级不变量**（语料 wire 面原理钉不住）：

- `tests/Hydrate/CanvasHydratorTest::testHydrateDoesNotPolluteSourceCanvas` — 源画布永不回写；
- `tests/Compiler/CanvasPaginatorTest::testPagesShareNoMutableStructure` — 页间零共享可变结构；
- `tests/Compiler/CanvasPaginatorTest::testEachPageSurvivesGraphRoundtrip`、
  `tests/Compiler/DocumentCompilerFlowTest::testEveryFlowProductSurvivesGraphRoundtrip` —
  产物 graph 往返无损（全产物不变量，非单用例语义）；
- `tests/Compiler/DocumentCompilerFlowTest::testUnboundDatasetWithFlowKeepsTemplateFrames` 的
  `assertSame($frame, $product)` — 直通同实例。

**B. 语料缺口补锁**（语料可表达但 v1.x 尚未收例，升格语料走提案通道；列此备查）：

- 求值：调用缺逗号 = 键名而非结构错（`InterpolationEvaluatorTest::testMissingCommaInCallIsKeynameNotStructureError`）；
- 填充：fit 随模板表实例化透传；QR 违例形态；资源单值门的前缀字面/纯空白片段/未闭合片段
  三形态；背景含 mustache 形态原样透传（语料背景用例值为普通串）；未标记字面 src
  （含 `{{`）不进求值与校验面；
- 分页：分页关时越底装饰层与表壳高不判定（语料 passthrough 用例装饰未越底）；嵌套表不驱动
  分页；构造期 pageHeight 类型/取值门全矩阵（语料只钉 `pageHeight: 0`）；split 的 background
  透传与无背景两键形态（语料 paginate 用例均无背景）；
- 流装箱：链外 rowsPath 冲突对 V1 表同拒（语料 rows-path-collides-off-chain 两帧均模板态）；
  quota 超容不钳制由帧内 paginate 裁决；dataset null 流路径恒等直通。

**C. 消息面自钉**（消息人读可本地化，语料不收，PHP 侧自锁形状）：

- `CanvasHydratorTest::testFallbackDiagnosticMessageCarriesExpressionText` — 诊断 message
  携带表达式原文与定位路径。

**D. 组合/骨架**（编译器模板方法、文档编排、渲染/资源/文本/运行时等单元测试不在本票范围，
语料只钉五契约语义，组合语义与单元 API 属实现仓所有）。

## 消费红线

- 语料 submodule 未检出（fresh clone 后先 `git submodule update --init`）runner 即红，
  不设任何降级路径；
- 不从任何导出载体/镜像副本读语义——go-canvas 载体镜像与 PHP 导出脚本
  （`scripts/export-*-fixtures.php`、`scripts/scan-fixtures.php`）已随工票 07/08 退役，
  语料仓是唯一语义源头（`scripts/export-layout-snapshot.php` 除外，布局快照属 wire 字节面）；
- 语料用例不得在本仓复刻手抄——新增语义先入语料（提案通道）再随 pin 移动进本仓。
