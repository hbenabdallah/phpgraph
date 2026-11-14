# Agent benchmark

Does an agent do better with phpgraph, and which of its tools earn their place? The same agent, Claude Code in
headless mode, answers the same 24 tasks on five public projects of the [corpus](../corpus/projects.json), with
phpgraph's MCP server and without it. Every answer is scored automatically against a ground truth derived from the
code, not from phpgraph.

## Results

10 November 2026: Claude Code 2.1.322, model `claude-sonnet-5-5` (`--model sonnet`, default effort), phpgraph at
commit `be91513` (after 2.0.0), 24 tasks × 2 arms × 3 runs = 144 runs, $18.74 at list price. No run hit
the token cap or the timeout; two usage limits of the account interrupted the batch, and the runs they broke were run
again (`--resume`), so every task and arm has its 3 runs.

| | phpgraph | baseline |
|---|---|---|
| Success | **70/72** | 66/72 |
| Success, classes compared by short name | 71/72 | **72/72** |
| Tokens per run (mean) | 162,282 (+2 %) | 159,654 |
| Time per run (mean) | **17 s** | 19 s |
| Tool calls per run (mean) | **2.9** | 3.5 |

By kind of task:

| Kind | Arm | Runs | Success | By short name | Tokens (mean) | Cost (USD, total) | Tool calls (mean) | Time (s, mean) |
|---|---|---|---|---|---|---|---|---|
| find | phpgraph | 18 | 18/18 | 18/18 | 111,681 | 1.96 | 1.7 | 13 |
| find | baseline | 18 | 15/18 | 18/18 | 119,928 | 1.98 | 2.3 | 15 |
| impact | phpgraph | 18 | 17/18 | 18/18 | 143,459 | 2.40 | 3.0 | 16 |
| impact | baseline | 18 | 15/18 | 18/18 | 121,422 | 2.18 | 2.7 | 15 |
| explain | phpgraph | 18 | 17/18 | 17/18 | 169,689 | 2.60 | 2.6 | 20 |
| explain | baseline | 18 | 18/18 | 18/18 | 193,705 | 2.56 | 4.4 | 27 |
| fix | phpgraph | 18 | 18/18 | 18/18 | 224,300 | 2.61 | 4.4 | 22 |
| fix | baseline | 18 | 18/18 | 18/18 | 203,559 | 2.44 | 4.4 | 21 |
| all | phpgraph | 72 | 70/72 | 71/72 | 162,282 | 9.58 | 2.9 | 17 |
| all | baseline | 72 | 66/72 | 72/72 | 159,654 | 9.16 | 3.5 | 19 |

What the numbers say:

- **Both arms understand almost every task.** Claude Code with grep finds handlers, callers and seeded bugs on these
  projects: compared by short name, the baseline gets all 72 right.
- **All six baseline failures are names, not understanding.** The agent found the right class but wrote a namespace
  guessed from its path: `PrestaShop\PrestaShopBundle\...\OrderController` for `PrestaShopBundle\...` (3 runs), and
  `Sylius\Bundle\CoreBundle\Tests\...` for the test class `Tests\Sylius\Bundle\CoreBundle\...` (3 runs). With
  phpgraph, ids come from the graph: one such slip in 72 runs (Akeneo, `Storage\Sql` written for `Storage\Save\Query`),
  and it comes from phpgraph's text: `impact_of` lists that dependent as `UpsertCategoryBaseSql (Infrastructure/Storage)`,
  a short name and a shortened location without its full namespace, which the agent completed wrongly. The column "by
  short name" counts these runs as successes; the one phpgraph failure left is a real miss (BookStack revisions without
  `BaseRepo` and `Page`).
- **Explaining a feature is where phpgraph pays:** −12 % tokens, −26 % time, −42 % tool calls, −25 % output tokens,
  with `outline` called once in every explain run. The three explain tasks whose token ranges do not overlap are all
  in phpgraph's favour (Akeneo −29 %, php-ddd-example −18 %, PrestaShop −13 %).
- **Finding:** −7 % tokens, −14 % time, −27 % tool calls, and the only arm without a wrong name.
- **Impact costs more tokens with phpgraph (+18 %)**: `impact_of` returns more than the question needs when a method has
  many callers (BookStack `ActivityLogger::add()`, 24 classes: +48 %); the baseline's greps are short there.
