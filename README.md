# nr-llm-compat

Runtime LLM compatibility layer for third-party TYPO3 AI extensions.

`nr_llm_compat` takes over the LLM provider calls of installed third-party AI extensions at runtime and routes them through [nr-llm](https://github.com/netresearch/t3x-nr-llm) — centralized provider management, budgets, rate limits, telemetry and privacy policies apply to those extensions without modifying a single line of their code.

## How it works

The extension ships one *integration* per supported third-party extension. An integration declares:

- the Composer package and supported version range,
- the PHP contract it relies on (classes, method signatures, properties — verified via reflection at container build time),
- the interception strategy (currently: DI service class replacement),
- the adapter that reroutes the final provider call into nr-llm.

An integration only activates when **all** of the following hold — otherwise nr-llm does not intercept and the third-party extension behaves as if `nr_llm_compat` were not installed:

1. the third-party extension is installed in a supported version,
2. the verified PHP contract matches (a silent upstream refactoring deactivates the integration instead of fataling in production),
3. the integration is explicitly enabled in the extension configuration (nothing is intercepted by default).

Once an integration is enabled, it is **fail closed**: if nr-llm cannot serve a request, the call fails — it never silently falls back to the third-party extension's own provider, so budgets, policies and telemetry cannot be bypassed by an error path.

## Supported integrations

| Extension | Package | Strategy | Since |
|-----------|---------|----------|-------|
| AI SEO Helper | `passionweb/ai-seo-helper` | DI class replacement | 0.1.0 |
| T3AI | `nitsan/ns-t3ai` | DI class replacement | 0.2.0 |
| AI File Metadata | `mfd/ai-filemetadata` | DI class replacement (vision) | 0.2.0 |
| Texter | `in2code/texter` | Provider configuration | 0.3.0 |

## Diagnostics

```bash
vendor/bin/typo3 nrllm:compat:status
```

reports for every known integration: installed version, contract verification result, strategy, and whether it is active.

## Definition of supported

> An integration is only supported when the third-party extension can be installed unmodified from its official Composer package and its normal workflow runs entirely through nr-llm without the extension's own provider API key.

## Installation

```bash
composer require netresearch/nr-llm-compat
```

Requires TYPO3 13.4 and a configured [nr-llm](https://github.com/netresearch/t3x-nr-llm). Enable individual integrations in the extension configuration of `nr_llm_compat`.

## License

GPL-2.0-or-later
