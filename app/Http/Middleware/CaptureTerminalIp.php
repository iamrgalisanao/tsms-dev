<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Schema;
use App\Models\PosTerminal;

class CaptureTerminalIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only proceed for authenticated terminal requests
        $user = $request->user();
        if ($user instanceof PosTerminal) {
            try {
                // Guard on schema presence
                $hasIp = Schema::hasColumn('pos_terminals', 'ip_address');
                $hasLastIpAt = Schema::hasColumn('pos_terminals', 'last_ip_at');
                if ($hasIp) {
                    $ip = $request->ip();
                    // Persist if changed or empty; avoid excessive writes
                    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $shouldUpdate = false;
                        if (empty($user->ip_address) || $user->ip_address !== $ip) {
                            $shouldUpdate = true;
                        }
                        // Optional: rate-limit updates to once every 5 minutes
                        if ($shouldUpdate && $hasLastIpAt) {
                            $lastAt = $user->last_ip_at;
                            if ($lastAt && now()->diffInMinutes($lastAt) < 5 && $user->ip_address === $ip) {
                                $shouldUpdate = false;
                            }
                        }
                        if ($shouldUpdate) {
                            $user->ip_address = $ip;
                            if ($hasLastIpAt) {
                                $user->last_ip_at = now();
                            }
                            // Use saveQuietly to avoid triggering observers/logging
                            method_exists($user, 'saveQuietly') ? $user->saveQuietly() : $user->save();
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Best-effort; do not break the request on IP capture error
                // Optionally, could log with a low severity if needed
            }
        }

        return $response;
    }
}
