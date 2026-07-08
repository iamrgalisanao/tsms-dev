import React, { useState } from 'react';
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
    Stack,
    IconButton,
    Collapse,
    Grid,
    Divider
} from '@mui/material';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';

const Row = ({ row, formatCurrency, cellStyles }) => {
    const [open, setOpen] = useState(false);

    return (
        <React.Fragment>
            <TableRow
                hover
                onClick={() => setOpen(!open)}
                sx={{
                    '& > *': { borderBottom: 'unset' },
                    cursor: 'pointer',
                    transition: 'background-color 0.2s',
                    '&:hover': { bgcolor: 'rgba(25, 118, 210, 0.02) !important' },
                    bgcolor: open ? 'rgba(25, 118, 210, 0.04)' : 'inherit'
                }}
            >
                <TableCell sx={{ position: 'sticky', left: 0, zIndex: 5, bgcolor: open ? 'rgba(235, 245, 255, 1)' : 'white' }}>
                    <Stack direction="row" spacing={1} alignItems="center">
                        <IconButton
                            size="small"
                            onClick={(e) => {
                                e.stopPropagation();
                                setOpen(!open);
                            }}
                            sx={{
                                bgcolor: open ? 'primary.main' : 'rgba(0,0,0,0.04)',
                                color: open ? 'white' : 'inherit',
                                '&:hover': { bgcolor: open ? 'primary.dark' : 'rgba(0,0,0,0.08)' },
                                width: 22,
                                height: 22
                            }}
                        >
                            {open ? <KeyboardArrowUpIcon sx={{ fontSize: 14 }} /> : <KeyboardArrowDownIcon sx={{ fontSize: 14 }} />}
                        </IconButton>
                        <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace', fontSize: '11px' }}>
                            {row.date}
                        </Typography>
                    </Stack>
                </TableCell>
                <TableCell sx={{ position: 'sticky', left: 110, zIndex: 5, bgcolor: open ? 'rgba(235, 245, 255, 1)' : 'white' }}>
                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', fontSize: '0.8125rem', whiteSpace: 'nowrap' }}>
                        {row.trade_name || 'Unknown'}
                    </Typography>
                </TableCell>
                <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 700, fontFamily: 'monospace', fontSize: '11px', whiteSpace: 'nowrap' }}>
                        {row.serial_number || 'N/A'}
                    </Typography>
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
                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', ...cellStyles }}>
                        {formatCurrency(row.vatable_sales)}
                    </Typography>
                </TableCell>
                <TableCell align="right">
                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.secondary', ...cellStyles }}>
                        {formatCurrency(row.vat)}
                    </Typography>
                </TableCell>
                <TableCell align="right">
                    <Typography variant="body2" sx={{ fontWeight: 800, color: row.refund > 0 ? 'error.main' : 'text.secondary', ...cellStyles }}>
                        {formatCurrency(row.refund)}
                    </Typography>
                </TableCell>
                <TableCell align="right">
                    <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', ...cellStyles }}>
                        {formatCurrency(row.service_charge_distributed)}
                    </Typography>
                </TableCell>
            </TableRow>
            <TableRow>
                <TableCell style={{ paddingBottom: 0, paddingTop: 0 }} colSpan={11}>
                    <Collapse in={open} timeout="auto" unmountOnExit>
                        <Box sx={{ py: 3, px: 5, bgcolor: 'rgba(248, 250, 252, 0.8)', borderBottom: '1px solid', borderColor: 'divider' }}>
                            <Typography variant="subtitle2" gutterBottom sx={{ fontWeight: 800, color: 'text.secondary', fontSize: '0.7rem', textTransform: 'uppercase', letterSpacing: '0.12em', mb: 2.5 }}>
                                Extended Financial Breakdown
                            </Typography>
                            <Grid container spacing={4}>
                                <Grid item xs={12} md={4}>
                                    <Stack spacing={2}>
                                        <DetailItem label="Vatable Sales" value={formatCurrency(row.vatable_sales)} />
                                        <DetailItem label="VAT Amount" value={formatCurrency(row.vat)} />
                                        <DetailItem label="Tax Exempt" value={formatCurrency(row.tax_exempt)} />
                                        <DetailItem label="Other Tax" value={formatCurrency(row.other_tax)} />
                                    </Stack>
                                </Grid>
                                <Grid item xs={12} md={4}>
                                    <Stack spacing={2}>
                                        <DetailItem label="Promo Discounts" value={formatCurrency(row.promo_discount)} />
                                        <DetailItem label="Senior Citizen" value={formatCurrency(row.senior_discount)} />
                                        <DetailItem label="PWD Discount" value={formatCurrency(row.pwd_discount)} />
                                        <DetailItem label="SC VAT Exempt Sales" value={formatCurrency(row.sc_vat_exempt_sales)} />
                                    </Stack>
                                </Grid>
                                <Grid item xs={12} md={4}>
                                    <Stack spacing={2}>
                                        <DetailItem label="VIP Discounts" value={formatCurrency(row.vip_discount)} />
                                        <DetailItem label="Employee Discounts" value={formatCurrency(row.employee_discount)} />
                                        <DetailItem label="SC (Management)" value={formatCurrency(row.service_charge_retained)} />
                                        <Box sx={{ pt: 1 }}>
                                            <Divider sx={{ mb: 1.5, opacity: 0.5 }} />
                                            <Typography variant="caption" color="text.disabled" sx={{ fontWeight: 700, display: 'block', mb: 0.5 }}>IDENTIFICATION</Typography>
                                            <Typography variant="body2" sx={{ fontFamily: 'monospace', fontWeight: 700, fontSize: '0.7rem' }}>
                                                TERMINAL: {row.serial_number} | MACHINE #{row.machine_number || 'N/A'}
                                            </Typography>
                                        </Box>
                                    </Stack>
                                </Grid>
                            </Grid>
                        </Box>
                    </Collapse>
                </TableCell>
            </TableRow>
        </React.Fragment>
    );
};

