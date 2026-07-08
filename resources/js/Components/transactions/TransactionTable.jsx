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
    Grid,
    Card
} from '@mui/material';
import VisibilityIcon from '@mui/icons-material/Visibility';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import { formatDate } from '../../utils/dateFormatter';

const Row = ({ transaction, onViewDetails, getStatusColor, formatCurrency, formatDate }) => {
    const [open, setOpen] = useState(false);
    const receivedAt = transaction.created_at;
    const transactionDisplayTime = transaction.transaction_timestamp || transaction.completed_at || transaction.created_at;
    const operationStatus = transaction.voided_at
        ? 'ERROR'
        : (['INVALID', 'WITH_ISSUES'].includes((transaction.validation_status || '').toUpperCase())
            ? 'ERROR'
            : ((transaction.validation_status || '').toUpperCase() === 'PENDING' ? 'PENDING' : 'RECONCILED'));
    const operationStatusColor = operationStatus === 'RECONCILED' ? 'success' : operationStatus === 'PENDING' ? 'warning' : 'error';

    return (
        <React.Fragment>
            <TableRow
                hover
                sx={{
                    '& > *': { borderBottom: 'unset' },
                    cursor: 'pointer',
                    transition: 'background-color 0.2s, opacity 0.2s',
                    '&:hover': { bgcolor: 'rgba(25, 118, 210, 0.02) !important' },
                    bgcolor: open ? 'rgba(25, 118, 210, 0.04)' : 'inherit',
                    opacity: transaction.voided_at ? 0.6 : 1
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
                            color: transaction.voided_at ? 'error.main' : 'primary.main',
                            fontWeight: 700,
                            letterSpacing: '0.02em',
                            textDecoration: transaction.voided_at ? 'line-through' : 'none'
                        }}>
                            {transaction.transaction_id.slice(0, 18)}...
                        </Typography>
                        {Boolean(Number(transaction.is_refunded)) && (
                            <Chip 
                                label="REFUNDED" 
                                size="small" 
                                color="warning" 
                                variant="outlined"
                                sx={{ 
                                    height: 16, 
                                    fontSize: '0.6rem', 
                                    fontWeight: 900,
                                    borderRadius: '4px',
                                    borderWidth: 1.5
                                }} 
                            />
                        )}
                        {transaction.voided_at && (
                            <Tooltip title={`Voided: ${transaction.void_reason || 'No reason provided'}`} arrow>
                                <Chip 
                                    label="VOIDED" 
                                    size="small" 
                                    color="error" 
                                    sx={{ 
                                        height: 16, 
                                        fontSize: '0.6rem', 
                                        fontWeight: 900,
                                        borderRadius: '4px'
                                    }} 
                                />
                            </Tooltip>
                        )}
                    </Stack>
                </TableCell>
                <TableCell sx={{ minWidth: 145 }}>
                    <Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: '11px', color: 'text.secondary', fontWeight: 700 }}>
                        {formatDate(transactionDisplayTime)}
                    </Typography>
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
                    <Tooltip title={transaction.voided_at ? "Voided transactions contribute ₱0.00 to reconciled totals" : ""} arrow>
                        <Typography variant="body2" sx={{ 
                            fontWeight: 800, 
                            fontFamily: 'monospace',
                            textDecoration: transaction.voided_at ? 'line-through' : 'none',
                            color: transaction.voided_at ? 'error.light' : 'inherit'
                        }}>
                            {transaction.voided_at ? formatCurrency(0) : formatCurrency(transaction.amount)}
                        </Typography>
                    </Tooltip>
                </TableCell>
                <TableCell align="left" sx={{ minWidth: 120 }}>
                    <Tooltip title={transaction.voided_at ? "Voided transactions contribute ₱0.00 to reconciled totals" : ""} arrow>
                        <Typography variant="body2" sx={{ 
                            fontWeight: 800, 
                            fontFamily: 'monospace', 
                            color: transaction.voided_at ? 'error.light' : 'primary.main',
                            textDecoration: transaction.voided_at ? 'line-through' : 'none'
                        }}>
                            {transaction.voided_at ? formatCurrency(0) : formatCurrency(transaction.net_sales)}
                        </Typography>
                    </Tooltip>
                </TableCell>
                <TableCell align="left" sx={{ minWidth: 130 }}>
                    <Tooltip title={transaction.voided_at ? "Voided transactions contribute ₱0.00 to reconciled totals" : ""} arrow>
                        <Typography variant="body2" sx={{
                            fontWeight: 800,
                            fontFamily: 'monospace',
                            color: transaction.voided_at ? 'error.light' : 'text.primary',
                            textDecoration: transaction.voided_at ? 'line-through' : 'none'
                        }}>
                            {transaction.voided_at ? formatCurrency(0) : formatCurrency(transaction.vatable_sales)}
                        </Typography>
                    </Tooltip>
                </TableCell>
                <TableCell align="left" sx={{ minWidth: 110 }}>
                    <Tooltip title={transaction.voided_at ? "Voided transactions contribute ₱0.00 to reconciled totals" : ""} arrow>
                        <Typography variant="body2" sx={{
                            fontWeight: 800,
                            fontFamily: 'monospace',
                            color: transaction.voided_at ? 'error.light' : 'text.secondary',
                            textDecoration: transaction.voided_at ? 'line-through' : 'none'
                        }}>
                            {transaction.voided_at ? formatCurrency(0) : formatCurrency(transaction.vat)}
                        </Typography>
                    </Tooltip>
                </TableCell>
                <TableCell align="center" sx={{ minWidth: 110 }}>
                    <Chip
                        label={transaction.validation_status || 'UNKNOWN'}
                        color={getStatusColor(transaction.validation_status)}
                        size="small"
                        sx={{ fontWeight: 800, fontSize: '0.65rem' }}
                    />
                </TableCell>
                <TableCell align="center" sx={{ minWidth: 110 }}>
                    <Chip
                        label={operationStatus}
                        color={operationStatusColor}
                        size="small"
                        sx={{ fontWeight: 800, fontSize: '0.65rem' }}
                    />
                </TableCell>
                <TableCell align="center" sx={{ minWidth: 70 }}>
                    <IconButton
                        size="small"
                        onClick={(e) => {
                            e.stopPropagation();
                            onViewDetails(transaction);
                        }}
                        sx={{ bgcolor: 'primary.50', color: 'primary.main' }}
                    >
                        <VisibilityIcon fontSize="small" />
                    </IconButton>
                </TableCell>
            </TableRow>
            <TableRow>
                <TableCell style={{ paddingBottom: 0, paddingTop: 0 }} colSpan={11}>
                    <Collapse in={open} timeout="auto" unmountOnExit>
                        <Box sx={{ py: 3, px: 4, bgcolor: 'rgba(248, 250, 252, 0.7)', borderTop: '1px solid', borderColor: 'divider' }}>
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
                                            <DetailItem label="Refund Amount" value={transaction.refund ? formatCurrency(transaction.refund) : '-'} />
                                        </Box>
                                        <Box>
                                            <DetailItem label="Tax Exempt" value={formatCurrency(transaction.tax_exempt)} />
                                            <DetailItem label="Other Tax" value="-" />
                                            <DetailItem
                                                label="Transaction Timestamp"
                                                value={
                                                    <Tooltip title={`Raw: ${transactionDisplayTime || 'N/A'}`} arrow>
                                                        <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.primary', cursor: 'help' }}>
                                                            {formatDate(transactionDisplayTime)}
                                                        </Typography>
                                                    </Tooltip>
                                                }
                                            />
                                            <DetailItem
                                                label="Received At"
                                                value={
                                                    <Tooltip title={`Server ingest time: ${receivedAt || 'N/A'}`} arrow>
                                                        <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.primary', cursor: 'help' }}>
                                                            {formatDate(receivedAt)}
                                                        </Typography>
                                                    </Tooltip>
                                                }
                                            />
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