- **Fixing a bug: phpgraph is never called** (0 of 18 runs): the failing test and its stack trace lead to the cause.
  Those runs show what carrying the server costs when it is not used: +10 % tokens (its 8 tool definitions and
  instructions, about 2,000 tokens, read again at every turn).

Variance: the median coefficient of variation of tokens over the 3 runs of a task and arm is 11 %. On 11 of the 24
tasks the token ranges of the two arms do not overlap; 3 runs per arm are too few for more than that. A difference of a
few percent on one task (most find and fix tasks) is within the noise.

Tool usage (phpgraph arm): 51 of the 72 runs call phpgraph. `outline` (18 calls, every explain run), `get_neighbors`
(22), `impact_of` (13) and `get_node` (4) are used; `overview`, `query_graph`, `shortest_path` and `god_nodes` never
are, although the server's instructions say to call `overview` first.

| Task | Runs using phpgraph | get_neighbors | get_node | impact_of | outline |
|---|---|---|---|---|---|
| akeneo-explain-completeness | 3/3 | 0 | 0 | 0 | 3 |
| akeneo-find-upsert-category | 3/3 | 3 | 0 | 0 | 0 |
| akeneo-fix-attribute-identifier | 0/3 | 0 | 0 | 0 | 0 |
| akeneo-fix-completeness-mask | 0/3 | 0 | 0 | 0 | 0 |
| akeneo-impact-category-by-code | 3/3 | 0 | 0 | 3 | 0 |
| bookstack-explain-page-revision | 3/3 | 0 | 0 | 0 | 3 |
| bookstack-explain-search-index | 3/3 | 0 | 0 | 0 | 3 |
| bookstack-find-book-sort | 3/3 | 3 | 3 | 0 | 0 |
| bookstack-impact-activity-add | 3/3 | 0 | 0 | 3 | 0 |
| ddd-explain-course-creation | 3/3 | 2 | 0 | 0 | 3 |
| ddd-find-subscribers | 3/3 | 3 | 1 | 0 | 0 |
| ddd-fix-counter | 0/3 | 0 | 0 | 0 | 0 |
| ddd-impact-event-constructor | 3/3 | 2 | 0 | 1 | 0 |
| prestashop-explain-bulk-delete | 3/3 | 0 | 0 | 0 | 3 |
| prestashop-find-cart-rule-to-order | 3/3 | 3 | 0 | 0 | 0 |
| prestashop-fix-wholesale-price | 0/3 | 0 | 0 | 0 | 0 |
| prestashop-impact-manufacturer-exists | 3/3 | 0 | 0 | 3 | 0 |
| prestashop-impact-redirect-option | 3/3 | 3 | 0 | 0 | 0 |
| sylius-explain-promotion | 3/3 | 0 | 0 | 0 | 3 |
| sylius-find-complete-checkout | 0/3 | 0 | 0 | 0 | 0 |
| sylius-find-resend-email | 3/3 | 3 | 0 | 0 | 0 |
| sylius-fix-cart-quantity-rule | 0/3 | 0 | 0 | 0 | 0 |
| sylius-fix-order-total | 0/3 | 0 | 0 | 0 | 0 |
| sylius-impact-promotions-integrity | 3/3 | 0 | 0 | 3 | 0 |
| Total | 51/72 | 22 | 4 | 13 | 18 |

The graph is built before each phpgraph run, outside the measured time (PHP 8.5 in the container, without opcache or
worker processes): Akeneo 26 s, PrestaShop 20 s, Sylius 18 s, BookStack 4 s, php-ddd-example 0.7 s.

### Reasons in `outline`, namespaces in `impact` (rerun)

Same day, after `outline` gained a reason per class (`[uses Notification]`) and `impact` the whole namespace of each
class: the explain and impact tasks again, phpgraph arm only, 3 runs each (36 runs, batch `20261110-151734`),
compared with the phpgraph arm above.

| Kind | Success before → after | Tokens (mean) | Time (s) | Tool calls |
|---|---|---|---|---|
| explain | 17/18 → 18/18 | 169,689 → 194,504 (+15 %) | 19.7 → 19.3 | 2.6 → 2.9 |
| impact | 17/18 → 16/18 | 143,459 → 138,165 (−4 %) | 15.9 → 14.3 | 3.0 → 2.8 |

