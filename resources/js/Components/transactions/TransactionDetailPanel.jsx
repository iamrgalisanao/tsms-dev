import React, { useState, useEffect } from 'react';
import {
    Drawer,
    Box,
    Typography,
    IconButton,
    Tabs,
    Tab,
    Divider,
    Stack,
    Chip,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Paper,
    CircularProgress,
    Card
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorIcon from '@mui/icons-material/Error';
import PendingIcon from '@mui/icons-material/Pending';
import { transactionLogService } from '../../services/transactionLogService';
import { formatDate } from '../../utils/dateFormatter';

const TransactionDetailPanel = ({ open, onClose, transaction }) => {
    const [activeTab, setActiveTab] = useState(0);
    const [details, setDetails] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (open && transaction) {
            loadDetails();
        }
    }, [open, transaction]);

    const loadDetails = async () => {
        setLoading(true);
        try {
            const data = await transactionLogService.getTransactionDetails(transaction.id);
            setDetails(data);
        } catch (error) {
            console.error('Error loading transaction details:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleTabChange = (event, newValue) => {
        setActiveTab(newValue);
    };

    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(amount || 0);
    };

    // formatDate is now imported from utils/dateFormatter

    const getStatusColor = (status) => {
        switch (status?.toUpperCase()) {
            case 'VALID':
            case 'SUCCESS':
            case 'COMPLETED':
                return 'success';
            case 'INVALID':
            case 'FAILED':
            case 'ERROR':
                return 'error';
            case 'WITH_ISSUES':
            case 'WARNING':
                return 'warning';
            case 'PENDING':
            case 'PROCESSING':
                return 'info';
            default:
                return 'default';
        }
    };

    const renderOverview = () => (
        <Box>
            <Typography variant="h6" sx={{ fontWeight: 'bold', mb: 3 }}>
                Transaction Overview
            </Typography>

            <Stack spacing={2}>
                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Transaction ID
                    </Typography>
                    <Typography variant="body1" sx={{ fontFamily: 'monospace', mt: 0.5 }}>
                        {transaction?.transaction_id}
                    </Typography>
                </Box>

                {transaction?.receipt_no && (
                    <Box>
                        <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                            Receipt Number
                        </Typography>
                        <Typography variant="body1" sx={{ fontFamily: 'monospace', mt: 0.5 }}>
                            {transaction.receipt_no}
                        </Typography>
                    </Box>
                )}

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Status
                    </Typography>
                    <Box sx={{ mt: 0.5 }}>
                        <Chip
                            label={transaction?.validation_status || 'PENDING'}
                            color={getStatusColor(transaction?.validation_status)}
                            size="small"
                            sx={{ fontWeight: 'bold' }}
                        />
                    </Box>
                </Box>

                <Divider />

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Tenant
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5 }}>
                        {transaction?.terminal?.tenant?.trade_name || 'N/A'}
                    </Typography>
                </Box>

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Terminal
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5 }}>
                        {transaction?.terminal?.serial_number || 'N/A'}
                        {transaction?.terminal?.machine_number && ` (Machine: ${transaction.terminal.machine_number})`}
                    </Typography>
                </Box>

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Provider
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5 }}>
                        {transaction?.provider?.name || 'N/A'}
                    </Typography>
                </Box>

                <Divider />

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Gross Sales
                    </Typography>
                    <Typography variant="body1" sx={{ fontWeight: 'bold', mt: 0.5 }}>
                        {formatCurrency(transaction?.amount)}
                    </Typography>
                </Box>

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Net Sales
                    </Typography>
                    <Typography variant="body1" sx={{ fontWeight: 'bold', mt: 0.5 }}>
                        {formatCurrency(transaction?.net_sales)}
                    </Typography>
                </Box>

                <Divider />

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Job Attempts
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5 }}>
                        {transaction?.job_attempts || 0}
                    </Typography>
                </Box>

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Transaction Timestamp
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5, fontWeight: 'bold' }}>
                        {formatDate(transaction?.transaction_timestamp)}
                    </Typography>
                </Box>

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        System Arrival Time (Created At)
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5 }}>
                        {formatDate(transaction?.created_at)}
                    </Typography>
                </Box>

                <Box>
                    <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                        Completed At
                    </Typography>
                    <Typography variant="body1" sx={{ mt: 0.5 }}>
                        {formatDate(transaction?.completed_at)}
                    </Typography>
                </Box>

                {transaction?.last_retry_at && (
                    <Box>
                        <Typography variant="caption" sx={{ color: 'grey.500', textTransform: 'uppercase', fontWeight: 'bold' }}>
                            Last Retry
                        </Typography>
                        <Typography variant="body1" sx={{ mt: 0.5 }}>
                            {formatDate(transaction.last_retry_at)}
                        </Typography>
                    </Box>
                )}
            </Stack>
        </Box>
    );

    const renderPayload = () => (
        <Box>
            <Typography variant="h6" sx={{ fontWeight: 'bold', mb: 3 }}>
                Transaction Payload
            </Typography>
            <Paper sx={{ p: 2, bgcolor: 'grey.50', borderRadius: 2 }}>
                <pre style={{ margin: 0, overflow: 'auto', fontSize: '12px', fontFamily: 'monospace' }}>
                    {JSON.stringify(details?.payload || transaction, null, 2)}
                </pre>
            </Paper>
        </Box>
    );

    const renderRetryHistory = () => (
        <Box>
            <Typography variant="h6" sx={{ fontWeight: 'bold', mb: 3 }}>
                Retry History
            </Typography>
            {details?.retry_history && details.retry_history.length > 0 ? (
                <Stack spacing={2}>
                    {details.retry_history.map((retry, index) => (
                        <Card key={index} sx={{ p: 2, bgcolor: retry.status === 'success' ? 'success.50' : 'error.50' }}>
                            <Stack direction="row" alignItems="center" spacing={2}>
                                {retry.status === 'success' ? (
                                    <CheckCircleIcon color="success" />
                                ) : (
                                    <ErrorIcon color="error" />
                                )}
                                <Box sx={{ flex: 1 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 'bold' }}>
                                        Attempt {retry.attempt}
                                    </Typography>
                                    <Typography variant="caption" color="text.secondary">
                                        Status: {retry.status} • {formatDate(retry.attempted_at)}
                                    </Typography>
                                    {retry.error && (
                                        <Typography variant="caption" color="error" sx={{ display: 'block', mt: 0.5 }}>
                                            Error: {retry.error}
                                        </Typography>
                                    )}
                                </Box>
                            </Stack>
                        </Card>
                    ))}
                </Stack>
            ) : (
                <Typography variant="body2" color="text.secondary">
                    No retry history available
                </Typography>
            )}
        </Box>
    );

    const renderSubmissionEvents = () => (
        <Box>
            <Typography variant="h6" sx={{ fontWeight: 'bold', mb: 3 }}>
                Linked Submission Events
            </Typography>
            {details?.submission_events && details.submission_events.length > 0 ? (
                <TableContainer component={Paper}>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                <TableCell sx={{ fontWeight: 'bold' }}>Submission UUID</TableCell>
                                <TableCell sx={{ fontWeight: 'bold' }}>Status</TableCell>
                                <TableCell sx={{ fontWeight: 'bold' }}>Created At</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {details.submission_events.map((event, index) => (
                                <TableRow key={index}>
                                    <TableCell sx={{ fontFamily: 'monospace', fontSize: '12px' }}>
                                        {event.submission_uuid}
                                    </TableCell>
                                    <TableCell>
                                        <Chip
                                            label={event.status}
                                            color={getStatusColor(event.status)}
                                            size="small"
                                        />
                                    </TableCell>
                                    <TableCell>{formatDate(event.created_at)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableContainer>
            ) : (
                <Typography variant="body2" color="text.secondary">
                    No submission events found
                </Typography>
            )}
        </Box>
    );

    const renderJobTags = () => (
        <Box>
            <Typography variant="h6" sx={{ fontWeight: 'bold', mb: 3 }}>
                Horizon Job Tags
            </Typography>
            {details?.horizon_job_tags && details.horizon_job_tags.length > 0 ? (
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                    {details.horizon_job_tags.map((tag, index) => (
                        <Chip
                            key={index}
                            label={tag}
                            variant="outlined"
                            size="small"
                            sx={{ fontFamily: 'monospace' }}
                        />
                    ))}
                </Stack>
            ) : (
                <Typography variant="body2" color="text.secondary">
                    No job tags available
                </Typography>
            )}
        </Box>
    );

    return (
        <Drawer
            anchor="right"
            open={open}
            onClose={onClose}
            PaperProps={{
                sx: { width: 600 }
            }}
        >
            <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                {/* Header */}
                <Box sx={{ p: 3, borderBottom: 1, borderColor: 'divider' }}>
                    <Stack direction="row" alignItems="center" justifyContent="space-between">
                        <Typography variant="h5" sx={{ fontWeight: 'bold' }}>
                            Transaction Details
                        </Typography>
                        <IconButton onClick={onClose} size="small">
                            <CloseIcon />
                        </IconButton>
                    </Stack>
                </Box>

                {/* Tabs */}
                <Box sx={{ borderBottom: 1, borderColor: 'divider' }}>
                    <Tabs value={activeTab} onChange={handleTabChange} variant="scrollable" scrollButtons="auto">
                        <Tab label="Overview" />
                        <Tab label="Payload" />
                        <Tab label="Retry History" />
                        <Tab label="Submissions" />
                        <Tab label="Job Tags" />
                    </Tabs>
                </Box>

                {/* Content */}
                <Box sx={{ flex: 1, overflow: 'auto', p: 3 }}>
                    {loading ? (
                        <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: 200 }}>
                            <CircularProgress />
                        </Box>
                    ) : (
                        <>
                            {activeTab === 0 && renderOverview()}
                            {activeTab === 1 && renderPayload()}
                            {activeTab === 2 && renderRetryHistory()}
                            {activeTab === 3 && renderSubmissionEvents()}
                            {activeTab === 4 && renderJobTags()}
                        </>
                    )}
                </Box>
            </Box>
        </Drawer>
    );
};

export default TransactionDetailPanel;
