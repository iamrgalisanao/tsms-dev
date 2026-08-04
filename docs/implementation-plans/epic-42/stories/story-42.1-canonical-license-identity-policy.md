# Story 42.1: Canonical License Identity Policy

## Status

Conditionally Approved for Implementation Ready - pending policy sign-off, estimation, and owner assignment

> This file is conditionally approved for Implementation Ready. `LIC-POL-001`, disaster-recovery identity behavior, final estimates, and owner assignments must still be signed before implementation starts.

## Story

As a vendor licensing operator, I need canonical license identity contracts and a provider-neutral identity evidence boundary, so later TSMS stories can consistently bind clients, deployments, licenses, locations, environments, tenants, terminals, and provider references.

## Business Context

Human-readable names, database IDs, hostnames, provider account IDs, provider store IDs, POS device serials, and terminal labels are not stable TSMS security identifiers. Release 1 needs canonical vendor-owned IDs before later stories can safely validate license scope, tenant binding, terminal binding, transaction attribution, environment isolation, recovery, and redeployment authorization.

Mosaic is the recommended benchmark for provider-resource mapping because its API-first, location-oriented resource model offers the cleanest external identity pattern. StoreHub is the strongest physical outlet/register lifecycle benchmark. UTAK remains supportable through middleware/manual mapping with higher identity-contract uncertainty.

The benchmark provider is not the authority. TSMS remains the sole authority for canonical license identity.

## Scope

### Included

- `client_id`, `deployment_id`, `license_id`, `location_code`, and environment enum.
- UUIDv7 generation and validation standard.
- `BINARY(16)` persistence representation for UUID-backed canonical identities.
- Configuration naming standard.
- Strongly typed value objects and validators.
- Provider identity evidence and adapter contract for UTAK, StoreHub, Mosaic, and future providers.
- Clear rule that provider IDs are external references only.
- Story 42.2 handoff contract.

### Excluded

- Billing IDs.
- Customer-facing license portal identifiers.
- Provider-specific POS intake enforcement.
- Provider-specific terminal enforcement.
- Full implementation of UTAK, StoreHub, or Mosaic adapters.
- Creation of the provider mapping table, repository, or mapping service implementation.

## Architecture Locks

1. Security identity uses opaque canonical identifiers, not business labels.
2. Canonical license identities are generated and owned by TSMS or the vendor licensing authority.
3. Provider merchant, account, store, location, outlet, register, terminal, API client, and device identifiers are external references only.
4. Provider IDs must not be used as `client_id`, `deployment_id`, `license_id`, or authoritative `location_code`.
5. `client_id` identifies the licensed TSMS client organization, not a PITX tenant, POS merchant account, provider account, branch, outlet, or terminal.
6. `deployment_id` identifies one approved TSMS deployment lifecycle.
7. `license_id` identifies exactly one signed license artifact and rotates on every issuance, renewal, replacement, or corrected license.
8. Tenant and terminal security binding should primarily follow `client_id + deployment_id + location_code`; routine `license_id` renewal must not force tenant/terminal rebinding when deployment lineage is unchanged.
9. Environment values are strict enums; aliases like `prod`, `prd`, or `live` are invalid.
10. Staging and production have different deployment and license identities.
11. Location code is a controlled business designation, not GPS, IP, hostname, provider location ID, provider outlet ID, or MAC address.
12. Release 1 supports one primary licensed business location per deployment unless DR or another location is separately approved.
13. Provider sandbox/test/staging/production labels are mapped to, but do not define, the TSMS `LicenseEnvironment` enum.
14. Provider references must be normalized without changing semantic identity; normalization rules are provider-specific and test-covered.
15. No POS provider receives authority to generate or rotate TSMS license identifiers.
16. Story 42.1 must not introduce billing, subscriptions, or module entitlement identifiers.
17. Canonical identifiers are strictly validated and are not silently normalized.
18. Provider references preserve original values and may store separate normalized values.
19. Provider identity evidence must not contain credentials, full payloads, payment data, personal data, or unrestricted request content.
20. `deployment_id` is the deployment lineage identifier. A restored instance may retain `deployment_id` only through an approved recovery process that proves continuity of that lineage.
21. Any byte-level or claim-level change to a signed license artifact requires a new `license_id`; a license artifact is immutable after signing.
22. Provider evidence metadata is assembled from an explicit allowlist. Denylist-based filtering is insufficient.

