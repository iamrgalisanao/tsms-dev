<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Support\Settings;

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
        Settings::set('allow_previous_day_transactions', $value, 'boolean');

        return redirect()->back()->with('status', 'Settings updated.');
    }
}
