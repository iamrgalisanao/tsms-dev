<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\PayloadChecksumService;
use App\Services\PayloadSandboxValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChecksumSandboxController extends Controller
{
    public function compute(Request $request)
    {
        $request->validate([
            'payload' => 'required|array',
            'provided_checksum' => 'nullable|string|min:64|max:64',
        ]);

        $svc = new PayloadChecksumService();
        $provided = $request->filled('provided_checksum')
            ? (string) $request->string('provided_checksum')
            : null;

        $result = [
            'versions' => [
                'v2.1' => [
                    'computed_checksum' => $svc->computeChecksum($request->payload, 'v2.1'),
                    'canonical_payload' => $svc->getCanonicalized($request->payload, 'v2.1'),
                ],
                'v2.0' => [
                    'computed_checksum' => $svc->computeChecksum($request->payload, 'v2.0'),
                    'canonical_payload' => $svc->getCanonicalized($request->payload, 'v2.0'),
                ],
            ],
        ];

        if ($provided !== null) {
            $result['provided_checksum'] = $provided;
            foreach ($result['versions'] as $version => $data) {
                $result['versions'][$version]['matches'] = hash_equals(
                    strtolower($data['computed_checksum']),
                    strtolower($provided)
                );
            }
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function validatePayload(Request $request, PayloadSandboxValidationService $validator)
    {
        if (!$request->isJson()) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'validation_id' => 'val_' . (string) Str::ulid(),
                'errors' => [[
                    'code' => 'UNSUPPORTED_CONTENT_TYPE',
                    'severity' => 'error',
                    'pointer' => '/',
                    'message' => 'Content-Type must be application/json.',
                ]],
            ], 415);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'validation_id' => 'val_' . (string) Str::ulid(),
                'errors' => [[
                    'code' => 'INVALID_JSON',
                    'severity' => 'error',
                    'pointer' => '/',
                    'message' => 'Request body must be valid JSON object.',
                    'detail' => json_last_error_msg(),
                ]],
            ], 400);
        }

        $includeDebug = filter_var($request->query('include_debug', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json($validator->validate($payload, $includeDebug));
    }
}
