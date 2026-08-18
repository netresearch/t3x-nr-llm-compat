# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Compatibility foundation: integration descriptors with reflection-verified PHP contracts (`ContractVerifier`), Composer version-range verification (`VersionVerifier`), opt-in activation via extension configuration, and a single decision point (`StatusReporter`) shared by interception and diagnostics
- `ThirdPartyCompatibilityPass`: swaps a third-party extension's own DI service class for a bridge while keeping the service id and its explicit arguments
- `nrllm:compat:status` command reporting installed version, contract result, strategy and activation state per integration; non-zero exit when an enabled integration cannot activate
- First integration: `passionweb/ai-seo-helper` (~0.7.2) — `ContentService::requestAi()` routes through nr-llm's `CompletionServiceInterface`, preserving the original prompt construction, temperature/top_p settings and result shaping; fail closed, the extension's own OpenAI key stays unused
