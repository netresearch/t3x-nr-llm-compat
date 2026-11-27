<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# LLM Compatibility Layer for TYPO3

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
| Sitescore | `in2code/sitescore` | Provider configuration | 0.5.0 |

AI File Metadata is supported up to 1.6.4 and 1.7.1, the verified releases (`>=1.6.0 <1.6.5 || >=1.7.0 <1.7.2`). The bridge rebuilds the extension's OpenAI request data itself, and upstream already changed that contract in a patch release (1.7.1), so each new release is diffed against the bridge before the range is widened. From 1.7.1 on, the extension lets listeners of its `ModifyRequestDataEvent` change the OpenAI request; the bridge dispatches that event too and passes on a changed system prompt as nr-llm's prompt, which every nr-llm vision provider sends. nr-llm sends that prompt as the text part of the user message, not as a system message, and the original's fixed user text ("Generate alt text.") is not sent; the same holds for the extension's own prompt. A detail level of `auto` counts as unchanged, because nr-llm sends `auto` when none is set. If a listener changes anything else, for example adds tools, picks another model, or changes the temperature, a token limit or the image's detail level to `low` or `high`, the bridge refuses that request with an exception that names the changed keys (ai_filemetadata's upload listener logs it as a warning), so no alt text is generated for that file. nr-llm's vision providers do not send a temperature ([netresearch/t3x-nr-llm#1474](https://github.com/netresearch/t3x-nr-llm/issues/1474)), and nr-llm reports no finish reason ([netresearch/t3x-nr-llm#1473](https://github.com/netresearch/t3x-nr-llm/issues/1473)), so an answer cut off at a listener's token limit could not be detected. Only the OpenAI-style providers send an image detail level, and the bridge cannot know in advance which provider nr-llm resolves. The bridge does not send a refused request to OpenAI instead, because that needs the extension's own API key and would bypass nr-llm (fail closed, see above). From 1.7.1 on, an empty answer raises an exception, so no empty alt text is stored; 1.7.1's upload listener catches it and the upload succeeds, while `ai:generate-alt-texts` stops at that file and the backend's alt-text suggestion button reports a failed request, as the original 1.7.1 does with its own exception. Before 1.7.1 the upload listener catches no exception, so the bridge keeps returning an empty text there, which ai_filemetadata stores together with its generation date and does not retry. An empty (0-byte) image returns an empty text on every version: it can never be described, so the file is marked done instead of stopping `ai:generate-alt-texts` at it on every run. The original also refuses an answer that ended for another reason than `stop`; the bridge cannot check that until nr-llm reports a finish reason.

> **Upgrading with a `ModifyRequestDataEvent` listener:** a site whose listener adds tools or changes anything but the system prompt gets no alt texts while the integration is active: every request is refused: an upload logs the refusal, `ai:generate-alt-texts` stops at the first file, and the backend's alt-text suggestion button reports a failed request (its AJAX action has no error handling). `vendor/bin/typo3 nrllm:compat:status` names the registered listeners, also before the integration is enabled. Remove or adapt such a listener, or leave the integration disabled.

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

- `.github/workflows/checks.yml`: Composer Audit (fails on any advisory for an installed package) and Opengrep SAST, both through `security.yml` of `netresearch/typo3-ci-workflows` (which Opengrep findings block a pull request is set by the organisation's [static analysis rule](https://github.com/netresearch/.github/blob/main/SECURITY.md#static-analysis-sast)); Dependency Review (fails on added or changed dependencies with a vulnerability of severity high or higher); PHP licence check (`license-check.yml`, fails when the `composer licenses` output matches its forbidden-licence pattern, which by default refuses `SSPL`, `BSL` and `BUSL` and every `SSPL-` or `BUSL-` identifier of any version (`BSL-1.0` passes)); CodeQL for the workflow files (the repository has no JavaScript or Go, and CodeQL has no PHP analyser); Betterleaks secret scanning; zizmor for the workflow files; the pull request quality gate (`pr-quality.yml`: a size check on non-draft pull requests and the solo-maintainer auto-approval for non-draft pull requests that an owner, member or collaborator opens from a branch of this repository); and the aggregate `All security checks` job, which fails unless every one of these jobs succeeded or was skipped. The `fuzz` job is called but runs nothing here, as `Build/phpunit.xml` has no fuzz test suite.
- `.github/workflows/ci.yml`: PHPStan level 10 (`ci:test:php:phpstan`), unit and functional tests for PHP 8.2 to 8.6 and TYPO3 13.4 and 14.3; PHP lint once per PHP version; code style (`ci:test:php:cgl`), Rector (`ci:test:php:rector`) and the isolated typo3-solver test environment (`ci:test:repo`) once, on PHP 8.2; and the aggregate `All CI checks` job, which fails unless every CI job succeeded or was skipped.
- `.github/workflows/check-template-drift.yml`: Template drift compares the `.github/` files managed by the organisation's typo3-extension template with that template.
- `.github/workflows/harness-verify.yml`: `Build/Scripts/verify-harness.sh` checks that `AGENTS.md` and `docs/` match the repository.

No exception is recorded: `composer.json` has no `config.audit.ignore` entry.

## License

GPL-2.0-or-later