const DetailItem = ({ label, value }) => (
    <Box>
        <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 800, display: 'block', fontSize: '0.625rem', textTransform: 'uppercase', letterSpacing: '0.02em' }}>
            {label}
        </Typography>
        <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', fontFamily: 'monospace' }}>
            {value}
        </Typography>
    </Box>
);

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
        fontWeight: 950,
        fontSize: '0.68rem',
        textTransform: 'uppercase',
        letterSpacing: '0.12em',
        color: '#EB342E',
        py: 3,
        bgcolor: 'white',
        borderBottom: '2px solid',
        borderColor: 'divider',
        whiteSpace: 'nowrap'
    };

    const cellStyles = {
        fontFamily: 'monospace',
        fontSize: '0.78rem',
        whiteSpace: 'nowrap'
    };

    const footerCellStyles = {
        fontWeight: 950,
        bgcolor: 'rgba(235, 52, 46, 0.05)',
        borderTop: '2px solid',
        borderColor: 'primary.main',
        color: 'primary.main',
        py: 2.5,
        fontSize: '0.8rem'
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
                            <TableCell sx={{ ...headerStyles, position: 'sticky', left: 110, zIndex: 10, bgcolor: 'white' }}>Tenant</TableCell>
                            <TableCell sx={headerStyles}>Terminal</TableCell>
                            <TableCell align="right" sx={headerStyles}>Tx Count</TableCell>
                            <TableCell align="right" sx={headerStyles}>Unique Receipts</TableCell>
                            <TableCell align="right" sx={headerStyles}>Gross Total</TableCell>
                            <TableCell align="right" sx={headerStyles}>Net Total</TableCell>
                            <TableCell align="right" sx={headerStyles}>VATable Sales</TableCell>
                            <TableCell align="right" sx={headerStyles}>VAT</TableCell>
                            <TableCell align="right" sx={headerStyles}>Refund</TableCell>
                            <TableCell align="right" sx={headerStyles}>SC (Emp)</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {summary.map((row, index) => (
                            <Row
                                key={index}
                                row={row}
                                formatCurrency={formatCurrency}
                                cellStyles={cellStyles}
                            />
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
