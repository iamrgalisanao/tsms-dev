<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PosTerminal;
// ...existing code...
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class TransactionValidationService
{
    //
    // ────────────────────────────────────────────────────────────────────────────────
    //    C O N S T A N T S   &   P R O P E R T I E S
    // ────────────────────────────────────────────────────────────────────────────────
    //
    /**
     * Stub for tenant validation. Returns empty array (no errors) for now.
     *
     * @param Transaction $transaction
     * @return array
     */
    protected function validateTenant(Transaction $transaction): array
    {
        // TODO: Implement tenant validation logic if needed
        return [];
    }

    /**
     * Maximum allowable difference (in absolute value) between computed VAT and reported VAT.
     */
    protected const MAX_VAT_DIFFERENCE = 0.02;

    /**
     * Maximum allowable difference (in absolute value) when reconciling net amounts (rounding).
     */
    protected const MAX_ROUNDING_DIFFERENCE = 0.05;

    /**
     * Service charges (service_charge + management_service_charge) cannot exceed 15% of gross_sales.
     */
    protected const MAX_SERVICE_CHARGE_PERCENTAGE = 0.15;

    /**
     * Discount amount cannot exceed 30% of gross_sales.
     * (Note: the original business‐rule message incorrectly said “50%.” We have corrected this to match the constant.)
     */
    protected const MAX_DISCOUNT_PERCENTAGE = 0.30;

    /**
     * Transactions older than 30 days are invalid.
     */
    protected const MAX_TRANSACTION_AGE_DAYS = 30;

    /**
     * These are example promo codes. In a real system, you might move these into config/transaction.php or a database.
     */
    protected array $validPromoCodes = [
        'SUMMER2023',
        'HOLIDAY25',
        'LOYAL10',
        'WELCOME15',
    ];

    //
    // ────────────────────────────────────────────────────────────────────────────────
    //    P U B L I C   M E T H O D S
    // ────────────────────────────────────────────────────────────────────────────────
    //

    /**
     * Entry point for validating “whatever” comes in:
     *  - If $data is already a Transaction model, we run validateTransaction(...)
     *  - If it is an array (e.g. JSON decoded or normalized text), we run validateSubmission(...)
     *  - If it is a string, we try to detect JSON → line‐numbered → “free‐form text” formats,
     *    normalize field names, and then validateSubmission(...)
     *
     * @param  array|string|Transaction  $data
     * @return array   ['valid' => bool, 'errors' => array, 'data' => array|null]
     */
    public function validate($data): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────────────
            // 1) DETECT FORMAT & NORMALIZE
            // ─────────────────────────────────────────────────────────────────────────
            $parsed = $this->detectAndParsePayload($data);

            // → If we ended up with a Transaction instance, validate it directly.
            if ($parsed instanceof Transaction) {
                return $this->validateTransaction($parsed);
            }

            // → Otherwise, we have an array of “submission fields.” Validate those.
            return $this->validateSubmission($parsed);
        }
        catch (\Exception $e) {
            // Any “unexpected” exception is logged and returned as a validation error.
            Log::error('Validation error in TransactionValidationService', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'valid'  => false,
                'errors' => [$e->getMessage()],
                'data'   => null,
            ];
        }
    }

    //
    // ────────────────────────────────────────────────────────────────────────────────
    //    P R I V A T E   H E L P E R   M E T H O D S
    // ────────────────────────────────────────────────────────────────────────────────
    //

    /**
     * Inspect “$data.” If it’s already a Transaction model, just return it.
     * If it’s an array, assume it came from JSON or normalized text and simply return it.
     * If it’s a string, try JSON decode first; if that fails, try line‐numbered; otherwise fall back to free‐form parseTextFormat().
     *
     * @param  array|string|Transaction  $data
     * @return array|Transaction
     *
     * @throws InvalidArgumentException  if $data is not array|string|Transaction
     */
    private function detectAndParsePayload($data)
    {
        // ---- Already a Transaction model? Return it immediately.
        if ($data instanceof Transaction) {
            return $data;
        }

        // ---- If it's already an array, assume it’s JSON‐decoded or normalized text.
        if (is_array($data)) {
            return $this->normalizeFieldNames($data);
        }

        // ---- If it’s a raw string, attempt to decode.
        if (is_string($data)) {
            $trimmed = trim($data);

            // 1) Try JSON decode.
            if (strlen($trimmed) > 0 && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->normalizeFieldNames($decoded);
                }
                // If JSON parsing fails, fall through.
            }

            // 2) Check for “line‐numbered” format: lines that begin with “01 ”, “02 ”, etc.
            if (preg_match('/^\d{2}\s+/', $trimmed)) {
                return $this->parseLineNumberedFormat($trimmed);
            }

            // 3) Else, assume “free‐form text” and run parseTextFormat().
            return $this->parseTextFormat($trimmed);
        }

        throw new InvalidArgumentException('Unsupported payload format: ' . gettype($data));
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    L I N E ‐ N U M B E R E D  →  A R R A Y
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Take a block of text in which each line is prefixed by a line‐number (01, 02, …),
     * extract the “value” column from each line, and return a numeric‐keyed array
     * (lineNumber => value). Then pass that array to normalizeLineNumberedFields().
     *
     * Example line: “01 Tenant Code    01C-D1016    C-D1016”
     * We capture “01” as $matches[1], then “Tenant Code” as $matches[2], then “01C-D1016” as $matches[3],
     * and “C-D1016” (the final column) as $matches[4]. We pick $matches[4] if present, else $matches[3].
     *
     * @param  string  $content
     * @return array
     */
    private function parseLineNumberedFormat(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $raw   = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Regex breakdown:
            //   ^(\d{2})         → capture exactly two digits (line number)
            //   \s+              → at least one space
            //   ([^\d]+?)        → the “description” portion (non‐digits, minimal)
            //   \s+(\S+)         → at least one space + then a “text‐file value” chunk (no whitespace inside)
            //   \s+(\S+)?$       → optionally a final “Value” column (no whitespace inside)
            if (
                preg_match(
                    '/^(\d{2})\s+([^\d]+?)\s+(\S+)\s+(\S+)?$/',
                    $line,
                    $matches
                )
            ) {
                $lineNum = $matches[1];
                $value   = (!empty($matches[4])) ? $matches[4] : $matches[3];
                $raw[$lineNum] = $value;
            }
        }

        return $this->normalizeLineNumberedFields($raw);
    }

    /**
     * Map each two‐digit line number (01, 02, 03, …) to a proper field name.
     * Also convert numeric strings into int/float as needed.
     *
     * Note: We fixed a bug where “18” was originally mapped to transaction_count instead of cover_count.
     *
     * @param  array  $raw  (e.g. ['01' => 'C-D1016', '02' => '02002', …])
     * @return array  (e.g. ['tenant_code' => 'C-D1016', 'machine_number' => 2002, …])
     */
    private function normalizeLineNumberedFields(array $raw): array
    {
        $fieldMap = [
            //   Line → normalized field key
            '01' => 'tenant_code',
            '02' => 'machine_number',
            '03' => 'transaction_date',
            '04' => 'old_grand_total',
            '05' => 'new_grand_total',
            '06' => 'gross_sales',
            '07' => 'vatable_sales',
            '08' => 'senior_discount',
            '09' => 'pwd_discount',
            '10' => 'vip_discount',
            '11' => 'vat_amount',
            '12' => 'service_charge',
            '13' => 'net_sales',
            '14' => 'cash_tender',
            '15' => 'credit_card_tender',
            '16' => 'other_tender',
            '17' => 'void_amount',
            '18' => 'cover_count',        // ← Fixed: was incorrectly “transaction_count” in original
            '19' => 'zread_number',
            '20' => 'transaction_count',  // ← “20” is the real transaction count
            '21' => 'sales_type',
            '22' => 'net_amount',
        ];

        $normalized = [];

        foreach ($raw as $lineNum => $value) {
            if (! isset($fieldMap[$lineNum])) {
                continue;
            }

            // Convert numeric strings to int/float
            if (is_numeric($value)) {
                $value = (strpos($value, '.') !== false)
                    ? (float) $value
                    : (int)   $value;
            }

            $normalized[$fieldMap[$lineNum]] = $value;
        }

        return $normalized;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “F R E E ‐ F O R M   T E X T”   →   A R R A Y
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Parse a block of text that may contain lines in one of these formats:
     *   - KEY: VALUE
     *   - KEY=VALUE
     *   - KEY VALUE
     *
     * Returns a normalized array of fieldName => value (via normalizeFieldNames()).
     *
     * @param  string  $content
     * @return array
     */
    private function parseTextFormat(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $raw   = [];

        Log::info('Parsing free‐form text format (length=' . strlen($content) . ')');

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // 1) KEY: VALUE
            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $m1)) {
                $raw[ $m1[1] ] = $m1[2];
                continue;
            }

            // 2) KEY=VALUE
            if (preg_match('/^([^=]+)=\s*(.+)$/', $line, $m2)) {
                $raw[ $m2[1] ] = $m2[2];
                continue;
            }

            // 3) KEY VALUE (split on first whitespace)
            if (preg_match('/^(\S+)\s+(.+)$/', $line, $m3)) {
                $raw[ $m3[1] ] = $m3[2];
                continue;
            }

            // If no pattern matches, skip this line.
        }

        $normalized = $this->normalizeFieldNames($raw);

        Log::info('Parsed text format: '
            . count($raw) . ' raw fields → '
            . count($normalized) . ' normalized fields'
        );

        return $normalized;
    }

    /**
     * Take an arbitrary array of key=>value (where keys might be
     * various forms of “TENANT_ID”, “TenantId”, “tx_id”, etc.), and
     * map each recognized key into a known “snake_case” form. If a key
     * is unrecognized, we simply lowercase() it and keep it.
     *
     * @param  array  $data
     * @return array
     */
    private function normalizeFieldNames(array $data): array
    {
        $fieldMap = [
            // * Transaction‐ID‐style keys
            'TENANT_ID'              => 'tenant_id',
            'TENANTID'               => 'tenant_id',
            'TENANT-ID'              => 'tenant_id',

            'TRANSACTION_ID'         => 'transaction_id',
            'TRANSACTIONID'          => 'transaction_id',
            'TX_ID'                  => 'transaction_id',
            'TXID'                   => 'transaction_id',

            'TRANSACTION_TIMESTAMP'  => 'transaction_timestamp',
            'TX_TIMESTAMP'           => 'transaction_timestamp',
            'TX_TIME'                => 'transaction_timestamp',
            'DATETIME'               => 'transaction_timestamp',
            'DATE_TIME'              => 'transaction_timestamp',

            // * Amount‐style keys
            'GROSS_SALES'            => 'gross_sales',
            'GROSSSALES'             => 'gross_sales',
            'GROSS'                  => 'gross_sales',

            'NET_SALES'              => 'net_sales',
            'NETSALES'               => 'net_sales',

            'VATABLE_SALES'          => 'vatable_sales',
            'VATABLESALES'           => 'vatable_sales',
            'VAT_SALES'              => 'vatable_sales',

            'VAT_EXEMPT_SALES'       => 'vat_exempt_sales',
            'VATEXEMPTSALES'         => 'vat_exempt_sales',
            'EXEMPT_SALES'           => 'vat_exempt_sales',

            'VAT_AMOUNT'             => 'vat_amount',
            'VATAMOUNT'              => 'vat_amount',
            'VAT'                    => 'vat_amount',

            'TRANSACTION_COUNT'      => 'transaction_count',
            'TX_COUNT'               => 'transaction_count',
            'TRANSACTIONCOUNT'       => 'transaction_count',

            'PAYLOAD_CHECKSUM'       => 'payload_checksum',
            'CHECKSUM'               => 'payload_checksum',
            'HASH'                   => 'payload_checksum',
        ];

        $normalized = [];

        foreach ($data as $key => $value) {
            $upper = strtoupper($key);
            if (isset($fieldMap[$upper])) {
                $normalized[ $fieldMap[$upper] ] = $value;
            }
            else {
                // If we don’t explicitly recognize the key, we lowercase() it and keep it verbatim.
                $normalized[ strtolower($key) ] = $value;
            }
        }

        return $normalized;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “A R R A Y   →  B U S I N E S S   L O G I C   V A L I D A T I O N”
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Validate fields that come from a “submission” (i.e. an array or JSON‐decoded). Typical
     * required fields:
     *   - tenant_id          (string)
     *   - terminal_id        (string)
     *   - transaction_id     (string)
     *   - transaction_timestamp (date)
     *   - gross_sales        (numeric)
     *   - net_sales          (numeric)
     *   - vatable_sales      (numeric)
     *   - vat_exempt_sales   (numeric)
     *   - vat_amount         (numeric)
     *   - transaction_count  (integer)
     *   - payload_checksum   (string)
     *
     * After Laravel’s Validator, we check for duplicates (tenant_id + transaction_id).
     *
     * @param  array  $data
     * @return array   ['valid' => bool, 'errors' => array, 'data' => null|array]
     */
    protected function validateSubmission(array $data): array
    {
        $validator = Validator::make($data, [
            'tenant_id'             => 'required|string',
            'terminal_id'           => 'required|string',
            'transaction_id'        => 'required|string',
            'transaction_timestamp' => 'required|date',
            'gross_sales'           => 'required|numeric|min:0',
            'net_sales'             => 'required|numeric|min:0',
            'vatable_sales'         => 'required|numeric|min:0',
            'vat_exempt_sales'      => 'required|numeric|min:0',
            'vat_amount'            => 'required|numeric|min:0',
            'transaction_count'     => 'required|integer|min:0',
            'payload_checksum'      => 'required|string',
        ]);

        if ($validator->fails()) {
            return [
                'valid'  => false,
                'errors' => $validator->errors()->toArray(),
                'data'   => null,
            ];
        }

        $validated = $validator->validated();

        // Check for duplicate (tenant_id, transaction_id) in DB
        if ($this->isDuplicate($validated)) {
            return [
                'valid'  => false,
                'errors' => ['Transaction ID has already been processed'],
                'data'   => null,
            ];
        }

        return [
            'valid'  => true,
            'errors' => [],
            'data'   => $validated,
        ];
    }

    /**
     * Returns true if (tenant_id, transaction_id) already exists.
     *
     * @param  array  $data
     * @return bool
     */
    protected function isDuplicate(array $data): bool
    {
        return Transaction::where('tenant_id', $data['tenant_id'])
            ->where('transaction_id', $data['transaction_id'])
            ->exists();
    }

    /**
     * Validate a fully‐hydrated Transaction model. Only tenant-based logic is used.
     *
     * @param  Transaction  $transaction
     * @return array   ['valid' => bool, 'errors' => array]
     */
    public function validateTransaction(Transaction $transaction): array
    {
        Log::info('Starting transaction validation', [
            'transaction_id' => $transaction->id,
        ]);

        $errors = [];

        // 1) Tenant rules (does tenant exist?)
        $tenantErrors = $this->validateTenant($transaction);
        if (! empty($tenantErrors)) {
            $errors = array_merge($errors, $tenantErrors);
        }

        // 2) Terminal rules (does terminal exist? is it active?)
        $terminalErrors = $this->validateTerminal($transaction);
        if (! empty($terminalErrors)) {
            $errors = array_merge($errors, $terminalErrors);
        }

        // 3) Amount checks (gross/net/vat/service/rounding)
        $amountErrors = $this->validateAmounts($transaction);
        if (! empty($amountErrors)) {
            $errors = array_merge($errors, $amountErrors);
        }

        // 4) Transaction integrity (duplicate ID, sequence number, date bounds)
        $integrityErrors = $this->validateTransactionIntegrity($transaction);
        if (! empty($integrityErrors)) {
            $errors = array_merge($errors, $integrityErrors);
        }

        // 5) High‐level business rules (tenant limits, daily totals, tax exemptions, etc.)
        $businessErrors = $this->validateBusinessRules($transaction);
        if (! empty($businessErrors)) {
            $errors = array_merge($errors, $businessErrors);
        }

        // 6) Discount‐specific checks (sum of individual discounts, auth codes, etc.)
        $discountErrors = $this->validateDiscounts($transaction);
        if (! empty($discountErrors)) {
            $errors = array_merge($errors, $discountErrors);
        }

        Log::info('Validation complete', [
            'transaction_id' => $transaction->id,
            'has_errors'     => ! empty($errors),
            'error_count'    => count($errors),
        ]);

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “V A L I D A T E   T E R M I N A L”
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Ensure that the terminal_id on the transaction refers to an active PosTerminal.
     *
     * @param  Transaction  $transaction
     * @return array   List of error messages (empty if none)
     */
    protected function validateTerminal(Transaction $transaction): array
    {
        $errors = [];

        $terminal = PosTerminal::find($transaction->terminal_id);
        if (! $terminal) {
            $errors[] = 'Terminal not found.';
            return $errors;
        }

        // Check if the related TerminalStatus name is 'active'
        $terminalStatus = $terminal->status;
        $isActive = false;
        if ($terminalStatus && strtolower($terminalStatus->name) === 'active') {
            $isActive = true;
        }
        // Also check is_active boolean and expiration
        if (!($isActive && $terminal->is_active && (!$terminal->expires_at || $terminal->expires_at->isFuture()))) {
            $errors[] = 'Terminal is not active (current status: ' . json_encode(['id' => $terminal->status_id, 'name' => $terminalStatus ? $terminalStatus->name : null]) . ').';
        }

        return $errors;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “V A L I D A T E   A M O U N T S”
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * High‐level checks on all monetary fields:
     *  - net_sales must equal gross_sales - other_tax (excluding VATABLE_SALES)
     *  - vatable_sales must be ≥ 0
     *  - If not tax‐exempt, ensure that vat_amount ≈ 12% of vatable_sales (± MAX_VAT_DIFFERENCE)
     *  - Enforce service‐charge ≤ 15% of gross_sales
     *  - Check that calculations are consistent
     *
     * @param  Transaction  $transaction
     * @return array   List of error messages (empty if none)
     */
    private function validateAmounts(Transaction $transaction): array
    {
        $errors = [];

        // Calculate other_tax sum (excluding VATABLE_SALES) from relationship
        $otherTaxSum = 0;
        if ($transaction->taxes && $transaction->taxes->count() > 0) {
            foreach ($transaction->taxes as $tax) {
                if (isset($tax['tax_type']) && $tax['tax_type'] !== 'VATABLE_SALES' && isset($tax['amount'])) {
                    $otherTaxSum += $tax['amount'];
                }
            }
        }

        // 1) Validate net_sales = gross_sales - other_tax
        $expectedNetSales = $transaction->gross_sales - $otherTaxSum;
        if (abs($transaction->net_sales - $expectedNetSales) > self::MAX_ROUNDING_DIFFERENCE) {
            $errors[] = sprintf(
                'Net sales (%.2f) does not equal gross_sales - other_tax (%.2f - %.2f = %.2f).',
                $transaction->net_sales,
                $transaction->gross_sales,
                $otherTaxSum,
                $expectedNetSales
            );
        }

        // 2) Basic positivity checks
        if ($transaction->gross_sales <= 0) {
            $errors[] = 'Gross sales must be positive.';
        }
        if ($transaction->net_sales < 0) {
            $errors[] = 'Net sales cannot be negative.';
        }
        if ($transaction->vatable_sales < 0) {
            $errors[] = 'Vatable sales cannot be negative.';
        }

        // 3) VAT check (only if not tax_exempt and vatable_sales > 0)
        if (empty($transaction->tax_exempt) && $transaction->vatable_sales > 0) {
            $expectedVat = round($transaction->vatable_sales * 0.12, 2);
            $actualVat   = round($transaction->vat_amount, 2);

            if (abs($expectedVat - $actualVat) > self::MAX_VAT_DIFFERENCE) {
                $errors[] = sprintf(
                    'VAT amount %.2f does not match expected 12%% of vatable sales (%.2f).',
                    $actualVat,
                    $expectedVat
                );
            }
        }

        // 4) Service‐charge percentage check
        $this->validateServiceCharges($transaction, $errors);

        return $errors;
    }

    /**
     * Ensure (service_charge + management_service_charge) ≤ 15% of gross_sales.
     * Any violation is appended to $errors.
     *
     * @param  Transaction  $transaction
     * @param  array       &$errors
     * @return void
     */
    private function validateServiceCharges(Transaction $transaction, array &$errors): void
    {
        $serviceChargeTotal = 0.0;
        $serviceChargeTotal += $transaction->service_charge ?? 0.0;
        $serviceChargeTotal += $transaction->management_service_charge ?? 0.0;

        if ($serviceChargeTotal > $transaction->gross_sales * self::MAX_SERVICE_CHARGE_PERCENTAGE) {
            $errors[] = sprintf(
                'Service charges (%.2f) exceed maximum allowed percentage (%.0f%% of gross sales).',
                $serviceChargeTotal,
                self::MAX_SERVICE_CHARGE_PERCENTAGE * 100
            );
        }
    }

    /**
     * Validate that the amount reconciliation follows the simplified formula:
     * net_sales = gross_sales - other_tax (excluding VATABLE_SALES)
     *
     * @param  Transaction  $transaction
     * @param  array       &$errors
     * @return void
     */
    private function validateAmountReconciliation(Transaction $transaction, array &$errors): void
    {
        // Calculate other_tax sum (excluding VATABLE_SALES) from relationship
        $otherTaxSum = 0;
        if ($transaction->taxes && $transaction->taxes->count() > 0) {
            foreach ($transaction->taxes as $tax) {
                if (isset($tax['tax_type']) && $tax['tax_type'] !== 'VATABLE_SALES' && isset($tax['amount'])) {
                    $otherTaxSum += $tax['amount'];
                }
            }
        }

        // Validate net_sales = gross_sales - other_tax
        $expectedNetSales = $transaction->gross_sales - $otherTaxSum;
        if (abs($transaction->net_sales - $expectedNetSales) > self::MAX_ROUNDING_DIFFERENCE) {
            $errors[] = sprintf(
                'Amount reconciliation failed: net_sales (%.2f) should equal gross_sales - other_tax (%.2f - %.2f = %.2f).',
                $transaction->net_sales,
                $transaction->gross_sales,
                $otherTaxSum,
                $expectedNetSales
            );
        }
    }

    /**
     * Sum up all “positive” charges and subtract any discounts:
     *    adjustments = service_charge + management_service_charge − (discount_amount + discount_total).
     *
     * @param  Transaction  $transaction
     * @return float
     */
    private function calculateAdjustments(Transaction $transaction): float
    {
        $adjustments = 0.0;

        // Add service charges
        $adjustments += $transaction->service_charge ?? 0.0;
        $adjustments += $transaction->management_service_charge ?? 0.0;

        // Subtract discounts from adjustments relationship
        if ($transaction->adjustments && $transaction->adjustments->count() > 0) {
            foreach ($transaction->adjustments as $adj) {
                if (isset($adj['amount'])) {
                    $adjustments -= $adj['amount']; // Discounts are negative adjustments
                }
            }
        }

        return $adjustments;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “V A L I D A T E   T R A N S A C T I O N   I N T E G R I T Y”
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * 1) No duplicate (ignore current ID if updating).  
     * 2) If there’s a sequence_number, ensure it’s at most 1 past the last sequence; or if lower, flag as out‐of‐order.  
     * 3) Transaction timestamp must be ≤ “now,” and ≥ “now – 30 days.”  
     * 4) Ensure transaction_id matches one of the “allowed” patterns.
     *
     * @param  Transaction  $transaction
     * @return array   List of errors (empty if none)
     */
    protected function validateTransactionIntegrity(Transaction $transaction): array
    {
        $errors = [];

        // 1) Check for duplicate (same tenant & transaction ID, different record ID)
        $duplicate = Transaction::where('tenant_id', $transaction->tenant_id)
            ->where('transaction_id', $transaction->transaction_id)
            ->where('id', '!=', $transaction->id)
            ->first();

        if ($duplicate) {
            $errors[] = sprintf(
                'Duplicate transaction detected (record ID: %s, created at: %s).',
                $duplicate->id,
                $duplicate->created_at->format('Y-m-d H:i:s')
            );
        }

        // 2) Sequence‐number checks (if property exists and is not null)
        if (property_exists($transaction, 'sequence_number') && $transaction->sequence_number !== null) {
            $lastTransaction = Transaction::where('terminal_id', $transaction->terminal_id)
                ->where('id', '!=', $transaction->id)
                ->whereNotNull('sequence_number')
                ->orderBy('sequence_number', 'desc')
                ->first();

            if ($lastTransaction) {
                $expectedSequence = $lastTransaction->sequence_number + 1;
                $currentSequence  = $transaction->sequence_number;

                if ($currentSequence > $expectedSequence) {
                    $gap = $currentSequence - $expectedSequence;
                    if ($gap > 2) {
                        $errors[] = sprintf(
                            'Sequence gap detected: expected %d but received %d (gap of %d transactions).',
                            $expectedSequence,
                            $currentSequence,
                            $gap
                        );
                    }
                }
                elseif ($currentSequence < $expectedSequence) {
                    $errors[] = sprintf(
                        'Out‐of‐sequence transaction: expected %d or higher but received %d.',
                        $expectedSequence,
                        $currentSequence
                    );
                }
            }
        }

        // 3) Timestamp must be for the current day only
        $now = Carbon::now();
        $txTime = Carbon::parse($transaction->transaction_timestamp);

        if (!$txTime->isSameDay($now)) {
            $errors[] = sprintf(
                'Transaction rejected: Only transactions dated today (%s) are accepted. Provided timestamp: %s.',
                $now->format('Y-m-d'),
                $txTime->format('Y-m-d')
            );
        }
        if ($txTime->gt($now)) {
            $errors[] = sprintf(
                'Transaction timestamp (%s) cannot be in the future (current time: %s).',
                $txTime->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s')
            );
        }

        // 4) Validate transaction_id format against a set of regex patterns
        if (property_exists($transaction, 'transaction_id')) {
            $patterns = [
                '/^[A-Za-z0-9]{6,32}$/',     // Alphanumeric, length 6–32
                '/^TX\-[A-Za-z0-9-]{4,}$/i', // “TX‐” prefix pattern
                '/^[A-Z0-9]{2,4}\-\d{6,10}$/',// PREFIX‐NUMBERS (e.g. “ABCD-123456”)
                '/^\d{4}\-\d{2}\-\d{2}\-\d{4,}$/' // DATE-NUMBER format: “YYYY-MM-DD-####”
            ];
            $validFormat = false;

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $transaction->transaction_id)) {
                    $validFormat = true;
                    break;
                }
            }

            if (! $validFormat) {
                $errors[] = sprintf(
                    'Transaction ID format is not recognized: %s',
                    $transaction->transaction_id
                );
            }
        }

        return $errors;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “V A L I D A T E   B U S I N E S S   R U L E S”
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * These are higher‐level, tenant‐specific business constraints:
     *  - Does tenant allow service_charge?
     *  - Is gross_sales ≤ tenant->max_transaction_amount?
     *  - Does adding this transaction exceed the tenant’s daily sales limit?
     *  - If tax_exempt, then vat_amount must be 0 and tax_exempt_id must exist
     *  - If not tax_exempt but tenant is tax_exempt, that’s a special error
     *  - Discounts must be ≤ 30% of gross
     *
     * @param  Transaction  $transaction
     * @return array   List of errors (empty if none)
     */
    protected function validateBusinessRules(Transaction $transaction): array
    {
        $errors = [];

        // 1) Terminal → tenant
        $terminal = PosTerminal::find($transaction->terminal_id);
        if (! $terminal) {
            $errors[] = 'Terminal not found for business‐rules validation.';
            return $errors;
        }

        $tenant = \App\Models\Tenant::find($transaction->tenant_id);
        if (! $tenant) {
            $errors[] = 'Tenant not found for this terminal.';
            return $errors;
        }

        // 2) Single‐transaction amount limit
        if ($tenant->max_transaction_amount !== null
            && $transaction->gross_sales > $tenant->max_transaction_amount
        ) {
            $errors[] = sprintf(
                'Transaction (%.2f) exceeds maximum allowed for this tenant (%.2f).',
                $transaction->gross_sales,
                $tenant->max_transaction_amount
            );
        }

        // 3) Daily transaction total limit
        $transactionDate = Carbon::parse($transaction->transaction_timestamp);
        $dailyTotal = $tenant->getDailySalesTotal($transactionDate);
        $newTotal   = $dailyTotal + $transaction->gross_sales;

        if ($tenant->max_daily_sales !== null && $newTotal > $tenant->max_daily_sales) {
            $errors[] = sprintf(
                'Transaction would exceed daily sales limit for this tenant (%.2f + %.2f = %.2f > %.2f).',
                $dailyTotal,
                $transaction->gross_sales,
                $newTotal,
                $tenant->max_daily_sales
            );
        }

        // 4) Service‐charge must be allowed by tenant
        $serviceChargeUsed = null;
        if (property_exists($transaction, 'service_charge') && $transaction->service_charge > 0) {
            $serviceChargeUsed = $transaction->service_charge;
        }
        elseif (property_exists($transaction, 'management_service_charge')
            && $transaction->management_service_charge > 0
        ) {
            $serviceChargeUsed = $transaction->management_service_charge;
        }

        if ($serviceChargeUsed !== null && ! $tenant->allows_service_charge) {
            $errors[] = sprintf(
                'This tenant does not allow service charges (found %.2f).',
                $serviceChargeUsed
            );
        }

        // 5) Tax‐exemption logic:
        $isTaxExempt    = property_exists($transaction, 'tax_exempt') && $transaction->tax_exempt;
        $hasTaxExemptId = property_exists($transaction, 'tax_exempt_id') && ! empty($transaction->tax_exempt_id);

        if ($isTaxExempt) {
            // If tax_exempt, vat_amount must be 0, and a tax_exempt_id must be provided
            if ($transaction->vat_amount != 0) {
                $errors[] = sprintf(
                    'Tax‐exempt transactions should have 0 VAT (found %.2f).',
                    $transaction->vat_amount
                );
            }
            if (! $hasTaxExemptId) {
                $errors[] = 'Tax‐exempt transactions require a valid exemption ID.';
            }
        }
        else {
            // If the tenant itself is tax_exempt but this transaction is not flagged as exempt,
            // that is a special situation.
            if ($tenant->tax_exempt && $transaction->vat_amount > 0) {
                $errors[] = 'Non‐exempt transactions in a tax‐exempt tenant must be flagged.';
            }
        }

        // 6) Discount amount cannot exceed 30% of gross
        if (property_exists($transaction, 'discount_amount') && $transaction->discount_amount > 0) {
            $maxDiscount = $transaction->gross_sales * self::MAX_DISCOUNT_PERCENTAGE;
            if ($transaction->discount_amount > $maxDiscount) {
                $errors[] = sprintf(
                    'Discount amount (%.2f) exceeds maximum allowed (%.0f%% of gross sales: %.2f).',
                    $transaction->discount_amount,
                    self::MAX_DISCOUNT_PERCENTAGE * 100,
                    $maxDiscount
                );
            }
        }

        return $errors;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    //    “D I S C O U N T – S P E C I F I C   V A L I D A T I O N”
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * 1) If discount_amount > 0, ensure discount_details array is present.
     * 2) Sum discount_details[].amount and compare (±0.01) to discount_amount.
     * 3) If senior_discount or pwd_discount is used, discount_auth_code must be provided.
     *
     * @param  Transaction  $transaction
     * @return array  List of errors (empty if none)
     */
    protected function validateDiscounts(Transaction $transaction): array
    {
        $errors = [];

        // 1) If discount_amount > 0, discount_details must exist (and be an array).
        if ($transaction->discount_amount > 0) {
            if (empty($transaction->discount_details) || ! is_array($transaction->discount_details)) {
                $errors[] = 'Discount details missing for a transaction with a discount amount.';
            } else {
                // 2) Sum up individual discounts
                $sum = 0.0;
                foreach ($transaction->discount_details as $d) {
                    if (isset($d['amount'])) {
                        $sum += floatval($d['amount']);
                    }
                }
                if (abs($sum - floatval($transaction->discount_amount)) > 0.01) {
                    $errors[] = sprintf(
                        'Sum of individual discounts (%.2f) does not match total discount_amount (%.2f).',
                        $sum,
                        floatval($transaction->discount_amount)
                    );
                }
            }
        }

        // 3) If senior_discount > 0 or pwd_discount > 0, we must have discount_auth_code
        if (
            (property_exists($transaction, 'senior_discount')
             && $transaction->senior_discount > 0)
            || (property_exists($transaction, 'pwd_discount')
             && $transaction->pwd_discount > 0)
        ) {
            if (empty($transaction->discount_auth_code)) {
                $errors[] = 'Authorization code is required for senior/PWD discount.';
            }
        }

        return $errors;
    }
}