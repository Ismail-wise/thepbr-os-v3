# ADR-013 — Fixed-Precision Money / FX Snapshots

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Fixed-Precision Money / FX Snapshots**.

## Context

Capital, contributions, valuation, finance, rewards, transfers and settlements require reproducible monetary values. Floating-point arithmetic and retroactively changing exchange rates would undermine financial and historical integrity.

## Decision

Money uses **fixed decimal/numeric precision, never floating-point**, and stores amount plus currency code.

A Business has a base currency independent of UI language.

An approved FX conversion preserves its original amount/currency, exchange rate, rate date, source, converted amount and base currency. An approved FX snapshot does not retroactively change.

## Required Invariants

- Monetary calculations do not use floating-point canonical values.
- Amount and currency code are stored together.
- Base currency is independent of language mode.
- Approved FX conversion data is preserved as a historical snapshot.
- Later exchange-rate changes do not rewrite approved historical conversions.
- Financial historical truth remains reproducible.

## Consequences

Money and FX data models must carry sufficient precision and conversion metadata. Historical reports must use the applicable stored approved snapshot rather than silently recalculating old business truth using a new rate.

## Security & Integrity Implications

Financial values must not be silently altered by representation, locale or later FX data. Authorization and version rules continue to apply to approved monetary records.

## Verification / Testing Implications

Domain invariant tests must cover decimal precision, currency identity, deterministic calculations and preservation of approved FX snapshots across later rate changes.

## Source Traceability

- `# 23. Money, currency and dates`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Changing canonical money precision or allowing approved FX history to recalculate retroactively requires explicit architecture/domain approval.
