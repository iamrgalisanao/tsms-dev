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
    Typography,
    Divider
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import FileDownloadIcon from '@mui/icons-material/FileDownload';

const TokenFilterBar = ({ filters, onFilterChange, onReset, onExportCSV }) => {
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
            status: '',
            per_page: 10,
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
                        placeholder="Search Identity (Terminal UID, Serial Number, or Asset ID)..."
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
                    <FormControl sx={{ flex: 1, minWidth: 200 }} size="small">
                        <InputLabel>Life-cycle Status</InputLabel>
                        <Select
                            value={localFilters.status || ''}
                            label="Life-cycle Status"
                            onChange={(e) => handleChange('status', e.target.value)}
                            sx={{ borderRadius: 2.5, bgcolor: 'grey.50' }}
                        >
                            <MenuItem value="">All Terminals</MenuItem>
                            <MenuItem value="active">Active Keys</MenuItem>
                            <MenuItem value="revoked">Revoked/Banned</MenuItem>
                            <MenuItem value="expired">Expired</MenuItem>
                            <MenuItem value="inactive">Inactive</MenuItem>
                        </Select>
                    </FormControl>

                    <FormControl sx={{ width: 140 }} size="small">
                        <InputLabel>Per Page</InputLabel>
                        <Select
                            value={localFilters.per_page || 10}
                            label="Per Page"
                            onChange={(e) => handleChange('per_page', e.target.value)}
                            sx={{ borderRadius: 2.5, bgcolor: 'grey.50' }}
                        >
                            <MenuItem value={10}>10 Rows</MenuItem>
                            <MenuItem value={25}>25 Rows</MenuItem>
                            <MenuItem value={50}>50 Rows</MenuItem>
                            <MenuItem value={100}>100 Rows</MenuItem>
                        </Select>
                    </FormControl>

                    <Stack direction="row" spacing={1.5} sx={{ minWidth: 240 }}>
                        <Button
                            variant="contained"
                            fullWidth
                            startIcon={<CheckCircleIcon />}
                            onClick={handleApply}
                            sx={{
                                borderRadius: 2.5,
                                textTransform: 'none',
                                fontWeight: 800,
                                height: 48,
                                bgcolor: 'primary.main',
                                boxShadow: '0 4px 14px rgba(29, 67, 155, 0.3)',
                                px: 3,
                                '&:hover': { bgcolor: 'primary.dark' }
                            }}
                        >
                            Synchronize
                        </Button>
                        <Button
                            variant="outlined"
                            onClick={handleReset}
                            sx={{
                                borderRadius: 2.5,
                                textTransform: 'none',
                                fontWeight: 700,
                                height: 48,
                                minWidth: 48,
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

                <Divider sx={{ borderStyle: 'dashed' }} />

                {/* Export Controls Row */}
                <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Stack direction="row" spacing={1}>
                        <Button
                            startIcon={<FileDownloadIcon />}
                            onClick={() => {
                                console.log('TokenFilterBar: CSV button clicked');
                                onExportCSV();
                            }}
                            sx={{
                                color: '#EB342E',
                                fontWeight: 800,
                                textTransform: 'none',
                                fontSize: '0.75rem',
                                borderRadius: 2,
                                px: 2,
                                '&:hover': { bgcolor: 'rgba(235, 52, 46, 0.05)' }
                            }}
                        >
                            CSV
                        </Button>
                        <Button
                            startIcon={<FileDownloadIcon />}
                            sx={{
                                color: '#EB342E',
                                fontWeight: 800,
                                textTransform: 'none',
                                fontSize: '0.75rem',
                                borderRadius: 2,
                                px: 2,
                                '&:hover': { bgcolor: 'rgba(235, 52, 46, 0.05)' }
                            }}
                        >
                            EXCEL
                        </Button>
                        <Button
                            startIcon={<FileDownloadIcon />}
                            sx={{
                                color: '#EB342E',
                                fontWeight: 800,
                                textTransform: 'none',
                                fontSize: '0.75rem',
                                borderRadius: 2,
                                px: 2,
                                '&:hover': { bgcolor: 'rgba(235, 52, 46, 0.05)' }
                            }}
                        >
                            PDF
                        </Button>
                    </Stack>

                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.disabled', textTransform: 'uppercase', letterSpacing: '0.1em' }}>
                        Provisioning Interface v2.4
                    </Typography>
                </Stack>
            </Stack>
        </Paper>
    );
};

export default TokenFilterBar;
