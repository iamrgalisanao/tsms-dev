import React, { useState, useEffect } from 'react';
import {
    Box,
    Card,
    CardContent,
    Typography,
    Tabs,
    Tab,
    Button,
    Alert,
    Stack,
    Grid,
    Divider
} from '@mui/material';
import { useLocation } from 'react-router-dom';
import ReceiptIcon from '@mui/icons-material/Receipt';
import SummarizeIcon from '@mui/icons-material/Summarize';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import HomeIcon from '@mui/icons-material/Home';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import SyncIcon from '@mui/icons-material/Sync';
import { Breadcrumbs, Link as MuiLink, CircularProgress } from '@mui/material';
import { useAuth } from '../Contexts/AuthContext';
import FilterBar from '../Components/transactions/FilterBar';
import TransactionTable from '../Components/transactions/TransactionTable';
import SummaryTable from '../Components/transactions/SummaryTable';
import TransactionDetailPanel from '../Components/transactions/TransactionDetailPanel';
import { transactionLogService } from '../services/transactionLogService';

const REPORTING_BASIS_STORAGE_KEY = 'transaction_logs_reporting_basis';

const StatCard = ({ label, value, color }) => (
    <Box>
        <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 800, display: 'block', fontSize: '0.65rem', textTransform: 'uppercase', letterSpacing: '0.1em', mb: 0.5 }}>
            {label}
        </Typography>
        <Typography variant="h5" sx={{ fontWeight: 950, color: color, letterSpacing: '-0.02em', fontFamily: 'monospace' }}>
            {value || '-'}
        </Typography>
    </Box>
);