The reasons do not pay on these tasks: one explanation more succeeds, but tokens grow by 15 %, mostly on two tasks
(Akeneo +77 %, BookStack search +33 %, ranges disjoint) where the agents added one or two greps after the outline to
find the full names of the classes; the tags themselves are 5 to 10 % of the outline. The whole namespaces in
`impact` fixed the Akeneo slip (2/3 → 3/3); the two impact failures left are the trap of PrestaShop's two
`RedirectOption` classes (`impact_of` on a constructor finds no `new`, so the agent greps) and a test namespace
guessed from its path in Sylius.

A day later, with the classes left out of a full core named in full: the same two tasks, 3 runs each (batch
`20261111-155303`): Akeneo 222,640 → 209,499 tokens, BookStack search 197,211 → 178,543, all runs successful. The
greps stayed: the agents look for classes the outline already places in their namespace, and for types seen only in
signatures, to read the code. Whether the tags themselves cause the extra turns is not measured yet (a rerun with
the text tags removed).

### Per task

| Task | Kind | Arm | Success | By short name | Recall | Precision | Tokens (mean ± sd) | Cost (USD) | Tool calls | Time (s) |
|---|---|---|---|---|---|---|---|---|---|---|
| akeneo-explain-completeness | explain | phpgraph | 3/3 | 3/3 | 1.00 | 0.88 | 126,064 ± 29,816 | 0.13 | 1.7 | 24 |
| akeneo-explain-completeness | explain | baseline | 3/3 | 3/3 | 1.00 | 0.87 | 176,358 ± 21,732 | 0.12 | 4.0 | 39 |
| akeneo-find-upsert-category | find | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 108,119 ± 23,456 | 0.06 | 1.7 | 14 |
| akeneo-find-upsert-category | find | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 109,546 ± 34 | 0.06 | 2.7 | 15 |
| akeneo-fix-attribute-identifier | fix | phpgraph | 3/3 | 3/3 | – | – | 241,178 ± 23,358 | 0.10 | 4.7 | 25 |
| akeneo-fix-attribute-identifier | fix | baseline | 3/3 | 3/3 | – | – | 219,255 ± 20,686 | 0.09 | 4.7 | 26 |
| akeneo-fix-completeness-mask | fix | phpgraph | 3/3 | 3/3 | – | – | 220,056 ± 1,096 | 0.10 | 4.0 | 23 |
| akeneo-fix-completeness-mask | fix | baseline | 3/3 | 3/3 | – | – | 191,760 ± 493 | 0.08 | 4.0 | 28 |
| akeneo-impact-category-by-code | impact | phpgraph | 2/3 | 3/3 | 0.90 | 0.90 | 140,479 ± 26,286 | 0.08 | 2.3 | 19 |
| akeneo-impact-category-by-code | impact | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 128,956 ± 26,009 | 0.04 | 2.7 | 20 |
| bookstack-explain-page-revision | explain | phpgraph | 2/3 | 2/3 | 0.83 | 1.00 | 153,844 ± 25,525 | 0.10 | 2.3 | 14 |
| bookstack-explain-page-revision | explain | baseline | 3/3 | 3/3 | 0.94 | 0.86 | 144,837 ± 26,546 | 0.09 | 4.3 | 16 |
| bookstack-explain-search-index | explain | phpgraph | 3/3 | 3/3 | 1.00 | 0.82 | 148,303 ± 29,829 | 0.09 | 2.3 | 15 |
| bookstack-explain-search-index | explain | baseline | 3/3 | 3/3 | 1.00 | 0.86 | 192,775 ± 26,351 | 0.11 | 5.3 | 18 |
| bookstack-find-book-sort | find | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 135,007 ± 24,306 | 0.06 | 2.3 | 11 |
| bookstack-find-book-sort | find | baseline | 3/3 | 3/3 | 1.00 | 0.92 | 125,257 ± 26,031 | 0.07 | 2.3 | 13 |
| bookstack-impact-activity-add | impact | phpgraph | 3/3 | 3/3 | 1.00 | 0.95 | 214,859 ± 30,972 | 0.13 | 5.7 | 20 |
| bookstack-impact-activity-add | impact | baseline | 3/3 | 3/3 | 1.00 | 0.99 | 144,772 ± 26,530 | 0.10 | 3.7 | 20 |
| ddd-explain-course-creation | explain | phpgraph | 3/3 | 3/3 | 0.96 | 0.98 | 126,276 ± 1,036 | 0.08 | 2.3 | 12 |
| ddd-explain-course-creation | explain | baseline | 3/3 | 3/3 | 1.00 | 0.94 | 154,707 ± 3,616 | 0.09 | 3.7 | 18 |
| ddd-find-subscribers | find | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 95,160 ± 24,138 | 0.06 | 1.7 | 8 |
| ddd-find-subscribers | find | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 110,449 ± 119 | 0.06 | 2.7 | 11 |
| ddd-fix-counter | fix | phpgraph | 3/3 | 3/3 | – | – | 169,890 ± 3,774 | 0.08 | 3.3 | 13 |
| ddd-fix-counter | fix | baseline | 3/3 | 3/3 | – | – | 167,129 ± 24,964 | 0.08 | 3.7 | 13 |
| ddd-impact-event-constructor | impact | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 137,264 ± 48,006 | 0.07 | 3.0 | 10 |
| ddd-impact-event-constructor | impact | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 100,912 ± 23,947 | 0.07 | 2.3 | 10 |
| prestashop-explain-bulk-delete | explain | phpgraph | 3/3 | 3/3 | 0.96 | 0.93 | 284,361 ± 3,537 | 0.24 | 4.0 | 24 |
| prestashop-explain-bulk-delete | explain | baseline | 3/3 | 3/3 | 0.85 | 0.95 | 325,711 ± 20,036 | 0.23 | 6.3 | 31 |
| prestashop-find-cart-rule-to-order | find | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 98,642 ± 81 | 0.17 | 1.0 | 13 |
| prestashop-find-cart-rule-to-order | find | baseline | 0/3 | 3/3 | 0.50 | 0.69 | 135,753 ± 70 | 0.17 | 2.0 | 19 |
| prestashop-fix-wholesale-price | fix | phpgraph | 3/3 | 3/3 | – | – | 297,367 ± 31,693 | 0.21 | 5.0 | 23 |
| prestashop-fix-wholesale-price | fix | baseline | 3/3 | 3/3 | – | – | 276,673 ± 27,997 | 0.21 | 5.7 | 21 |
| prestashop-impact-manufacturer-exists | impact | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 100,552 ± 22 | 0.17 | 2.0 | 15 |
| prestashop-impact-manufacturer-exists | impact | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 90,447 ± 26 | 0.16 | 1.0 | 9 |
| prestashop-impact-redirect-option | impact | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 150,206 ± 272 | 0.18 | 3.3 | 14 |
| prestashop-impact-redirect-option | impact | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 140,026 ± 1,028 | 0.18 | 3.7 | 16 |
| sylius-explain-promotion | explain | phpgraph | 3/3 | 3/3 | 1.00 | 0.87 | 179,284 ± 60,247 | 0.22 | 2.7 | 29 |
| sylius-explain-promotion | explain | baseline | 3/3 | 3/3 | 1.00 | 0.87 | 167,840 ± 74,627 | 0.20 | 3.0 | 39 |
| sylius-find-complete-checkout | find | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 117,350 ± 25,128 | 0.15 | 1.7 | 13 |
| sylius-find-complete-checkout | find | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 119,248 ± 1,338 | 0.15 | 2.0 | 14 |
| sylius-find-resend-email | find | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 115,805 ± 25,550 | 0.15 | 1.7 | 17 |
| sylius-find-resend-email | find | baseline | 3/3 | 3/3 | 1.00 | 1.00 | 119,317 ± 206 | 0.15 | 2.0 | 17 |
| sylius-fix-cart-quantity-rule | fix | phpgraph | 3/3 | 3/3 | – | – | 181,203 ± 6,459 | 0.18 | 3.7 | 22 |
| sylius-fix-cart-quantity-rule | fix | baseline | 3/3 | 3/3 | – | – | 168,187 ± 56 | 0.17 | 4.0 | 16 |
| sylius-fix-order-total | fix | phpgraph | 3/3 | 3/3 | – | – | 236,108 ± 50,620 | 0.19 | 6.0 | 23 |
| sylius-fix-order-total | fix | baseline | 3/3 | 3/3 | – | – | 198,353 ± 53,331 | 0.18 | 4.7 | 22 |
| sylius-impact-promotions-integrity | impact | phpgraph | 3/3 | 3/3 | 1.00 | 1.00 | 117,394 ± 26,096 | 0.15 | 1.7 | 16 |
| sylius-impact-promotions-integrity | impact | baseline | 0/3 | 3/3 | 0.80 | 0.57 | 123,418 ± 2,473 | 0.17 | 3.0 | 17 |

