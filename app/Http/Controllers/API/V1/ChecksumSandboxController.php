<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\PayloadChecksumService;
use Illuminate\Http\Request;

class ChecksumSandboxController extends Controller
{
    public function compute(Request $request)
    {
        $request->validate([
            'payload' => 'required|array',
            'provided_checksum' => 'nullable|string|min:64|max:64',
        ]);

        $svc = new PayloadChecksumService();
        $canonical = $svc->getCanonicalized($request->payload);
        $computed = $svc->computeChecksum($request->payload);

        $result = [
            'computed_checksum' => $computed,
            'canonical_payload' => $canonical,
        ];

        if ($request->filled('provided_checksum')) {
            $result['matches'] = hash_equals($computed, $request->string('provided_checksum'));
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