## Dependencies

- Epic 42 architecture lock.
- `LIC-POL-001` approval.
- Confirmation of licensed legal client.
- Confirmation of production and staging deployments.
- Confirmation of primary licensed location.
- Confirmation whether disaster recovery receives a separate deployment or location code.
- Existing tenant and terminal model naming compatibility review.
- Existing provider integration configuration review.
- Agreement that provider identities are mapped references only.

## Data Model Changes

No mandatory production migration is owned by this story, but it must define the data contract for later stories.

Recommended canonical identity representation:

| Field | Classification | Recommended Format | Authority | Lifecycle |
|---|---|---|---|
| `client_id` | Canonical identity | `cli_<uuidv7>` | Vendor licensing authority | Immutable for client relationship |
| `deployment_id` | Canonical identity / lineage | `dep_<uuidv7>` | Vendor licensing authority | Rotates for new lifecycle; may be retained for approved same-lineage recovery |
| `license_id` | Canonical identity | `lic_<uuidv7>` | License issuance process | Rotates for every signed artifact |
| `location_code` | Controlled binding designation | Registry-approved code such as `PH-PITX-MAIN` | Approved business-location registry | Changes only through relocation approval |
| `environment` | Strict binding dimension | Strict enum | Architecture/release policy | Each environment receives distinct deployment/license identity |

Approved environment enum:

```text
development
test
staging
production
disaster-recovery
```

Canonical format:

```text
<prefix>_<canonical lowercase UUIDv7 string>
```

UUID parsing must:

- validate the required prefix;
- parse the UUID;
- confirm UUID version 7;
- reject noncanonical UUID text;
- reject surrounding whitespace;
- emit one lowercase canonical output format.

Examples:

```text
cli_019c0ab1-2e11-7cc4-95de-98d6843a8f11
dep_019c0ab2-0143-78cf-b56f-5802a62592f0
lic_019c0ab2-c66d-7616-a197-10597944d691
```

Location-code syntax should allow registry growth:

```text
^[A-Z]{2}(?:-[A-Z0-9]{2,16}){2,4}$
```

The regex validates syntax only. A `location_code` is valid only when it exists in the approved vendor-controlled location registry.

Recommended runtime configuration names:

```env
LICENSE_ENABLED=true
LICENSE_ENFORCEMENT_MODE=observe
LICENSE_EXPECTED_CLIENT_ID=cli_...
LICENSE_EXPECTED_DEPLOYMENT_ID=dep_...
LICENSE_EXPECTED_LOCATION_CODE=PH-PITX-MAIN
LICENSE_EXPECTED_ENVIRONMENT=production
LICENSE_FILE_PATH=storage/app/private/license.json
LICENSE_TRUST_STORE_PATH=storage/app/private/license-trust-store.json
```

Do not add `LICENSE_ID`; the active license artifact is the authority for `license_id`.

Source precedence:

```text
Signed license artifact supplies actual licensed claims.
Runtime configuration supplies expected deployment constraints.
deployment_metadata supplies persisted installation identity.
```

A mismatch is reported; no source silently overwrites another.

Storage representation:

- Use `BINARY(16)` for UUID-backed canonical IDs in indexed relational columns.
- Store UUID bytes only; prefixes such as `cli_`, `dep_`, and `lic_` are rendered by the domain layer.
- Use textual prefixed identifiers at API, configuration, license JSON, and safe log boundaries.

Recommended relational fields:

```text
client_uuid       BINARY(16)
deployment_uuid   BINARY(16)
license_uuid      BINARY(16)
```

Conversion logic must be centralized in value objects, casts, or serializers, not repeated ad hoc in models.

Release 1 location registry:

```php
// config/license_locations.php

return [
    'PH-PITX-MAIN' => [
        'name' => 'PITX Main',
        'status' => 'active',
        'environment' => ['production'],
    ],
    'PH-PITX-DR' => [
        'name' => 'PITX Disaster Recovery',
        'status' => 'approved_standby',
        'environment' => ['disaster-recovery'],
    ],
];
```

