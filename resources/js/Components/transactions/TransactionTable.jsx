import React, { useState } from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    TablePagination,
    Chip,
    IconButton,
    Box,
    Typography,
    CircularProgress,
    Tooltip,
    Stack,
    Button,
    Collapse,
    Paper,
    Divider,
    Grid,
    Card
} from '@mui/material';
import VisibilityIcon from '@mui/icons-material/Visibility';
import BlockIcon from '@mui/icons-material/Block';
import PrintIcon from '@mui/icons-material/Print';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const Row = ({ transaction, onViewDetails, getStatusColor, formatCurrency, formatDate }) => {
    const [open, setOpen] = useState(false);

    return (
        <React.Fragment>
            <TableRow
                hover
                sx={{
                    '& > *': { borderBottom: 'unset' },
                    cursor: 'pointer',
                    transition: 'background-color 0.2s',
                    '&:hover': { bgcolor: 'rgba(25, 118, 210, 0.02) !important' },
                    bgcolor: open ? 'rgba(25, 118, 210, 0.04)' : 'inherit'
                }}
                onClick={() => setOpen(!open)}
            >
                <TableCell sx={{ minWidth: 180 }}>
                    <Stack direction="row" spacing={1} alignItems="center">
                        <IconButton
                            aria-label="expand row"
                            size="small"
                            onClick={(e) => {
                                e.stopPropagation();
                                setOpen(!open);
                            }}
                            sx={{
                                bgcolor: open ? 'primary.main' : 'rgba(0,0,0,0.04)',
                                color: open ? 'white' : 'inherit',
                                '&:hover': { bgcolor: open ? 'primary.dark' : 'rgba(0,0,0,0.08)' },
                                width: 24,
                                height: 24
                            }}
                        >
                            {open ? <KeyboardArrowUpIcon sx={{ fontSize: 16 }} /> : <KeyboardArrowDownIcon sx={{ fontSize: 16 }} />}
                        </IconButton>
                        <Typography variant="body2" sx={{
                            fontFamily: 'monospace',
                            fontSize: '11px',
                            color: 'primary.main',
                            fontWeight: 700,
                            letterSpacing: '0.02em'
                        }}>
                            {transaction.transaction_id.slice(0, 18)}...
                        </Typography>
                    </Stack>
                </TableCell>
                <TableCell>
                    <Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: '11px', color: 'text.secondary' }}>
                        {transaction.receipt_no || '-'}
                    </Typography>
                </TableCell>
                <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', fontSize: '0.8125rem' }}>
                        {transaction.terminal?.tenant?.trade_name || 'N/A'}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 600 }}>
                        SN: {transaction.terminal?.serial_number || 'N/A'} • {transaction.terminal?.machine_number || 'N/A'}
                    </Typography>
                </TableCell>
                <TableCell align="left">
                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace' }}>
                        {formatCurrency(transaction.amount)}
                    </Typography>
                </TableCell>
                <TableCell align="left">
                    <Typography variant="body2" sx={{ fontWeight: 800, fontFamily: 'monospace', color: 'primary.main' }}>
                        {formatCurrency(transaction.net_sales)}
                    </Typography>
                </TableCell>
                <TableCell align="right" sx={{ color: 'text.secondary', fontSize: '11px' }}>
                    {transaction.promo_discount ? formatCurrency(transaction.promo_discount) : '-'}
                </TableCell>
                <TableCell align="right" sx={{ color: 'text.secondary', fontSize: '11px' }}>
                    {transaction.senior_discount ? formatCurrency(transaction.senior_discount) : '-'}
                </TableCell>
                <TableCell align="right" sx={{ color: 'text.secondary', fontSize: '11px' }}>
                    {transaction.pwd_discount ? formatCurrency(transaction.pwd_discount) : '-'}
                </TableCell>
                <TableCell align="right" sx={{ color: 'text.secondary', fontSize: '11px' }}>-</TableCell>
                <TableCell align="right" sx={{ color: 'text.secondary', fontSize: '11px' }}>-</TableCell>
                <TableCell align="left" sx={{ color: 'text.secondary', fontSize: '11px' }}>
                    {transaction.service_charge ? formatCurrency(transaction.service_charge) : '-'}
                </TableCell>
            </TableRow>
            <TableRow>
                <TableCell style={{ paddingBottom: 0, paddingTop: 0 }} colSpan={11}>
                    <Collapse in={open} timeout="auto" unmountOnExit>
                        <Box sx={{ py: 3, px: 4, bgcolor: 'rgba(248, 250, 252, 0.5)' }}>
                            <Grid container spacing={4}>
                                <Grid item xs={12} md={7}>
                                    <Typography variant="subtitle2" gutterBottom sx={{ fontWeight: 800, color: 'text.secondary', fontSize: '0.7rem', textTransform: 'uppercase', letterSpacing: '0.1em', mb: 2 }}>
                                        Extended Financial Breakdown
                                    </Typography>
                                    <Stack direction="row" spacing={6}>
                                        <Box>
                                            <DetailItem label="Service Charge (Management)" value={transaction.management_service_charge ? formatCurrency(transaction.management_service_charge) : '-'} />
                                            <DetailItem label="VAT" value={formatCurrency(transaction.vat)} />
                                            <DetailItem label="Vatable Sales" value={formatCurrency(transaction.vatable_sales)} />
                                            <DetailItem label="SC VAT Exempt Sales" value={formatCurrency(transaction.sc_vat_exempt_sales)} />
                                        </Box>
                                        <Box>
                                            <DetailItem label="Tax Exempt" value={formatCurrency(transaction.tax_exempt)} />
                                            <DetailItem label="Other Tax" value="-" />
                                            <DetailItem label="Transaction Time" value={formatDate(transaction.transaction_timestamp || transaction.created_at)} />
                                            <Box sx={{ mt: 2 }}>
                                                <Button
                                                    variant="contained"
                                                    size="small"
                                                    startIcon={<VisibilityIcon />}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onViewDetails(transaction);
                                                    }}
                                                    sx={{
                                                        borderRadius: 2,
                                                        textTransform: 'none',
                                                        fontWeight: 700,
                                                        boxShadow: '0 4px 12px rgba(25, 118, 210, 0.2)'
                                                    }}
                                                >
                                                    View Full Audit
                                                </Button>
                                            </Box>
                                        </Box>
                                    </Stack>
                                </Grid>
                                <Grid item xs={12} md={5}>
                                    <Box sx={{ p: 2.5, bgcolor: 'white', borderRadius: 3, border: '1px solid', borderColor: 'divider', boxShadow: '0 4px 15px rgba(0,0,0,0.03)' }}>
                                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2 }}>
                                            <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary' }}>AUDIT STATUS</Typography>
                                            <Chip
                                                label={transaction.validation_status}
                                                color={getStatusColor(transaction.validation_status)}
                                                size="small"
                                                sx={{ fontWeight: 800, fontSize: '0.65rem' }}
                                            />
                                        </Stack>
                                        <Typography variant="caption" color="text.disabled" sx={{ display: 'block', mb: 1, fontFamily: 'monospace' }}>
                                            TXID: {transaction.transaction_id}
                                        </Typography>
                                        <Stack direction="row" spacing={1}>
                                            <IconButton size="small" color="error" sx={{ bgcolor: 'error.50' }}><BlockIcon fontSize="small" /></IconButton>
                                            <IconButton size="small" sx={{ bgcolor: 'grey.100' }}><PrintIcon fontSize="small" /></IconButton>
                                        </Stack>
                                    </Box>
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
    <Box sx={{ mb: 1.5 }}>
        <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 700, display: 'block', fontSize: '0.65rem', textTransform: 'uppercase' }}>
            {label}
        </Typography>
        <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.primary' }}>
            {value}
        </Typography>
    </Box>
);

