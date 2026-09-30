<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# nr-llm-compat

Runtime LLM compatibility layer for third-party TYPO3 AI extensions.

`nr_llm_compat` takes over the LLM provider calls of installed third-party AI extensions at runtime and routes them through [nr-llm](https://github.com/netresearch/t3x-nr-llm) — centralized provider management, budgets, rate limits, telemetry and privacy policies apply to those extensions without modifying a single line of their code.

## How it works

The extension ships one *integration* per supported third-party extension. An integration declares:

- the Composer package and supported version range,
- the PHP contract it relies on (classes, method signatures, properties — verified via reflection at container build time),
- the strategy (DI service class replacement, provider configuration, or tool provision),
- the adapter that reroutes the final provider call into nr-llm — or, for an extension that makes no LLM calls but has no nr-llm writer for its records, the tool this layer ships on its behalf.

An integration only activates when **all** of the following hold — otherwise nr-llm does not intercept and the third-party extension behaves as if `nr_llm_compat` were not installed:

1. the third-party extension is installed in a supported version,
2. the verified PHP contract matches (a silent upstream refactoring deactivates the integration instead of fataling in production),
3. the integration is explicitly enabled in the extension configuration (nothing is intercepted by default).

Once an integration is enabled, it is **fail closed**: if nr-llm cannot serve a request, the call fails — it never silently falls back to the third-party extension's own provider, so budgets, policies and telemetry cannot be bypassed by an error path.

## Supported integrations

| Extension | Package | Strategy | Since |
|-----------|---------|----------|-------|
| AI SEO Helper | `passionweb/ai-seo-helper` | DI class replacement | 0.1.0 |
| T3AI | `nitsan/ns-t3ai` | DI class replacement | 0.1.0 |
| AI File Metadata | `mfd/ai-filemetadata` | DI class replacement (vision) | 0.1.0 |
| Texter | `in2code/texter` | Provider configuration | 0.1.0 |
| Exception Solver | `eliashaeussler/typo3-solver` | Provider configuration | 0.1.0 |
| News | `georgringer/news` | Tool provision (`create_news_draft`) | 0.2.0 |

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

Requires TYPO3 13.4 or 14.3 and a configured [nr-llm](https://github.com/netresearch/t3x-nr-llm). Enable individual integrations in the extension configuration of `nr_llm_compat`.

## Security

Which credentials the extension handles, what data each integration sends to LLM providers through nr-llm, and what users can and cannot expect in terms of security is in [docs/SECURITY-ASSURANCE.md](docs/SECURITY-ASSURANCE.md). Report vulnerabilities privately as described in the organisation's [SECURITY.md](https://github.com/netresearch/.github/blob/main/SECURITY.md), not in a public issue. A change that adds or removes a security control updates that document.

## Governance and policies

This extension follows the organisation-wide Netresearch policies:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md): ownership, roles and their responsibilities, how decisions are made and how disagreements are resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md): planned and excluded work for the next twelve months. It applies here because this repository has no `ROADMAP.md` of its own.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings): which vulnerability, licence and static-analysis findings must be fixed, by when, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management): where CI and release credentials are stored, who may use them, and when they are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md): the accounts with admin, maintain or write access to this repository.

Checks that run on every pull request in this repository:

- `.github/workflows/checks.yml`: Composer Audit (fails on any advisory for an installed package) and Opengrep SAST with the `auto` rule set, run with `--error --severity WARNING` (fails on any finding it reports), both through `security.yml` of `netresearch/typo3-ci-workflows`; Dependency Review (fails on added or changed dependencies with a vulnerability of severity high or higher); PHP licence check (`license-check.yml`, fails on an SSPL or BSL licensed Composer dependency); CodeQL for the workflow files (the repository has no JavaScript or Go, and CodeQL has no PHP analyser); Betterleaks secret scanning; zizmor for the workflow files. The `fuzz` job is called but runs nothing here, as `Build/phpunit.xml` has no fuzz test suite.
- `.github/workflows/ci.yml`: PHPStan level 10 (`ci:test:php:phpstan`), unit and functional tests for PHP 8.2 to 8.5 and TYPO3 13.4 and 14.3; PHP lint once per PHP version; code style (`ci:test:php:cgl`), Rector (`ci:test:php:rector`) and the isolated typo3-solver test environment (`ci:test:repo`) once, on PHP 8.2.
- `.github/workflows/harness-verify.yml`: `Build/Scripts/verify-harness.sh` checks that `AGENTS.md` and `docs/` match the repository.

No exception is recorded: `composer.json` has no `config.audit.ignore` entry.

## License

GPL-2.0-or-later
