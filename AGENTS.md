<!-- Managed by agent: keep sections & headings; edit content only. Last sync: 2026-08-19 -->

# AGENTS.md — nr_llm_compat

**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Overview

TYPO3 v13.4/v14.3 extension: runtime LLM compatibility layer. Takes over the LLM provider calls of installed third-party AI extensions and routes them through `nr-llm` — without modifying the third-party code. PHP 8.2+, PHPStan level 10.

Companion repo to [t3x-nr-llm](https://github.com/netresearch/t3x-nr-llm); the design is recorded in `Documentation/Adr/Adr001CompatibilityLayerArchitecture.rst`.

## Commands

CI runs the suites through the Composer scripts below. `Build/Scripts/runTests.sh` — the shared runner from `netresearch/typo3-ci-workflows` — runs the same suites locally in Docker against a chosen PHP version (`-s unit|functional|lint|phpstan|rector|cgl`, `-p 8.2`). It does not cover `ci:test:repo`, and `-s phpstan` always uses `Build/phpstan/phpstan.neon`, not the TYPO3-13-only fallback the Composer script picks when `passionweb/ai-seo-helper` is absent:

| Task | Command |
|------|---------|
| Unit tests | `composer ci:test:php:unit` |
| Functional tests (sqlite) | `composer ci:test:php:functional` |
| PHPStan (level 10) | `composer ci:test:php:phpstan` |
| Code style (fix) | `composer ci:cgl` |
| Code style (dry-run) | `composer ci:test:php:cgl` |
| Rector (dry-run) | `composer ci:test:php:rector` |
| Lint | `composer ci:test:php:lint` |
| Everything incl. functional | `composer ci:full` |
| Solver isolated test env | `composer ci:test:repo` |
| Harness/docs consistency | `bash Build/Scripts/verify-harness.sh` |
| Any suite locally in Docker | `./Build/Scripts/runTests.sh -s unit` |

## Architecture — the rules that matter

Component map and data flow: `docs/ARCHITECTURE.md`.

- **A third-party extension is never patched, forked or configured differently.** The functional tests install it unmodified from Packagist; that IS the product guarantee.
- **`Classes/Bridge/` is excluded from DI service loading** (Services.yaml). Bridge classes extend third-party classes that may not be installed — autoregistering them would break the container build. The compiler pass swaps them into the third-party extension's EXISTING service definitions instead.
- **`StatusReporter` is the single activation decision.** The compiler pass intercepts exactly when it says Active; the `nrllm:compat:status` command reports the same evaluation. Never add a second decision path.
- **Contracts over versions.** Every integration declares the method signatures and properties it relies on (`MethodContract`/`PropertyContract`); `ContractVerifier` checks them via reflection so an upstream refactoring deactivates the integration instead of fataling.
- **Fail closed.** An active integration never falls back to the third-party extension's own provider on error. That would bypass nr-llm's budgets, policies and telemetry.
- **Integrations are opt-in** via `ext_conf_template.txt` toggles, default off.
- **Strategy enum grows with consumers.** ADR-001 designs four interception strategies; a case is only added together with the first integration using it.

## Adding a new integration

1. Fetch the REAL package source (Packagist dist zip) and verify the interception point — never trust an assumed signature.
2. Write the descriptor in `Classes/Integration/<Name>Integration.php` with contracts mirroring the verified source; register it in `IntegrationRegistry::withDefaultIntegrations()`.
3. Write the bridge in `Classes/Bridge/<Name>/` — override ONLY the provider call, keep everything before and after original.
4. Add the toggle to `ext_conf_template.txt` and the row to README's support table.
5. Add the package to `require-dev` (and to `remove-dev-deps` in `.github/workflows/ci.yml` for matrix cells it does not support).
6. Tests: descriptor contract vs installed package (unit), bridge behavior with `FakeCompletionService` (unit), container wiring enabled + disabled (functional).

## Testing

- Unit: `Tests/Unit`, functional: `Tests/Functional` (sqlite, boots nr_vault + nr_llm + the third-party extension + this extension + the `nr_llm_compat_fake` fixture that fakes nr-llm's completion surface).
- nr-llm ships consumer-facing fakes in `Netresearch\NrLlm\Testing\` — use those, never hand-rolled doubles of nr-llm interfaces.
- PHPStan level 10 analyzes Tests/ too — run it after writing tests.

## Conventions

- `declare(strict_types=1);` everywhere; PSR-12 via PHP-CS-Fixer; conventional commits; signed commits (`git commit -S --signoff`, DCO).
- `composer.lock` is NOT committed (extension = library).
- PRs target `main`; merge via `--merge` (no squash).

## Index of scoped AGENTS.md

- [Classes/AGENTS.md](./Classes/AGENTS.md) — extension code: integrations, bridges, compiler pass, diagnostics
- [Tests/AGENTS.md](./Tests/AGENTS.md) — unit/functional suites, solver environment, fixtures
- [.github/workflows/AGENTS.md](./.github/workflows/AGENTS.md) — CI matrix, reusable workflows, release pipeline
