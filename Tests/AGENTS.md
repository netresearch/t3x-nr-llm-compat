<!-- Managed by agent: keep sections & headings; edit content only. Last sync: 2026-08-19 -->

# AGENTS.md — Tests/

## Overview

| Path | Purpose |
|------|---------|
| `Unit/` | Mirrors `Classes/` (Bridge, Command, DependencyInjection, Integration) + `Fixtures/` doubles |
| `Functional/` | Container wiring + interception per integration (sqlite); abstract per-extension base cases |
| `Functional/Fixtures/Extensions/` | `nr_llm_compat_fake` (fakes nr-llm's completion surface), `nr_llm_compat_probe_filemetadata` |
| `Solver/` | typo3-solver unit + functional tests, run ONLY inside the isolated environment below |
| `SolverEnvironment/` | Self-contained composer project for typo3-solver — it cannot join the main require-dev resolution (openai-php/client conflict, see issue #8) |

## Setup

- No Docker wrapper: suites run directly via Composer scripts from the repo root; `composer install` first (vendor dir is `.Build/vendor`, bin dir `.Build/bin`).
- Functional tests use sqlite — no database service needed.

## Commands

- Unit: `composer ci:test:php:unit` — Functional: `composer ci:test:php:functional`
- Solver environment (installs + runs its own suite): `composer ci:test:repo`
- Everything incl. functional: `composer ci:full`

## Conventions

- Functional tests boot nr_vault + nr_llm + the third-party extension + this extension + the `nr_llm_compat_fake` fixture.
- The third-party package is installed UNMODIFIED from Packagist — that is the product guarantee; never patch or fork it in a fixture.
- nr-llm ships consumer-facing fakes in `Netresearch\NrLlm\Testing\` — use those, never hand-rolled doubles of nr-llm interfaces.
- `passionweb/ai-seo-helper` caps at TYPO3 ^13.4: its tests self-skip when the package is absent (the ^14.3 CI cells remove it).

## Security

- Fixtures use placeholder credentials only; never embed real API keys or endpoints in test data.
- Tests assert the fail-closed behavior: on error the bridge must NOT fall back to the third-party provider.

## Checklist

- [ ] New integration ships: descriptor contract vs installed package (unit), bridge behavior with `FakeCompletionService` (unit), container wiring enabled + disabled (functional).
- [ ] PHPStan level 10 analyzes `Tests/` too — run `composer ci:test:php:phpstan` after writing tests.
- [ ] Test output pristine: expected errors are captured and asserted, not printed.

## Examples

- `Functional/TexterInterceptionTest.php` + `Functional/AbstractTexterTestCase.php` — interception coverage pattern.
- `Unit/Integration/Diagnostics/` — status/contract verification test pattern.

## When stuck

- PHPUnit configs: `Build/phpunit.xml` (unit), `Build/FunctionalTests.xml` (functional), `Tests/SolverEnvironment/*.xml` (solver).
- CI behavior differences per matrix cell: `.github/workflows/AGENTS.md`.