Release 1 location registry is configuration-backed. A database-backed or vendor-service registry is outside Story 42.1.

Implementation guidance for Story 42.8 only. This is a non-binding reference model subject to provider integration discovery:

```text
provider_identity_mappings
- id
- provider_code
- provider_account_reference
- provider_location_reference
- provider_terminal_reference
- tenant_id
- terminal_id
- client_id
- deployment_id
- location_code
- environment
- effective_from
- effective_until
- status
- metadata_hash
- created_at
- updated_at
```

Potential uniqueness, subject to provider capabilities:

```text
provider_code
provider_account_reference
provider_location_reference
provider_terminal_reference
environment
```

Do not require `provider_terminal_reference` when a provider does not expose it.

Provider mappings should normally bind to:

```text
client_id
deployment_id
location_code
environment
```

Do not include `license_id` in provider mapping uniqueness or the core binding key. The active `license_id` may rotate during renewal without changing provider mappings.

## Service and Component Changes

Deliver or define:

- `LicenseClientId`
- `LicenseDeploymentId`
- `LicenseArtifactId`
- `LicenseLocationCode`
- `LicenseEnvironment`
- `ExpectedDeploymentIdentity`
- `SignedLicenseIdentity`
- UUIDv7 generation/validation standard
- provider identity mapping contract
- provider identity evidence DTO
- location-code validator
- configuration naming standard

Recommended provider adapter contract:

```php
enum ProviderCode: string
{
    case UTAK = 'utak';
    case STOREHUB = 'storehub';
    case MOSAIC = 'mosaic';
}
```

Future providers may use a controlled registry if enum deployment becomes restrictive.

```php
final readonly class ProviderEvidenceMetadata
{
    public function __construct(
        public ?string $sourceField,
        public ?string $sourceVersion,
        public ?string $evidenceHash,
        public array $allowlistedAttributes = [],
    ) {}
}
```

```php
final readonly class ProviderIdentityEvidence
{
    public function __construct(
        public ProviderCode $providerCode,
        public ?string $accountReference,
        public ?string $locationReference,
        public ?string $terminalReference,
        public LicenseEnvironment $sourceEnvironment,
        public ProviderEvidenceMetadata $metadata,
    ) {}
}
```

Invariant: at least one stable provider reference must be present. Empty evidence is invalid.

Default maximum lengths:

```text
provider_code          32 characters
account_reference      191 characters
location_reference     191 characters
terminal_reference     191 characters
source_field           128 characters
source_version         64 characters
```

Provider adapters may enforce stricter limits.

Provider references may resolve downstream to statuses such as:

```text
unresolved
resolved
ambiguous
conflicting
inactive
```

```php
interface ProviderPayloadView
{
    public function accountReference(): ?string;

    public function locationReference(): ?string;

    public function terminalReference(): ?string;

    public function sourceVersion(): ?string;
}
```

```php
final readonly class ProviderAuthenticationContext
{
    public function __construct(
        public ProviderCode $providerCode,
        public LicenseEnvironment $sourceEnvironment,
    ) {}
}
```

```php
interface ProviderIdentityAdapter
{
    public function providerCode(): ProviderCode;

    public function extractIdentity(
        ProviderPayloadView $payload,
        ProviderAuthenticationContext $authentication
    ): ProviderIdentityEvidence;
}
```

`ProviderIdentityEvidence` contains original references. Normalization must produce a separate normalized provider identity object in later implementation work.

Recommended identity contracts:

```php
final readonly class ExpectedDeploymentIdentity
{
    public function __construct(
        public LicenseClientId $clientId,
        public LicenseDeploymentId $deploymentId,
        public LicenseLocationCode $locationCode,
        public LicenseEnvironment $environment,
    ) {}
}
```

```php
final readonly class SignedLicenseIdentity
{
    public function __construct(
        public LicenseArtifactId $licenseId,
        public LicenseClientId $clientId,
        public LicenseDeploymentId $deploymentId,
        public LicenseLocationCode $locationCode,
        public LicenseEnvironment $environment,
    ) {}
}
```

Story 42.2 compares `ExpectedDeploymentIdentity` against `SignedLicenseIdentity`.

