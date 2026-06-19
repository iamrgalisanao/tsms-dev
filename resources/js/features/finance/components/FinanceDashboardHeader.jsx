import React from 'react';
import {
    Box, Typography, Stack, Button, CircularProgress,
    Breadcrumbs, Link as MuiLink, Select, MenuItem, FormControl, InputLabel,
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import DashboardIcon from '@mui/icons-material/Dashboard';
import SyncIcon from '@mui/icons-material/Sync';

export default function FinanceDashboardHeader({ dateRange, onDateChange, onRefresh, refreshing }) {
    return (
        <Box sx={{ py: 3, mb: 1 }}>
            {/* Title row */}
            <Stack
                direction={{ xs: 'column', lg: 'row' }}
                justifyContent="space-between"
                alignItems={{ xs: 'flex-start', lg: 'center' }}
                spacing={3}
            >
                {/* Left: Title + description */}
                <Stack direction="row" spacing={2} alignItems="center">
                    <Box sx={{
                        width: 40,
                        height: 40,
                        bgcolor: '#EEF2FF',
                        color: '#1A56DB',
                        borderRadius: '10px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}>
                        <DashboardIcon sx={{ fontSize: 20 }} />
                    </Box>
                    <Box>
                        <Typography sx={{ fontWeight: 700, fontSize: '28px', color: '#0F172A', lineHeight: 1.2, mb: 0.5, letterSpacing: '-0.02em' }}>
                            Finance Command Center
                        </Typography>
                        <Typography sx={{ color: '#64748B', fontWeight: 400, fontSize: '14px' }}>
                            Financial health, reconciliations, exceptions &amp; compliance.
                        </Typography>
                    </Box>
                </Stack>

                {/* Right: Status + controls */}
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ xs: 'stretch', sm: 'center' }}>
                    {/* Live status pill */}
                    <Stack
                        direction="row" spacing={1} alignItems="center"
                        sx={{
                            bgcolor: '#DCFCE7',
                            px: 1.5, py: 0.75,
                            borderRadius: '20px',
                            border: '1px solid #BBF7D0',
                        }}
                    >
                        <Box sx={{
                            width: 8,
                            height: 8,
                            borderRadius: '50%',
                            bgcolor: '#16A34A',
                            boxShadow: '0 0 0 3px rgba(22,163,74,0.2)'
                        }} />
                        <Typography sx={{ fontWeight: 600, color: '#16A34A', textTransform: 'uppercase', letterSpacing: '0.05em', fontSize: '11px' }}>
                            Ecosystem Healthy
                        </Typography>
                    </Stack>

                    {/* Period selector */}
                    <FormControl size="small" sx={{ minWidth: 140 }}>
                        <Select
                            value={dateRange}
                            onChange={(e) => onDateChange(e.target.value)}
                            sx={{
                                borderRadius: '10px',
                                bgcolor: '#FFFFFF',
                                fontWeight: 500,
                                fontSize: '14px',
                                color: '#0F172A',
                                '.MuiOutlinedInput-notchedOutline': { borderColor: '#E8ECF4' },
                                '&:hover .MuiOutlinedInput-notchedOutline': { borderColor: '#1A56DB' },
                                '&.Mui-focused .MuiOutlinedInput-notchedOutline': { borderColor: '#1A56DB' }
                            }}
                        >
                            <MenuItem value="7">Last 7 Days</MenuItem>
                            <MenuItem value="30">Last 30 Days</MenuItem>
                        </Select>
                    </FormControl>

                    {/* Force Sync */}
                    <Button
                        variant="contained"
                        onClick={onRefresh}
                        disabled={refreshing}
                        startIcon={refreshing ? <CircularProgress size={14} color="inherit" /> : <SyncIcon sx={{ fontSize: 16 }} />}
                        sx={{
                            borderRadius: '10px',
                            px: 2,
                            py: 1,
                            fontWeight: 500,
                            fontSize: '14px',
                            textTransform: 'none',
                            bgcolor: '#1A56DB',
                            color: '#FFFFFF',
                            boxShadow: '0 2px 8px rgba(26,86,219,0.35)',
                            '&:hover': { bgcolor: '#1347B8', boxShadow: '0 4px 12px rgba(26,86,219,0.45)' },
                        }}
                    >
                        {refreshing ? 'Syncing…' : 'Force Sync'}
                    </Button>
                </Stack>
            </Stack>
        </Box>
    );
}
