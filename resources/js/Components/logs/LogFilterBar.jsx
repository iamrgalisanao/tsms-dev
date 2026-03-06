import React, { useState, useEffect } from 'react';
import {
    Paper,
    Stack,
    Box,
    TextField,
    InputAdornment,
    FormControl,
    InputLabel,
    Select,
    MenuItem,
    Button,
    Divider,
    Typography
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import DeleteSweepIcon from '@mui/icons-material/DeleteSweep';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const LogFilterBar = ({ filters, onFilterChange, onReset, terminals, activeTab, onPruneClick }) => {
    const [localFilters, setLocalFilters] = useState(filters);

    useEffect(() => {
        setLocalFilters(filters);
    }, [filters]);

    const handleChange = (field, value) => {
        setLocalFilters(prev => ({ ...prev, [field]: value }));
    };

    const handleApply = () => {
        onFilterChange(localFilters);
    };

    const handleReset = () => {
        const resetFilters = {
            type: '',
            severity: '',
            date_from: '',
            date_to: '',
            terminal: '',
            search: ''
        };
        setLocalFilters(resetFilters);
        onReset(resetFilters);
    };

    return (
        <Paper
            elevation={0}
            sx={{
                p: { xs: 2, lg: 3 },
                bgcolor: '#fff',
                borderRadius: 4,
                border: '1px solid',
                borderColor: 'divider',
                boxShadow: '0 10px 40px rgba(0,0,0,0.04)',
                mb: 4
            }}
        >
            <Stack spacing={3}>
                {/* Search Bar Slab */}
                <Box>
                    <TextField
                        fullWidth
                        placeholder="Search logs by action, context, or resource identifier..."
                        value={localFilters.search || ''}
                        onChange={(e) => handleChange('search', e.target.value)}
                        onKeyPress={(e) => e.key === 'Enter' && handleApply()}
                        InputProps={{
                            startAdornment: (
                                <InputAdornment position="start">
                                    <SearchIcon sx={{ color: 'primary.main', fontSize: 24, ml: 1 }} />
                                </InputAdornment>
                            ),
                            sx: {
                                borderRadius: 3,
                                bgcolor: 'grey.50',
                                height: 56,
                                fontSize: '1.05rem',
                                '& fieldset': { border: 'none' },
                                '&:hover': { bgcolor: 'grey.100' },
                                '&.Mui-focused': { bgcolor: 'white', boxShadow: 'inset 0 0 0 2px #1D439B' }
                            }
                        }}
                    />
                </Box>

                {/* Filter Controls Row */}
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems="center">
                    <FormControl sx={{ flex: 1, minWidth: 150 }} size="small">
                        <InputLabel>Log Category</InputLabel>
                        <Select
                            value={localFilters.type || ''}
                            label="Log Category"
                            onChange={(e) => handleChange('type', e.target.value)}
                            sx={{ borderRadius: 2.5, bgcolor: 'grey.50' }}
                        >
                            <MenuItem value="">All Categories</MenuItem>
                            <MenuItem value="security">Security / Auth</MenuItem>
                            <MenuItem value="audit">Administrative Audit</MenuItem>
                            <MenuItem value="integration">System Integration</MenuItem>
                            <MenuItem value="payload_validation">Payload Validation</MenuItem>
                            <MenuItem value="transaction">Transact Engine</MenuItem>
                        </Select>
                    </FormControl>

                    <FormControl sx={{ flex: 1, minWidth: 150 }} size="small">
                        <InputLabel>Severity</InputLabel>
                        <Select
                            value={localFilters.severity || ''}
                            label="Severity"
                            onChange={(e) => handleChange('severity', e.target.value)}
                            sx={{ borderRadius: 2.5, bgcolor: 'grey.50' }}
                        >
                            <MenuItem value="">All Levels</MenuItem>
                            <MenuItem value="info">Info / Success</MenuItem>
                            <MenuItem value="warning">Warning</MenuItem>
                            <MenuItem value="error">Error / Critical</MenuItem>
                        </Select>
                    </FormControl>

                    <TextField
                        type="date"
                        size="small"
                        label="From"
                        value={localFilters.date_from || ''}
                        onChange={(e) => handleChange('date_from', e.target.value)}
                        sx={{ flex: 1, '& .MuiInputLabel-root': { shrink: true } }}
                        InputLabelProps={{ shrink: true }}
                    />

                    <TextField
                        type="date"
                        size="small"
                        label="To"
                        value={localFilters.date_to || ''}
                        onChange={(e) => handleChange('date_to', e.target.value)}
                        sx={{ flex: 1 }}
                        InputLabelProps={{ shrink: true }}
                    />

                    <Stack direction="row" spacing={1.5} sx={{ minWidth: 200 }}>
                        <Button
                            variant="contained"
                            fullWidth
                            startIcon={<CheckCircleIcon />}
                            onClick={handleApply}
                            sx={{
                                borderRadius: 2.5,
                                textTransform: 'none',
                                fontWeight: 800,
                                height: 44,
                                bgcolor: 'primary.main',
                                boxShadow: '0 4px 14px rgba(29, 67, 155, 0.3)',
                                px: 3,
                                '&:hover': { bgcolor: 'primary.dark' }
                            }}
                        >
                            Sync Logs
                        </Button>
                        <Button
                            variant="outlined"
                            onClick={handleReset}
                            sx={{
                                borderRadius: 2.5,
                                textTransform: 'none',
                                fontWeight: 700,
                                height: 44,
                                minWidth: 44,
                                p: 0,
                                borderColor: 'divider',
                                color: 'text.secondary',
                                bgcolor: 'white',
                                '&:hover': { bgcolor: 'grey.50', borderColor: 'grey.300' }
                            }}
                        >
                            <RestartAltIcon />
                        </Button>
                    </Stack>
                </Stack>

                {activeTab === 'submission' && localFilters.type === 'payload_validation' && localFilters.severity === 'error' && (
                    <Stack direction="row" spacing={1} alignItems="center">
                        <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.7 }} />
                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500 }}>
                            You are viewing node submissions where payload validation detected an error or critical issue.
                            Each row represents a single submission envelope (submission_uuid) that may contain one or more
                            transactions.
                        </Typography>
                    </Stack>
                )}

                <Divider sx={{ borderStyle: 'dashed' }} />

                <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.disabled', textTransform: 'uppercase', letterSpacing: '0.1em' }}>
                        Viewing: {activeTab.replace('_', ' ')} Registry
                    </Typography>

                    <Button
                        variant="soft"
                        color="error"
                        size="small"
                        startIcon={<DeleteSweepIcon />}
                        onClick={onPruneClick}
                        sx={{
                            fontWeight: 800,
                            textTransform: 'none',
                            bgcolor: 'rgba(235, 52, 46, 0.05)',
                            color: '#EB342E',
                            borderRadius: 2,
                            px: 2,
                            '&:hover': { bgcolor: 'rgba(235, 52, 46, 0.1)' }
                        }}
                    >
                        Master Prune Action
                    </Button>
                </Stack>
            </Stack>
        </Paper>
    );
};

export default LogFilterBar;
