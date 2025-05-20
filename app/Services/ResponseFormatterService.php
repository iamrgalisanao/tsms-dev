<?php

namespace App\Services;

class ResponseFormatterService
{
    public function success($data, $message = '')
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], 200);
    }

    public function error($message, $errors = [])
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], 422);
    }

    public function status($transaction)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $transaction->id,
                'status' => $transaction->status,
                'completed_at' => $transaction->completed_at,
                'attempts' => $transaction->attempts,
                'error' => $transaction->error
            ]
        ]);
    }
}