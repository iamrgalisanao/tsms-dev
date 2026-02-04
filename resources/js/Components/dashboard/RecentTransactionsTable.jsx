import React from 'react';
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
    Link,
    IconButton,
    CircularProgress,
    Stack
} from '@mui/material';
import ForwardIcon from '@mui/icons-material/Forward';
import LaunchIcon from '@mui/icons-material/Launch';
import VisibilityIcon from '@mui/icons-material/Visibility';
import ReceiptIcon from '@mui/icons-material/Receipt';

const RecentTransactionsTable = React.memo(({ transactions, loading, onForward }) => {
    if (loading) {
        return (
            <Paper sx={{ p: 8, display: 'flex', justifyContent: 'center', borderRadius: '32px' }}>
                <CircularProgress />
            </Paper>
        );
    }

    const headerStyles = {
        fontWeight: 800,
        fontSize: '0.65rem',
        textTransform: 'uppercase',
        letterSpacing: '0.1em',
        color: '#EB342E',
        py: 2,
        bgcolor: 'white',
        borderBottom: '2px solid',
        borderColor: 'divider'
    };

    return (
        <TableContainer component={Paper} sx={{ borderRadius: '24px', overflow: 'hidden', boxShadow: '0 8px 32px rgba(0,0,0,0.06)', border: '1px solid', borderColor: 'divider' }}>
            <Box sx={{ p: 3, px: 4, display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '1px solid', borderColor: 'grey.50', bgcolor: 'white' }}>
                <Stack direction="row" spacing={1.5} alignItems="center">
                    <ReceiptIcon sx={{ color: 'primary.main', fontSize: 24 }} />
                    <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.02em' }}>
                        Recent Activity
                    </Typography>
                </Stack>
                <Button
                    href="/transactions"
                    size="small"
                    sx={{
                        fontWeight: 800,
                        textTransform: 'uppercase',
                        fontSize: '0.7rem',
                        letterSpacing: '0.05em',
                        color: 'primary.main'
                    }}
                >
                    View Archive
                </Button>
            </Box>
            <Table sx={{ minWidth: 650 }} aria-label="recent transactions table">
                <TableHead>
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
                    {transactions.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={6} align="center" sx={{ py: 8 }}>
                                <Typography sx={{ color: 'grey.400', fontWeight: 500 }}>No recent transactions found.</Typography>
                            </TableCell>
                        </TableRow>
                    ) : (
                        transactions.map((tx) => (
                            <TableRow key={tx.id} sx={{ '&:hover': { bgcolor: 'rgba(25, 118, 210, 0.02)' }, transition: 'background-color 0.2s' }}>
                                <TableCell sx={{ fontWeight: 800, color: 'text.disabled', fontSize: '11px', fontFamily: 'monospace' }}>#{tx.id}</TableCell>
                                <TableCell>
                                    <Typography
                                        sx={{
                                            fontFamily: 'monospace',
                                            fontSize: '11px',
                                            fontWeight: 800,
                                            color: 'primary.main',
                                            bgcolor: 'rgba(25, 118, 210, 0.05)',
                                            px: 1,
                                            py: 0.5,
                                            borderRadius: 1,
                                            display: 'inline-block'
                                        }}
                                    >
                                        {tx.transaction_id}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography sx={{ fontWeight: 800, color: 'text.primary', fontSize: '0.85rem' }}>
                                        {tx.tenant?.trade_name || 'N/A'}
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.disabled', fontWeight: 700, fontFamily: 'monospace', fontSize: '10px' }}>
                                        {tx.terminal?.serial_number || tx.display_tenant_code}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography sx={{ fontWeight: 950, color: 'text.primary', fontFamily: 'monospace', fontSize: '0.9rem' }}>
                                        ₱{new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2 }).format(tx.net_sales || 0)}
                                    </Typography>
                                </TableCell>
                                <TableCell>
                                    <Typography sx={{ color: 'text.secondary', fontSize: '11px', fontWeight: 700, fontFamily: 'monospace' }}>
                                        {tx.transaction_timestamp || 'N/A'}
                                    </Typography>
                                </TableCell>
                                <TableCell align="right">
                                    <Button
                                        variant="contained"
                                        size="small"
                                        startIcon={<VisibilityIcon />}
                                        onClick={() => onForward(tx)}
                                        sx={{
                                            borderRadius: '8px',
                                            textTransform: 'none',
                                            fontWeight: 800,
                                            px: 2,
                                            py: 0.75,
                                            bgcolor: 'white',
                                            color: 'text.primary',
                                            border: '1px solid',
                                            borderColor: 'divider',
                                            boxShadow: 'none',
                                            '&:hover': {
                                                bgcolor: 'primary.main',
                                                color: 'white',
                                                borderColor: 'primary.main',
                                                boxShadow: '0 4px 12px rgba(25, 118, 210, 0.2)'
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
        </TableContainer>
    );
});

export default RecentTransactionsTable;
