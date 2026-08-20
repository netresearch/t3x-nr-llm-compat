# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-08-20

### Changed

- Requires `netresearch/nr-llm` `^0.31`; the `ext_emconf.php` constraint said `0.29.0-0.99.99` while composer.json required `^0.30` — the two now say the same thing

## [0.1.0] - 2026-08-18

### Added

- Caller-source attribution: every bridge annotates its nr-llm calls with `withCallerSource(<extension key>, <operation>)` (nr-llm ADR-177), so telemetry rows name the originating integration; requires `netresearch/nr-llm` ^0.30

- TYPO3 14.3 support: the extension constraint widens to `^13.4 || ^14.3` and the CI matrix runs both. The one TYPO3-13-only third-party package, `passionweb/ai-seo-helper`, is removed from the ^14.3 cells — its integration reports NOT INSTALLED there (exactly as on a real v14 site), its tests self-skip, and static analysis switches to a config that excludes its files

- Integration: `eliashaeussler/typo3-solver` (^3.3) — the solver's official `provider` setting is pointed at a bridge implementing its `SolutionProvider`; the extension's request shaping (max tokens, temperature, number of completions — one nr-llm completion per alternative, capped at 10) and its exception-code ignore list keep working, `listModels()` is honestly empty (model routing belongs to nr-llm). Unblocks #8: the package cannot join the main require-dev resolution (its `openai-php/client ^0.18+` floor conflicts with ai-filemetadata's `^0.10` pin), so a dedicated environment under `Tests/SolverEnvironment/` installs the unmodified package (path-repository copy of this extension) and runs the integration's own PHPStan/unit/functional suites — wired into CI via `composer ci:test:repo`

- Integration: `in2code/texter` (^3.0) — the first provider-configuration integration: no third-party internals are touched; the runtime configuration points texter's official `llmRepositoryClass` hook at a bridge implementing its `RepositoryInterface`, which the compiler pass registers as a public service. The configured prompt prefix (via the original `extendPrompt()`) and the per-page conversation history keep working; history entries are mapped onto nr-llm chat messages so follow-up prompts keep their context
- `ProvidesRuntimeConfiguration` + `RuntimeConfigurationApplier`: boot-time twin of the compiler pass for hook-based strategies, following the same single Active decision; `ContractVerifier` now accepts interfaces as contract subjects

- Integration: `nitsan/ns-t3ai` (^14.0) — both OpenAI-calling methods bridged: `requestAi()` (SEO suggestions; the prompt is still built by the original `addModelSpecificPrompt()`, so [Content] placeholders and per-request overrides behave as upstream) and `requestAiForRteContent()` (RTE content generation; the dialog's request-shaping values are honored and each requested alternative becomes one nr-llm completion, capped at 10)
- Integration: `mfd/ai-filemetadata` (^1.6) — first vision integration: `OpenAiClient::buildAltText()` routes through nr-llm's `VisionServiceInterface`; the bridge constructor never builds the openai-php client or reads the extension's API key, and nr-llm's usage result is mirrored into the extension's own `TokenUsageService` so its dashboard widgets keep working
- `MethodContract` can require at-least-protected instead of public (for original helpers a bridge calls internally); `ContractVerifier` turns bridge-class load failures (e.g. a readonly-modifier mismatch with the installed parent) into violations instead of container crashes

- Compatibility foundation: integration descriptors with reflection-verified PHP contracts (`ContractVerifier`), Composer version-range verification (`VersionVerifier`), opt-in activation via extension configuration, and a single decision point (`StatusReporter`) shared by interception and diagnostics
- `ThirdPartyCompatibilityPass`: swaps a third-party extension's own DI service class for a bridge while keeping the service id and its explicit arguments
- `nrllm:compat:status` command reporting installed version, contract result, strategy and activation state per integration; non-zero exit when an enabled integration cannot activate
- First integration: `passionweb/ai-seo-helper` (~0.7.2) — `ContentService::requestAi()` routes through nr-llm's `CompletionServiceInterface`, preserving the original prompt construction, temperature/top_p settings and result shaping; fail closed, the extension's own OpenAI key stays unused
