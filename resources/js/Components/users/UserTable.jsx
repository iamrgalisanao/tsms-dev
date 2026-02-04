import React from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Paper,
    Chip,
    IconButton,
    Typography,
    Box,
    Button,
    Tooltip,
    Stack,
    TablePagination,
    CircularProgress,
    Avatar
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import PersonIcon from '@mui/icons-material/Person';

const UserTable = ({
    users,
    loading,
    page,
    rowsPerPage,
    totalCount,
    onPageChange,
    onRowsPerPageChange,
    onEdit,
    onDelete
}) => {
    const getRoleChip = (role) => {
        const roleName = typeof role === 'string' ? role : role.name;
        let color = 'primary';

        switch (roleName.toLowerCase()) {
            case 'admin':
                color = 'error';
                break;
            case 'manager':
                color = 'warning';
                break;
            case 'finance':
                color = 'success';
                break;
            default:
                color = 'primary';
        }

        return (
            <Chip
                label={roleName.toUpperCase()}
                size="small"
                color={color}
                sx={{
                    fontWeight: 800,
                    fontSize: '0.65rem',
                    borderRadius: 1.5,
                    px: 0.5
                }}
            />
        );
    };

    return (
        <Paper
            elevation={0}
            sx={{
                border: '1px solid',
                borderColor: 'divider',
                borderRadius: 4,
                overflow: 'hidden',
                boxShadow: '0 10px 30px rgba(0,0,0,0.03)'
            }}
        >
            <TableContainer>
                <Table stickyHeader>
                    <TableHead>
                        <TableRow>
                            <TableCell sx={{ fontWeight: 800, bgcolor: 'grey.50', py: 2, color: 'text.secondary', fontSize: '0.75rem', textTransform: 'uppercase' }}>ID</TableCell>
                            <TableCell sx={{ fontWeight: 800, bgcolor: 'grey.50', py: 2, color: 'text.secondary', fontSize: '0.75rem', textTransform: 'uppercase' }}>User Identity</TableCell>
                            <TableCell sx={{ fontWeight: 800, bgcolor: 'grey.50', py: 2, color: 'text.secondary', fontSize: '0.75rem', textTransform: 'uppercase' }}>Roles</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 800, bgcolor: 'grey.50', py: 2, color: 'text.secondary', fontSize: '0.75rem', textTransform: 'uppercase' }}>Actions</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {loading ? (
                            Array.from(new Array(5)).map((_, index) => (
                                <TableRow key={index}>
                                    <TableCell colSpan={4} sx={{ py: 3 }}>
                                        <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', justifyContent: 'center' }}>
                                            <CircularProgress size={20} />
                                            <Typography variant="body2" color="text.secondary">Fetching user directory...</Typography>
                                        </Box>
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : users.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} align="center" sx={{ py: 10 }}>
                                    <PersonIcon sx={{ fontSize: 48, color: 'grey.300', mb: 1 }} />
                                    <Typography variant="h6" color="text.secondary" sx={{ fontWeight: 700 }}>No Users Found</Typography>
                                </TableCell>
                            </TableRow>
                        ) : (
                            users.map((user) => (
                                <TableRow key={user.id} hover>
                                    <TableCell sx={{ fontWeight: 700, color: 'text.secondary' }}>#{user.id}</TableCell>
                                    <TableCell>
                                        <Stack direction="row" spacing={2} alignItems="center">
                                            <Avatar sx={{ bgcolor: 'primary.light', width: 36, height: 36 }}>
                                                {user.name.charAt(0).toUpperCase()}
                                            </Avatar>
                                            <Box>
                                                <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>
                                                    {user.name}
                                                </Typography>
                                                <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                                                    {user.email}
                                                </Typography>
                                            </Box>
                                        </Stack>
                                    </TableCell>
                                    <TableCell>
                                        <Stack direction="row" spacing={1}>
                                            {user.roles && user.roles.map((role) => (
                                                <React.Fragment key={role.id}>
                                                    {getRoleChip(role)}
                                                </React.Fragment>
                                            ))}
                                        </Stack>
                                    </TableCell>
                                    <TableCell align="right">
                                        <Stack direction="row" spacing={1} justifyContent="flex-end">
                                            <Tooltip title="Edit User">
                                                <IconButton
                                                    onClick={() => onEdit(user)}
                                                    size="small"
                                                    sx={{ bgcolor: 'primary.50', color: 'primary.main', '&:hover': { bgcolor: 'primary.100' } }}
                                                >
                                                    <EditIcon fontSize="small" />
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip title="Delete User">
                                                <IconButton
                                                    onClick={() => onDelete(user)}
                                                    size="small"
                                                    sx={{ bgcolor: 'error.50', color: 'error.main', '&:hover': { bgcolor: 'error.100' } }}
                                                >
                                                    <DeleteIcon fontSize="small" />
                                                </IconButton>
                                            </Tooltip>
                                        </Stack>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </TableContainer>
            <TablePagination
                rowsPerPageOptions={[10, 25, 50]}
                component="div"
                count={totalCount}
                rowsPerPage={rowsPerPage}
                page={page}
                onPageChange={onPageChange}
                onRowsPerPageChange={onRowsPerPageChange}
                sx={{ borderTop: '1px solid', borderColor: 'divider', bgcolor: 'grey.50' }}
            />
        </Paper>
    );
};

export default UserTable;
