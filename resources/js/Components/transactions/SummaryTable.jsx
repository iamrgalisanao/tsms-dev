import React from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    TableFooter,
    TablePagination,
    Box,
    Typography,
    CircularProgress,
    Stack
} from '@mui/material';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const SummaryTable = ({ summary, grandTotal, loading, page, rowsPerPage, totalCount, onPageChange, onRowsPerPageChange, sortDirection = 'desc', onToggleSortDirection }) => {
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
        if (!amount && amount !== 0) return '-';
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
        borderColor: 'divider',
        whiteSpace: 'nowrap'
    };

    const cellStyles = {
        fontFamily: 'monospace',
        fontSize: '0.75rem',
        whiteSpace: 'nowrap'
    };

    const footerCellStyles = {
        fontWeight: 950,
        bgcolor: 'rgba(235, 52, 46, 0.04)',
        borderTop: '2px solid',
        borderColor: 'primary.main',
        color: 'primary.main',
        py: 2
    };

    return (
        <Box>
            <TableContainer sx={{ overflowX: 'auto' }}>
                <Table size="small" stickyHeader>
                    <TableHead>
                        <TableRow>
                            <TableCell
                                sx={{
                                    ...headerStyles,
                                    cursor: 'pointer',
                                    position: 'sticky',
                                    left: 0,
                                    zIndex: 10,
                                    bgcolor: 'white'
                                }}
                                onClick={onToggleSortDirection}
                            >
                                <Stack direction="row" spacing={0.5} alignItems="center">
                                    <span>Date</span>
                                    <Typography variant="caption" sx={{ fontWeight: 800 }}>
                                        {sortDirection === 'asc' ? '↑' : '↓'}
                                    </Typography>
                                </Stack>
                            </TableCell>
                            <TableCell sx={{ ...headerStyles, position: 'sticky', left: 100, zIndex: 10, bgcolor: 'white' }}>Tenant</TableCell>
                            <TableCell sx={headerStyles}>Terminal</TableCell>
                            <TableCell align="right" sx={headerStyles}>Tx Count</TableCell>
                            <TableCell align="right" sx={headerStyles}>Unique Receipts</TableCell>
                            <TableCell align="right" sx={headerStyles}>Gross Total</TableCell>
                            <TableCell align="right" sx={headerStyles}>Net Total</TableCell>
                            <TableCell align="right" sx={headerStyles}>Refund</TableCell>
                            
                            {/* Adjustment Columns */}
                            <TableCell align="right" sx={headerStyles}>Promo</TableCell>
                            <TableCell align="right" sx={headerStyles}>Senior</TableCell>
                            <TableCell align="right" sx={headerStyles}>PWD</TableCell>
                            <TableCell align="right" sx={headerStyles}>VIP</TableCell>
                            <TableCell align="right" sx={headerStyles}>Employee</TableCell>
                            <TableCell align="right" sx={headerStyles}>SC (Empl)</TableCell>
                            <TableCell align="right" sx={headerStyles}>SC (Mng)</TableCell>
                            
                            {/* Tax Columns */}
                            <TableCell align="right" sx={headerStyles}>VAT</TableCell>
                            <TableCell align="right" sx={headerStyles}>Vatable</TableCell>
                            <TableCell align="right" sx={headerStyles}>Exempt</TableCell>
                            <TableCell align="right" sx={headerStyles}>Other Tax</TableCell>
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
                                <TableCell sx={{ position: 'sticky', left: 0, zIndex: 5, bgcolor: 'white' }}>
                                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace', fontSize: '11px' }}>
                                        {row.date}
                                    </Typography>
                                </TableCell>
                                <TableCell sx={{ position: 'sticky', left: 100, zIndex: 5, bgcolor: 'white' }}>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', fontSize: '0.8125rem', whiteSpace: 'nowrap' }}>
                                        {row.trade_name || 'Unknown'}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography variant="body2" sx={{ fontWeight: 700, fontFamily: 'monospace', fontSize: '11px', whiteSpace: 'nowrap' }}>
                                        {row.serial_number || 'N/A'}
                                    </Typography>
                                    {row.machine_number && (
                                        <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 600 }}>
                                            #{row.machine_number}
                                        </Typography>
                                    )}
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 800, ...cellStyles }}>
                                        {row.tx_count?.toLocaleString()}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 950, color: 'primary.main', ...cellStyles }}>
                                        {row.unique_receipts !== undefined ? row.unique_receipts.toLocaleString() : '-'}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 800, ...cellStyles }}>
                                        {formatCurrency(row.gross)}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 950, color: 'primary.main', ...cellStyles }}>
                                        {formatCurrency(row.net)}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: row.refund > 0 ? 'error.main' : 'text.secondary', ...cellStyles }}>
                                        {formatCurrency(row.refund)}
                                    </Typography>
                                </TableCell>
                                
                                {/* Adjustment Cells */}
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.promo_discount)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.senior_discount)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.pwd_discount)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.vip_discount)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.employee_discount)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.service_charge_distributed)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.service_charge_retained)}</Typography>
                                </TableCell>
                                
                                {/* Tax Cells */}
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.vat)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.vatable_sales)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.sc_vat_exempt_sales)}</Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Typography variant="body2" sx={cellStyles}>{formatCurrency(row.tax_exempt)}</Typography>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    {grandTotal && (
                        <TableFooter>
                            <TableRow sx={{ bgcolor: 'rgba(0,0,0,0.02)' }}>
                                <TableCell colSpan={3} sx={{ ...footerCellStyles, textAlign: 'center', position: 'sticky', left: 0, zIndex: 10 }}>
                                    GRAND TOTAL (FILTERED)
                                </TableCell>
                                <TableCell align="right" sx={footerCellStyles}>
                                    {grandTotal.tx_count?.toLocaleString()}
                                </TableCell>
                                <TableCell align="right" sx={footerCellStyles}>
                                    {grandTotal.unique_receipts?.toLocaleString()}
                                </TableCell>
                                <TableCell align="right" sx={footerCellStyles}>
                                    {formatCurrency(grandTotal.gross)}
                                </TableCell>
                                <TableCell align="right" sx={footerCellStyles}>
                                    {formatCurrency(grandTotal.net)}
                                </TableCell>
                                <TableCell align="right" sx={footerCellStyles}>
                                    {formatCurrency(grandTotal.refund)}
                                </TableCell>
                                
                                {/* Adjustment Footer */}
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.promo_discount)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.senior_discount)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.pwd_discount)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.vip_discount)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.employee_discount)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.service_charge)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.management_service_charge)}</TableCell>
                                
                                {/* Tax Footer */}
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.vat)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.vatable_sales)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.sc_vat_exempt_sales)}</TableCell>
                                <TableCell align="right" sx={footerCellStyles}>{formatCurrency(grandTotal.tax_exempt)}</TableCell>
                            </TableRow>
                        </TableFooter>
                    )}
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
