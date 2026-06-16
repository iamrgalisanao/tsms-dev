import React from 'react';
import { Paper, TableContainer, Table, TableHead, TableRow, TableCell, TableBody, Typography, Stack, Chip, Button, Tooltip } from '@mui/material';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import RefreshIcon from '@mui/icons-material/Refresh';
import LibraryBooksIcon from '@mui/icons-material/LibraryBooks';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';

const TenantAuditTable = ({
    auditRows = [],
    onInspect,
    onReplay,
    onViewLogs
}) => {
    const getRowHealth = (flags = []) => {
        if (flags.length === 0) return { label: 'Healthy', color: '#00e676', icon: '🟢' };
        if (flags.some(f => f.includes('DRIFT') || f.includes('NO_PERSISTED'))) {
            return { label: 'Critical', color: '#ff1744', icon: '🔴' };
        }
        return { label: 'Warning', color: '#feb700', icon: '🟡' };
    };

    return (
        <TableContainer component={Paper} sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', overflow: 'hidden' }}>
            <Table size="small">
                <TableHead>
                    <TableRow sx={{ bgcolor: 'slate.50/20' }}>
                        <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Health</TableCell>
                        <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Tenant</TableCell>
                        <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Terminals</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Submissions</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Quarantine</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Intake</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Tx</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Valid</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Pending</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Failed</TableCell>
                        <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Gross</TableCell>
                        <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Last Ingest</TableCell>
                        <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Flags</TableCell>
                        <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em' }}>Actions</TableCell>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {auditRows.map((row) => {
                        const health = getRowHealth(row.flags);
                        return (
                            <TableRow key={row.tenant_id} hover>
                                <TableCell>
                                    <Tooltip title={health.label} arrow>
                                        <Typography sx={{ cursor: 'help', fontSize: '1rem', textAlign: 'center' }}>
                                            {health.icon}
                                        </Typography>
                                    </Tooltip>
                                </TableCell>
                                <TableCell>
                                    <Typography sx={{ fontWeight: 900, fontSize: '0.82rem' }}>{row.tenant}</Typography>
                                    <Typography sx={{ color: 'text.secondary', fontSize: '0.72rem', fontWeight: 800 }}>#{row.tenant_id} · {row.status || 'n/a'}</Typography>
                                </TableCell>
                                <TableCell sx={{ fontSize: '0.78rem' }}>{row.active_terminals}/{row.terminals} active · {row.terminals_without_tx} no tx</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.submissions}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', color: row.quarantined > 0 ? '#ff1744' : 'inherit' }}>{row.quarantined}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.intake_received}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.transactions}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', color: row.valid > 0 ? '#00c853' : 'inherit' }}>{row.valid}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.pending}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', color: row.invalid_or_failed > 0 ? '#ff1744' : 'inherit' }}>{row.invalid_or_failed}</TableCell>
                                <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', fontWeight: 700 }}>
                                    {Number(row.gross_sales || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                </TableCell>
                                <TableCell sx={{ fontSize: '0.75rem', whiteSpace: 'nowrap' }}>{row.last_transaction_at ? row.last_transaction_at.split(' ')[1] || row.last_transaction_at : '-'}</TableCell>
                                <TableCell>
                                    <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                                        {(row.flags || []).map((flag) => (
                                            <Chip
                                                key={flag}
                                                size="small"
                                                label={flag}
                                                color={flag.includes('DRIFT') || flag.includes('NO_PERSISTED') ? 'error' : 'warning'}
                                                sx={{ fontWeight: 900, fontSize: '0.62rem', borderRadius: '4px' }}
                                            />
                                        ))}
                                        {(row.flags || []).length === 0 && <Chip size="small" label="OK" color="success" sx={{ fontWeight: 900, borderRadius: '4px' }} />}
                                    </Stack>
                                </TableCell>
                                <TableCell>
                                    <Stack direction="row" spacing={0.5}>
                                        <Tooltip title="View Tenant Ingestion Logs" arrow>
                                            <Button
                                                size="small"
                                                variant="outlined"
                                                onClick={() => onViewLogs && onViewLogs(row)}
                                                sx={{ minWidth: '32px', p: 0.5, borderRadius: '6px' }}
                                            >
                                                <LibraryBooksIcon style={{ fontSize: 14 }} />
                                            </Button>
                                        </Tooltip>
                                        <Tooltip title="Replay Pending Queue" arrow>
                                            <Button
                                                size="small"
                                                variant="outlined"
                                                color="warning"
                                                onClick={() => onReplay && onReplay(row)}
                                                sx={{ minWidth: '32px', p: 0.5, borderRadius: '6px' }}
                                            >
                                                <RefreshIcon style={{ fontSize: 14 }} />
                                            </Button>
                                        </Tooltip>
                                        <Tooltip title="Inspect Payload Drift" arrow>
                                            <Button
                                                size="small"
                                                variant="outlined"
                                                color="info"
                                                onClick={() => onInspect && onInspect(row)}
                                                sx={{ minWidth: '32px', p: 0.5, borderRadius: '6px' }}
                                            >
                                                <OpenInNewIcon style={{ fontSize: 14 }} />
                                            </Button>
                                        </Tooltip>
                                    </Stack>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                    
                    {auditRows.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={14} sx={{ py: 6, textAlign: 'center' }}>
                                <Box sx={{ maxWidth: '400px', mx: 'auto', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                                    <Box sx={{ p: 1.5, borderRadius: '50%', bgcolor: 'rgba(76, 175, 80, 0.1)', color: '#00c853', mb: 2 }}>
                                        <CheckCircleIcon sx={{ fontSize: 36 }} />
                                    </Box>
                                    <Typography variant="body1" sx={{ fontWeight: 1000, color: '#101221', mb: 1, letterSpacing: '0.02em' }}>
                                        ✓ No Issues Found
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, leading: '1.4', display: 'block', mb: 2 }}>
                                        No tenants matched the selected filters. All systems are green and within bounds.
                                    </Typography>
                                    <Stack direction="row" spacing={1} sx={{ fontSize: '0.68rem', fontWeight: 800, color: 'text.secondary' }}>
                                        <span>Try:</span>
                                        <span>• Expanding date range</span>
                                        <span>• Removing Tenant filter</span>
                                        <span>• Running a full audit</span>
                                    </Stack>
                                </Box>
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </TableContainer>
    );
};

export default TenantAuditTable;
