<?php

namespace App\Services\Security;

use App\Models\SecurityAlert;
use App\Models\SecurityAlertResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\Contracts\SecurityAlertManagementInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityAlertManagementService implements SecurityAlertManagementInterface
{
    /**
     * Get security alerts list
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getAlerts(int $tenantId, array $filters = []): array
    {
        try {
            $query = SecurityAlert::where(function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id'); // Include system-wide alerts
            });
            
            // Apply filters
            if (isset($filters['status']) && $filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['severity']) && !empty($filters['severity'])) {
                $query->where('severity', $filters['severity']);
            }
            
            if (isset($filters['from']) && !empty($filters['from'])) {
                $query->where('created_at', '>=', Carbon::parse($filters['from']));
            }
            
            if (isset($filters['to']) && !empty($filters['to'])) {
                $query->where('created_at', '<=', Carbon::parse($filters['to']));
            }
            
            if (isset($filters['search']) && !empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }
            
            // Sorting
            $sortField = $filters['sort_field'] ?? 'created_at';
            $sortDir = $filters['sort_dir'] ?? 'desc';
            
            $query->orderBy($sortField, $sortDir);
            
            // Pagination
            $page = $filters['page'] ?? 1;
            $perPage = $filters['per_page'] ?? 15;
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            
            $results = $paginator->items();
            
            // Format data for frontend
            $formattedResults = [];
            foreach ($results as $alert) {
                $formattedResults[] = [
                    'id' => $alert->id,
                    'title' => $alert->title,
                    'description' => $alert->description,
                    'severity' => $alert->severity,
                    'status' => $alert->status,
                    'source' => $alert->source,
                    'created_at' => $alert->created_at->toIso8601String(),
                    'acknowledged_at' => $alert->acknowledged_at ? Carbon::parse($alert->acknowledged_at)->toIso8601String() : null,
                    'resolved_at' => $alert->resolved_at ? Carbon::parse($alert->resolved_at)->toIso8601String() : null,
                    'tenant_id' => $alert->tenant_id,
                ];
            }
            
            return [
                'data' => $formattedResults,
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ];
        } catch (\Exception $e) {
            Log::error('Error getting security alerts: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'exception' => $e
            ]);
            
            return [
                'data' => [],
                'total' => 0,
                'per_page' => $filters['per_page'] ?? 15,
                'current_page' => $filters['page'] ?? 1,
                'last_page' => 1,
            ];
        }
    }

    /**
     * Get a specific alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @return array|null
     */
    public function getAlert(int $alertId, int $tenantId): ?array
    {
        try {
            $alert = SecurityAlert::where('id', $alertId)
                ->where(function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id'); // Include system-wide alerts
                })
                ->with(['responses.user'])
                ->first();
            
            if (!$alert) {
                return null;
            }
            
            $tenant = null;
            if ($alert->tenant_id) {
                $tenant = Tenant::find($alert->tenant_id);
            }
            
            // Format responses as notes
            $notes = [];
            foreach ($alert->responses as $response) {
                $notes[] = [
                    'id' => $response->id,
                    'content' => $response->notes,
                    'user' => $response->user ? [
                        'id' => $response->user->id,
                        'name' => $response->user->name
                    ] : null,
                    'created_at' => $response->created_at->toIso8601String()
                ];
            }
            
            return [
                'id' => $alert->id,
                'title' => $alert->title,
                'description' => $alert->description,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'source' => $alert->source,
                'context' => json_decode($alert->context, true),
                'created_at' => $alert->created_at->toIso8601String(),
                'acknowledged_at' => $alert->acknowledged_at ? Carbon::parse($alert->acknowledged_at)->toIso8601String() : null,
                'resolved_at' => $alert->resolved_at ? Carbon::parse($alert->resolved_at)->toIso8601String() : null,
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name
                ] : null,
                'notes' => $notes
            ];
        } catch (\Exception $e) {
            Log::error('Error getting security alert: ' . $e->getMessage(), [
                'alert_id' => $alertId,
                'tenant_id' => $tenantId,
                'exception' => $e
            ]);
            
            return null;
        }
    }

    /**
     * Acknowledge an alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @param int $userId
     * @param string|null $notes
     * @return bool
     */
    public function acknowledgeAlert(int $alertId, int $tenantId, int $userId, ?string $notes = null): bool
    {
        try {
            $alert = SecurityAlert::where('id', $alertId)
                ->where(function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
                ->first();
            
            if (!$alert) {
                return false;
            }
            
            // Only acknowledge if it's active
            if ($alert->status !== 'active') {
                return false;
            }
            
            $alert->status = 'acknowledged';
            $alert->acknowledged_at = now();
            $alert->acknowledged_by = $userId;
            $alert->save();
            
            // Add response record
            if ($notes) {
                SecurityAlertResponse::create([
                    'alert_id' => $alertId,
                    'user_id' => $userId,
                    'action' => 'acknowledge',
                    'notes' => $notes
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error acknowledging security alert: ' . $e->getMessage(), [
                'alert_id' => $alertId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'exception' => $e
            ]);
            
            return false;
        }
    }

    /**
     * Resolve an alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @param int $userId
     * @param string $status
     * @param string|null $notes
     * @return bool
     */
    public function resolveAlert(int $alertId, int $tenantId, int $userId, string $status = 'resolved', ?string $notes = null): bool
    {
        try {
            $alert = SecurityAlert::where('id', $alertId)
                ->where(function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
                ->first();
            
            if (!$alert) {
                return false;
            }
            
            // Only resolve if it's not already resolved
            if ($alert->status === 'resolved') {
                return false;
            }
            
            $alert->status = 'resolved';
            $alert->resolved_at = now();
            $alert->resolved_by = $userId;
            $alert->resolution_status = $status; // false positive, confirmed, etc.
            $alert->save();
            
            // Add response record
            SecurityAlertResponse::create([
                'alert_id' => $alertId,
                'user_id' => $userId,
                'action' => 'resolve',
                'notes' => $notes ?? "Alert resolved with status: {$status}"
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error resolving security alert: ' . $e->getMessage(), [
                'alert_id' => $alertId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'exception' => $e
            ]);
            
            return false;
        }
    }

    /**
     * Add notes to an alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @param int $userId
     * @param string $notes
     * @return bool
     */
    public function addAlertNotes(int $alertId, int $tenantId, int $userId, string $notes): bool
    {
        try {
            $alert = SecurityAlert::where('id', $alertId)
                ->where(function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
                ->first();
            
            if (!$alert) {
                return false;
            }
            
            // Add response record
            SecurityAlertResponse::create([
                'alert_id' => $alertId,
                'user_id' => $userId,
                'action' => 'note',
                'notes' => $notes
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error adding notes to security alert: ' . $e->getMessage(), [
                'alert_id' => $alertId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'exception' => $e
            ]);              return false;
        }
    }

    /**
     * Get alert responses
     * 
     * @param int $alertId
     * @return \Illuminate\Support\Collection
     */
    public function getAlertResponses(int $alertId)
    {
        try {
            return SecurityAlertResponse::where('alert_id', $alertId)
                ->orderBy('created_at', 'asc')
                ->get();
        } catch (\Exception $e) {
            Log::error('Error getting security alert responses: ' . $e->getMessage(), [
                'alert_id' => $alertId,
                'exception' => $e
            ]);
            
            return collect([]);
        }
    }
}