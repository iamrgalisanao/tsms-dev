import React from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    TablePagination,
    Box,
    Typography,
    CircularProgress,
    Stack
} from '@mui/material';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const SummaryTable = ({ summary, loading, page, rowsPerPage, totalCount, onPageChange, onRowsPerPageChange }) => {
    if (loading) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: 400 }}>
                <CircularProgress thickness={5} size={40} sx={{ color: 'primary.main', opacity: 0.5 }} />
            </Box>
        );
    }

    if (!summary || summary.length === 0) {
        return (
            <Box sx={{ textAlign: 'center', py: 12 }}>
                <InfoOutlinedIcon sx={{ fontSize: 48, color: 'grey.300', mb: 2 }} />
                <Typography variant="h6" sx={{ color: 'grey.500', fontWeight: 800 }}>
                    No summary data found
                </Typography>
                <Typography variant="body2" sx={{ color: 'grey.400', mt: 1 }}>
                    Try adjusting your filters to populate the archive summary.
                </Typography>
            </Box>
        );
    }

    const formatCurrency = (amount) => {
        if (!amount && amount !== 0) return '₱0.00';
        return '₱' + new Intl.NumberFormat('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    };

    const headerStyles = {
        fontWeight: 800,
        fontSize: '0.65rem',
        textTransform: 'uppercase',
        letterSpacing: '0.1em',
        color: '#EB342E',
        py: 2.5,
        bgcolor: 'white',
        borderBottom: '2px solid',
        borderColor: 'divider'
    };

    return (
        <Box>
            <TableContainer sx={{ overflowX: 'auto' }}>
                <Table size="small">
                    <TableHead>
                        <TableRow>
                            <TableCell sx={headerStyles}>Date</TableCell>
                            <TableCell sx={headerStyles}>Tenant</TableCell>
                            <TableCell sx={headerStyles}>Terminal</TableCell>
                            <TableCell align="right" sx={headerStyles}>Tx Count</TableCell>
                            <TableCell align="right" sx={headerStyles}>Unique Receipts</TableCell>
                            <TableCell align="right" sx={headerStyles}>Gross Total</TableCell>
                            <TableCell align="right" sx={headerStyles}>VAT</TableCell>
                            <TableCell align="right" sx={headerStyles}>Net Total</TableCell>
                            <TableCell align="right" sx={headerStyles}>Refund</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {summary.map((row, index) => (
                            <TableRow
                                key={index}
                                hover
                                sx={{
                                    transition: 'background-color 0.2s',
                                    '&:hover': { bgcolor: 'rgba(25, 118, 210, 0.02) !important' }
                                }}
                            >
                                <TableCell>
                                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace', fontSize: '11px' }}>
                                        {row.date}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', fontSize: '0.8125rem' }}>
                                        {row.trade_name || 'Unknown'}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography variant="body2" sx={{ fontWeight: 700, fontFamily: 'monospace', fontSize: '11px' }}>
                                        {row.serial_number || 'N/A'}
                                    </Typography>
                                    {row.machine_number && (
                                        <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 600 }}>
                                            #{row.machine_number}
                                        </Typography>
                                    )}
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace' }}>
                                        {row.tx_count?.toLocaleString()}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 950, color: 'primary.main', fontFamily: 'monospace' }}>
                                        {row.unique_receipts !== undefined ? row.unique_receipts.toLocaleString() : '-'}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace' }}>
                                        {formatCurrency(row.gross)}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
                                        {formatCurrency(row.vat)}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 950, fontFamily: 'monospace', color: 'primary.main' }}>
                                        {formatCurrency(row.net)}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace', color: row.refund > 0 ? 'error.main' : 'text.secondary' }}>
                                        {formatCurrency(row.refund)}
                                    </Typography>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </TableContainer>

            <Box sx={{ p: 2, bgcolor: 'grey.50', borderTop: '1px solid', borderColor: 'divider' }}>
                <TablePagination
                    component="div"
                    count={totalCount}
                    page={page}
                    onPageChange={onPageChange}
                    rowsPerPage={rowsPerPage}
                    onRowsPerPageChange={onRowsPerPageChange}
                    rowsPerPageOptions={[15, 50, 100, 500, 1000]}
                    sx={{ border: 'none' }}
                />
            </Box>
        </Box>
    );
};

export default SummaryTable;
