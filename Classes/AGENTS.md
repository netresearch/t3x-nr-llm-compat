<!-- Managed by agent: keep sections & headings; edit content only. Last sync: 2026-08-19 -->

# AGENTS.md — Classes/

## Overview

Extension code of the compatibility layer. One **Integration** descriptor per supported third-party AI extension declares where and how its provider calls are intercepted; a **Bridge** class per integration performs the actual call through nr-llm.

| Path | Purpose |
|------|---------|
| `Integration/` | Descriptors (`*Integration.php`), `IntegrationRegistry`, `IntegrationStrategy` enum, `ProvidesRuntimeConfiguration`, `RuntimeConfigurationApplier` |
| `Integration/Contract/` | `ClassContract`/`MethodContract`/`PropertyContract` — the reflection-checked API surface an integration relies on |
| `Integration/Diagnostics/` | `StatusReporter` (single activation decision), `ContractVerifier`, `VersionVerifier`, `IntegrationState`/`IntegrationStatus`, `IntegrationSettings` |
| `Bridge/<Name>/` | One subclass per integration overriding ONLY the provider call (AiFilemetadata, AiSeoHelper, NsT3Ai, Solver, Texter) |
| `DependencyInjection/` | `ThirdPartyCompatibilityPass` — swaps bridge classes into the third-party extension's existing service definitions |
| `Command/` | `CompatibilityStatusCommand` (`nrllm:compat:status`) |
| `Exception/` | `UnexpectedAiResponseException` |

## Setup

- Namespace `Netresearch\NrLlmCompat\` maps to `Classes/` (composer.json PSR-4).
- DI: `Configuration/Services.yaml` autoregisters everything EXCEPT `Classes/Bridge/*` — bridge classes extend third-party classes that may not be installed. Never remove that exclude, never add a bridge as its own service.
- Boot-time hooks (`ProviderConfiguration` strategy) are applied in `ext_localconf.php` via `RuntimeConfigurationApplier`.

## Build

- PHPStan level 10: `composer ci:test:php:phpstan` (auto-picks the config matching the installed dev deps).
- Style: `composer ci:cgl` (fix) / `composer ci:test:php:cgl` (dry-run). Rector dry-run: `composer ci:test:php:rector`.
- Unit tests for this code live in `Tests/Unit/` mirroring the directory layout.

## Code style

- `declare(strict_types=1);`, PSR-12 via PHP-CS-Fixer (`.php-cs-fixer.dist.php`), file header with Netresearch copyright + `SPDX-License-Identifier: GPL-2.0-or-later`.
- `IntegrationStrategy` only grows a case together with the first integration using it (currently `DiClassReplacement`, `ProviderConfiguration`).

## Security

- **Fail closed**: an active integration never falls back to the third-party extension's own provider on error — that would bypass nr-llm's budgets, policies and telemetry.
- Integrations are opt-in (`ext_conf_template.txt`), default off; `StatusReporter` is the ONLY activation decision path.
- Never patch, fork or reconfigure the third-party package itself.

## Checklist

- [ ] New integration follows the 6-step recipe in the root AGENTS.md ("Adding a new integration").
- [ ] Contracts mirror the VERIFIED third-party source (Packagist dist), not an assumed signature.
- [ ] `composer ci` green; PHPStan analyzed the final tree including tests.

## Examples

- `Integration/SolverIntegration.php` + `Bridge/Solver/NrLlmSolutionProvider.php` — `ProviderConfiguration` strategy pair.
- `Integration/NsT3AiIntegration.php` + `Bridge/NsT3Ai/NsT3AiContentService.php` — `DiClassReplacement` strategy pair.

## When stuck

- Design rationale: `Documentation/Adr/Adr001CompatibilityLayerArchitecture.rst`; component map: `docs/ARCHITECTURE.md`.
- Runtime state of every integration: the `nrllm:compat:status` console command (bin-dir is `.Build/bin`).