Future implementations:

```text
UtakIdentityAdapter
StoreHubIdentityAdapter
MosaicIdentityAdapter
```

Story 42.1 defines the contract only; provider-specific enforcement remains in later stories.

## API and Route Changes

None.

## Processing Flow

1. Vendor creates canonical `client_id`.
2. Vendor creates environment-specific `deployment_id`.
3. Vendor assigns approved `location_code`.
4. License issuance creates `license_id`.
5. TSMS stores expected deployment constraints.
6. Story 42.1 defines the provider identity evidence contract.
7. Provider-specific adapters extract original identity references without treating them as canonical identity.
8. Later stories define normalization, persistence, resolution, and mapping to tenants and terminals.
9. Later stories validate signed license and binding scope.

No provider participates in steps 1-4.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Wrong environment | `ENVIRONMENT_MISMATCH` | Log | Block protected | Block protected |
| Wrong client | `CLIENT_MISMATCH` | Log | Block protected | Block protected |
| Wrong deployment | `DEPLOYMENT_MISMATCH` | Log | Block protected | Block protected |
| Wrong location | `LOCATION_MISMATCH` | Log | Block protected | Block protected |
| Provider reference used as canonical ID | `PROVIDER_REFERENCE_NOT_CANONICAL` | Reject config/mapping | Reject config/mapping | Reject config/mapping |

The mismatch codes above are downstream canonical reason codes reserved by Story 42.1 and implemented by Stories 42.2-42.4.

`ProviderReferenceNotCanonical` is the domain exception. `PROVIDER_REFERENCE_NOT_CANONICAL` is the safe reason code emitted at API/audit boundaries when this condition must be reported outside the domain layer.

Story 42.1 construction/validation errors:

```text
INVALID_CLIENT_ID_FORMAT
INVALID_DEPLOYMENT_ID_FORMAT
INVALID_LICENSE_ID_FORMAT
INVALID_LOCATION_CODE_FORMAT
INVALID_LICENSE_ENVIRONMENT
PROVIDER_REFERENCE_NOT_CANONICAL
INVALID_PROVIDER_IDENTITY_EVIDENCE
```

## Security Requirements

- Canonical identifiers must not expose legal or sensitive client data.
- Labels must not be accepted as authoritative identity.
- Provider IDs must be stored as provider identity evidence, not license identity.
- Provider references must preserve original values for audit.
- Normalized provider references must not be globally lowercased or altered unless the provider contract confirms the transformation is safe.
- Canonical identifiers must reject lowercase/whitespace variants instead of normalizing them silently.
- Cross-environment provider references must not override TSMS environment mismatch.
- Provider identity evidence metadata must exclude credentials, full payloads, payment data, personal data, and sensitive headers.
- Provider evidence metadata must use explicit allowlisted attributes only.

## Provider Benchmark and Mapping Guidance

### Recommended Benchmark

```text
Mosaic
```

Mosaic is the best-fit architectural benchmark because its API-first, location-oriented resource model supports a clean anti-corruption layer:

```text
Mosaic API resource
    -> MosaicIdentityAdapter
    -> ProviderIdentityMapping
    -> ExpectedDeploymentIdentity plus tenant/terminal binding
```

### Strong Secondary Benchmark

```text
StoreHub
```

StoreHub is the strongest benchmark for outlet/register lifecycle and terminal activation concepts. It is especially useful for later terminal-binding stories.

### Higher-Uncertainty Provider

```text
UTAK
```

UTAK remains supportable through adapter and manual onboarding registry where stable machine-readable identifiers are incomplete.

### Authority Rule

Provider adapters may identify:

- provider account;
- provider location;
- provider terminal;
- source environment.

Provider adapters must not generate:

- `client_id`;
- `deployment_id`;
- `license_id`;
- authoritative TSMS `location_code`.

## Fallback Approaches

Fallback A - StoreHub benchmark:

- Use when physical outlet/register activation becomes more important than API resource modeling.
- StoreHub IDs remain mapped references only.

Fallback B - Provider-neutral manual registry:

- Use when a provider does not expose stable machine-readable identities.
- Requires manual validation and audit approval.

Fallback C - Tenant-level mapping only:

