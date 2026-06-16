import React from 'react';
import { Card, CardContent, Typography, Box, Stack, Button, CircularProgress } from '@mui/material';
import RefreshIcon from '@mui/icons-material/Refresh';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import AutoFixHighIcon from '@mui/icons-material/AutoFixHigh';

const OperationsActions = ({
    onForceSync,
    onReplayQueue,
    onRefreshAudit,
    isRefreshing = false,
    isReplaying = false,
    isAuditing = false
}) => {
    return (
        <Card sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', height: '100%', bgcolor: 'white', display: 'flex', flexDirection: 'column', width: '100%' }}>
            <CardContent sx={{ p: 3, flexGrow: 1, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                <Box>
                    <Typography variant="h6" sx={{ fontWeight: 900, mb: 1, color: '#101221', display: 'flex', alignItems: 'center' }}>
                        <AutoFixHighIcon sx={{ mr: 1, color: 'blue' }} /> Operations Actions
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, mb: 3, display: 'block' }}>
                        Core operational pipeline control overrides
                    </Typography>
                </Box>

                <Stack spacing={2} sx={{ my: 2 }}>
                    <Button
                        fullWidth
                        variant="contained"
                        onClick={onForceSync}
                        disabled={isRefreshing}
                        startIcon={isRefreshing ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon />}
                        sx={{
                            height: '42px',
                            borderRadius: '12px',
                            fontWeight: 900,
                            textTransform: 'none',
                            bgcolor: '#101221',
                            color: 'white',
                            '&:hover': { bgcolor: '#1d1e2e' },
                            '&:disabled': { bgcolor: 'rgba(16, 18, 33, 0.4)' }
                        }}
                    >
                        {isRefreshing ? 'Syncing...' : 'Force Pipeline Sync'}
                    </Button>

                    <Button
                        fullWidth
                        variant="outlined"
                        color="primary"
                        onClick={onReplayQueue}
                        disabled={isReplaying}
                        startIcon={isReplaying ? <CircularProgress size={16} color="inherit" /> : <PlayArrowIcon />}
                        sx={{
                            height: '42px',
                            borderRadius: '12px',
                            fontWeight: 900,
                            textTransform: 'none',
                            borderWidth: '2px',
                            '&:hover': { borderWidth: '2px' }
                        }}
                    >
                        {isReplaying ? 'Replaying...' : 'Replay Failed Queue'}
                    </Button>

                    <Button
                        fullWidth
                        variant="outlined"
                        color="secondary"
                        onClick={onRefreshAudit}
                        disabled={isAuditing}
                        startIcon={isAuditing ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon />}
                        sx={{
                            height: '42px',
                            borderRadius: '12px',
                            fontWeight: 900,
                            textTransform: 'none',
                            borderWidth: '2px',
                            '&:hover': { borderWidth: '2px' }
                        }}
                    >
                        {isAuditing ? 'Auditing...' : 'Run Full Ingestion Audit'}
                    </Button>
                </Stack>

                <Box sx={{ borderTop: '1px solid', borderColor: 'divider', pt: 2 }}>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, fontSize: '0.62rem', display: 'block', leading: '1.2' }}>
                        Actions run asynchronously in the background. Use the Diagnostic Feed to monitor completion events.
                    </Typography>
                </Box>
            </CardContent>
        </Card>
    );
};

export default OperationsActions;
