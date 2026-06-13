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
    Tooltip,
    Accordion,
    AccordionSummary,
    AccordionDetails,
    ToggleButtonGroup,
    ToggleButton
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { transactionLogService } from '../../services/transactionLogService';

const FilterBar = ({ filters, onFilterChange, onReset }) => {
    const [terminals, setTerminals] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);
    const [datePreset, setDatePreset] = useState('custom');

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
        setDatePreset(preset);
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
            case 'custom':
                break;
        }
    };

    const statuses = [
        { value: '', label: 'All Statuses' },
        { value: 'VALID', label: 'Valid' },
        { value: 'VOIDED', label: 'Voided' },
        { value: 'INVALID', label: 'Invalid' },
        { value: 'PENDING', label: 'Pending' },
        { value: 'WITH_ISSUES', label: 'With Issues' },
        { value: 'REFUNDED', label: 'Refunded' },
        { value: 'DUPLICATE', label: 'Duplicate' }
    ];

    const dateBasisOptions = [
        { value: 'transaction', label: 'Transaction Date (POS Sale Date)' },
        { value: 'completed', label: 'Completed Date (TSMS Finalized)' },
        { value: 'created', label: 'Created Date' }
    ];

    return (
        <Paper
            elevation={0}
            sx={{
                p: { xs: 2.5, md: 3 },
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
            <Stack spacing={2.5} sx={{ width: '100%' }}>
                <TextField
                    fullWidth
                    placeholder="Search by transaction ID, receipt number, tenant, or terminal..."
                    value={filters.transaction_id || ''}
                    onChange={(e) => handleChange('transaction_id', e.target.value)}
                    variant="outlined"
                    InputProps={{
                        startAdornment: (
                            <InputAdornment position="start">
                                <SearchIcon sx={{ color: 'primary.main', mr: 1, fontSize: 22 }} />
                            </InputAdornment>
                        ),
                        sx: {
                            borderRadius: 2.5,
                            bgcolor: '#fbfcfd',
                            height: 50,
                            fontSize: '0.95rem'
                        }
                    }}
                />

                <Stack direction={{ xs: 'column', lg: 'row' }} spacing={2}>
                    <FormControl sx={{ minWidth: 180, flex: 1 }} size="small">
                        <InputLabel>Status</InputLabel>
                        <Select
                            value={filters.status || ''}
                            label="Status"
                            onChange={(e) => handleChange('status', e.target.value)}
                            sx={{ borderRadius: 2 }}
                        >
                            {statuses.map((status) => (
                                <MenuItem key={status.value} value={status.value}>{status.label}</MenuItem>
                            ))}
                        </Select>
                    </FormControl>

                    <Box sx={{ minWidth: 220, flex: 1 }}>
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

                    <Box sx={{ minWidth: 240, flex: 1 }}>
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
                </Stack>

                <Stack direction={{ xs: 'column', lg: 'row' }} spacing={2} alignItems={{ xs: 'stretch', lg: 'center' }}>
                    <ToggleButtonGroup
                        size="small"
                        color="primary"
                        value={datePreset}
                        exclusive
                        onChange={(_, value) => value && handleDatePreset(value)}
                        sx={{
                            '& .MuiToggleButton-root': {
                                textTransform: 'none',
                                fontWeight: 700,
                                px: 1.5
                            }
                        }}
                    >
                        <ToggleButton value="today">Today</ToggleButton>
                        <ToggleButton value="yesterday">Yesterday</ToggleButton>
                        <ToggleButton value="last7days">Last 7 Days</ToggleButton>
                        <ToggleButton value="thismonth">This Month</ToggleButton>
                        <ToggleButton value="custom">Custom</ToggleButton>
                    </ToggleButtonGroup>

                    <TextField
                        sx={{ minWidth: 170 }}
                        size="small"
                        type="date"
                        label="From"
                        value={filters.date_from || ''}
                        onChange={(e) => {
                            setDatePreset('custom');
                            handleChange('date_from', e.target.value);
                        }}
                        InputLabelProps={{ shrink: true }}
                    />
                    <TextField
                        sx={{ minWidth: 170 }}
                        size="small"
                        type="date"
                        label="To"
                        value={filters.date_to || ''}
                        onChange={(e) => {
                            setDatePreset('custom');
                            handleChange('date_to', e.target.value);
                        }}
                        InputLabelProps={{ shrink: true }}
                    />

                    <Stack direction="row" spacing={1.2} sx={{ ml: { lg: 'auto' } }}>
                        <Button
                            variant="contained"
                            startIcon={<CheckCircleIcon />}
                            onClick={() => onFilterChange(filters)}
                            sx={{
                                borderRadius: 2,
                                textTransform: 'none',
                                fontWeight: 700,
                                height: 40,
                                px: 2.5
                            }}
                        >
                            Apply
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
                                px: 1.8
                            }}
                        >
                            Reset
                        </Button>
                    </Stack>
                </Stack>

                <Accordion disableGutters elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, '&:before': { display: 'none' } }}>
                    <AccordionSummary expandIcon={<ExpandMoreIcon />}>
                        <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                            Advanced Filters
                        </Typography>
                    </AccordionSummary>
                    <AccordionDetails>
                        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
                            <FormControl sx={{ minWidth: 260 }} size="small">
                                <InputLabel>Reporting Basis</InputLabel>
                                <Select
                                    value={filters.date_basis || 'transaction'}
                                    label="Reporting Basis"
                                    onChange={(e) => handleChange('date_basis', e.target.value)}
                                    sx={{ borderRadius: 2 }}
                                >
                                    {dateBasisOptions.map((option) => (
                                        <MenuItem key={option.value} value={option.value}>{option.label}</MenuItem>
                                    ))}
                                </Select>
                            </FormControl>
                            <Tooltip
                                arrow
                                title="Transaction Date matches POS sale dates. Completed Date reflects when TSMS finalized records for audit timelines."
                            >
                                <Box sx={{ display: 'flex', alignItems: 'center', color: 'text.secondary', gap: 1 }}>
                                    <InfoOutlinedIcon sx={{ fontSize: 18 }} />
                                    <Typography variant="caption">Use completed date for processing audit.</Typography>
                                </Box>
                            </Tooltip>
                        </Stack>
                    </AccordionDetails>
                </Accordion>

                <Typography variant="caption" sx={{ fontStyle: 'italic', color: 'text.disabled', fontWeight: 500 }}>
                    Refining transaction results by applied filters
                </Typography>
            </Stack>
        </Paper>
    );
};

export default FilterBar;