- Use when provider terminal IDs are unavailable.
- Map provider account + location to TSMS tenant + licensed location.
- Terminal binding then relies on TSMS-issued terminal credentials.

Fallback D - Composite provider reference:

- Use provider code + merchant reference + branch reference + terminal reference where no single stable resource ID exists.
- Hash normalized composite for lookup, but retain original fields for audit.

Fallback E - Provider integration instance:

- Add `provider_integration_instance_id` where one provider account serves multiple integration channels.
- This is a TSMS integration record, not a licensing identity.

## Restoration and Disaster-Recovery Identity Policy

`LIC-POL-001` must explicitly define restoration and DR identity behavior:

- approved restoration of the same deployment may retain `deployment_id` when deployment lineage and recovery authorization remain valid;
- concurrent clone, relocation, or new installation lifecycle requires a new `deployment_id`;
- cold standby restored only during disaster may potentially retain deployment lineage under controlled recovery;
- concurrently running DR requires a separate `deployment_id`;
- geographically separate DR site requires a separate `location_code`;
- staging/UAT DR testing requires separate environment and deployment identity.

## Decision Owners

| Decision | Recommended Owner |
|---|---|
| Canonical ID policy | Solution Architect |
| Licensed client and location | Vendor Management / Client IT |
| UUID and storage standard | Backend Lead |
| Environment enum | Release Architect |
| Provider mapping contract | Integration Architect |
| Security review | Security Owner |

## Acceptance Criteria

### AC1 - Canonical Identity Standard

Given `LIC-POL-001` is approved, when canonical identities are generated, then they use the approved formats, authority, lifecycle, and rotation rules.

### AC2 - Strict Environment Contract

Given an environment value is supplied, when it is validated, then only approved enum values are accepted.

### AC3 - Provider References Cannot Become Security Identity

Given a UTAK, StoreHub, or Mosaic account, location, store, register, or terminal identifier, when it is processed, then it is classified as an external provider reference and cannot satisfy `client_id`, `deployment_id`, `license_id`, or authoritative `location_code` validation.

### AC4 - Provider Evidence Contract Available

Given UTAK, StoreHub, and Mosaic integrations, when later stories bind tenants and terminals, then each provider can extract provider-neutral identity evidence that can later resolve to an `ExpectedDeploymentIdentity` plus tenant and terminal binding.

### AC5 - License Rotation Does Not Rebind Deployment

Given an existing deployment receives a replacement license, when only `license_id` changes, then `client_id`, `deployment_id`, `environment`, and `location_code` remain unchanged unless separately approved.

### AC6 - Deployment Rotation Issues New Identity

Given TSMS is formally moved to a new approved deployment lifecycle, when the move is authorized, then a new `deployment_id` and new `license_id` are issued.

### AC7 - Location Control

Given a provider location ID or branch name, when it is mapped, then it does not automatically become the approved TSMS `location_code`.

### AC8 - Cross-Environment Identity Rejected

Given a provider reference is valid in staging, when it is used under production, then it cannot override a TSMS environment mismatch.

### AC9 - Immutable License Artifact Identity

Given a signed license artifact has been issued, when any signed claim or artifact content changes, then a new `license_id` is required and the previous artifact remains immutable.

### AC10 - Canonical Serialization

Given a valid canonical UUID identity is constructed, when it is serialized, then it emits the approved lowercase prefixed UUIDv7 representation.

### AC11 - Empty Provider Evidence Rejected

Given provider identity evidence has no account, location, or terminal reference, when it is constructed, then validation fails.

### AC12 - Sensitive Evidence Excluded

Given provider identity evidence is created from a request, when the evidence object is persisted or logged, then credentials, full payloads, payment data, and sensitive headers are not included.

### AC13 - Runtime and Signed Identity Sources Remain Separate

Given runtime expected identity and a signed license identity, when the values differ, then the system reports a mismatch and does not silently overwrite either source.

## Test Requirements

### Unit Tests

