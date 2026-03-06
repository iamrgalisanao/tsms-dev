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
    Stack
} from '@mui/material';
import ReceiptIcon from '@mui/icons-material/Receipt';
import SummarizeIcon from '@mui/icons-material/Summarize';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import HomeIcon from '@mui/icons-material/Home';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import { Breadcrumbs, Link as MuiLink } from '@mui/material';
import FilterBar from '../Components/transactions/FilterBar';
import TransactionTable from '../Components/transactions/TransactionTable';
import SummaryTable from '../Components/transactions/SummaryTable';
import TransactionDetailPanel from '../Components/transactions/TransactionDetailPanel';
import { transactionLogService } from '../services/transactionLogService';

const TransactionLogsPage = () => {
    const [activeTab, setActiveTab] = useState('detailed');
    const [filters, setFilters] = useState({
        status: '',
        terminal_id: '',
        tenant_id: '',
        date_from: '',
        date_to: '',
        transaction_id: '',
        date_basis: 'completed'
    });

    // Shared sort direction for date-based ordering (detailed & summary)
    const [sortDirection, setSortDirection] = useState('desc');

    const [transactions, setTransactions] = useState([]);
    const [summary, setSummary] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

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
            } else {
                const response = await transactionLogService.getSummary(
                    cleanFilters,
                    page + 1,
                    rowsPerPage
                );
                setSummary(response.data || []);
                setTotalCount(response.total || 0);
            }
        } catch (err) {
            console.error('Error loading data:', err);
            setError('Failed to load transaction data');
        } finally {
            setLoading(false);
        }
    };

    const handleFilterChange = (newFilters) => {
        setFilters(newFilters);
        setPage(0);
    };

    const handleReset = () => {
        setFilters({
            status: '',
            terminal_id: '',
            tenant_id: '',
            date_from: '',
            date_to: '',
            transaction_id: '',
            date_basis: 'completed'
        });
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
                Object.entries(filters).filter(([_, value]) => value !== '')
            );
            await transactionLogService.exportToExcel(cleanFilters);
        } catch (err) {
            console.error('Error exporting:', err);
            setError('Failed to export data');
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
                            variant="outlined"
                            color="inherit"
                            startIcon={activeTab === 'detailed' ? <SummarizeIcon /> : <ReceiptIcon />}
                            sx={{ borderRadius: 3, px: 2.5, py: 1.2, fontWeight: 700, borderColor: 'divider', textTransform: 'none', bgcolor: 'white' }}
                            onClick={() => setActiveTab(activeTab === 'detailed' ? 'summary' : 'detailed')}
                        >
                            {activeTab === 'detailed' ? 'Switch to Summary' : 'Switch to Detailed'}
                        </Button>
                        <Button
                            variant="contained"
                            startIcon={<FileDownloadIcon />}
                            onClick={handleExport}
                            sx={{ borderRadius: 3, px: 3, py: 1.2, fontWeight: 800, textTransform: 'none', boxShadow: '0 4px 15px rgba(25, 118, 210, 0.3)' }}
                        >
                            Export Archive
                        </Button>
                    </Stack>
                </Stack>

                <FilterBar
                    filters={filters}
                    onFilterChange={handleFilterChange}
                    onReset={handleReset}
                />
            </Box>

            {/* Error Alert */}
            {error && (
                <Alert severity="error" sx={{ mb: 3, borderRadius: '12px' }} onClose={() => setError(null)}>
                    {error}
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
                            <SummaryTable
                                summary={summary}
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
