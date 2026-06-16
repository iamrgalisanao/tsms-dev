import React from 'react';
import { Paper, Box, Typography, Stack, Button } from '@mui/material';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';

const IncidentCenter = ({
    isOffline = false,
    apiError = false,
    affectedTenantsCount = 0,
    affectedTxCount = 0,
    onInvestigate,
    onViewLogs
}) => {
    if (!isOffline && !apiError) return null;

    const incidentTitle = apiError 
        ? 'DISPATCH QUEUE UNAVAILABLE' 
        : 'PIPELINE OFFLINE (CRITICAL)';
        
    const incidentDesc = apiError
        ? 'The TSMS dispatch queue is currently unreachable. System processing is paused.'
        : 'No ingestion activity detected for over 15 minutes. Check device connectivity.';

    return (
        <Paper
            sx={{
                p: 3,
                borderRadius: '20px',
                border: '2px solid',
                borderColor: apiError ? 'error.main' : 'warning.main',
                bgcolor: apiError ? 'rgba(211, 47, 47, 0.03)' : 'rgba(254, 183, 0, 0.03)',
                boxShadow: apiError 
                    ? '0 10px 30px rgba(211, 47, 47, 0.15)' 
                    : '0 10px 30px rgba(254, 183, 0, 0.15)',
                transition: 'all 0.3s'
            }}
            role="alert"
        >
            <Stack direction={{ xs: 'column', md: 'row' }} justify-content="space-between" alignItems="center" spacing={3}>
                <Stack direction="row" spacing={2.5} alignItems="flex-start" sx={{ flexGrow: 1 }}>
                    <Box 
                        sx={{ 
                            p: 1.5, 
                            borderRadius: '12px', 
                            bgcolor: apiError ? 'error.main' : 'warning.main',
                            color: 'white',
                            display: 'flex',
                            boxShadow: '0 4px 12px rgba(0,0,0,0.1)'
                        }}
                    >
                        {apiError ? <ErrorOutlineIcon fontSize="medium" /> : <WarningAmberIcon fontSize="medium" />}
                    </Box>
                    <Box>
                        <Typography variant="h6" sx={{ fontWeight: 1000, color: apiError ? 'error.main' : 'warning.dark', letterSpacing: '0.02em', mb: 0.5 }}>
                            {incidentTitle}
                        </Typography>
                        <Typography variant="body2" sx={{ color: 'text.primary', fontWeight: 800, mb: 1.5 }}>
                            {incidentDesc}
                        </Typography>
                        <Stack direction="row" spacing={3} sx={{ fontSize: '0.75rem', fontWeight: 800, color: 'text.secondary' }}>
                            <span>Affected Ingestors: <strong className="text-slate-900">{affectedTenantsCount}</strong></span>
                            <span className="border-l border-slate-300 pl-3">Stuck Dispatches: <strong className="text-slate-900">{affectedTxCount}</strong></span>
                        </Stack>
                    </Box>
                </Stack>
                
                <Stack direction="row" spacing={1.5} sx={{ shrink: 0, width: { xs: '100%', md: 'auto' } }}>
                    <Button
                        variant="outlined"
                        color={apiError ? "error" : "warning"}
                        onClick={onInvestigate}
                        sx={{ 
                            borderRadius: '12px', 
                            px: 3, 
                            fontWeight: 900, 
                            textTransform: 'none', 
                            fontSize: '0.75rem',
                            flexGrow: { xs: 1, md: 0 }
                        }}
                    >
                        Investigate
                    </Button>
                    <Button
                        variant="contained"
                        onClick={onViewLogs}
                        sx={{ 
                            borderRadius: '12px', 
                            px: 3, 
                            fontWeight: 900, 
                            textTransform: 'none', 
                            fontSize: '0.75rem',
                            bgcolor: apiError ? 'error.main' : 'warning.main',
                            color: 'white',
                            '&:hover': {
                                bgcolor: apiError ? 'error.dark' : 'warning.dark',
                            },
                            flexGrow: { xs: 1, md: 0 }
                        }}
                    >
                        View Logs
                    </Button>
                </Stack>
            </Stack>
        </Paper>
    );
};

export default IncidentCenter;
