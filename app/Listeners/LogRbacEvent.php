<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\RbacAudit;

class LogRbacEvent
{
    /**
     * Handle the event.
     * Accepts any Spatie permission events and writes a normalized audit record.
     */
    public function handle($event): void
    {
        try {
            $eventClass = is_object($event) ? (new \ReflectionClass($event))->getShortName() : (string)$event;

            $modelType = null;
            $modelId = null;
            $targetType = null;
            $targetId = null;
            $targetName = null;

            // Spatie events commonly include properties like 'role', 'permission', and 'model'
            $vars = get_object_vars($event);

            if (isset($vars['model']) && is_object($vars['model'])) {
                $modelType = get_class($vars['model']);
                $modelId = $vars['model']->id ?? null;
            }

            if (isset($vars['role']) && is_object($vars['role'])) {
                $targetType = 'role';
                $targetId = $vars['role']->id ?? null;
                $targetName = $vars['role']->name ?? null;
            }

            if (isset($vars['permission']) && is_object($vars['permission'])) {
                $targetType = 'permission';
                $targetId = $vars['permission']->id ?? null;
                $targetName = $vars['permission']->name ?? null;
            }

            // actor = currently authenticated user if available
            $actorId = Auth::id();

            // request-related context (if available)
            $requestId = null;
            $actorIp = null;
            try {
                if (app()->bound('request')) {
                    $req = app('request');
                    $requestId = $req->header('X-Request-ID') ?: $req->header('x-request-id');
                    $actorIp = $req->ip();
                }
            } catch (\Throwable $_) {
                // ignore request inspection failures
            }

            // store remaining metadata
            $meta = $vars;
            // remove heavy objects we already normalized
            unset($meta['model'], $meta['role'], $meta['permission']);

            // include request context in meta (non-breaking)
            if ($requestId) {
                $meta['request_id'] = $requestId;
            }
            if ($actorIp) {
                $meta['request_ip'] = $actorIp;
            }

            RbacAudit::create([
                'event_type' => $eventClass,
                'model_type' => $modelType,
                'model_id' => $modelId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_name' => $targetName,
                'actor_id' => $actorId,
                'meta' => $meta,
            ]);
        } catch (\Throwable $ex) {
            // Never let audit failures bubble; log for ops.
            Log::warning('Failed to write RBAC audit: ' . $ex->getMessage());
        }
    }
}