- UUIDv7-backed `client_id` is accepted.
- UUIDv4 or malformed prefixed value is rejected if UUIDv7 is mandatory.
- Business label such as `PITX` is rejected as `client_id`.
- Database integer is rejected as `deployment_id`.
- `prod`, `live`, and `prd` are rejected.
- `production` is accepted.
- Valid location code is accepted.
- Lowercase or whitespace-padded canonical location code is rejected.
- Provider account ID is rejected by canonical license validators.
- Provider location ID is rejected as authoritative `location_code`.
- New `license_id` can coexist with unchanged deployment identity.
- Provider evidence preserves original references.
- Empty provider identity evidence is rejected.
- Provider evidence metadata rejects sensitive raw payload content.
- Provider evidence metadata accepts only allowlisted attributes.

### Contract Tests

The provider adapter contract must have shared conformance tests using stub adapters:

```text
native provider references
    -> ProviderIdentityEvidence
    -> provider-neutral mapping contract
```

UTAK, StoreHub, and Mosaic implementations will execute the same tests when implemented in later stories. Provider-specific enforcement tests remain in later stories.

## Migration and Rollback

No production data migration is owned by this story. If a provider mapping table is introduced later, it must preserve original provider references, normalized references, environment scope, effective dates, and audit trail.

## Observability

Invalid canonical values should produce safe reason codes in later stories. Provider mapping failures should be auditable without exposing provider secrets or raw credentials.

## Definition of Done

### Implementation Ready

- [ ] `LIC-POL-001` approved.
- [ ] Canonical value-object contracts approved.
- [ ] Concrete class names, constructors, serialization, and validation behavior approved.
- [ ] `ExpectedDeploymentIdentity` and `SignedLicenseIdentity` contracts approved.
- [ ] UUIDv7 generation and validation standard approved.
- [ ] Location-code policy approved.
- [ ] Configuration-backed Release 1 location registry approved.
- [ ] Configuration names approved.
- [ ] `BINARY(16)` persistence representation approved.
- [ ] DR/restoration identity behavior approved.
- [ ] Decision owners assigned.
- [ ] Provider IDs explicitly classified as external references.
- [ ] Provider mapping contract documented.
- [ ] `ProviderIdentityEvidence` contract documented.
- [ ] `ProviderEvidenceMetadata` contract documented.
- [ ] Provider evidence safety constraints approved.
- [ ] Provider payload view and authentication context contracts approved.
- [ ] Mosaic mapping example documented.
- [ ] StoreHub mapping example documented.
- [ ] UTAK fallback/manual mapping documented.
- [ ] Unit tests identified and accepted by dev team.
- [ ] Architecture review approved.
- [ ] Story 42.2 can consume the identity contracts without reopening policy.

### Completed

- [ ] Canonical value objects implemented.
- [ ] `LicenseEnvironment` implemented.
- [ ] Provider code type implemented.
- [ ] Provider identity evidence contract implemented.
- [ ] Provider payload view and authentication context implemented.
- [ ] Location-code registry validation implemented.
- [ ] Unit and contract tests passing.
- [ ] Story 42.2 handoff documentation produced.

## Recommended Implementation Sequence

1. Approve `LIC-POL-001`.
2. Implement `BINARY(16)` persistence conversions through value objects, casts, or serializers.
3. Finalize DR/restoration identity behavior.
4. Define canonical serialization and validation rules.
5. Implement `ExpectedDeploymentIdentity` and `SignedLicenseIdentity`.
6. Define provider evidence safety constraints.
7. Implement UUIDv7 generator and value objects.
8. Implement environment and provider-code types.
9. Implement location-code registry validation.
10. Implement provider evidence, provider payload view, authentication context, and stub adapter contract.
11. Add unit and conformance tests.
12. Produce Story 42.2 handoff documentation.

## Out-of-Scope Follow-Ups

- Vendor-side issuance registry UI.
- Provider-specific adapter implementation.
- Provider-specific terminal enforcement.
- Billing, subscription, and module entitlement identifiers.

## Final Architectural Position

```text
Mosaic is the recommended architectural benchmark for Story 42.1 because its API-first, location-oriented resource model offers the cleanest external identity evidence pattern.

TSMS remains the sole authority for canonical license identity.

UTAK, StoreHub, and Mosaic identifiers are provider references only.
They must pass through a provider identity evidence and mapping boundary before they can participate in tenant, terminal, transaction, or license binding.
```
