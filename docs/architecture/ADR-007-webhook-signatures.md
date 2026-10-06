# ADR-007: Webhook signatures: V1 legacy stays for all of 1.x, V2 is the default

Status: accepted (2026-10-06). Decision #47 in the ROADMAP.

## Context

Until 0.17 a delivery carried only the legacy headers `<prefix>-Signature` (hex HMAC-SHA256 of the raw body) and
`<prefix>-Event` and friends. The signature covered the body but not the time, so a captured request could be replayed
indefinitely. 0.17 added `X-Rivet-Timestamp` and `X-Rivet-Signature-V2` (`t=<unix seconds>,v1=<hex HMAC-SHA256 of
"<t>.<raw body>">`), kept the legacy headers byte-identical, and made retries send the same body with a fresh timestamp and V2
signature. Existing receivers (an n8n flow, a customer's script) were written against the legacy header and cannot be
changed on Core's schedule.

## Decision

1. **V1 (legacy) stays for all of 1.x.** The legacy headers are marked `@deprecated` in the docblocks and in `docs/webhooks.md`
   from 1.0, still sent on every request, still byte-identical. They are **removed in 2.0**.
2. **V2 is the default and the documented way.** New destinations, every preset guide (`docs/webhook-platforms.md`) and every
   verification snippet use V2 only.
3. **Receivers should verify V2 and reject anything whose timestamp differs from their clock by more than 5 minutes (300 seconds)
   in either direction.** A receiver that keeps deduplication state should keep recent `event` plus `timestamp` pairs for a few
   minutes as well. The verification snippets for node, python, php, bash and n8n in `docs/webhooks.md` implement exactly this;
   they use a constant-time comparison.
4. The tolerance is the receiver's choice; Core does not enforce it. Core's obligations are: sign the exact bytes sent, stamp a
   fresh time on every attempt (`$signedAt = time()` per retry), and never alter the legacy output.
5. No setting turns V1 off in 1.x. An edition that wants to stop sending it can do so only by a Core-side option, which would be a
   minor (additive, default on) and is not planned.

## Consequences

- Nothing breaks for existing receivers at 1.0.
- Every request carries two signatures for the whole of 1.x; the cost is a few hundred bytes of header and one extra HMAC.
- Anyone who verifies only V1 has no replay protection; the docs say so in plain words and recommend moving.
- The 2.0 removal is announced in advance: the deprecation is in the 1.0 changelog and `UPGRADING.md`, and the admin screens that
  show a destination's setup instructions only show V2.

## Reversal cost

Low in the direction of keeping V1 longer (no change). Removing V1 earlier than 2.0 would break every receiver that has not moved and
is a breaking change by ADR-004, so it needs a major. Re-adding V1 after removal is cheap for Core (one header) but cannot help a
receiver that is already broken.
