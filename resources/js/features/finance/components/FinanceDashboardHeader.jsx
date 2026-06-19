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
        <Box sx={{ py: 3, mb: 2 }}>
            {/* Breadcrumbs */}
            <Breadcrumbs
                separator={<NavigateNextIcon fontSize="small" />}
                sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.7rem', letterSpacing: '0.06em' } }}
            >
                <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.5 }}>
                    <HomeIcon sx={{ mr: 0.5, fontSize: 14 }} />
                    FINANCE
                </MuiLink>
                <Typography color="primary.main" sx={{ fontWeight: 800 }}>DASHBOARD COMMAND</Typography>
            </Breadcrumbs>

            {/* Title row */}
            <Stack
                direction={{ xs: 'column', lg: 'row' }}
                justifyContent="space-between"
                alignItems={{ xs: 'flex-start', lg: 'center' }}
                spacing={4}
            >
                {/* Left: Title + description */}
                <Stack direction="row" spacing={2.5} alignItems="center">
                    <Box sx={{
                        p: 1.5, bgcolor: 'primary.main', color: 'white',
                        borderRadius: '16px', display: 'flex',
                        boxShadow: '0 8px 25px rgba(29,67,155,0.25)',
                    }}>
                        <DashboardIcon sx={{ fontSize: 28 }} />
                    </Box>
                    <Box>
                        <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.25 }}>
                            Finance Command Center
                        </Typography>
                        <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 500 }}>
                            Financial health, reconciliations, exceptions &amp; compliance.
                        </Typography>
                    </Box>
                </Stack>

                {/* Right: Status + controls */}
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ xs: 'stretch', sm: 'center' }}>
                    {/* Live status pill */}
                    <Stack
                        direction="row" spacing={1.5} alignItems="center"
                        sx={{
                            bgcolor: 'rgba(255,255,255,0.6)',
                            px: 2, py: 1, borderRadius: '14px',
                            border: '1px solid rgba(255,255,255,0.7)',
                            backdropFilter: 'blur(8px)',
                        }}
                    >
                        <Box sx={{ width: 7, height: 7, borderRadius: '50%', bgcolor: 'success.main', boxShadow: '0 0 8px #10B981' }} />
                        <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                            Ecosystem Healthy
                        </Typography>
                    </Stack>

                    {/* Period selector */}
                    <FormControl size="small" sx={{ minWidth: 140 }}>
                        <InputLabel id="finance-period-label">Period</InputLabel>
                        <Select
                            labelId="finance-period-label"
                            value={dateRange}
                            label="Period"
                            onChange={(e) => onDateChange(e.target.value)}
                            sx={{ borderRadius: '12px', bgcolor: 'background.paper', fontWeight: 700 }}
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
                        startIcon={refreshing ? <CircularProgress size={14} color="inherit" /> : <SyncIcon />}
                        sx={{
                            borderRadius: '12px',
                            px: 3,
                            py: 1.25,
                            fontWeight: 900,
                            fontSize: '0.75rem',
                            letterSpacing: '0.08em',
                            textTransform: 'uppercase',
                            bgcolor: 'primary.main',
                            color: 'white',
                            boxShadow: '0 6px 20px rgba(29,67,155,0.25)',
                            '&:hover': { bgcolor: 'primary.dark' },
                        }}
                    >
                        {refreshing ? 'Syncing…' : 'Force Sync'}
                    </Button>
                </Stack>
            </Stack>
        </Box>
    );
}
