# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Integration: `nitsan/ns-t3ai` (^14.0) — both OpenAI-calling methods bridged: `requestAi()` (SEO suggestions; the prompt is still built by the original `addModelSpecificPrompt()`, so [Content] placeholders and per-request overrides behave as upstream) and `requestAiForRteContent()` (RTE content generation; the dialog's request-shaping values are honored and each requested alternative becomes one nr-llm completion, capped at 10)
- Integration: `mfd/ai-filemetadata` (^1.6) — first vision integration: `OpenAiClient::buildAltText()` routes through nr-llm's `VisionServiceInterface`; the bridge constructor never builds the openai-php client or reads the extension's API key, and nr-llm's usage result is mirrored into the extension's own `TokenUsageService` so its dashboard widgets keep working
- `MethodContract` can require at-least-protected instead of public (for original helpers a bridge calls internally); `ContractVerifier` turns bridge-class load failures (e.g. a readonly-modifier mismatch with the installed parent) into violations instead of container crashes

- Compatibility foundation: integration descriptors with reflection-verified PHP contracts (`ContractVerifier`), Composer version-range verification (`VersionVerifier`), opt-in activation via extension configuration, and a single decision point (`StatusReporter`) shared by interception and diagnostics
- `ThirdPartyCompatibilityPass`: swaps a third-party extension's own DI service class for a bridge while keeping the service id and its explicit arguments
- `nrllm:compat:status` command reporting installed version, contract result, strategy and activation state per integration; non-zero exit when an enabled integration cannot activate
- First integration: `passionweb/ai-seo-helper` (~0.7.2) — `ContentService::requestAi()` routes through nr-llm's `CompletionServiceInterface`, preserving the original prompt construction, temperature/top_p settings and result shaping; fail closed, the extension's own OpenAI key stays unused