const TransactionTable = ({ transactions, loading, page, rowsPerPage, totalCount, onPageChange, onRowsPerPageChange, onViewDetails, hidePagination = false }) => {
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
        if (status?.toUpperCase() === 'VOIDED') return 'error'; 
        switch (status?.toUpperCase()) {
            case 'VALID': return 'success';
            case 'INVALID': return 'error';
            case 'WITH_ISSUES': return 'warning';
            case 'PENDING': return 'info';
            case 'REFUNDED': return 'warning';
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

    // formatDate is now imported from utils/dateFormatter

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
        position: 'sticky',
        top: 0,
        zIndex: 2
    };

    return (
        <Box>
            <TableContainer sx={{ overflowX: 'auto', maxHeight: '70vh' }}>
                <Table size="small" stickyHeader>
                    <TableHead>
                        <TableRow>
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Transaction ID</TableCell>
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Transaction Date</TableCell>
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Receipt No</TableCell>
                            <TableCell sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Tenant / Terminal</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Gross Sales</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Net Sales</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>VATable Sales</TableCell>
                            <TableCell align="left" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>VAT</TableCell>
                            <TableCell align="center" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Audit</TableCell>
                            <TableCell align="center" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Status</TableCell>
                            <TableCell align="center" sx={{ ...headerStyles, verticalAlign: 'bottom', pb: 1.5 }}>Actions</TableCell>
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

            {!hidePagination && (
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
            )}
        </Box>
    );
};

export default TransactionTable;
