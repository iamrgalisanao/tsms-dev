import React from 'react';
import {
    Box,
    Stack,
    TextField,
    MenuItem,
    Select,
    FormControl,
    InputLabel,
    Button,
    Typography,
    Paper,
    Divider
} from '@mui/material';
import FilterListIcon from '@mui/icons-material/FilterList';
import DownloadIcon from '@mui/icons-material/Download';
import ClearAllIcon from '@mui/icons-material/ClearAll';

const FilterBar = ({ onFilterChange, onExport, loading }) => {
    const [filters, setFilters] = React.useState({
        start_date: '',
        end_date: '',
        terminal_id: '',
        search: ''
    });

    const handleChange = (e) => {
        const { name, value } = e.target;
        const newFilters = { ...filters, [name]: value };
        setFilters(newFilters);
        onFilterChange(newFilters);
    };

    const handleDatePreset = (preset) => {
        const now = new Date();
        let start = '';
        const end = now.toISOString().split('T')[0];

        if (preset === 'today') {
            start = end;
        } else if (preset === 'yesterday') {
            const date = new Date();
            date.setDate(date.getDate() - 1);
            start = date.toISOString().split('T')[0];
        } else if (preset === '7days') {
            const date = new Date();
            date.setDate(date.getDate() - 7);
            start = date.toISOString().split('T')[0];
        }

        const newFilters = { ...filters, start_date: start, end_date: end };
        setFilters(newFilters);
        onFilterChange(newFilters);
    };

    const handleClear = () => {
        const cleared = { start_date: '', end_date: '', terminal_id: '', search: '' };
        setFilters(cleared);
        onFilterChange(cleared);
    };

    return (
        <Paper
            elevation={0}
            sx={{
                p: 3,
                borderRadius: '24px',
                bgcolor: 'white',
                border: '1px solid',
                borderColor: 'grey.100',
                mb: 6
            }}
        >
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={3} alignItems="center">
                <Box sx={{ flex: 1, minWidth: { md: 300 } }}>
                    <TextField
                        fullWidth
                        name="search"
                        placeholder="Search Transactions or Tenants..."
                        value={filters.search}
                        onChange={handleChange}
                        variant="outlined"
                        InputProps={{
                            startAdornment: <FilterListIcon sx={{ color: 'grey.400', mr: 1 }} />,
                            sx: { borderRadius: '12px', bgcolor: 'grey.50', '& fieldset': { border: 'none' }, '&:hover fieldset': { border: 'none' } }
                        }}
                    />
                </Box>

                <Stack direction="row" spacing={2} alignItems="center">
                    <TextField
                        type="date"
                        label="From"
                        name="start_date"
                        InputLabelProps={{ shrink: true }}
                        value={filters.start_date}
                        onChange={handleChange}
                        sx={{ '& .MuiOutlinedInput-root': { borderRadius: '12px' } }}
                    />
                    <TextField
                        type="date"
                        label="To"
                        name="end_date"
                        InputLabelProps={{ shrink: true }}
                        value={filters.end_date}
                        onChange={handleChange}
                        sx={{ '& .MuiOutlinedInput-root': { borderRadius: '12px' } }}
                    />
                </Stack>

                <FormControl variant="outlined" sx={{ minWidth: 150 }}>
                    <InputLabel id="terminal-select-label">Terminal</InputLabel>
                    <Select
                        labelId="terminal-select-label"
                        name="terminal_id"
                        value={filters.terminal_id}
                        label="Terminal"
                        onChange={handleChange}
                        sx={{ borderRadius: '12px' }}
                    >
                        <MenuItem value="">All Terminals</MenuItem>
                        <MenuItem value="T-001">Terminal 001</MenuItem>
                        <MenuItem value="T-002">Terminal 002</MenuItem>
                    </Select>
                </FormControl>

                <Button
                    startIcon={<ClearAllIcon />}
                    onClick={handleClear}
                    sx={{ color: 'grey.500', fontWeight: 'bold' }}
                >
                    Clear
                </Button>

                <Divider orientation="vertical" flexItem />

                <Button
                    variant="contained"
                    color="secondary"
                    startIcon={<DownloadIcon />}
                    onClick={onExport}
                    disabled={loading}
                    sx={{
                        borderRadius: '12px',
                        px: 4,
                        py: 1.5,
                        boxShadow: '0 8px 16px rgba(235, 52, 46, 0.2)'
                    }}
                >
                    Export CSV
                </Button>
            </Stack>
        </Paper>
    );
};

export default FilterBar;
