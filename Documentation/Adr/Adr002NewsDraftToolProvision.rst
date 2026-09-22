.. _adr-002:

======================================================
ADR-002: A writer for EXT:news, shipped as a tool
======================================================

:Status: Accepted
:Date: 2026-09-22

Context
=======

ADR-001 designs this extension around one thing: taking over the LLM
provider calls of third-party AI extensions. EXT:news (``georgringer/news``)
makes no such calls. What it lacks is the other direction — nr-llm's
assistant recognises its table and cannot create a record in it, because no
nr-llm writer exists for it.

nr-llm's position is that an extension whose records the assistant should
write brings its own writer, or a bridge extension does so on its behalf.
The shipped authority for that is the tool contract itself:
``ToolInterface`` is marked ``@api`` as the extension point third parties
implement and carries ``#[AutoconfigureTag('nr_llm.tool')]``, and nr-llm's
ADR-127 makes the ``@api`` marker the semver authority. The record that
states the position in those words, ADR-197 ("A generic creator where no
narrow writer exists"), is pending on nr-llm's branch
``feature/NEXT-160-generic-record-fallback`` and not part of a release at
the time of writing; it adds a generic fallback that covers the gap only
while no such writer exists. EXT:news ships none. The Netresearch demo
deploys news 14.0.3 on TYPO3 14.3.7 and needs one.

Decision
========

A fifth strategy, not a fifth interception
------------------------------------------

``IntegrationStrategy::ToolProvision``: the integration names a tool class,
and the compiler pass registers it as an autowired service tagged
``nr_llm.tool`` exactly when ``StatusReporter`` evaluates the integration
as Active — installed in a supported version, contract verified, enabled in
the extension configuration. Nothing of the third-party extension is
replaced, hooked or configured. The class lives under ``Classes/Bridge/``,
which is excluded from service autoregistration: anywhere else it would be
tagged and offered on every container build, activation or not.

The contract the verifier checks is the extension setting the tool reads at
call time (``EmConfiguration::getDateTimeRequired()``) and that the tool
class implements nr-llm's ``ToolInterface``. The supported range brackets
the TCA shape the tool writes against, diffed at the tags 12.0.0, 12.3.2,
13.0.0, 13.0.2, 14.0.0, 14.0.3 and 14.1.1.

The tool holds nr-llm's line for writers
----------------------------------------

``create_news_draft`` is shaped after nr-llm's ``CreatePageDraftTool``:
disabled by default, in the ``editing`` group, ``NON_IDEMPOTENT_WRITE`` so
the approval pause applies, live workspace only, the acting backend user
authorised explicitly and never read from ``$GLOBALS``, one neutral refusal
for "no such folder" and "not yours", and a read-back that deletes the
record again when the DataHandler dropped an exclude field the approver was
shown. Only ``pid``, ``type`` and the fields the live TCA marks ``exclude``
are compared, a text by presence rather than by bytes: ``bodytext`` is an
RTE field, stored through the RTE parser, which joins block elements with a
line feed, so its bytes legitimately differ from the argument — and a
missing grant drops a field to empty, never to a different form.

Fixed by the tool: the record is hidden; the type is article (the link
types require a URL field each); the language is the default one (phase
one); the target is a storage folder; ``path_segment`` is the DataHandler's
slug generator's. Not settable in phase one, and stated in the tool's
description: categories, media, tags, related records.

The page permission checked is ``CONTENT_EDIT`` on the folder, because that
is what ``DataHandler::checkRecordInsertAccess()`` checks for a record in a
table other than ``pages``. The date is required exactly when the installed
EXT:news requires it; the DataHandler does not enforce ``required`` (it
skips the field without an error entry), so the tool does. The value handed
over is an integer UNIX timestamp: TYPO3 13.4 stores an integer unchanged,
14.3 reads it as the database value and writes the same value back, while
an ISO string with an offset is shifted by the server timezone on 13.4.

Own copies of the writers' mechanics
------------------------------------

nr-llm's builtin writers share ``WritesThroughDataHandlerTrait`` and
``PlansOneEditorialWriteTrait``. Neither carries the ``@api`` marker that
nr-llm's ADR-127 makes the semver authority, and neither appears in its
``api-surface.txt``; a minor nr-llm release may change them without a
deprecation. The tool carries its own copy of the few private helpers it
needs instead of depending on them.

Consequences
============

* The status command lists the integration like any other; its
  ``capabilities`` row names the tool. Enabling the integration registers
  the tool; an administrator still enables the tool itself in nr-llm's Tools
  module, as with every writer.
* ``hidden`` is an exclude field with default 0 in the news TCA: an editor
  without the ``tx_news_domain_model_news:hidden`` field grant gets the
  record deleted again and a message naming the grant, rather than a
  visible article.
* ``georgringer/news`` joins ``require-dev`` (``^14.0``, installable on
  every matrix cell); the functional suite boots the unmodified package.
* ``netresearch/nr-llm`` rises to ``^0.35``. The tool hands a ``WriteKind``
  to ``ToolResult::withWriteTarget()``, which 0.35.0 introduced; 0.1.5 kept
  ``^0.34`` because no tool was registered here, and that no longer holds.
  ``Tests/Unit/VersionConsistencyTest.php`` reads ``composer.json`` and
  ``ext_emconf.php`` and refuses a floor below 0.35.0.
* A translation, categories, media and the link types are the case for a
  later phase; each widens the approval card and is a decision of its own.
