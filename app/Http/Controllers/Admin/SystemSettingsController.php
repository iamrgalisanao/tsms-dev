<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Support\Settings;
use App\Models\AuditLog;

class SystemSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:admin');
    }

    public function edit()
    {
        $allowPrevious = Settings::get('allow_previous_day_transactions', false);
        return view('admin.settings.edit', [
            'allow_previous_day_transactions' => $allowPrevious,
        ]);
    }

    public function update(Request $request)
    {
        $value = $request->has('allow_previous_day_transactions') ? true : false;
        // Capture previous value for audit
        $old = Settings::get('allow_previous_day_transactions', false);

        $row = Settings::set('allow_previous_day_transactions', $value, 'boolean');

        // Record audit log entry for the change
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'system_setting.updated',
                'action_type' => 'SYSTEM',
                'resource_type' => 'system_setting',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => 'Toggled allow_previous_day_transactions',
                'old_values' => ['allow_previous_day_transactions' => $old],
                'new_values' => ['allow_previous_day_transactions' => $value],
                'metadata' => ['setting_row_id' => $row->id ?? null]
            ]);
        } catch (\Exception $e) {
            // Don't fail the update if audit logging fails; log warning
            logger()->warning('Failed to write audit log for system setting change', ['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('status', 'Settings updated.');
    }
}