### Hard tasks (pilot)

13 November 2026: does phpgraph answer what grep cannot, when the answer goes through something a search of the
obvious name does not see? Four `impact` tasks (`tools/agent-bench/tasks/*-hard-*.yaml`), written so that a grep of
the class or method named in the prompt finds none of the expected classes. Claude Code 2.1.323, phpgraph at commit
`363df2f`, 1 run per task, arm and model (12 runs, $1.94).

| Task | Indirection to follow |
|---|---|
| sylius-hard-promotion-processor-triggers | callers of the composite order processor, given by `service()` in the container config; trap: `ProcessOrderListener` holds the same interface but receives the payment processor |
| stayhub-hard-inventory-release-callers | callers in another service of `POST /api/inventory/releases`, through an HTTP client and a path string |
| sylius-hard-handlers-reaching-promotion-processor | API command handlers reaching `OrderPromotionProcessor` in 1 to 3 hops: composite, `OrderModifier`, promotion code assigner, integrity checker, checkout transitions heard by tagged event listeners |
| akeneo-hard-controllers-reaching-category-by-code | controllers reaching `GetCategoryInterface::byCode()` through a custom command bus (`tagged_iterator` indexed by command), savers and SQL builders; traps: controllers holding the interface but calling `byId()` |

| Task | Model | Arm | Recall | Precision | Tokens | Time (s) |
|---|---|---|---|---|---|---|
| sylius-hard-promotion-processor-triggers | sonnet | phpgraph | 0.89 | 1.00 | 264,956 | 30 |
| sylius-hard-promotion-processor-triggers | sonnet | baseline | 0.89 | 1.00 | 243,380 | 28 |
| sylius-hard-promotion-processor-triggers | haiku | phpgraph | 1.00 | 0.90 | 451,550 | 34 |
| sylius-hard-promotion-processor-triggers | haiku | phpgraph, `e103e69`, 3 runs | 1.00 | 1.00 | 437,863 ± 69,915 | 29 |
| sylius-hard-promotion-processor-triggers | haiku | baseline | 1.00 | 1.00 | 288,506 | 29 |
| stayhub-hard-inventory-release-callers | sonnet | phpgraph | 1.00 | 1.00 | 224,120 | 11 |
| stayhub-hard-inventory-release-callers | sonnet | baseline | 1.00 | 1.00 | 124,127 | 10 |
| stayhub-hard-inventory-release-callers | haiku | phpgraph | 1.00 | 1.00 | 249,497 | 16 |
| stayhub-hard-inventory-release-callers | haiku | baseline | 1.00 | 1.00 | 237,703 | 15 |
| sylius-hard-handlers-reaching-promotion-processor | sonnet | phpgraph | 1.00 | 1.00 | 377,930 | 25 |
| sylius-hard-handlers-reaching-promotion-processor | sonnet | baseline | 1.00 | 1.00 | 246,932 | 27 |
| akeneo-hard-controllers-reaching-category-by-code | sonnet | phpgraph | 1.00 | 1.00 | 181,774 | 19 |
| akeneo-hard-controllers-reaching-category-by-code | sonnet | baseline | 1.00 | 1.00 | 381,911 | 33 |

