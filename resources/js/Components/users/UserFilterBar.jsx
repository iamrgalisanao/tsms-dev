import React from 'react';
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
    Button
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';

const UserFilterBar = ({ filters, onFilterChange, onReset, roles }) => {
    const handleChange = (field, value) => {
        onFilterChange({ ...filters, [field]: value });
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
            <Stack spacing={2.5}>
                {/* Search Bar Slab */}
                <Box>
                    <TextField
                        fullWidth
                        placeholder="Search users by name or email..."
                        value={filters.search || ''}
                        onChange={(e) => handleChange('search', e.target.value)}
                        InputProps={{
                            startAdornment: (
                                <InputAdornment position="start">
                                    <SearchIcon sx={{ color: 'primary.main', fontSize: 24, ml: 1 }} />
                                </InputAdornment>
                            ),
                            sx: {
                                borderRadius: 3,
                                bgcolor: 'grey.50',
                                height: 52,
                                fontSize: '1.05rem',
                                '& fieldset': { border: 'none' },
                                '&:hover': { bgcolor: 'grey.100' },
                                '&.Mui-focused': { bgcolor: 'white', boxShadow: 'inset 0 0 0 2px #1976d2' }
                            }
                        }}
                    />
                </Box>

                {/* Filter Controls Slab */}
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems="center">
                    <FormControl sx={{ flex: 1, minWidth: 200 }} size="small">
                        <InputLabel>Filter by Role</InputLabel>
                        <Select
                            value={filters.role || ''}
                            label="Filter by Role"
                            onChange={(e) => handleChange('role', e.target.value)}
                            sx={{ borderRadius: 2.5, bgcolor: 'grey.50' }}
                        >
                            <MenuItem value="">All Roles</MenuItem>
                            {roles.map((role) => (
                                <MenuItem key={role.id} value={role.name} sx={{ py: 1, fontWeight: 500 }}>
                                    {role.name}
                                </MenuItem>
                            ))}
                        </Select>
                    </FormControl>

                    <Stack direction="row" spacing={1.5} sx={{ minWidth: 240 }}>
                        <Button
                            variant="contained"
                            fullWidth
                            startIcon={<CheckCircleIcon />}
                            onClick={() => onFilterChange(filters)}
                            sx={{
                                borderRadius: 2.5,
                                textTransform: 'none',
                                fontWeight: 800,
                                height: 44,
                                boxShadow: '0 4px 14px rgba(25, 118, 210, 0.3)',
                                px: 3
                            }}
                        >
                            Apply Filter
                        </Button>
                        <Button
                            variant="outlined"
                            onClick={onReset}
                            sx={{
                                borderRadius: 2.5,
                                textTransform: 'none',
                                fontWeight: 700,
                                height: 44,
                                minWidth: 44,
                                p: 0,
                                borderColor: 'divider',
                                color: 'text.secondary'
                            }}
                        >
                            <RestartAltIcon />
                        </Button>
                    </Stack>
                </Stack>
            </Stack>
        </Paper>
    );
};

export default UserFilterBar;
