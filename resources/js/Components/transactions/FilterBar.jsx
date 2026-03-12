import React, { useState, useEffect } from 'react';
import {
    Box,
    TextField,
    Select,
    MenuItem,
    FormControl,
    InputLabel,
    Button,
    Autocomplete,
    Stack,
    Chip,
    Typography,
    Paper,
    InputAdornment,
    Divider
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import TodayIcon from '@mui/icons-material/Today';
import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { transactionLogService } from '../../services/transactionLogService';

const FilterBar = ({ filters, onFilterChange, onReset }) => {
    const [terminals, setTerminals] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        loadFilterOptions();
    }, []);

    const loadFilterOptions = async () => {
        try {
            const [terminalsData, tenantsData] = await Promise.all([
                transactionLogService.getTerminals(),
                transactionLogService.getTenants()
            ]);
            setTerminals(terminalsData);
            setTenants(tenantsData);
        } catch (error) {
            console.error('Error loading filter options:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleChange = (field, value) => {
        onFilterChange({ ...filters, [field]: value });
    };

    const handleDatePreset = (preset) => {
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        const last7Days = new Date(today);
        last7Days.setDate(last7Days.getDate() - 7);
        const thisMonth = new Date(today.getFullYear(), today.getMonth(), 1);

        const formatDateStr = (date) => {
            // Use ISO string but cut off time part for date picker
            return date.toISOString().split('T')[0];
        };

        switch (preset) {
            case 'today':
                onFilterChange({ ...filters, date_from: formatDateStr(today), date_to: formatDateStr(today) });
                break;
            case 'yesterday':
                onFilterChange({ ...filters, date_from: formatDateStr(yesterday), date_to: formatDateStr(yesterday) });
                break;
            case 'last7days':
                onFilterChange({ ...filters, date_from: formatDateStr(last7Days), date_to: formatDateStr(today) });
                break;
            case 'thismonth':
                onFilterChange({ ...filters, date_from: formatDateStr(thisMonth), date_to: formatDateStr(today) });
                break;
        }
    };

    const statuses = [
        { value: '', label: 'All Statuses' },
        { value: 'VALID', label: 'Valid' },
        { value: 'INVALID', label: 'Invalid' },
        { value: 'PENDING', label: 'Pending' },
        { value: 'WITH_ISSUES', label: 'With Issues' },
        { value: 'DUPLICATE', label: 'Duplicate' }
    ];

    const dateBasisOptions = [
        { value: 'completed', label: 'Completed Date' },
        { value: 'transaction', label: 'Transaction Date' },
        { value: 'created', label: 'Created Date' }
    ];

    return (
        <Paper
            elevation={0}
            sx={{
                p: { xs: 2.5, md: 4 },
                bgcolor: '#fff',
                borderRadius: 4,
                border: '1px solid',
                borderColor: 'divider',
                boxShadow: '0 8px 32px rgba(0,0,0,0.06)',
                mb: 4,
                width: '100%',
                boxSizing: 'border-box'
            }}
        >
            <Stack spacing={4} sx={{ width: '100%' }}>
                {/* Section 1: Global Search */}
                <Box>
                    <TextField
                        fullWidth
                        placeholder="Search by transaction ID, receipt number, tenant, or terminal..."
                        value={filters.transaction_id || ''}
                        onChange={(e) => handleChange('transaction_id', e.target.value)}
                        variant="outlined"
                        InputProps={{
                            startAdornment: (
                                <InputAdornment position="start">
                                    <SearchIcon sx={{ color: 'primary.main', mr: 1, fontSize: 24 }} />
                                </InputAdornment>
                            ),
                            sx: {
                                borderRadius: 3,
                                bgcolor: '#fbfcfd',
                                height: 56,
                                fontSize: '1.05rem',
                                boxShadow: 'inset 0 2px 4px rgba(0,0,0,0.02)'
                            }
                        }}
                    />
                </Box>

                {/* Section 2: Time & Status Filters */}
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2.5} sx={{ width: '100%' }}>
                    <FormControl sx={{ flex: 1.2, minWidth: 150 }}>
                        <InputLabel>Status</InputLabel>
                        <Select
                            value={filters.status || ''}
                            label="Status"
                            onChange={(e) => handleChange('status', e.target.value)}
                            sx={{ borderRadius: 2 }}
                            size="small"
                        >
                            {statuses.map((status) => (
                                <MenuItem key={status.value} value={status.value}>{status.label}</MenuItem>
                            ))}
                        </Select>
                    </FormControl>
                    <FormControl sx={{ flex: 2, minWidth: 180 }}>
                        <InputLabel>Date Basis</InputLabel>
                        <Select
                            value={filters.date_basis || 'completed'}
                            label="Date Basis"
                            onChange={(e) => handleChange('date_basis', e.target.value)}
                            sx={{ borderRadius: 2 }}
                            size="small"
                        >
                            {dateBasisOptions.map((option) => (
                                <MenuItem key={option.value} value={option.value}>{option.label}</MenuItem>
                            ))}
                        </Select>
                    </FormControl>
                    <TextField
                        sx={{ flex: 1.5, minWidth: 160 }}
                        size="small"
                        type="date"
                        label="From"
                        value={filters.date_from || ''}
                        onChange={(e) => handleChange('date_from', e.target.value)}
                        InputLabelProps={{ shrink: true }}
                    />
                    <TextField
                        sx={{ flex: 1.5, minWidth: 160 }}
                        size="small"
                        type="date"
                        label="To"
                        value={filters.date_to || ''}
                        onChange={(e) => handleChange('date_to', e.target.value)}
                        InputLabelProps={{ shrink: true }}
                    />
                </Stack>

                {/* Section 3: Merchant & Hardware Filters + Action Buttons */}
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2.5} sx={{ width: '100%' }}>
                    <Box sx={{ flex: 1, minWidth: 250 }}>
                        <Autocomplete
                            size="small"
                            options={tenants}
                            getOptionLabel={(option) => option.trade_name || ''}
                            value={tenants.find(t => t.id === filters.tenant_id) || null}
                            onChange={(e, newValue) => handleChange('tenant_id', newValue?.id || '')}
                            renderInput={(params) => <TextField {...params} label="Tenant" placeholder="Filter by tenant" />}
                            sx={{ '& .MuiOutlinedInput-root': { borderRadius: 2 } }}
                        />
                    </Box>
                    <Box sx={{ flex: 1, minWidth: 250 }}>
                        <Autocomplete
                            size="small"
                            options={terminals}
                            getOptionLabel={(option) => `${option.serial_number}${option.machine_number ? ` (${option.machine_number})` : ''}`}
                            value={terminals.find(t => t.id === filters.terminal_id) || null}
                            onChange={(e, newValue) => handleChange('terminal_id', newValue?.id || '')}
                            renderInput={(params) => <TextField {...params} label="Terminal" placeholder="Filter by terminal" />}
                            sx={{ '& .MuiOutlinedInput-root': { borderRadius: 2 } }}
                        />
                    </Box>

                    <Stack direction="row" spacing={1.5} sx={{ flex: '0 0 auto', minWidth: 220 }}>
                        <Button
                            variant="contained"
                            startIcon={<CheckCircleIcon />}
                            onClick={() => onFilterChange(filters)}
                            sx={{
                                borderRadius: 2,
                                textTransform: 'none',
                                fontWeight: 700,
                                height: 40,
                                px: 3,
                                bgcolor: 'primary.main',
                                boxShadow: '0 4px 14px rgba(25, 118, 210, 0.3)'
                            }}
                        >
                            Apply Filters
                        </Button>
                        <Button
                            variant="outlined"
                            startIcon={<RestartAltIcon />}
                            onClick={onReset}
                            sx={{
                                borderRadius: 2,
                                textTransform: 'none',
                                fontWeight: 600,
                                height: 40,
                                px: 2,
                                borderWidth: 1.5,
                                borderColor: 'divider',
                                '&:hover': { borderWidth: 1.5, bgcolor: 'grey.50' }
                            }}
                        >
                            Reset
                        </Button>
                    </Stack>
                </Stack>

                {/* Section 4: Quick Presets */}
                <Box sx={{
                    pt: 2.5,
                    borderTop: '1px solid',
                    borderColor: 'grey.100',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    flexWrap: 'wrap',
                    gap: 2
                }}>
                    <Stack direction="row" spacing={2} alignItems="center">
                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', letterSpacing: 1.5 }}>
                            Quick Range:
                        </Typography>
                        <Stack direction="row" spacing={1}>
                            {['Today', 'Yesterday', 'Last 7 Days', 'This Month'].map((label) => (
                                <Chip
                                    key={label}
                                    label={label}
                                    onClick={() => handleDatePreset(label.toLowerCase().replace(/ /g, ''))}
                                    size="small"
                                    color="primary"
                                    variant="outlined"
                                    sx={{
                                        borderRadius: 1.5,
                                        fontWeight: 700,
                                        height: 30,
                                        px: 1,
                                        '&:hover': { bgcolor: 'primary.50' }
                                    }}
                                />
                            ))}
                        </Stack>
                    </Stack>

                    <Typography variant="caption" sx={{ fontStyle: 'italic', color: 'text.disabled', fontWeight: 500 }}>
                        Refining transaction results by applied filters
                    </Typography>
                </Box>
            </Stack>
        </Paper>
    );
};

export default FilterBar;