const formatCurrency = (amount) => {
    if (!amount && amount !== 0) return '-';
    return '₱' + new Intl.NumberFormat('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
};

const formatLocalDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

const TransactionLogsPage = () => {
    const location = useLocation();
    const [activeTab, setActiveTab] = useState('detailed');
    const getInitialReportingBasis = () => {
        const savedBasis = window.localStorage?.getItem(REPORTING_BASIS_STORAGE_KEY);
        return ['completed', 'transaction', 'created'].includes(savedBasis) ? savedBasis : 'transaction';
    };

    const getInitialFilters = () => {
        const query = new URLSearchParams(location.search);
        const today = formatLocalDate(new Date());

        return {
            status: query.get('status') || '',
            terminal_id: query.get('terminal_id') || '',
            tenant_id: query.get('tenant_id') || '',
            date_from: query.get('date_from') || today,
            date_to: query.get('date_to') || today,
            transaction_id: query.get('transaction_id') || query.get('search') || '',
            date_basis: query.get('date_basis') || getInitialReportingBasis()
        };
    };

    const [filters, setFilters] = useState({
        ...getInitialFilters()
    });

    // Shared sort direction for date-based ordering (detailed & summary)
    const [sortDirection, setSortDirection] = useState('desc');

    const [transactions, setTransactions] = useState([]);
    const [summary, setSummary] = useState([]);
    const [grandTotal, setGrandTotal] = useState(null);
    const [dateBasisDiscrepancy, setDateBasisDiscrepancy] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [reconciling, setReconciling] = useState(false);
    const { user } = useAuth();

    const userRole = user?.role?.toUpperCase() || (user?.roles?.[0]?.name || user?.roles?.[0] || '').toUpperCase();
    const canReconcile = ['ADMIN', 'FINANCE', 'COMMERCIAL'].includes(userRole);

    const [page, setPage] = useState(0);
    const [rowsPerPage, setRowsPerPage] = useState(15);
    const [totalCount, setTotalCount] = useState(0);

    const [detailPanelOpen, setDetailPanelOpen] = useState(false);
    const [selectedTransaction, setSelectedTransaction] = useState(null);

    useEffect(() => {
        loadData();
    }, [activeTab, filters, page, rowsPerPage, sortDirection]);

    const loadData = async () => {
        setLoading(true);
        setError(null);

        try {
            if (activeTab === 'summary' && (!filters.date_from || !filters.date_to)) {
                setSummary([]);
                setTotalCount(0);
                setGrandTotal(null);
                setDateBasisDiscrepancy(null);
                setError('Summary View requires both From and To dates. Select a preset or enter a custom date range.');
                return;
            }

            // Include sort_direction so backend can control ASC/DESC
            const filtersWithSort = {
                ...filters,
                sort_direction: sortDirection
            };

            const cleanFilters = Object.fromEntries(
                Object.entries(filtersWithSort).filter(([_, value]) => value !== '')
            );

            if (activeTab === 'detailed') {
                const response = await transactionLogService.getTransactions(
                    cleanFilters,
                    page + 1,
                    rowsPerPage
                );
                setTransactions(response.data || []);
                setTotalCount(response.total || 0);
                setDateBasisDiscrepancy(null);
            } else {
                const response = await transactionLogService.getSummary(
                    cleanFilters,
                    page + 1,
                    rowsPerPage
                );
                // Extract summary list and global grand total from structured response
                const summaryData = response.summary || {};
                setSummary(summaryData.data || []);
                setTotalCount(summaryData.total || 0);
                setGrandTotal(response.grandTotal || null);
                setDateBasisDiscrepancy(response.dateBasisDiscrepancy || null);
            }
        } catch (err) {
            console.error('Error loading data:', err);
            setError('Failed to load transaction data');
        } finally {
            setLoading(false);
        }
    };

    const handleFilterChange = (newFilters) => {
        if (newFilters.date_basis) {
            window.localStorage?.setItem(REPORTING_BASIS_STORAGE_KEY, newFilters.date_basis);
        }
        setFilters(newFilters);
        setPage(0);
    };

    const handleReset = () => {
        const today = formatLocalDate(new Date());

        setFilters({
            status: '',
            terminal_id: '',
            tenant_id: '',
            date_from: today,
            date_to: today,
            transaction_id: '',
            date_basis: 'transaction'
        });
        window.localStorage?.setItem(REPORTING_BASIS_STORAGE_KEY, 'transaction');
        setSortDirection('desc');
        setPage(0);
    };

    const handleTabChange = (event, newValue) => {
        setActiveTab(newValue);
        setPage(0);
    };

    const handleExport = async () => {
        try {
            const cleanFilters = Object.fromEntries(
                Object.entries({
                    ...filters,
                    date_basis: filters.date_basis || 'transaction'
                }).filter(([_, value]) => value !== '')
            );
            await transactionLogService.exportToExcel(cleanFilters);
        } catch (err) {
            console.error('Error exporting:', err);
            setError('Failed to export data');
        }
    };

    const handleReconcile = async () => {
        setReconciling(true);
        setError(null);
        setSuccess(null);

        try {
            const response = await transactionLogService.reconcile();
            if (response.status === 'success') {
                setSuccess(response.message || 'Reconciliation completed successfully.');
                loadData(); // reload page data
            } else {
                setError(response.message || 'Failed to trigger reconciliation.');
            }
        } catch (err) {
            console.error('Error during reconciliation:', err);
            setError(err.response?.data?.message || 'Failed to run manual reconciliation.');
        } finally {
            setReconciling(false);
        }
    };

    return (
        <Box sx={{ pb: 8 }}>
            {/* Unified Breadcrumbs */}
            <Box sx={{ py: 3 }}>
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        SYSTEM
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>TRANSACTION ARCHIVE</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} sx={{ mb: 5 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(25, 118, 210, 0.25)' }}>
                                <ReceiptIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Transaction Logs
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Deep-dive into historical records and financial data flow.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Stack direction="row" spacing={1.5}>
                        <Button
                            variant="text"
                            color="inherit"
                            startIcon={activeTab === 'detailed' ? <SummarizeIcon /> : <ReceiptIcon />}
                            sx={{ borderRadius: 3, px: 2, py: 1.2, fontWeight: 700, textTransform: 'none' }}
                            onClick={() => setActiveTab(activeTab === 'detailed' ? 'summary' : 'detailed')}
                        >
                            {activeTab === 'detailed' ? 'Switch to Summary' : 'Switch to Detailed'}
                        </Button>
                        <Button
                            variant="outlined"
                            startIcon={<FileDownloadIcon />}
                            onClick={handleExport}
                            sx={{ borderRadius: 3, px: 2.5, py: 1.2, fontWeight: 700, textTransform: 'none' }}
                        >
                            Export
                        </Button>
                        {canReconcile && (
                            <Button
                                variant="contained"
                                color="warning"
                                startIcon={reconciling ? <CircularProgress size={20} color="inherit" /> : <SyncIcon />}
                                onClick={handleReconcile}
                                disabled={reconciling}
                                sx={{ borderRadius: 3, px: 3, py: 1.2, fontWeight: 800, textTransform: 'none', boxShadow: '0 4px 15px rgba(237, 108, 2, 0.3)' }}
                            >
                                {reconciling ? 'Reconciling...' : 'Manual Reconciliation'}
                            </Button>
                        )}
                    </Stack>
                </Stack>

                <FilterBar
                    filters={filters}
                    onFilterChange={handleFilterChange}
                    onReset={handleReset}
                />
            </Box>

            {/* Success Alert */}
            {success && (
                <Alert severity="success" sx={{ mb: 3, borderRadius: '12px', whiteSpace: 'pre-line' }} onClose={() => setSuccess(null)}>
                    {success}
                </Alert>
            )}

            {/* Error Alert */}
            {error && (
                <Alert severity="error" sx={{ mb: 3, borderRadius: '12px', whiteSpace: 'pre-line' }} onClose={() => setError(null)}>
                    {error}
                </Alert>
            )}

            {activeTab === 'summary' && filters.date_basis === 'transaction' && dateBasisDiscrepancy && (
                <Alert
                    severity={Math.abs(dateBasisDiscrepancy.net_difference || 0) > 0 ? 'warning' : 'info'}
                    sx={{ mb: 3, borderRadius: '12px' }}
                    action={
                        <Button
                            color="inherit"
                            size="small"
                            onClick={() => handleFilterChange({ ...filters, date_basis: 'completed' })}
                            sx={{ fontWeight: 800, whiteSpace: 'nowrap' }}
                        >
                            Switch to Completed Date
                        </Button>
                    }
                >
                    Transaction Date: {dateBasisDiscrepancy.transaction_date_count?.toLocaleString() || 0}. Completed Date: {dateBasisDiscrepancy.completed_date_count?.toLocaleString() || 0}. Net difference: {(dateBasisDiscrepancy.net_difference || 0).toLocaleString()}. Event-date rows finalized outside range: {dateBasisDiscrepancy.event_date_rows_completed_outside_range?.toLocaleString() || 0}; finalized rows with event date outside range: {dateBasisDiscrepancy.completed_date_rows_with_event_outside_range?.toLocaleString() || 0}.
                </Alert>
            )}

            {/* Main Content Card */}
            <Card
                sx={{
                    borderRadius: 3,
                    overflow: 'hidden',
                    boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
                    border: 1,
                    borderColor: 'divider'
                }}
            >
                {/* Tabs */}
                <Box sx={{ borderBottom: 1, borderColor: 'divider', bgcolor: 'background.paper' }}>
                    <Tabs
                        value={activeTab}
                        onChange={handleTabChange}
                        sx={{
                            px: 3,
                            '& .MuiTab-root': {
                                textTransform: 'none',
                                fontWeight: 600,
                                fontSize: '14px',
                                minHeight: 56
                            },
                            '& .MuiTabs-indicator': {
                                height: 3,
                                borderRadius: '3px 3px 0 0'
                            }
                        }}
                    >
                        <Tab
                            icon={<ReceiptIcon />}
                            iconPosition="start"
                            label="Detailed View"
                            value="detailed"
                        />
                        <Tab
                            icon={<SummarizeIcon />}
                            iconPosition="start"
                            label="Summary View"
                            value="summary"
                        />
                    </Tabs>
                </Box>

                <CardContent sx={{ p: 0 }}>
                    <Box>
                        {activeTab === 'detailed' ? (
                            <TransactionTable
                                transactions={transactions}
                                loading={loading}
                                page={page}
                                rowsPerPage={rowsPerPage}
                                totalCount={totalCount}
                                onPageChange={(e, newPage) => setPage(newPage)}
                                onRowsPerPageChange={(e) => {
                                    setRowsPerPage(parseInt(e.target.value, 10));
                                    setPage(0);
                                }}
                                onViewDetails={(tx) => {
                                    setSelectedTransaction(tx);
                                    setDetailPanelOpen(true);
                                }}
                            />
                        ) : (
                            <Box>
                                {/* Summary Stats Cards */}
                                {grandTotal && (
                                    <Box sx={{ p: 4, bgcolor: 'rgba(248, 250, 252, 0.5)', borderBottom: '1px solid', borderColor: 'divider' }}>
                                        <Grid container spacing={4}>
                                            <Grid item xs={12} sm={6} md={3}>
                                                <StatCard 
                                                    label="Gross (Valid)" 
                                                    value={formatCurrency(grandTotal.gross)} 
                                                    color="text.primary"
                                                />
                                            </Grid>
                                            <Grid item xs={12} sm={6} md={3}>
                                                <StatCard 
                                                    label="Net (Reconciled)" 
                                                    value={formatCurrency(grandTotal.net)} 
                                                    color="primary.main"
                                                />
                                            </Grid>
                                            <Grid item xs={12} sm={6} md={3}>
                                                <StatCard 
                                                    label="Total Refunds" 
                                                    value={formatCurrency(grandTotal.refund)} 
                                                    color="error.main"
                                                />
                                            </Grid>
                                            <Grid item xs={12} sm={6} md={3}>
                                                <StatCard 
                                                    label="Total Transactions" 
                                                    value={grandTotal.tx_count?.toLocaleString()} 
                                                    color="text.secondary"
                                                />
                                            </Grid>
                                        </Grid>
                                    </Box>
                                )}

                                <SummaryTable
                                    summary={summary}
                                    grandTotal={grandTotal}
                                    loading={loading}
                                    page={page}
                                    rowsPerPage={rowsPerPage}
                                    totalCount={totalCount}
                                    onPageChange={(e, newPage) => setPage(newPage)}
                                    onRowsPerPageChange={(e) => {
                                        setRowsPerPage(parseInt(e.target.value, 10));
                                        setPage(0);
                                    }}
                                    sortDirection={sortDirection}
                                    onToggleSortDirection={() =>
                                        setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'))
                                    }
                                />
                            </Box>
                        )}
                    </Box>
                </CardContent>
            </Card>

            {/* Transaction Detail Side Panel */}
            <TransactionDetailPanel
                open={detailPanelOpen}
                transaction={selectedTransaction}
                onClose={() => setDetailPanelOpen(false)}
            />
        </Box>
    );
};

export default TransactionLogsPage;
