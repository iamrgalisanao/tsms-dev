<?php

namespace App\Services\Security;

use App\Models\SecurityAlert;
use App\Models\SecurityAlertResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\Contracts\SecurityAlertManagementInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityAlertManagementService implements SecurityAlertManagementInterface
{
    // Error codes
    private const ERROR_ALERT_NOT_FOUND = 'ALERT_001';
    private const ERROR_INVALID_STATUS = 'ALERT_002';
    private const ERROR_RESPONSE_FAILED = 'ALERT_003';

    private function handleAlertNotFound(int $alertId, int $tenantId): void
    {
        Log::warning('Alert not found', [
            'code' => self::ERROR_ALERT_NOT_FOUND,
            'alert_id' => $alertId,
            'tenant_id' => $tenantId
        ]);
    }

    private function handleInvalidStatus(int $alertId, string $currentStatus, string $action): void
    {
        Log::warning("Cannot $action - Invalid status", [
            'code' => self::ERROR_INVALID_STATUS,
            'alert_id' => $alertId,
            'current_status' => $currentStatus
        ]);
    }

    private function logOperationError(string $operation, int $alertId, int $tenantId, \Exception $e): void
    {
        Log::error("Error performing $operation", [
            'code' => self::ERROR_RESPONSE_FAILED,
            'alert_id' => $alertId,
            'tenant_id' => $tenantId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function getAlerts(int $tenantId, array $filters = []): array
    {
        try {
            $query = SecurityAlert::where(function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id');
            });
            
            // Basic filters
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }
            
            if (!empty($filters['severity'])) {
                $query->where('severity', $filters['severity']);
            }

            // Date filters and search in separate methods
            $this->applyDateFilters($query, $filters);
            $this->applySearchFilter($query, $filters);
            
            // Sort and paginate
            $sortField = $filters['sort_field'] ?? 'created_at';
            $sortDir = $filters['sort_dir'] ?? 'desc';
            $query->orderBy($sortField, $sortDir);
            
            $page = $filters['page'] ?? 1;
            $perPage = $filters['per_page'] ?? 15;
            
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            
            return [
                'data' => $this->formatAlertResults($paginator->items()),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ];
        } catch (\Exception $e) {
            $this->logOperationError('getAlerts', 0, $tenantId, $e);
            return $this->getEmptyPaginatedResponse($filters);
        }
    }

    private function applyDateFilters($query, array $filters): void
    {
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from']));
        }
        
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to']));
        }
    }

    private function applySearchFilter($query, array $filters): void
    {
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
    }

    private function formatAlertResults(array $alerts): array
    {
        return array_map(function($alert) {
            return [
                'id' => $alert->id,
                'title' => $alert->title,
                'description' => $alert->description,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'source' => $alert->source,
                'created_at' => $alert->created_at->toIso8601String(),
                'acknowledged_at' => optional($alert->acknowledged_at)->toIso8601String(),
                'resolved_at' => optional($alert->resolved_at)->toIso8601String(),
                'tenant_id' => $alert->tenant_id,
            ];
        }, $alerts);
    }

    private function getEmptyPaginatedResponse(array $filters): array
    {
        return [
            'data' => [],
            'total' => 0,
            'per_page' => $filters['per_page'] ?? 15,
            'current_page' => $filters['page'] ?? 1,
            'last_page' => 1,
        ];
    }

    public function getAlert(int $alertId, int $tenantId): ?array
    {
        try {
            $alert = SecurityAlert::where('id', $alertId)
                ->where(function($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
                ->with(['responses.user'])
                ->first();
            
            if (!$alert) {
                $this->handleAlertNotFound($alertId, $tenantId);
                return null;
            }

            return $this->formatAlertDetail($alert);
        } catch (\Exception $e) {
            $this->logOperationError('getAlertDetail', $alertId, $tenantId, $e);
            return null;
        }
    }

    private function formatAlertDetail(SecurityAlert $alert): array
    {
        $tenant = $alert->tenant_id ? Tenant::find($alert->tenant_id) : null;
        $notes = $this->formatAlertNotes($alert->responses);

        return [
            'id' => $alert->id,
            'title' => $alert->title,
            'description' => $alert->description,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'source' => $alert->source,
            'context' => json_decode($alert->context, true),
            'created_at' => $alert->created_at->toIso8601String(),
            'acknowledged_at' => optional($alert->acknowledged_at)->toIso8601String(),
            'resolved_at' => optional($alert->resolved_at)->toIso8601String(),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name
            ] : null,
            'notes' => $notes
        ];
    }

    private function formatAlertNotes($responses): array
    {
        return $responses->map(function($response) {
            return [
                'id' => $response->id,
                'content' => $response->notes,
                'user' => $response->user ? [
                    'id' => $response->user->id,
                    'name' => $response->user->name
                ] : null,
                'created_at' => $response->created_at->toIso8601String()
            ];
        })->all();
    }

    public function acknowledgeAlert(int $alertId, int $tenantId, int $userId, ?string $notes = null): bool
    {
        try {
            return DB::transaction(function() use ($alertId, $tenantId, $userId, $notes) {
                $alert = SecurityAlert::where('id', $alertId)
                    ->where(function($q) use ($tenantId) {
                        $q->where('tenant_id', $tenantId)
                          ->orWhereNull('tenant_id');
                    })
                    ->lockForUpdate()
                    ->first();
                
                if (!$alert) {
                    $this->handleAlertNotFound($alertId, $tenantId);
                    return false;
                }
                
                if ($alert->status !== 'active') {
                    $this->handleInvalidStatus($alertId, $alert->status, 'acknowledge');
                    return false;
                }
                
                $alert->status = 'acknowledged';
                $alert->acknowledged_at = now();
                $alert->acknowledged_by = $userId;
                $alert->save();
                
                if ($notes) {
                    SecurityAlertResponse::create([
                        'alert_id' => $alertId,
                        'user_id' => $userId,
                        'response_type' => 'acknowledge',
                        'notes' => $notes
                    ]);
                }
                
                return true;
            });
        } catch (\Exception $e) {
            $this->logOperationError('acknowledgeAlert', $alertId, $tenantId, $e);
            return false;
        }
    }

    public function resolveAlert(int $alertId, int $tenantId, int $userId, string $status = 'resolved', ?string $notes = null): bool
    {
        try {
            return DB::transaction(function() use ($alertId, $tenantId, $userId, $status, $notes) {
                $alert = SecurityAlert::where('id', $alertId)
                    ->where(function($q) use ($tenantId) {
                        $q->where('tenant_id', $tenantId)
                          ->orWhereNull('tenant_id');
                    })
                    ->lockForUpdate()
                    ->first();
                
                if (!$alert) {
                    $this->handleAlertNotFound($alertId, $tenantId);
                    return false;
                }
                
                if ($alert->status === 'resolved') {
                    $this->handleInvalidStatus($alertId, $alert->status, 'resolve');
                    return false;
                }
                
                $alert->status = $status;
                $alert->resolved_at = now();
                $alert->resolved_by = $userId;
                $alert->save();
                
                SecurityAlertResponse::create([
                    'alert_id' => $alertId,
                    'user_id' => $userId,
                    'response_type' => 'resolve',
                    'notes' => $notes
                ]);
                
                return true;
            });
        } catch (\Exception $e) {
            $this->logOperationError('resolveAlert', $alertId, $tenantId, $e);
            return false;
        }
    }

    public function addAlertNotes(int $alertId, int $tenantId, int $userId, string $notes): bool
    {
        try {
            return DB::transaction(function() use ($alertId, $tenantId, $userId, $notes) {
                $alert = SecurityAlert::where('id', $alertId)
                    ->where(function($q) use ($tenantId) {
                        $q->where('tenant_id', $tenantId)
                          ->orWhereNull('tenant_id');
                    })
                    ->lockForUpdate()
                    ->first();
                
                if (!$alert) {
                    $this->handleAlertNotFound($alertId, $tenantId);
                    return false;
                }
                
                SecurityAlertResponse::create([
                    'alert_id' => $alertId,
                    'user_id' => $userId,
                    'response_type' => 'note',
                    'notes' => $notes
                ]);
                
                return true;
            });
        } catch (\Exception $e) {
            $this->logOperationError('addAlertNotes', $alertId, $tenantId, $e);
            return false;
        }
    }

    public function getAlertResponses(int $alertId): \Illuminate\Support\Collection
    {
        try {
            return SecurityAlertResponse::where('alert_id', $alertId)
                ->orderBy('created_at', 'asc')
                ->get();
        } catch (\Exception $e) {
            $this->logOperationError('getAlertResponses', $alertId, 0, $e);
            return collect([]);
        }
    }
}