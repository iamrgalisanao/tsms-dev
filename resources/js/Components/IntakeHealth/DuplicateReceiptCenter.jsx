import React from 'react';
import { Paper, Stack, Box, Typography, Chip, Grid, TextField, Button, CircularProgress, Alert, TableContainer, Table, TableHead, TableRow, TableCell, TableBody } from '@mui/material';
import RefreshIcon from '@mui/icons-material/Refresh';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';

const DuplicateReceiptCenter = ({
    duplicateReport = { duplicate_groups: [], legacy_payload_conflicts: [] },
    duplicateLoading = false,
    duplicateFilters = {},
    setDuplicateFilters,
    onRefreshDuplicates,
    transactionSearchUrl
}) => {
    const duplicateGroups = duplicateReport?.duplicate_groups || [];
    const legacyConflicts = duplicateReport?.legacy_payload_conflicts || [];

    return (
        <Stack spacing={3}>
            {duplicateReport?.error && (
                <Alert severity="error" sx={{ borderRadius: 3, fontWeight: 800 }}>
                    Duplicate receipt monitor could not load.
                </Alert>
            )}

            <Paper className="glass-container" sx={{ p: 3, borderRadius: 3 }}>
                <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" spacing={2} alignItems={{ xs: 'stretch', lg: 'center' }}>
                    <Box>
                        <Typography variant="h5" sx={{ fontWeight: 1000, color: '#101221', mb: 0.5 }}>
                            Duplicate Receipt Monitor
                        </Typography>
                        <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 700 }}>
                            Read-only audit view for receipt conflicts by tenant, terminal, receipt number, and transaction date.
                        </Typography>
                    </Box>

                    <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                        <Chip label={`${duplicateGroups.length} Duplicate Groups`} color={duplicateGroups.length > 0 ? 'error' : 'success'} sx={{ fontWeight: 900 }} />
                        <Chip label={`${legacyConflicts.length} Legacy Conflicts`} color={legacyConflicts.length > 0 ? 'warning' : 'success'} sx={{ fontWeight: 900 }} />
                    </Stack>
                </Stack>

                <Grid container spacing={2} sx={{ mt: 2 }}>
                    <Grid item xs={12} sm={6} md={2.4}>
                        <TextField
                            fullWidth
                            label="From"
                            type="date"
                            size="small"
                            value={duplicateFilters.from || ''}
                            onChange={(e) => setDuplicateFilters((prev) => ({ ...prev, from: e.target.value }))}
                            InputLabelProps={{ shrink: true }}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={2.4}>
                        <TextField
                            fullWidth
                            label="To"
                            type="date"
                            size="small"
                            value={duplicateFilters.to || ''}
                            onChange={(e) => setDuplicateFilters((prev) => ({ ...prev, to: e.target.value }))}
                            InputLabelProps={{ shrink: true }}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={2.4}>
                        <TextField
                            fullWidth
                            label="Tenant ID"
                            size="small"
                            value={duplicateFilters.tenant || ''}
                            onChange={(e) => setDuplicateFilters((prev) => ({ ...prev, tenant: e.target.value }))}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={2.4}>
                        <TextField
                            fullWidth
                            label="Terminal ID"
                            size="small"
                            value={duplicateFilters.terminal || ''}
                            onChange={(e) => setDuplicateFilters((prev) => ({ ...prev, terminal: e.target.value }))}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={2.4}>
                        <Button
                            fullWidth
                            variant="contained"
                            onClick={onRefreshDuplicates}
                            disabled={duplicateLoading}
                            startIcon={duplicateLoading ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon />}
                            sx={{ height: 40, borderRadius: 2, fontWeight: 900, textTransform: 'none', bgcolor: '#101221' }}
                        >
                            Refresh
                        </Button>
                    </Grid>
                </Grid>

                <Alert
                    severity="info"
                    icon={<InfoOutlinedIcon />}
                    sx={{ mt: 2, borderRadius: 2, fontWeight: 700 }}
                >
                    Legacy conflicts are historical transactions where the stored receipt number is blank, but the original payload contains a receipt number that would collide with an already stored transaction for the same tenant, terminal, and sale date. These rows are kept read-only so backfills do not overwrite or mutate old records.
                </Alert>
            </Paper>

            {/* Duplicate Receipt Groups Table */}
            <Paper sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', overflow: 'hidden' }}>
                <Box sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
                    <Typography variant="h6" sx={{ fontWeight: 1000, color: '#101221' }}>
                        Populated Duplicate Receipt Groups
                    </Typography>
                </Box>
                <TableContainer>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                {['Tenant', 'Terminal', 'Receipt No.', 'Date', 'Count', 'Transaction IDs', 'Hardware IDs', 'Actions'].map((header) => (
                                    <TableCell key={header} sx={{ fontWeight: 1000, color: 'error.main', letterSpacing: '0.06em', textTransform: 'uppercase', fontSize: '0.7rem' }}>
                                        {header}
                                    </TableCell>
                                ))}
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {duplicateGroups.map((row) => (
                                <TableRow key={`${row.tenant_id}-${row.terminal_id}-${row.receipt_no}-${row.transaction_date}`} hover>
                                    <TableCell>{row.tenant_id}</TableCell>
                                    <TableCell>{row.terminal_id}</TableCell>
                                    <TableCell sx={{ fontFamily: 'monospace', fontWeight: 900 }}>{row.receipt_no}</TableCell>
                                    <TableCell>{row.transaction_date}</TableCell>
                                    <TableCell><Chip size="small" color="error" label={row.count} sx={{ fontWeight: 900 }} /></TableCell>
                                    <TableCell sx={{ maxWidth: 300, wordBreak: 'break-word', fontFamily: 'monospace', fontSize: '0.75rem' }}>
                                        {(row.transaction_ids || []).join(', ')}
                                    </TableCell>
                                    <TableCell sx={{ maxWidth: 220, wordBreak: 'break-word', fontFamily: 'monospace', fontSize: '0.75rem' }}>
                                        {(row.hardware_ids || []).join(', ')}
                                    </TableCell>
                                    <TableCell>
                                        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                                            {(row.transaction_ids || []).slice(0, 3).map((transactionId) => (
                                                <Button
                                                    key={transactionId}
                                                    size="small"
                                                    variant="outlined"
                                                    href={transactionSearchUrl(transactionId)}
                                                    endIcon={<OpenInNewIcon fontSize="inherit" />}
                                                    sx={{ textTransform: 'none', fontWeight: 800, borderRadius: 2, whiteSpace: 'nowrap' }}
                                                >
                                                    View
                                                </Button>
                                            ))}
                                        </Stack>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {duplicateGroups.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} sx={{ py: 5, textAlign: 'center', color: 'text.secondary', fontWeight: 800 }}>
                                        No populated duplicate receipt groups found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </TableContainer>
            </Paper>

            {/* Legacy Payload Conflicts Table */}
            <Paper sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', overflow: 'hidden' }}>
                <Box sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
                    <Typography variant="h6" sx={{ fontWeight: 1000, color: '#101221' }}>
                        Legacy Payload Receipt Conflicts
                    </Typography>
                    <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 700, mt: 0.5 }}>
                        Use this list for audit review: the legacy row still has a blank stored receipt number, while the existing row already owns that receipt for the same tenant, terminal, and sale date.
                    </Typography>
                </Box>
                <TableContainer>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                {['Legacy TX PK', 'Tenant', 'Terminal', 'Receipt No.', 'Date', 'Legacy TXID', 'Existing TXID', 'Existing TX PK', 'Actions'].map((header) => (
                                    <TableCell key={header} sx={{ fontWeight: 1000, color: 'warning.main', letterSpacing: '0.06em', textTransform: 'uppercase', fontSize: '0.7rem' }}>
                                        {header}
                                    </TableCell>
                                ))}
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {legacyConflicts.map((row) => (
                                <TableRow key={`${row.legacy_transaction_pk}-${row.existing_transaction_pk}`} hover>
                                    <TableCell>{row.legacy_transaction_pk}</TableCell>
                                    <TableCell>{row.tenant_id}</TableCell>
                                    <TableCell>{row.terminal_id}</TableCell>
                                    <TableCell sx={{ fontFamily: 'monospace', fontWeight: 900 }}>{row.receipt_no}</TableCell>
                                    <TableCell>{row.transaction_date}</TableCell>
                                    <TableCell sx={{ maxWidth: 240, wordBreak: 'break-word', fontFamily: 'monospace', fontSize: '0.75rem' }}>{row.legacy_transaction_id}</TableCell>
                                    <TableCell sx={{ maxWidth: 240, wordBreak: 'break-word', fontFamily: 'monospace', fontSize: '0.75rem' }}>{row.existing_transaction_id}</TableCell>
                                    <TableCell>{row.existing_transaction_pk}</TableCell>
                                    <TableCell>
                                        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                                            <Button
                                                size="small"
                                                variant="outlined"
                                                href={transactionSearchUrl(row.legacy_transaction_id)}
                                                endIcon={<OpenInNewIcon fontSize="inherit" />}
                                                sx={{ textTransform: 'none', fontWeight: 800, borderRadius: 2, whiteSpace: 'nowrap' }}
                                            >
                                                Legacy
                                            </Button>
                                            <Button
                                                size="small"
                                                variant="outlined"
                                                color="warning"
                                                href={transactionSearchUrl(row.existing_transaction_id)}
                                                endIcon={<OpenInNewIcon fontSize="inherit" />}
                                                sx={{ textTransform: 'none', fontWeight: 800, borderRadius: 2, whiteSpace: 'nowrap' }}
                                            >
                                                Existing
                                            </Button>
                                        </Stack>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {legacyConflicts.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} sx={{ py: 5, textAlign: 'center', color: 'text.secondary', fontWeight: 800 }}>
                                        No legacy payload receipt conflicts found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </TableContainer>
            </Paper>
        </Stack>
    );
};

export default DuplicateReceiptCenter;
