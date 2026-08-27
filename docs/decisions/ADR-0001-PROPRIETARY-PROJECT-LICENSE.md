# ADR-0001 — VSN Commerce project licensing is proprietary

Status: Accepted

Decision date: 2026-08-28

## Context

The repository contained contradictory project-level licensing metadata:

- root `LICENSE` contained GNU GPL v3 text;
- `composer.json` declared the root package license as `proprietary`.

The project operating rules explicitly prohibited an AI/code agent from guessing which representation was intended.

## Decision

The repository owner explicitly confirmed that VSN Commerce is **not GPLv3**.

The project-level licensing intent is therefore proprietary / closed-source.

The root package will continue to declare:

```json
"license": "proprietary"
```

The root `LICENSE` file must not grant GPLv3 or another open-source license to VSN Commerce proprietary project materials. It is replaced by a narrow proprietary software notice.

## Scope

This decision applies to original VSN Commerce project materials owned or controlled by the project rights holder.

It does **not** relicense third-party dependencies, frameworks, libraries, fonts, media, or other third-party materials. Those remain governed by their respective licenses.

This ADR records repository engineering intent. It is not a replacement for commercial agreements, contributor agreements, customer terms, or jurisdiction-specific legal advice where those are required.

## Consequences

- P0-02 is no longer blocked on owner intent.
- `composer.json` needs no license-field change because it already says `proprietary`.
- root GPLv3 license text must be removed/replaced.
- dependency/license compliance still needs to respect all third-party terms.
- future automation must reject accidental reintroduction of contradictory project-level open-source license metadata unless an explicit owner decision supersedes this ADR.
