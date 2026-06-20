import React, { useState, useEffect } from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Paper,
    Typography,
    Button,
    Box,
    Chip,
    CircularProgress,
    Stack,
    Pagination,
    Tooltip
} from '@mui/material';
import VisibilityIcon from '@mui/icons-material/Visibility';
import ReceiptIcon from '@mui/icons-material/Receipt';
import { formatDate } from '../../utils/dateFormatter';
import { useTheme } from '@mui/material/styles';

const RecentTransactionsTable = ({ transactions, loading, onViewDetails }) => {
    const [page, setPage] = useState(1);
    const pageSize = 10;
    const theme = useTheme();

    useEffect(() => {
        // Reset to first page whenever the data set changes
        setPage(1);
    }, [transactions]);

    if (loading) {
        return (
            <Paper sx={{ p: 8, display: 'flex', justifyContent: 'center', borderRadius: '24px', background: 'rgba(255,255,255,0.4)', backdropFilter: 'blur(20px)' }}>
                <CircularProgress />
            </Paper>
        );
    }

    const headerStyles = {
        fontWeight: 800,
        fontSize: '0.68rem',
        textTransform: 'uppercase',
        letterSpacing: '0.08em',
        color: '#EB342E',
        py: 2,
        bgcolor: 'transparent',
        borderBottom: '2.5px solid',
        borderColor: 'rgba(235, 52, 46, 0.15)'
    };

    const total = transactions.length;
    const pageCount = total > 0 ? Math.ceil(total / pageSize) : 1;
    const startIndex = (page - 1) * pageSize;
    const endIndex = startIndex + pageSize;
    const pageItems = transactions.slice(startIndex, endIndex);

    return (
        <TableContainer
            sx={{
                width: '100%',
                overflow: 'hidden',
                bgcolor: 'transparent',
            }}
        >
            <Table sx={{ minWidth: 650, width: '100%' }} aria-label="recent transactions table">
                <TableHead sx={{ bgcolor: 'rgba(255, 255, 255, 0.2)' }}>
                    <TableRow>
                        <TableCell sx={headerStyles}>ID</TableCell>
                        <TableCell sx={headerStyles}>Transaction ID</TableCell>
                        <TableCell sx={headerStyles}>Tenant / Terminal</TableCell>
                        <TableCell sx={headerStyles}>Net Sales</TableCell>
                        <TableCell sx={headerStyles}>Timestamp</TableCell>
                        <TableCell align="right" sx={headerStyles}>Actions</TableCell>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {total === 0 ? (
                        <TableRow>
                            <TableCell colSpan={6} align="center" sx={{ py: 8 }}>
                                <Typography sx={{ color: 'text.secondary', fontWeight: 600 }}>No recent transactions found.</Typography>
                            </TableCell>
                        </TableRow>
                    ) : (
                        pageItems.map((tx) => (
                            <TableRow key={tx.id} sx={{ '&:hover': { bgcolor: 'rgba(29, 67, 155, 0.03)' }, transition: 'background-color 0.2s', borderBottom: '1px solid rgba(0,0,0,0.03)' }}>
                                <TableCell sx={{ fontWeight: 800, color: 'text.disabled', fontSize: '11px', fontFamily: 'monospace' }}>#{tx.id}</TableCell>
                                <TableCell>
                                    <Typography
                                        sx={{
                                            fontFamily: 'monospace',
                                            fontSize: '11px',
                                            fontWeight: 800,
                                            color: 'primary.main',
                                            bgcolor: 'rgba(29, 67, 155, 0.05)',
                                            px: 1,
                                            py: 0.5,
                                            borderRadius: 1.5,
                                            display: 'inline-block'
                                        }}
                                    >
                                        {tx.transaction_id}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography sx={{ fontWeight: 800, color: '#0F172A', fontSize: '0.85rem' }}>
                                        {tx.tenant?.trade_name || 'N/A'}
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 600, fontFamily: 'monospace', fontSize: '10px' }}>
                                        {tx.terminal?.serial_number || tx.display_tenant_code}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography sx={{ fontWeight: 800, color: '#0F172A', fontFamily: 'monospace', fontSize: '0.9rem', fontVariantNumeric: 'tabular-nums' }}>
                                        ₱{new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2 }).format(tx.net_sales || 0)}
                                    </Typography>
                                </TableCell>
                                 <TableCell>
                                    <Tooltip title={`Transaction Timestamp (Raw): ${tx.transaction_timestamp || 'N/A'}`} arrow>
                                        <Typography sx={{ color: 'text.secondary', fontSize: '11px', fontWeight: 600, fontFamily: 'monospace', cursor: 'help' }}>
                                            {formatDate(tx.transaction_timestamp)}
                                        </Typography>
                                    </Tooltip>
                                 </TableCell>
                                <TableCell align="right">
                                    <Button
                                        variant="outlined"
                                        size="small"
                                        onClick={() => onViewDetails(tx)}
                                        sx={{
                                            borderRadius: '8px',
                                            textTransform: 'none',
                                            fontWeight: 800,
                                            px: 2,
                                            py: 0.75,
                                            bgcolor: 'white',
                                            color: 'text.primary',
                                            borderColor: 'divider',
                                            boxShadow: 'none',
                                            '&:hover': {
                                                bgcolor: 'primary.main',
                                                color: 'white',
                                                borderColor: 'primary.main',
                                                boxShadow: '0 4px 12px rgba(29, 67, 155, 0.15)'
                                            }
                                        }}
                                    >
                                        View Details
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </Table>
            <Box
                sx={{
                    px: 3,
                    py: 2,
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    bgcolor: 'rgba(255, 255, 255, 0.2)',
                    borderTop: '1px solid',
                    borderColor: 'rgba(0,0,0,0.04)',
                }}
            >
                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 600 }}>
                    {total > 0
                        ? `Showing ${Math.min(startIndex + 1, total)}-${Math.min(endIndex, total)} of ${total} transaction${total !== 1 ? 's' : ''}`
                        : 'No recent transactions in this window'}
                </Typography>
                <Pagination
                    count={pageCount}
                    page={page}
                    onChange={(e, value) => setPage(value)}
                    color="primary"
                    size="small"
                />
            </Box>
        </TableContainer>
    );
};

export default RecentTransactionsTable;