No recall gap: on every task both arms reach the full answer, with sonnet and with haiku. The agents without the
graph grep the interface or the path string, then read the container config, and take the extra hops too; a grep of
the obvious name missing the answer does not mean the agent misses it. The two Sylius sonnet runs at 0.89 left out
`CompositeOrderProcessor`, which the prompt did not ask for (it is now `allowed`). Tokens go both ways: −52 % with
phpgraph on Akeneo, the largest project, +53 % on the Sylius multi-hop task and +80 % on stayhub; with one run each,
that is within run-to-run variance. The pilot was stopped there: the hard tasks do not separate the arms on this
corpus, so the full set planned after it was not written.

One fault of phpgraph came out: `impact_of` goes from `OrderProcessorInterface::process()` to every caller of the
interface, whichever service the container injects into each, so haiku with phpgraph listed the `ProcessOrderListener`
trap that the baseline avoided by reading the config. Fixed at commit `e103e69`: `impact` leaves out a caller the
configuration gives another implementation, and reads the decorators of a service (the composite the container
injects in its place). The haiku phpgraph arm again, 3 runs (Claude Code 2.1.325, batch `20261113-205206`, $0.06):
precision 1.00 on every run, tokens unchanged within variance (366,237 to 505,932).

### Impact without the extra cost (2.1)

