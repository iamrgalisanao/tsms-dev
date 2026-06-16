import React from 'react';
import { Paper, Typography, Stack, TextField, Button, CircularProgress, Grid } from '@mui/material';
import RefreshIcon from '@mui/icons-material/Refresh';

const TenantAuditFilters = ({
    filters = {},
    setFilters,
    onRunAudit,
    loading = false
}) => {
    return (
        <Paper
            className="glass-container"
            sx={{
                p: 3,
                borderRadius: '20px',
                border: '1px solid',
                borderColor: 'divider',
                bgcolor: 'white',
                mb: 3
            }}
        >
            <Grid container spacing={2} alignItems="center">
                <Grid item xs={12} lg={2.5}>
                    <Box>
                        <Typography variant="subtitle1" sx={{ fontWeight: 1000, color: '#101221', mb: 0.2, fontSize: '0.9rem', letterSpacing: '0.02em' }}>
                            INVESTIGATION AUDIT
                        </Typography>
                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, fontSize: '0.68rem', display: 'block' }}>
                            Configure audit bounds and parameters
                        </Typography>
                    </Box>
                </Grid>
                
                <Grid item xs={12} sm={6} md={2.4} lg={1.8}>
                    <TextField
                        fullWidth
                        label="From Date"
                        type="date"
                        size="small"
                        value={filters.from || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, from: e.target.value }))}
                        InputLabelProps={{ shrink: true }}
                    />
                </Grid>

                <Grid item xs={12} sm={6} md={2.4} lg={1.8}>
                    <TextField
                        fullWidth
                        label="To Date"
                        type="date"
                        size="small"
                        value={filters.to || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, to: e.target.value }))}
                        InputLabelProps={{ shrink: true }}
                    />
                </Grid>

                <Grid item xs={12} sm={6} md={2.4} lg={1.8}>
                    <TextField
                        fullWidth
                        label="Tenant ID"
                        placeholder="e.g. 24"
                        size="small"
                        value={filters.tenant || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, tenant: e.target.value }))}
                    />
                </Grid>

                <Grid item xs={12} sm={6} md={2.4} lg={1.8}>
                    <TextField
                        fullWidth
                        label="Terminal ID"
                        placeholder="e.g. 32"
                        size="small"
                        value={filters.terminal || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, terminal: e.target.value }))}
                    />
                </Grid>

                <Grid item xs={12} sm={6} md={2.4} lg={2.3}>
                    <Stack direction="row" spacing={1} sx={{ width: '100%' }}>
                        <Button
                            fullWidth
                            variant={filters.only_issues ? 'contained' : 'outlined'}
                            color={filters.only_issues ? 'warning' : 'inherit'}
                            onClick={() => setFilters((prev) => ({ ...prev, only_issues: !prev.only_issues }))}
                            sx={{
                                height: '38px',
                                borderRadius: '10px',
                                fontWeight: 900,
                                textTransform: 'none',
                                borderWidth: '2px',
                                fontSize: '0.72rem',
                                '&:hover': { borderWidth: '2px' }
                            }}
                        >
                            {filters.only_issues ? 'Issues Only' : 'Show All'}
                        </Button>
                        <Button
                            fullWidth
                            variant="contained"
                            onClick={onRunAudit}
                            disabled={loading}
                            startIcon={loading ? <CircularProgress size={14} color="inherit" /> : <RefreshIcon sx={{ fontSize: 14 }} />}
                            sx={{
                                height: '38px',
                                borderRadius: '10px',
                                fontWeight: 900,
                                textTransform: 'none',
                                fontSize: '0.72rem',
                                bgcolor: '#101221',
                                color: 'white',
                                '&:hover': { bgcolor: '#1d1e2e' }
                            }}
                        >
                            Audit
                        </Button>
                    </Stack>
                </Grid>
            </Grid>
        </Paper>
    );
};

// Add a helper wrapper Box import for completeness inside the filters file
import { Box } from '@mui/material';

export default TenantAuditFilters;
