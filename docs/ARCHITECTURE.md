# Architecture — nr_llm_compat

Agent-facing component map. Design rationale and the full decision record live in `Documentation/Adr/Adr001CompatibilityLayerArchitecture.rst`; this file only maps the code as it exists.

## System overview

nr_llm_compat is a runtime compatibility layer: it takes over the LLM provider calls of installed third-party TYPO3 AI extensions and routes them through [nr-llm](https://github.com/netresearch/t3x-nr-llm) (`netresearch/nr-llm`), without modifying the third-party code. Interception happens through the DI container (compiler pass) or through the third-party extension's own provider-configuration hook — never through patches or forks.

## Components

| Component | Path | Role |
|-----------|------|------|
| Integration descriptors | `Classes/Integration/*Integration.php` | One per supported extension; declare package name, supported versions, contracts, strategy, bridge class |
| Registry | `Classes/Integration/IntegrationRegistry.php` | Built via `withDefaultIntegrations()` factory (see `Configuration/Services.yaml`); the single list of known integrations |
| Strategy enum | `Classes/Integration/IntegrationStrategy.php` | `DiClassReplacement`, `ProviderConfiguration` (ADR-001 designs four; cases are added with their first consumer) |
| Contracts | `Classes/Integration/Contract/` | `ClassContract`, `MethodContract`, `PropertyContract` — the third-party API surface an integration relies on |
| Diagnostics | `Classes/Integration/Diagnostics/` | `StatusReporter` (single activation decision), `ContractVerifier` (reflection), `VersionVerifier` (composer/semver), `IntegrationState`, `IntegrationStatus`, `IntegrationSettings` |
| Compiler pass | `Classes/DependencyInjection/ThirdPartyCompatibilityPass.php` | Swaps bridge classes into the third-party extension's existing service definitions (`DiClassReplacement`), registers bridges as public services (`ProviderConfiguration`) |
| Runtime applier | `Classes/Integration/RuntimeConfigurationApplier.php` | Called from `ext_localconf.php`; points the third-party extension's official hook at the bridge for Active `ProviderConfiguration` integrations |
| Bridges | `Classes/Bridge/<Name>/` | Subclasses of third-party classes overriding ONLY the provider call (AiFilemetadata, AiSeoHelper, NsT3Ai, Solver, Texter) |
| Status command | `Classes/Command/CompatibilityStatusCommand.php` | `nrllm:compat:status` — reports the same evaluation `StatusReporter` uses |
| Exception | `Classes/Exception/UnexpectedAiResponseException.php` | Raised on malformed nr-llm responses (fail closed, no fallback) |

## Dependency rules

There is no phpat/architecture test suite; these rules are enforced by DI configuration and review:

- `Classes/Bridge/*` is excluded from service autoregistration (`Configuration/Services.yaml`) — bridge classes extend third-party classes that may not be installed. Never register a bridge as its own service.
- `StatusReporter` is the only activation decision path; the compiler pass, the runtime applier and the status command all consume its evaluation.
- Third-party packages are consumed unmodified from Packagist (require-dev), never vendored or patched.

## Data flow

1. **Container build**: `ThirdPartyCompatibilityPass` asks `StatusReporter` per integration (installed → supported version → contract verified → enabled toggle). Active `DiClassReplacement` integrations get the bridge class set on the third-party extension's existing service definition (same service id, wiring untouched).
2. **Boot**: `RuntimeConfigurationApplier` (`ext_localconf.php`) points official provider hooks at the bridges for Active `ProviderConfiguration` integrations.
3. **Runtime**: the third-party extension calls its usual service, the bridge intercepts only the provider call and routes it through nr-llm (budgets, policies, telemetry). On error it fails closed — no fallback to the third-party provider.
4. **Diagnostics**: `nrllm:compat:status` reports the same per-integration evaluation.

## Key decisions

- ADR-001 — Compatibility layer architecture: `Documentation/Adr/Adr001CompatibilityLayerArchitecture.rst` (strategies, contracts, fail-closed, opt-in).
- CI-cell handling of version-capped dev deps (`passionweb/ai-seo-helper`): comments in `.github/workflows/ci.yml` and the `ci:test:php:phpstan` composer script.
- Isolated solver test environment (`Tests/SolverEnvironment/`): openai-php/client conflict, issue [#8](https://github.com/netresearch/t3x-nr-llm-compat/issues/8).
