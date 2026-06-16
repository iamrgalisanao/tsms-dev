import React from 'react';
import { Paper, Typography, Stack, TextField, Button, CircularProgress } from '@mui/material';
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
                height: '100%'
            }}
        >
            <Stack spacing={3}>
                <div>
                    <Typography variant="subtitle1" sx={{ fontWeight: 1000, color: '#101221', mb: 0.5 }}>
                        INVESTIGATION FILTERS
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700 }}>
                        Configure audit bounds and parameters
                    </Typography>
                </div>

                <Stack spacing={2.5}>
                    <TextField
                        fullWidth
                        label="From Date"
                        type="date"
                        size="small"
                        value={filters.from || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, from: e.target.value }))}
                        InputLabelProps={{ shrink: true }}
                    />

                    <TextField
                        fullWidth
                        label="To Date"
                        type="date"
                        size="small"
                        value={filters.to || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, to: e.target.value }))}
                        InputLabelProps={{ shrink: true }}
                    />

                    <TextField
                        fullWidth
                        label="Tenant ID"
                        placeholder="e.g. 24"
                        size="small"
                        value={filters.tenant || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, tenant: e.target.value }))}
                    />

                    <TextField
                        fullWidth
                        label="Terminal ID"
                        placeholder="e.g. 32"
                        size="small"
                        value={filters.terminal || ''}
                        onChange={(e) => setFilters((prev) => ({ ...prev, terminal: e.target.value }))}
                    />

                    <Button
                        fullWidth
                        variant={filters.only_issues ? 'contained' : 'outlined'}
                        color={filters.only_issues ? 'warning' : 'inherit'}
                        onClick={() => setFilters((prev) => ({ ...prev, only_issues: !prev.only_issues }))}
                        sx={{
                            height: '40px',
                            borderRadius: '12px',
                            fontWeight: 900,
                            textTransform: 'none',
                            borderWidth: '2px',
                            '&:hover': { borderWidth: '2px' }
                        }}
                    >
                        {filters.only_issues ? 'Show Issues Only' : 'Show All Tenants'}
                    </Button>

                    <Button
                        fullWidth
                        variant="contained"
                        onClick={onRunAudit}
                        disabled={loading}
                        startIcon={loading ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon />}
                        sx={{
                            height: '42px',
                            borderRadius: '12px',
                            fontWeight: 900,
                            textTransform: 'none',
                            bgcolor: '#101221',
                            color: 'white',
                            '&:hover': { bgcolor: '#1d1e2e' }
                        }}
                    >
                        Run Audit Check
                    </Button>
                </Stack>
            </Stack>
        </Paper>
    );
};

export default TenantAuditFilters;
