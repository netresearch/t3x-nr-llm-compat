<!-- Managed by agent: keep sections & headings; edit content only. Last sync: 2026-08-19 -->

# AGENTS.md — .github/workflows/

## Overview

All jobs are thin callers of central reusable workflows (`netresearch/typo3-ci-workflows` for TYPO3-specific checks, `netresearch/.github` for org-wide ones). Keep it that way: no inline job logic beyond the `gate` aggregator in `checks.yml`.

## Workflow files

| File | Calls | Notes |
|------|-------|-------|
| `ci.yml` | `typo3-ci-workflows/ci.yml` | Matrix PHP 8.2–8.5 × TYPO3 `^13.4`/`^14.3`; functional tests on; `run-repo-checks` runs `composer ci:test:repo` (solver env) |
| `checks.yml` | security, gitleaks, zizmor, fuzz, license-check, codeql, scorecard, dependency-review, pr-quality + inline `gate` | |
| `harness-verify.yml` | `netresearch/.github script-check.yml` | Runs `Build/Scripts/verify-harness.sh`; exit 2 (warnings only) passes |
| `release.yml` | `typo3-ci-workflows/release-typo3-extension.yml` | |
| `auto-merge-deps.yml`, `labeler.yml`, `community.yml`, `check-template-drift.yml` | `netresearch/.github` reusables | |

## Common patterns

- `remove-dev-deps: [{"dep":"passionweb/ai-seo-helper","only-for":"^13.4"}]` — the dep caps at TYPO3 ^13.4; on every other cell it is removed, its tests self-skip, and PHPStan switches to `Build/phpstan/phpstan-no-typo3-13-only.neon` (see the `ci:test:php:phpstan` composer script).
- New dev dependency that not every matrix cell supports → extend `remove-dev-deps` in `ci.yml` in the same PR.
- Coverage upload needs the `CODECOV_TOKEN` secret (passed to the reusable).

## Workflow conventions

- Reusables are pinned to `@main`; version pinning, harden-runner and checkout pins live centrally in the reusable repos.
- Default-deny permissions: `permissions: {}` at workflow level (ci.yml: `contents: read`); each job grants only what it needs.

## Security

- Never add repo secrets inline; pass them to reusables via the `secrets:` block.
- zizmor, CodeQL, Scorecard and gitleaks run from `checks.yml` — a new workflow must not weaken their triggers.

## Checklist

- [ ] Changing CI behavior? Update this file and the root `AGENTS.md` in the same commit (the harness drift check flags mismatches).
- [ ] Matrix change → sweep ALL version surfaces: `composer.json`, `ext_emconf.php`, README, this file.

## Examples

- `ci.yml` is the golden sample for calling a matrix reusable with per-dependency removal.

## When stuck

- The truth about what a job does lives in the central reusable: `netresearch/typo3-ci-workflows/.github/workflows/*.yml` and `netresearch/.github/.github/workflows/*.yml`.
