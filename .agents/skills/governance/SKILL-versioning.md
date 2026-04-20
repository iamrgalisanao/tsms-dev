# SKILL: Versioning & Release Governance

---
name: versioning
category: governance
argument-hint: bump|audit|enforce|changelog
---

## Purpose
Embed robust, auditable versioning practices into the TSMS-DEV workflow, ensuring all code, documentation, and APIs are versioned, traceable, and compliant with governance standards.

## Standard: Semantic Versioning (SemVer 2.0.0)
- **Format:** MAJOR.MINOR.PATCH (e.g., 2.1.0)
- **MAJOR:** Incompatible API/schema changes
- **MINOR:** Backward-compatible feature additions
- **PATCH:** Backward-compatible bug fixes

## Policy & Best Practices

### 1. Version Bumping
- Every release increments the version according to SemVer rules.
- All PRs that change public interfaces, schemas, or documentation must propose a version bump.

### 2. Documentation Versioning
- All major documentation/spec changes are tagged with the current SemVer.
- Maintain `docs/CHANGELOG.md` and versioned folders (e.g., `docs/v2.1/`).
- Documentation/spec headers must include the current version.

### 3. API & Schema Versioning
- Prefix breaking API changes with `/v{major}/` (e.g., `/api/v2/tenants`).
- Deprecate but do not immediately remove old versions; follow a sunset policy.
- Each migration batch is tagged with the release version.
- Maintain a `database/schema_version` table or file.

### 4. Changelog Management
- All changes must be documented in `CHANGELOG.md` using [Keep a Changelog](https://keepachangelog.com/) format.
- Changelogs are mandatory for every version bump.

### 5. Enforcement & Audit
- Governance agents and PR reviewers must enforce version and changelog updates before merge.
- Automated checks should block merges if versioning or changelog requirements are unmet.

## Actions
| Action      | Description                                                      |
|-------------|------------------------------------------------------------------|
| `bump`      | Propose or apply a version bump based on changes.                |
| `audit`     | Scan for versioning and changelog compliance.                    |
| `enforce`   | Block merges if versioning or changelog rules are violated.      |
| `changelog` | Generate or update the changelog for the current release.        |

## References
- [semver.org](https://semver.org/)
- [Keep a Changelog](https://keepachangelog.com/)
- [API Versioning Best Practices](https://learn.microsoft.com/en-us/azure/architecture/best-practices/api-design#versioning)

---

> This skill ensures all releases are traceable, auditable, and compliant with best practices for long-term maintainability and governance.
