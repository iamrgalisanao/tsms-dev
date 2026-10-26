// ADR-004 slice 3: the React status label util must match the shared expectation table
// (also asserted against App\Models\Transaction::statusLabel() in PHPUnit).
// Run: node tests/js/transaction-status-labels.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { getOperationStatus, getStatusLabel } from '../../resources/js/utils/transactionStatus.js';

const cases = JSON.parse(readFileSync(new URL('../fixtures/transaction_status_labels.json', import.meta.url), 'utf8'));

for (const row of cases) {
    assert.equal(getStatusLabel(row), row.label, JSON.stringify(row));
    assert.equal(getOperationStatus(row).label, row.label, JSON.stringify(row));
}
assert.equal(getOperationStatus({ validation_status: 'VALID', voided_at: '2026-01-01' }).label, 'VOIDED');

console.log(`transaction status labels: ${cases.length} cases OK`);
