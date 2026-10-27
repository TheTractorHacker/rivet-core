# What we may say publicly about security and compliance

Status: the project has **no certifications and no third-party audit**. Every claim below is true only because it is a self-assessment that anyone can inspect. If a sentence here stops being true, remove it everywhere the same day.

Evidence for every approved claim: [BASELINE.md](BASELINE.md) (controls, status, tests), [CONTROL_MAPPING.md](CONTROL_MAPPING.md) (generated), the CI job `security baseline`, and the audit in [CORE_SECURITY_AUDIT.md](CORE_SECURITY_AUDIT.md).

## Approved wording (use as written)

| Claim | Why it is defensible |
|---|---|
| "Built to OWASP ASVS Level 2 (self-assessed; controls and tests published)." | The control list is public, each control has a status and a named test, and gaps are listed. "Built to" and "self-assessed" are the honest verbs. Do not drop either. |
| "Security controls mapped to the SOC 2 Trust Services Criteria and ISO/IEC 27001:2022 Annex A." | `CONTROL_MAPPING.md` is generated from the control list. It says mapped, not met. |
| "Secure-by-default configuration: TLS-only rules, Secure/HttpOnly cookie rules, encrypted stored credentials, an audit-logging rule that cannot be switched off, an audit retention floor of 90 days in the settings layer." | Each is a control with a test (HARD-*, CRYPT-*, ERR-*). Use the wording "rules" for items an edition applies; say "encrypted stored credentials" only about Core-owned secrets. |
| "Every security change is covered by automated checks in CI, including secret scanning, dependency audit and static analysis." | CI jobs `secret-scan`, `dependency-audit`, `static`, `security baseline`. |
| "Independent security review: none yet. Self-audited 2026-10; findings and fixes published." | Saying what has not been done builds more trust than silence. |
| "We publish our known gaps." | The gap list in `BASELINE.md`. |
| "Designed to help you meet your own SOC 2, ISO 27001 or HIPAA obligations." | Software can support a customer's compliance program; it cannot make the customer compliant. |

Version and date: always say which release the claim refers to, and link the control list at that tag.

## Phrases that must NOT be used

| Do not say | Why |
|---|---|
| "SOC 2 compliant", "SOC 2 certified", "SOC 2 attested", "SOC 2 Type I/II" | SOC 2 is an attestation report issued by a licensed CPA firm after an audit. Without that report, the statement is false. Customers and insurers will ask for the report. |
| "ISO 27001 certified", "ISO 27001 compliant", "ISO 27001 aligned certified" | Certification is granted by an accredited certification body after auditing an ISMS (a management system, not a codebase). We have no ISMS audit. "Compliant" is read as "certified" by buyers. |
| "HIPAA compliant" or "HIPAA certified" as a product claim | There is no HIPAA certification. Compliance belongs to the covered entity or business associate and depends on how the product is operated, plus a signed Business Associate Agreement. A product can only be said to "support" a customer's HIPAA safeguards, and only with the control evidence behind it. |
| "PCI compliant", "FedRAMP", "CMMC", "NIST certified" | Same reason; each is an assessed status. (The compliance profiles in the product check a customer's own environment against a framework; they do not make the product or the customer certified.) |
| "Guaranteed secure", "unhackable", "bank-grade", "military-grade encryption", "100% secure" | Unprovable and absolute. A breach after such a claim is evidence of a false statement. |
| "ASVS Level 2 certified/compliant/verified", "OWASP certified" | OWASP does not certify. ASVS verification by a third party is a separate service. |
| "Audited by" or "penetration tested" without naming who, when and the scope | The 2026-10 review was internal. Say "self-audited". |
| "Meets all ASVS Level 2 requirements" | It does not: see the gap list and the `partial`, `gap` and `edition-responsibility` controls. |

Why this matters: a false or misleading compliance claim can be an unfair or deceptive practice (for example under FTC Act section 5 in the US), can void contract warranties, can be an exclusion or misrepresentation problem on a cyber-insurance application (a claim may be denied if the application overstated controls), and gives a customer grounds to terminate. This is general information, not legal advice; have counsel review marketing pages before launch.

## Edition notes

RivetIT and RivetMSP may repeat the approved claims about Core. Claims about the whole product (logins, sessions, MFA, headers, backups) are only true for an edition once its `edition-responsibility` controls are implemented and tested; see [the edition prompts](../design/security-baseline-edition-prompts.md). Until then an edition should say "built on RivetCore, which is built to ..." and not claim the controls for itself.

## What a real audit would involve (so the decision can be made later)

All figures are rough, US, 2026, and vary a lot by firm and scope. Get quotes.

| Option | What it requires | Typical cost and time |
|---|---|---|
| SOC 2 Type I | Written policies, access reviews, vendor management, change management, background checks, incident process, and a compliance platform to collect evidence; a CPA firm audits the design at a date. | Platform about 8k to 25k per year, auditor about 8k to 25k, plus internal time; 2 to 4 months. |
| SOC 2 Type II | Same controls operating for an observation period of 3 to 12 months, with evidence for each. | Auditor about 15k to 50k per year on top of the platform; 6 to 15 months to the first report; repeated every year. |
| ISO/IEC 27001 | An information security management system: scope, risk assessment, Statement of Applicability, internal audit, management review; stage 1 and stage 2 audits by an accredited body, annual surveillance audits. | About 15k to 60k for the first cycle including consultant help, then 8k to 20k per year; 6 to 12 months. |
| Third-party ASVS L2 assessment or pentest | An outside firm tests against ASVS or a scoped pentest and writes a report you can share. | A scoped web/API pentest is commonly 8k to 30k. |

Most of the cost is the organisation (policies, evidence, reviews), not the code. A solo maintainer should expect the audit to ask for things that are not in this repository: HR onboarding and offboarding records, access reviews, vendor assessments, a risk register and board or management reviews.

## Low-cost, credible steps (in order of value)

1. Publish the control list and gaps (done: this directory) and keep the CI check green.
2. Commission an annual third-party pentest of a release candidate and publish the summary and fixes. This is the single most convincing artefact for MSP buyers.
3. Answer a CAIQ (CSA Consensus Assessments Initiative Questionnaire) Lite or SIG Lite once, truthfully, from this baseline, and send it on request. Many MSP customers accept this instead of a report. The CSA STAR Level 1 self-assessment is free and public.
4. Add a trust page on the website: the approved claims verbatim, a link to the control list at the current tag, the gap list, the disclosure policy (SECURITY.md), the sub-processors, and a contact.
5. Publish an SBOM and sign releases (GAP-12); cheap and expected by security teams.
6. Use a vulnerability disclosure policy and, later, a small bug bounty.
7. Only then consider SOC 2 Type I, driven by a customer who asks for it; Type II after at least a year of operating the controls.