const TransactionTable = ({ transactions, loading, page, rowsPerPage, totalCount, onPageChange, onRowsPerPageChange, onViewDetails }) => {
    if (loading) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: 400 }}>
                <CircularProgress thickness={5} size={40} sx={{ color: 'primary.main', opacity: 0.5 }} />
            </Box>
        );
    }

    if (!transactions || transactions.length === 0) {
        return (
            <Box sx={{ textAlign: 'center', py: 12 }}>
                <InfoOutlinedIcon sx={{ fontSize: 48, color: 'grey.300', mb: 2 }} />
                <Typography variant="h6" sx={{ color: 'grey.500', fontWeight: 800 }}>
                    No transactions found
                </Typography>
                <Typography variant="body2" sx={{ color: 'grey.400', mt: 1 }}>
                    Adjust your timeframe or filters to explore the archive.
                </Typography>
            </Box>
        );
    }

    const getStatusColor = (status) => {
        switch (status?.toUpperCase()) {
            case 'VALID': return 'success';
            case 'INVALID': return 'error';
            case 'WITH_ISSUES': return 'warning';
            case 'PENDING': return 'info';
            case 'DUPLICATE': return 'default';
            default: return 'default';
        }
    };

    const formatCurrency = (amount) => {
        if (!amount && amount !== 0) return '₱0.00';
        return '₱' + new Intl.NumberFormat('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString('en-PH', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
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
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }} rowSpan={2}>Transaction ID</TableCell>
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }} rowSpan={2}>Receipt No</TableCell>
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }} rowSpan={2}>Tenant / Terminal</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }} rowSpan={2}>Gross Sales</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }} rowSpan={2}>Net Sales</TableCell>
                            <TableCell align="center" sx={{ ...headerStyles, verticalAlign: 'top', pt: 1.5, pb: 0, borderBottom: '1px solid rgba(0,0,0,0.06)' }} colSpan={5}>Discounts</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }} rowSpan={2}>SC (Emp)</TableCell>
                        </TableRow>
                        <TableRow>
                            <TableCell align="right" sx={{ ...headerStyles, verticalAlign: 'bottom', py: 1.5 }}>Promo</TableCell>
                            <TableCell align="right" sx={{ ...headerStyles, verticalAlign: 'bottom', py: 1.5 }}>Senior</TableCell>
                            <TableCell align="right" sx={{ ...headerStyles, verticalAlign: 'bottom', py: 1.5 }}>PWD</TableCell>
                            <TableCell align="right" sx={{ ...headerStyles, verticalAlign: 'bottom', py: 1.5 }}>VIP</TableCell>
                            <TableCell align="right" sx={{ ...headerStyles, verticalAlign: 'bottom', py: 1.5 }}>Employee</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {transactions.map((transaction) => (
                            <Row
                                key={transaction.id}
                                transaction={transaction}
                                onViewDetails={onViewDetails}
                                getStatusColor={getStatusColor}
                                formatCurrency={formatCurrency}
                                formatDate={formatDate}
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

export default TransactionTable;
