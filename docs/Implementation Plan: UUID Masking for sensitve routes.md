# Implementation Plan: UUID Masking for Sensitive Routes

## Background

The TSMS platform currently exposes auto-incrementing integer IDs (`bigIncrements`) in public URLs for sensitive resources like Tenants (e.g., `/commercial/tenants/12`). This opens the system to **Resource Enumeration Attacks** where an attacker can iterate IDs to discover the full scope of the tenant database.

This plan transitions the Tenant resource (and subsequently POS Terminals) to use **UUIDs** as public-facing identifiers, while preserving the internal `bigIncrements` primary key for all database relationships and performance.

---

## User Review Required

> [!IMPORTANT]
> This plan adds a `uuid` column to the `tenants` table. A database migration must be run on **staging and production**. No existing data will be lost — the migration adds the UUID column and back-fills all existing records automatically.

> [!WARNING]
> All API consumers (e.g., future integrations or external scripts) that reference tenant IDs by integer in `GET /api/tenants/{id}` will need to be updated to use the UUID. Internal references (foreign keys between tables) remain unchanged.

---

## Proposed Changes

### Phase 1: Database — Add UUID to Tenants

---

#### [NEW] Migration: `add_uuid_to_tenants_table.php`

Add a `uuid` column (unique, indexed) to the `tenants` table. The migration will back-fill all existing rows using `Str::uuid()`.

```php
Schema::table('tenants', function (Blueprint $table) {
    $table->uuid('uuid')->nullable()->unique()->after('id');
});
// Back-fill existing rows
Tenant::whereNull('uuid')->each(fn($t) => $t->update(['uuid' => (string) Str::uuid()]));
// Make non-nullable
Schema::table('tenants', function (Blueprint $table) {
    $table->uuid('uuid')->nullable(false)->change();
});
```

---

### Phase 2: Backend Model

#### [MODIFY] [Tenant.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/app/Models/Tenant.php)

- Add `uuid` to `$fillable`.
- Override `getRouteKeyName()` to return `'uuid'` — this tells Laravel's route model binding to resolve `Tenant` objects by UUID instead of ID.
- Add a `boot()` method to auto-generate UUIDs on creation.

```php
public static function boot(): void {
    parent::boot();
    static::creating(fn($model) => $model->uuid = (string) Str::uuid());
}

public function getRouteKeyName(): string {
    return 'uuid';
}
```

---

### Phase 3: Backend API Controller

#### [MODIFY] [TenantController.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/app/Http/Controllers/TenantController.php)

- The `show`, `update`, and `destroy` methods already use Route Model Binding (`Tenant $tenant`) — they will **automatically** resolve by UUID after step 2 with zero changes needed.
- **Only `store()`** needs a change: remove any validation requiring `customer_code` uniqueness vs. ID and ensure the UUID is not in the request payload (it is generated server-side).

---

### Phase 4: Backend API Routes

#### [MODIFY] [api.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/routes/api.php)

No path changes needed. Laravel route model binding handles the resolution transparently. The route `/api/tenants/{tenant}` will accept a UUID naturally.

---

### Phase 5: Backend Web Routes

#### [MODIFY] [web.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/routes/web.php)

The SPA shell routes (`return view('app')`) do not resolve model bindings, so no backend changes needed here. The UUID is consumed purely by the React frontend and the API.

---

### Phase 6: Frontend — React SPA

#### [MODIFY] [TenantDirectoryPage.jsx](file:///Users/teamsolo/Projects/PITX/tsms-dev/resources/js/Pages/Commercial/TenantDirectoryPage.jsx)

- Update all `navigate('/commercial/tenants/' + tenant.id)` calls to use `tenant.uuid`.

#### [MODIFY] [TenantProfilePage.jsx](file:///Users/teamsolo/Projects/PITX/tsms-dev/resources/js/Pages/Commercial/TenantProfilePage.jsx)

- Update the `useParams()` destructuring from `{ id }` to `{ uuid }`.
- Update the API call from `axios.get('/api/tenants/' + id)` to `axios.get('/api/tenants/' + uuid)`.

#### [MODIFY] [app.jsx](file:///Users/teamsolo/Projects/PITX/tsms-dev/resources/js/app.jsx)

- Update the route parameter from `:id` to `:uuid`:
  ```diff
  - <Route path="/commercial/tenants/:id" ...
  + <Route path="/commercial/tenants/:uuid" ...
  ```

---

## Open Questions

> [!NOTE]
> **Scope**: This phase covers the `Tenant` resource only. A Phase 2 can cover `PosTerminal` (TerminalToken page) using the same pattern.
> 
> **Do you want `PosTerminal` UUID masking included in this same migration cycle?**

---

## Verification Plan

### Automated Tests
- Run `php artisan migrate` on local — verify no errors.
- Run `php artisan tinker` → `Tenant::first()->uuid` — confirm UUID is present and unique.
- Run `php artisan route:list | grep tenants` — confirm routes still resolve.

### Manual Verification
- Navigate to `/commercial/tenants` — verify the directory loads correctly.
- Click a tenant — verify the URL shows `/commercial/tenants/550e8400-...` (UUID) instead of `/commercial/tenants/12`.
- Verify the profile page loads the correct tenant data.
- Attempt to manually navigate to `/commercial/tenants/1` — verify it returns a 404 (React `NotFoundPage`).
