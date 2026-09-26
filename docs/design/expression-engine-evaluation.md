# 表达式取值接入 CEL 评估报告

- 日期：2026-09-18
- 评估口径：v1 需求为**文本插值**（`TextLayer` 文案中的变量取值/拼接/简单条件/数字格式化）；一致性机制预设为**前后端双端各自实现同一语言规范**；约束为**核心包依赖红线不破**（composer require 只有 `php + endroid/qr-code`，PHP `^8.3`）。
- 结论（TL;DR）：**在当前约束与 v1 范围下，不值得接入 CEL。** 原因不是 CEL 语言不好，而是它的一致性卖点在本项目兑现不了：PHP 侧没有可用实现（唯一实现要求 PHP ≥ 8.4），JS 侧只有社区实现；而插值 v1 真正需要的「外壳 + 格式化」恰恰都不在 CEL 规格核心里。建议改走**双端自研受限插值求值器 + 共享一致性 fixture**，CEL 留作表达式需求升级后的重评选项。

## 一、关键事实（2026-09 查证）

### 1. PHP 侧：CEL「有库，但引不进来」

- 唯一像样的实现是 [carthage-software/cel-php](https://github.com/carthage-software/cel-php)（azjezz，php-standard-library 作者）。质量尚可、活跃（2.0.0，2026-07），自称 fully spec-compliant，但**所有已发布版本 `require php: ~8.4 || ~8.5`，不支持 PHP 8.3**（packagist 元数据核实）。
- 本项目 PHP `^8.3` → **当前用不了**。除非升 PHP，否则 PHP 侧 CEL 等于自研一个语言子集求值器并自己跑 conformance——对一个文本插值 v1 是严重的成本错配。
- packagist 上没有第二个候选；symfony/expression-language 活跃但 PHP-only、无 JS 对应物、无共享 spec，做不了双端一致。

### 2. JS 侧：全社区实现，无官方背书

- 官方（cel-expr 组织，2026-06 迁入）没有 JS 实现；JS 全靠社区：
  - [@bufbuild/cel](https://github.com/bufbuild/cel-es)：Buf 公司出品，最接近生产级——机构维护、自带 cel-spec 测试数据、以跑官方 conformance 为目标（未公布通过率），但仍自称 **beta**，gzip ~40KB。
  - [@marcbachmann/cel-js](https://github.com/marcbachmann/cel-js)：零依赖、报错定位最好（AST + 错误 range），但个人维护、一年 8 个大版本；自称 >90% conformance（维护者自述，未独立证实）。
  - `cel-js`（ChromeGG）：2025-07 后停更。
- WASM 路线（cel-cpp + Emscripten）只有 POC 级案例，MB 级体积，不适合编辑器。

### 3. CEL 规格与「文本插值」需求的错位

- **无模板语法**：`{{ }}` 外壳拆片、空白处理、错误提示全要自建；插值提案被关闭，spec 至今无 string template。CEL-T 是 Tekton 的私有包装，不是 spec。
- **格式化能力分裂**：`string.format()` 2025 年才进 spec 的**扩展文档**（非核心库）；日期格式化只在 cel-go 的私有扩展里（Go 布局），spec 只有 RFC3339；无本地化/中文日期。
- **双端漂移实锤**：`string(12.0)` 在 langdef 未钉死，cel-go 输出 `"12"`、cel-java 历史上输出 `"12.0"`——这正是本项目要防的事故类型，即用了 CEL 也必须禁用裸 `string()`、只用 spec 钉死的子集。
- **无先例**：主流用途是策略/鉴权/校验（K8s、Envoy），产出用户可见文案的公开案例零星；甚至有团队公开写了反向选择（incident.io「Why we chose JavaScript over CEL」）。
- spec 仍在变大（21 个月 15 个 release，2025-04 改过文法保留字）→ 自建实现长期追平是持续成本。

### 4. 一致性卖点在本项目兑现不了

CEL 最大卖点 = spec + 官方 conformance 套件锁跨端行为。但：

- 官方 conformance **参考驱动只有 Go/Java/C++**，PHP 要自己写驱动读 textproto 用例；
- JS 侧社区实现自称 ~90%，无认证；
- 结论：双端一致性**仍然要靠我们自建的「子集契约 + 共享用例集」兜底**——这份工作量选任何方案都躲不掉，CEL 并不能替你省掉它。

## 二、候选全景（双栈 = PHP + JS 都有实现）

| 方案 | 插值 v1 表达力 | PHP 侧 | JS 侧 | 一致性机制 | 判断 |
|---|---|---|---|---|---|
| **CEL** | 取值/拼接/条件 ✓；模板外壳 ✗；格式化在扩展区 | ✗ 无可用实现（8.4+） | △ beta/个人 | conformance 公开但驱动无 PHP | **本场景错位** |
| **Mustache/Handlebars** | 取值/拼接/条件 ✓；格式化全靠 helper 双端各写 | △ LightnCandy 停更，继任 devtheorem/php-handlebars 很新 | ✓ handlebars.js 成熟 | 模板语法有 spec；helper 漂移 | 求快的备选 |
| **Liquid** | filter 体系格式化最现成（date/money） | △ keepsuit/php-liquid v0.x、单人 | ✓ liquidjs 活跃 | 差异页非 spec | PHP 侧不可托底 |
| **JsonLogic** | 文案内插值错位（JSON 树非文本语言） | ✓ 官方同源 | ✓ 官方 | 官方共享测试 | 适合未来**显隐规则**，非插值 |
| **JMESPath** | 查询语言，无拼接/格式化 | ✓ 官方 | ✓ 官方 | ✓ 共享 compliance suite | 只适合纯取值 |
| **JSONata** | 表达力最强（$formatNumber 等） | ✗ port 0.x 单人 | ✓ 官方 | 官方 corpus 可共享 | PHP 侧不成熟 |
| **自研受限插值** | 按需裁剪，格式化语义收进自己的 fixture | 自建（零依赖可行） | 自建 | **自建共享 JSON fixture，双端 CI 互跑** | **风险收益比最优** |

## 三、判决：不值得（当前约束 + v1 范围下）

1. **落地成本**：PHP 8.3 下 CEL 无库可引 → 自研 CEL 子集求值器。CEL 规格含类型系统/宏/optional 语法，远超 `{{a.b}}` 插值所需；而 spec 仍在演进，追平无上限。
2. **卖点折损**：双端一致的核心保障（官方 conformance）在 PHP/JS 两侧都拿不到现成的，最终仍依赖自建 fixture——选 CEL 并没有省掉一致性工作，只是把求值器换成了更复杂的语言。
3. **需求错位**：插值 v1 的两个大头——模板外壳与数字/日期格式化——恰在 CEL 规格核心之外；`string(double)` 双端漂移史说明「用了 CEL 一致性也不自动成立」。
4. **机会成本**：自研受限插值（mustache 风格 `{{ expr }}` + 变量路径 + 三元/比较 + 白名单格式化函数）每端约 500–1000 行，天然非图灵完备（无循环 → 无 DoS），格式化语义第一次实现就收进自己的 fixture，反而是一致性能被真正锁死的唯一位置。

### 重评触发条件（满足任一再回头看 CEL）

- PHP 升到 8.4+：cel-php 立即可用，可作为「显隐规则引擎」类重需求重审；
- 表达式需求从插值膨胀到复杂规则组合（多层条件、集合运算）；
- @bufbuild/cel 转 stable 且出现符合 conformance 的官方系 PHP 实现。

## 四、建议路线（若采纳插值能力）

1. **核心包只加契约**：`Contracts/ExpressionEvaluatorInterface`（编译/求值，与现有 `LineBreakerInterface`、`TextMeasurerFactoryInterface` 注入模式同构）；表达式在 `graph()` 里就是普通字符串字段，往返恒等天然成立。
2. **实现放伴随包或零依赖小包**（红线不破）：mustache 风格外壳 + 受限表达式子集（字面量/路径/拼接/三元/比较/`format*` 白名单函数），解析错误带位置信息（编辑器需要）。
3. **双端共享 JSON fixture** 是一致性契约的物理载体：一份用例文件（含中文、金额 `%.2f`、缺字段兜底、null 语义），PHP 与前端 CI 各跑一遍；借鉴 jmespath/jsonata/mustache/CEL 各家的 compliance 模式。
4. **先定语义再写码**：缺字段报错 vs 兜底空串、数字格式化舍入规则（避免银行家舍入 vs 四舍五入漂移）、日期格式化函数集——这三处是所有现成方案的漂移盲区，必须单独立 spec。
5. 未来「图层显隐条件」与插值共享同一求值器的 bool 上下文；届时若规则复杂度超出受限表达式，再并行评估 JsonLogic（显隐是其主场，与插值方案互斥不冲突）。

## 附：主要来源（2026-09-18 查证）

- [carthage-software/cel-php](https://github.com/carthage-software/cel-php) / [Packagist](https://packagist.org/packages/carthage-software/cel-php)（PHP ≥8.4 约束）
- [@bufbuild/cel](https://www.npmjs.com/package/@bufbuild/cel) / [bufbuild/cel-es](https://github.com/bufbuild/cel-es)（conformance issue #137、beta 状态）
- [@marcbachmann/cel-js](https://github.com/marcbachmann/cel-js)、[cel-js (ChromeGG)](https://registry.npmjs.org/cel-js)
- [cel-spec langdef](https://github.com/cel-expr/cel-spec/blob/master/doc/langdef.md)、[strings 扩展](https://github.com/cel-expr/cel-spec/blob/master/doc/extensions/strings.md)、[conformance 用例](https://github.com/cel-expr/cel-spec/tree/master/tests/simple/testdata)、[cel-go#640 插值提案已关闭](https://github.com/cel-expr/cel-go/issues/640)
- [mustache/mustache](https://packagist.org/packages/mustache/mustache)、[LightnCandy](https://github.com/zordius/lightncandy)、[devtheorem/php-handlebars](https://github.com/devtheorem/php-handlebars)、[keepsuit/php-liquid](https://github.com/keepsuit/php-liquid)、[liquidjs](https://liquidjs.com/tutorials/differences.html)、[jmespath.php](https://github.com/jmespath/jmespath.php/releases)、[json-logic-php](https://packagist.org/packages/jwadhams/json-logic-php)、[jsonata-php](https://github.com/JhumanJ/jsonata-php)
- [Tekton CEL-T (TEP-0145)](https://tekton.dev/docs/pipelines/additional-configs/)、[cel-expr 组织迁移公告](https://opensource.googleblog.com/2026/06/cel-finds-a-new-home-at-githubcomcel-expr.html)