13 November 2026: on 10 November, impact cost +18 % tokens with phpgraph, and carrying the server cost about 2,000
tokens on every turn. Since then the server lists 4 tools instead of 8 (11.2 KB → 4.8 KB sent before the first
call), and `impact_of` answers in a compact form past 20 dependents (BookStack `ActivityLogger::add()`: 18.5 → 6.2
KB, every expected class still named). The 6 impact tasks again, both arms, 3 runs each, Claude Code 2.1.323
(batch `20261113-142124`, $5.34). Two tasks were run again with the phpgraph arm only after wording fixes found by
reading the transcripts (agents were asking for the full lists, `limit: 0`, because the descriptions offered it):
PrestaShop manufacturer (batch `20261113-162124`) and BookStack (`20261113-172327`, after three more batches); the
table takes the last batch of each task.

| Task | Success with / without | Tokens, median with / without | |
|---|---|---|---|
| akeneo-impact-category-by-code | 3/3 / 3/3 | 86,174 / 174,735 | −51 % |
| bookstack-impact-activity-add | 3/3 / 3/3 | 189,005 / 173,159 | +9 % |
| ddd-impact-event-constructor | 3/3 / 3/3 | 126,977 / 122,241 | +4 % |
| prestashop-impact-manufacturer-exists | 3/3 / 3/3 | 153,993 / 98,547 | +56 % |
| prestashop-impact-redirect-option | 3/3 / 1/3 | 207,694 / 149,381 | +39 % |
| sylius-impact-promotions-integrity | 1/3 / 1/3 | 137,316 / 135,457 | +1 % |
| Total (18 runs per arm) | 16/18 / 14/18 | 2,651,855 / 2,701,972 | −1.9 % |

- Impact no longer costs more in total (+18 % on 10 November, −1.9 % now), but the tasks differ: half on Akeneo, the
  largest project, where one `impact_of` call replaces the greps; more on PrestaShop manufacturer, where the agent
  still checks the answer with one grep that alone would have answered.
- `prestashop-impact-redirect-option` says nothing about phpgraph: its agents never called it (the prompt asks for
  every class constructing an object; they grep `new RedirectOption`). Its success 3/3 against 1/3 and its +39 %
  are run-to-run variance.
- The fix tasks were not measured again cleanly: a first rerun (batch `20261113-132609`) moved by more than the
  ~1,600 tokens per turn saved (6.4 KB), on fix tasks that never call phpgraph too (a new Claude Code version the
  same day). The lighter tool list is a size, not a measured saving.


## Method

### Arms

| Arm | Tools |
|---|---|
| `phpgraph` | Bash, Read, Grep, Glob, Edit, Write, and the 8 tools of phpgraph's MCP server (`serve`) |
| `baseline` | Bash, Read, Grep, Glob, Edit, Write |

