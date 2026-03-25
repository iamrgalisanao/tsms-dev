<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TransactionPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(mixed $user, Transaction $transaction): bool
    {
        // If it's a POS Terminal, it can only view its own transactions
        if ($user instanceof PosTerminal) {
            return (int) $transaction->terminal_id === (int) $user->id;
        }

        // If it's a User, TenantScope already restricts visibility to their own tenant
        return $user instanceof User;
    }

    /**
     * Determine whether the user can update the model (Refund/Void).
     */
    public function update(mixed $requestingEntity, Transaction $transaction): bool
    {
        // Refunds/Voids from POS must belong to the terminal
        if ($requestingEntity instanceof PosTerminal) {
            return (int) $transaction->terminal_id === (int) $requestingEntity->id;
        }

        // Standard users (Admins) can update transactions within their tenant scope
        return $requestingEntity instanceof User;
    }
}
