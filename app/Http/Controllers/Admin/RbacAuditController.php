<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RbacAudit;

class RbacAuditController extends Controller
{
    public function index(Request $request)
    {
        $audits = RbacAudit::orderBy('created_at', 'desc')->paginate(25);
        return view('admin.rbac_audits.index', compact('audits'));
    }
}