Both arms run `claude -p` with the same prompt, model and options: `--setting-sources project` (the user's global
instructions are not loaded; the project's own `CLAUDE.md`, as Sylius and PrestaShop have, is), `--strict-mcp-config`
(only the servers of the run), no slash commands, no session saved, permissions skipped (the run is in a container).
The phpgraph arm gets a graph built before the run, outside the project's directory, so that the baseline never sees
it and the build time is reported apart. Each run records whether a shell command of the agent mentions phpgraph:
none of the baseline runs does.

Each run works on a throwaway copy of the checkout, without `.git` (a seeded bug would show in `git diff`) and without
`phpgraph-out/`; `corpus/checkouts/` is never modified. The copies are deleted after scoring; the stream of events of
each run and its changes (a diff against the original checkout) are kept in `build/agent-bench/runs/`.

### Tasks

Four kinds, in `tools/agent-bench/tasks/*.yaml`. Each file holds the prompt, the expected answer, and a `checked:`
field saying how that answer was derived from the code (grep commands, files and lines read).

| Kind | The agent is asked to | Scored by |
|---|---|---|
| find | name the handler of a message, the controller of a route, the classes behind it | recall and precision of the classes named |
| impact | list every class that must change when a signature changes | recall and precision |
| explain | explain a feature and list the classes of its flow | recall over the classes of the flow; precision over those plus the classes an explanation may name without being wrong (`allowed`) |
| fix | fix a seeded bug that makes a test fail, without changing the tests | the test commands: the first fails before the run, all pass after; no file under `tests/` changed |

Answers to find, impact and explain come back as structured output (`--json-schema`): a list of fully qualified class
names and a short explanation. A name without namespace counts when it designates a single expected or allowed class;
`Class::method` counts as its class. A run succeeds when recall and precision reach the task's thresholds
(`min_recall`, `min_precision`: 1 and 0.75 for most find and impact tasks, 0.7 to 0.8 for explanations).

Seeded bugs are patches (`tools/agent-bench/patches/`) applied to the copy. Each one is placed one or two layers below
the failing test, often in another component or bounded context: `Uuid::equals()` of php-ddd-example's shared kernel
under a test of the courses counter; Sylius' base `Order` under a test of Core's `Order`; Akeneo's Structure public API
under a spec of Enrichment's completeness. Each was checked by hand: the tests fail with the patch and pass without it.
The harness checks the first again before every run.

Some tasks hold traps for a tool linking by name: PrestaShop has two `RedirectOption` classes and two
`ManufacturerRepository` classes; Akeneo's `GetCategoryInterface` has callers of `byId()` that do not call `byCode()`;
BookStack's `ActivityLogger::add()` is called through a facade.

| Project | find | impact | explain | fix |
|---|---|---|---|---|
| php-ddd-example | subscribers of an event | constructor of an event | course creation | `Uuid::equals()` |
| Sylius | handler and dispatcher of a command; message and handler of an API operation | `OrderPromotionsIntegrityCheckerInterface::check()` | promotions applied to an order | base `Order` adjustments; comparison operator of promotion rules |
| PrestaShop | handler and controller of a CQRS command | `assertManufacturerExists()`; constructor of `RedirectOption` | bulk product deletion | `UpdateProductCommand::setWholesalePrice()` |
| BookStack | controller and classes of the sort route | `ActivityLogger::add()` | page revisions; search indexing | none: every BookStack test, `tests/Unit` included, opens a MySQL transaction |
| Akeneo PIM | handler and controller of a category command | `GetCategoryInterface::byCode()` | product completeness | attribute identifier; required attributes mask |

The test commands run with PHP 8.5, the version phpgraph needs; projects pinned to an older PHP print deprecations
from `vendor/`, which the commands send to stderr (`-d display_errors=stderr`) or leave out (`error_reporting=8191`,
Akeneo's phpspec) so that they are not counted as failures.

### What is measured

| Measure | Meaning |
|---|---|
| Success | runs meeting the task's thresholds, out of 3 |
| Recall, precision | means over the runs |
| Tokens | input + cache creation + cache read + output, summed over the API calls of the run, as Claude Code reports them |
| Cost | Claude Code's `total_cost_usd`, at list price |
| Tool calls | calls of all tools, the structured answer excepted; phpgraph tools are counted by name |
| Time | wall time of `claude -p`, without the copy and the graph build |

A run is stopped at 3,000,000 tokens (`--max-tokens`) or 900 s (`--timeout`) and counts as a failure.

### Reproducing

Requirements: Docker, Claude Code logged in, the corpus checkouts (`composer corpus:fetch`).

```sh
tools/agent-bench/bench check                    # patches apply, expected classes are declared, no agent
tools/agent-bench/bench                          # every task, both arms, 3 runs (--runs, --tasks, --arms, --model)
tools/agent-bench/bench report                   # the tables of this page, from the last batch
```

`bench` builds an image (PHP 8.5, git, ripgrep) and runs `run.php` in it, with the host's Claude Code binary and login
mounted: the agents' shells, edits and tests stay in the container.
