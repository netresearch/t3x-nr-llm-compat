.. _adr-001:

======================================================
ADR-001: Compatibility layer architecture
======================================================

:Status: Accepted
:Date: 2026-08-18

Context
=======

Several third-party TYPO3 AI extensions (ai-seo-helper, ns-t3ai,
ai-filemetadata, texter, typo3-solver, ai-tools, ai_translate, mkcontentai)
each call their LLM providers directly with their own API keys. Installations
that standardize on nr-llm for provider management, budgets, rate limits,
telemetry and privacy policies lose all of that governance the moment such an
extension is installed.

Forking or patching those extensions is not maintainable. The compatibility
layer must leave the third-party package byte-identical to its official
Composer dist and take over only the provider call at runtime.

Decision
========

Separate package
----------------

The layer is its own Composer package (``netresearch/nr-llm-compat``,
extension key ``nr_llm_compat``) with a hard dependency on
``netresearch/nr-llm`` and **no** hard dependencies on the third-party
extensions (``suggest`` at most). The volatile compatibility surface is
versioned independently of the nr-llm core.

Four interception strategies
----------------------------

Ordered by preference; a strategy is only implemented together with the
first integration that consumes it:

#. **Provider configuration** — the third-party extension exposes an official
   hook for a custom provider/repository class (texter's
   ``llmRepositoryClass``, typo3-solver's provider setting). No internals are
   touched; the most stable option whenever it exists.
#. **DI class replacement** — the extension's own Symfony service definition
   gets a bridge class set via a compiler pass (``Definition::setClass()``);
   the service id stays identical, so controllers, routes, TCA, JS and
   templates keep working unchanged. Preferred whenever the provider call
   lives in a DI service (ai-seo-helper, ns-t3ai, ai-filemetadata).
#. **Registry injection** — an existing provider/server registry is extended
   at runtime, ordered before the original constructor reads it (ai-tools).
#. **XCLASS** — only for classes created via
   ``GeneralUtility::makeInstance()`` with no DI service definition
   (ai_translate's hard-coded translate services, mkcontentai's engine
   clients). TYPO3 documents XCLASS as the fragile fallback; integrations
   using it get the narrowest supported version ranges.

Bridges override only the provider boundary
-------------------------------------------

A bridge subclasses the third-party class and overrides exactly the method
containing the provider HTTP call. Content extraction, prompt construction
and response rendering stay original code. This keeps the compatibility
surface minimal.

Contract verification, not just version ranges
-----------------------------------------------

Every integration declares the PHP contract it relies on (method signatures,
property visibility) as data; ``ContractVerifier`` checks it via reflection
at container build time. A silent upstream refactoring inside a nominally
supported version range deactivates the integration instead of fataling in
production.

Opt-in activation, fail closed
------------------------------

Installing ``nr_llm_compat`` changes nothing. Each integration is enabled
individually in the extension configuration. Once enabled, requests that
nr-llm cannot serve FAIL — there is no silent fallback to the third-party
extension's own provider, because that would bypass every control the layer
exists to enforce. ``StatusReporter`` is the single decision point: the
compiler pass activates exactly what it evaluates as Active, and the
``nrllm:compat:status`` command reports the same evaluation.

Definition of supported
-----------------------

    An integration is only supported when the third-party extension can be
    installed unmodified from its official Composer package and its normal
    workflow runs entirely through nr-llm without the extension's own
    provider API key.

The functional test suite encodes this: the real package from Packagist, an
empty provider key, a faked nr-llm completion surface, and the assertion
that no external provider transport is touched.

Consequences
============

* Adapters depend on nr-llm's feature-service interfaces
  (``CompletionServiceInterface`` etc.), the designed consumer surface with
  shipped test fakes — not on ``LlmServiceManager``.
* The extension currently constrains TYPO3 to ``^13.4``: the first shipped
  integration supports TYPO3 <= 13.4. The matrix widens with the first
  v14-capable integration.
* Model selection moves to nr-llm: a third-party extension's own model
  setting (e.g. ai-seo-helper's ``openAiModel``) is deliberately ignored,
  while request-shaping settings (temperature, top_p) are preserved.
* Per-request source attribution in nr-llm telemetry (``source.extension``)
  needs a metadata channel on nr-llm's side; until that lands, intercepted
  requests appear in telemetry under the serving configuration without a
  caller tag.
* Editing a toggle directly in ``settings.php`` requires a cache flush; the
  backend settings module flushes automatically on save.
