<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Security assurance

What users of `nr_llm_compat` can and cannot expect in terms of security, and the argument for it: which credentials the extension handles, what data it sends to LLM providers, the threat model, the trust boundaries, the design principles applied and how common weaknesses are countered. Every claim names the file or test that implements it. Components and data flow: [ARCHITECTURE.md](ARCHITECTURE.md). Vulnerability reporting: [SECURITY.md of the organisation](https://github.com/netresearch/.github/blob/main/SECURITY.md).

The document describes the code on `main`. Statements about third-party extensions refer to the versions a `composer install` resolved on 2026-09-30: TYPO3 13.4.35, nr-llm 0.38.1, passionweb/ai-seo-helper 0.7.2, nitsan/ns-t3ai 14.0.0, mfd/ai-filemetadata 1.6.3, in2code/texter 3.1.0, georgringer/news 14.1.1 and, in `Tests/SolverEnvironment/`, eliashaeussler/typo3-solver 3.3.4.

## What the extension does, security-wise

The extension sends no request to any LLM provider itself and has no HTTP client of its own. When an integration is active, it replaces the provider call of a third-party AI extension with a call to nr-llm's service interfaces (`CompletionServiceInterface`, `VisionServiceInterface`, `LlmServiceManagerInterface`). nr-llm selects the provider and model, holds the credentials and applies its budgets, policies and telemetry. The `News` integration makes no LLM call: it registers a tool, `create_news_draft`, that nr-llm's assistant can call to write a record.

It has no database table, no TCA, no backend module, route or AJAX endpoint, and no frontend output. Its only entry points are the DI compiler pass (`Classes/DependencyInjection/ThirdPartyCompatibilityPass.php`), `ext_localconf.php`, the console command `nrllm:compat:status` (`Classes/Command/CompatibilityStatusCommand.php`) and the bridge classes that the third-party extensions or nr-llm call.

## Activation

Nothing is intercepted after installation. An integration is active only when `Classes/Integration/Diagnostics/StatusReporter.php` finds all of the following, and the compiler pass and `Classes/Integration/RuntimeConfigurationApplier.php` act only on that result:

1. the third-party package is installed (`VersionVerifier`, Composer's `InstalledVersions`),
2. its version satisfies the integration's range,
3. every class, method signature and property the bridge relies on matches (`ContractVerifier`, via reflection),
4. its toggle in the extension configuration is on (`IntegrationSettings`; every toggle defaults to `0` in `ext_conf_template.txt`).

Tests: `Tests/Unit/Integration/Diagnostics/`, and the `*DefaultsTest` functional tests, which install each third-party package unmodified and assert that its original service stays in place while the toggle is off.

## Credentials

- **The extension stores no credentials and has no setting for one** (`ext_conf_template.txt` holds only the six integration toggles).
- **Provider API keys are nr-llm's.** nr-llm's README (0.38.1) states that it keeps API keys as nr-vault identifiers with envelope encryption. That is a property of nr-llm, not a control of this repository.
- **The third-party extensions' own API keys are not used.** `Bridge/AiFilemetadata/OpenAiClient.php` does not call the parent constructor, which is where ai-filemetadata reads its `apiKey` setting and builds the OpenAI client. The service classes of ai-seo-helper (`ContentService`) and ns-t3ai (`NsT3AiContentService`) read their key only in the methods that send the request (`requestAi()`, and for ns-t3ai also `requestAiForRteContent()`), and `Bridge/AiSeoHelper/ContentService.php` and `Bridge/NsT3Ai/NsT3AiContentService.php` override exactly those methods. `Bridge/Texter/NrLlmRepository.php` implements texter's `checkApiKey()` as an empty method, and `Bridge/Solver/NrLlmSolutionProvider.php` does not read the solver's key setting. The functional tests `AiSeoHelperInterceptionTest`, `NsT3AiInterceptionTest` and `AiFilemetadataInterceptionTest` run the interception with the extension's OpenAI key left empty. The key a site had configured in those extensions stays in their configuration; this extension neither removes nor rotates it.
- **CI and release credentials.** `.github/workflows/ci.yml` passes `CODECOV_TOKEN` and `.github/workflows/release.yml` passes `TYPO3_TER_ACCESS_TOKEN` to the reusable workflows they call. Both are GitHub Actions secrets and are handled as described in the organisation's [secret management policy](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management).

## Data sent to LLM providers

The bridges send what the third-party extension would have sent to its own provider, to nr-llm instead. nr-llm decides which provider receives it. Each request carries a caller-source attribution (`withCallerSource()`), for nr-llm's telemetry.

| Integration | Data sent through nr-llm | Source |
|-------------|--------------------------|--------|
| AI SEO Helper | The configured prompt prefix, the language name and either the text of the page's frontend preview with the HTML tags stripped, the stripped text of a news article, or, with ai-seo-helper's `useUrlForRequest` setting, the preview URL itself | `ContentService::getContentFromAi()` of ai-seo-helper; `Bridge/AiSeoHelper/ContentService.php` |
| T3AI | The prompt ns-t3ai builds from the stripped text of the page's frontend preview; for the RTE dialog, the prompt from the dialog's request (`T3AiController` of ns-t3ai), with temperature, top-p, max tokens and penalties from the request | `NsT3AiContentService` of ns-t3ai; `Bridge/NsT3Ai/NsT3AiContentService.php` |
| AI File Metadata | The complete image file as a base64 data URL and the alt-text prompt with the target language | `Bridge/AiFilemetadata/OpenAiClient.php` |
| Texter | The whole per-page conversation of the backend user, with texter's prompt prefix on each new prompt; texter keeps that history in the backend user's session data | `ConversationHistory` of texter; `Bridge/Texter/NrLlmRepository.php` |
| Exception Solver | The solver's prompt: exception class and message, file and line, a source-code snippet around the failing line, and the TYPO3, PHP and database versions | `Resources/Private/Templates/Prompt/Default.html` of typo3-solver; `Bridge/Solver/NrLlmSolutionProvider.php` |
| News | Nothing: the tool receives arguments from nr-llm's assistant and writes a record | `Bridge/News/CreateNewsDraftTool.php` |

The AI File Metadata bridge logs the prompt at level `info` and never the image (`OpenAiClient::buildAltText()`). No other bridge logs request or response content.

## Threat model and trust boundaries

| Boundary | Trusted side | What crosses it | How it is handled |
|----------|--------------|-----------------|-------------------|
| Extension configuration (`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']`) | Administrators of the installation | Integration toggles; the third-party extensions' prompt, temperature and top-p settings | Trusted. Numeric settings are clamped to the ranges nr-llm accepts (`ContentService::buildOptions()`, `NsT3AiContentService::buildRteOptions()`, `OpenAiClient::buildOptions()`). While the Texter or Solver integration is active, `TexterIntegration::applyRuntimeConfiguration()` and `SolverIntegration::applyRuntimeConfiguration()` overwrite texter's `llmRepositoryClass` and the solver's `provider` setting at every boot. |
| Third-party extension code | The installed, unmodified package | The call into the bridge and its arguments | The bridge overrides only the provider call. `ContractVerifier` deactivates the integration when the third-party API no longer matches, instead of failing at runtime. |
| Request data from the ns-t3ai RTE dialog | Backend users allowed to use that dialog (checked by ns-t3ai) | Prompt, number of alternatives, sampling parameters | The number of alternatives is capped at 10 (`MAX_RTE_ALTERNATIVES`), sampling values are clamped, an empty prompt is refused (`Tests/Unit/Bridge/NsT3Ai/NsT3AiContentServiceTest.php`). |
| LLM output | Untrusted | Generated text, JSON, tool calls | Text is handed back to the third-party extension as its own provider's answer would have been. A JSON answer that does not have the shape ai-seo-helper expects raises `UnexpectedAiResponseException` (`ContentServiceTest::emptyResponseThrows`, `singleScalarResponseThrows`). Tool calls to `create_news_draft` are handled as described below. |
| nr-llm | Trusted dependency | Provider routing, credentials, budgets | This extension relies on nr-llm for provider selection, key storage and cost limits. |

The `create_news_draft` tool (`Classes/Bridge/News/CreateNewsDraftTool.php`, ADR-002) treats every argument as model-chosen:

- It is disabled by default (`isEnabledByDefault()`); an administrator has to enable it in nr-llm's Tools module in addition to the `news` toggle.
- It declares itself a non-idempotent write (`getEffect()`), and it writes as the acting backend user through the DataHandler, only in the live workspace and only in the default language. Without an acting backend user it refuses with the same message it gives for a folder the user may not use, so a refusal does not confirm that a page exists.
- It checks the table grant, the default-language access and "edit content" permission on the target page, and requires the target to be a storage folder, before it writes and before it shows a preview (`plan()`); the DataHandler checks the permissions again.
- The record is always created hidden, as type "article". Unknown arguments refuse the whole call; texts are length-limited; dates must be ISO 8601 or a UNIX timestamp from 2001 on.
- After the write it reads the record back. When the DataHandler dropped a field the user has no exclude-field grant for (for example `hidden`), the record is deleted again.

Tests: `Tests/Unit/Bridge/News/CreateNewsDraftToolTest.php` and `Tests/Functional/CreateNewsDraftToolTest.php` (web mount, page permission, table grant, record that could not be hidden, refusal after the insert, preview writes nothing, viewer gate).

## Secure design principles applied

- **Secure defaults:** every integration and the news tool are off until an administrator enables them (`ext_conf_template.txt`, `CreateNewsDraftTool::isEnabledByDefault()`).
- **Fail closed:** an active integration never falls back to the third-party provider. An nr-llm failure propagates as an exception (`failsClosed*` tests in `Tests/Unit/Bridge/` and `Tests/Solver/Unit/NrLlmSolutionProviderTest.php`), so a request cannot bypass nr-llm's budgets and policies through an error path.
- **Single decision point:** the compiler pass, the runtime applier and the status command all use `StatusReporter::evaluate()`.
- **Least privilege:** the news tool acts with the permissions of the backend user who runs the assistant, never as an administrator (`requiresAdmin()` returns `false`; the DataHandler receives the acting user).
- **Minimal change to third-party code:** bridges override only the provider call; prompt construction stays in the original code where it is reachable (`NsT3AiContentService::requestAi()` calls ns-t3ai's `addModelSpecificPrompt()`, `NrLlmRepository::getText()` calls texter's `extendPrompt()`).
- **Bounded cost:** the number of completions per request is capped at 10 in the ns-t3ai RTE path and in the solver bridge (`MAX_RTE_ALTERNATIVES`, `MAX_COMPLETIONS`).

## Common weaknesses

| Weakness | Where it could arise | Counter-measure |
|----------|----------------------|-----------------|
| SQL injection (CWE-89) | News tool reads of `pages` and `tx_news_domain_model_news` | QueryBuilder with named integer parameters (`CreateNewsDraftTool::fetchRowByUid()`); writes go through the DataHandler |
| Missing or incorrect authorization (CWE-862, CWE-863) | News tool writes | Permission checks in `plan()` plus the DataHandler's own checks, against the acting user; functional tests listed above |
| Information exposure through error messages (CWE-209) | News tool refusals | One neutral message for "not found" and "not permitted" (`NOT_PERMITTED`); DataHandler errors are shown only after the permission check and are limited to 5 messages of 200 characters (`summariseErrors()`); an unknown argument name is echoed with every character except `A-Za-z0-9_` removed |
| Improper input validation (CWE-20) | Tool arguments, RTE dialog parameters, extension settings | Refusal of unknown arguments, type and length checks, strict date parsing (`CreateNewsDraftTool::timestamp()`); clamping of numeric parameters in the bridges |
| Uncontrolled resource consumption (CWE-400) | Number of completions requested by a setting or request | Caps described above; token budgets are nr-llm's |
| Use of a credential in an unintended path (CWE-522) | Third-party API keys | Not read by the bridges; see Credentials |
| Vulnerable dependencies (OWASP A06) | Composer dependencies | Composer Audit and Dependency Review on every pull request; see "Governance and policies" in the [README](../README.md#governance-and-policies) |

Cross-site scripting is not handled here: the extension renders no HTML. The text the bridges return is displayed by the third-party extension, and the news record is displayed by EXT:news, each with its own escaping.

## Security expectations

Users can expect:

- **No change in behaviour until an integration is enabled**, and none while its contract check fails (`nrllm:compat:status` shows the reason).
- **No LLM request past nr-llm** from an active integration, including on errors.
- **No use of the third-party extensions' API keys** by the bridges.
- **News drafts that are hidden, in a storage folder, written with the acting user's permissions** and removed again when they could not be stored as shown.

Users cannot expect:

- **Less data to leave the installation than the third-party extension sends.** The bridges forward the same content (see the table above), to whichever provider nr-llm is configured to use. Filtering personal data is not part of this extension.
- **Protection against prompt injection.** Page content, images, exception messages and editor input reach the model unchanged; a model can be steered by them. For the news tool, the hidden state, the permission checks and nr-llm's approval step limit the effect.
- **Security of the third-party extensions or of nr-llm.** Their own controllers, access checks, output escaping and credential storage are outside this extension. A third-party API key that remains configured stays readable by that extension's other code.
- **Security review of every supported third-party version.** Support is based on the version range and the contract check, not on an audit of the third-party code.
