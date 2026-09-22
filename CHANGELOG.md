# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Integration: `georgringer/news` (`^12.0 || ^13.0 || ^14.0`) — the first tool-provision integration (ADR-002). EXT:news makes no LLM calls; what it lacks is an nr-llm writer for its records, and nr-llm's position is that an extension brings its own writer or a bridge extension does so on its behalf. When enabled, the compiler pass registers `create_news_draft` under nr-llm's `nr_llm.tool` tag: one hidden news article per call in a storage folder, through the DataHandler as the acting backend user, live workspace and default language only, type fixed to article, the URL segment left to the DataHandler's slug generator, categories, media, tags and related records left to an editor. The date is required exactly when the installed EXT:news requires it (`dateTimeNotRequired`, read at call time); an ISO 8601 date-time or a UNIX timestamp is accepted and handed to the DataHandler as an integer timestamp, the one shape TYPO3 13.4 and 14.3 store unchanged. A read-back deletes the record again when the DataHandler dropped an exclude field the approver was shown — `hidden` is an exclude field with default 0 in the news TCA, so a missing field grant would otherwise publish the draft; only `pid`, `type` and the exclude fields are compared, a text by presence, because `bodytext` is stored through the RTE parser in a different byte form. Disabled by default in nr-llm's Tools module like every writer; declares `NON_IDEMPOTENT_WRITE`, so the approval pause applies. The supported range brackets the TCA shape the tool writes against, diffed at 12.0.0, 12.3.2, 13.0.0, 13.0.2, 14.0.0, 14.0.3 and 14.1.1. The tool carries its own copy of the few private mechanics nr-llm's builtin writers share: neither `WritesThroughDataHandlerTrait` nor `PlansOneEditorialWriteTrait` carries the `@api` marker nr-llm's ADR-127 makes the semver authority, so a minor nr-llm release may change them without a deprecation.
- `IntegrationStrategy::ToolProvision`: the compiler pass registers an Active integration's tool class as an autowired service tagged `nr_llm.tool`; `nrllm:compat:status` lists the tool name under capabilities. The existing strategies are unchanged.

### Changed

- Requires `netresearch/nr-llm` `^0.35`; `ext_emconf.php` declares `0.35.0-0.35.99` with it. The floor rises because the news tool hands a `WriteKind` to `ToolResult::withWriteTarget()`, and nr-llm 0.35.0 is where both exist. 0.1.5 kept `^0.34` on the ground that the changed `withWriteTarget()` signature "affects only extensions that register tools" — this extension now registers one. On 0.34 the failure is late: the tool loads, the contract verifies, the integration activates, the hidden record is created, and the success line then fatals on the missing enum. `Tests/Unit/VersionConsistencyTest.php` reads both files and refuses a floor below 0.35.0.

## [0.1.5] - 2026-09-17

### Changed

- **`netresearch/nr-llm` is accepted at `^0.34 || ^0.35`.** nr-llm 0.35.0 is released and on a 0.x `^0.34` does not admit it, so this extension pinned every installation it is part of to nr-llm 0.34 — the constraint is declared in `composer.json` and in `ext_emconf.php`, and both now carry the wider range. Source compatibility was measured rather than assumed: of nr-llm 0.35's three breaking changes, `ToolResult::withWriteTarget()` affects only extensions that register tools, `ConversationService::startSession()` is not called here, and the `ModelResolution` added to `chatForConfiguration()`/`chatWithConfiguration()` is nullable and last, so existing call sites are unchanged. The one consumer the 0.35 notes did break, `netresearch/nr-repurpose`, is fixed and released separately.

## [0.1.4] - 2026-09-03

### Changed

- Requires `netresearch/nr-llm` `^0.34`. The floor rises because 0.34.0 is where the demo instance and every sibling extension are going, and staying on `^0.33` would keep an installation from taking both. 0.34.0's one breaking change is the backend module move (nr-llm ADR-183): the modules left Administration for a shared `AI` section and the container URL `/module/nrllm` is gone. Nothing here registers a module under that container or links to that URL, so nothing else changes.
- `ext_emconf.php` is raised with it, and its upper bound is now `0.34.99` rather than `0.99.99` — the two install paths had disagreed about everything above 0.34.

## [0.1.3] - 2026-08-21

### Changed

- Requires `netresearch/nr-llm` `^0.33`. The floor rises because 0.33.0 removes a regression 0.32.0 introduced: `vision()` and `embed()` handed the provider registry the `tx_nrllm_provider` row's identifier where it is keyed by the adapter's own name, so a call that names no provider — which is what this extension makes — failed with "Provider … not found" on an installation that has a perfectly good default configuration. 0.32.0 did not fix the failure it was written for, it renamed it.
- `ext_emconf.php` declares the same dependency and is raised with it, so the two cannot disagree about which versions this extension accepts.

## [0.1.2] - 2026-08-21

### Changed

- Requires `netresearch/nr-llm` `^0.32`. The floor rises because 0.32.0 fixes two things this layer depends on: `vision()` and `embed()` now fall back to the default configuration when the caller pins no provider — without it a bridge that names no provider threw instead of using a perfectly good default — and the caller source survives every options rebuild, so the `withCallerSource()` annotation each bridge writes reaches nr-llm's per-extension usage and cost breakdown instead of being dropped on the way to the middleware
- `composer.json` declares the extension version again; `extra.typo3/cms.version` had been left at 0.1.0 through the 0.1.1 release

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
