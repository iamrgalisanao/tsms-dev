// ADR-004 slice 3: operator-facing transaction status.
// validation_status is the business outcome (PENDING / VALID / INVALID); job_status is
// the processing lifecycle. Legacy blank ('') rows are "processing failed / needs
// attention" until the slice 4 remediation: never RECONCILED and never INVALID.
// The label rules mirror App\Models\Transaction::statusLabel(); both sides are checked
// against tests/fixtures/transaction_status_labels.json (tests/js/transaction-status-labels.mjs).

export const PROCESSING_FAILED = 'PROCESSING_FAILED';

const upper = (value) => (value || '').toString().toUpperCase();

export const getStatusLabel = (transaction) => {
    const validation = upper(transaction?.validation_status);
    const job = upper(transaction?.job_status);

    if (validation === 'VALID') return 'RECONCILED';
    if (validation === 'INVALID' || validation === 'ERROR') return 'INVALID';
    if (validation === '') return 'PROCESSING FAILED';
    if (validation === 'PENDING' && job === 'FAILED') return 'PROCESSING FAILED';
    if (validation === 'PENDING') return 'PENDING';

    return 'UNKNOWN';
};

const LABEL_COLORS = {
    RECONCILED: 'success',
    INVALID: 'error',
    'PROCESSING FAILED': 'warning',
    PENDING: 'warning',
    UNKNOWN: 'default',
};

export const isProcessingFailed = (transaction) => getStatusLabel(transaction) === 'PROCESSING FAILED';

export const getOperationStatus = (transaction) => {
    // Display only: a voided row is shown as VOIDED (no filtering involved).
    if (transaction?.voided_at) {
        return { label: 'VOIDED', color: 'error' };
    }

    const label = getStatusLabel(transaction);

    return { label, color: LABEL_COLORS[label] };
};

// Label for the raw validation chip: a blank legacy status reads PROCESSING FAILED.
export const getValidationStatusLabel = (transaction) =>
    (upper(transaction?.validation_status) === '' ? 'PROCESSING FAILED' : upper(transaction?.validation_status));
