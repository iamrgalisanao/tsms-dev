import React, { useState, useEffect } from 'react';
import {
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Button,
    TextField,
    Stack,
    FormControl,
    InputLabel,
    Select,
    MenuItem,
    Typography,
    Box,
    InputAdornment,
    IconButton,
    FormHelperText
} from '@mui/material';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import EditIcon from '@mui/icons-material/Edit';

const UserFormDialog = ({ open, user, onClose, onSave, roles }) => {
    const isEdit = !!user;
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: '',
        role: ''
    });
    const [errors, setErrors] = useState({});
    const [showPassword, setShowPassword] = useState(false);

    useEffect(() => {
        if (user) {
            setFormData({
                name: user.name || '',
                email: user.email || '',
                password: '',
                role: user.roles?.[0]?.name || ''
            });
        } else {
            setFormData({
                name: '',
                email: '',
                password: '',
                role: ''
            });
        }
        setErrors({});
    }, [user, open]);

    const handleChange = (field, value) => {
        setFormData({ ...formData, [field]: value });
        if (errors[field]) {
            setErrors({ ...errors, [field]: null });
        }
    };

    const handleSubmit = () => {
        // Simple validation
        const newErrors = {};
        if (!formData.name) newErrors.name = 'Name is required';
        if (!formData.email) newErrors.email = 'Email is required';
        if (!isEdit && !formData.password) newErrors.password = 'Password is required';
        if (!formData.role) newErrors.role = 'Role is required';

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        onSave(formData);
    };

    // Refined 'Ultra-Smooth' design: Removes sharp outlines in favor of soft shadows and background transitions
    const inputStyles = {
        '& .MuiOutlinedInput-root': {
            borderRadius: '16px',
            backgroundColor: '#f8fafc',
            transition: 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
            // Remove the default focus ring and the notched outline border
            '& fieldset': {
                border: 'none',
            },
            '& .MuiOutlinedInput-notchedOutline': {
                borderWidth: '0px',
                borderColor: 'transparent',
            },
            '&:hover': {
                backgroundColor: '#f1f5f9',
            },
            '&.Mui-focused': {
                backgroundColor: '#ffffff',
                // Use a soft depth shadow instead of a thin sharp outline
                boxShadow: '0 0 0 2px rgba(25, 118, 210, 0.1), 0 12px 30px rgba(0, 0, 0, 0.08)',
                '& .MuiOutlinedInput-notchedOutline': {
                    borderWidth: '0px',
                    borderColor: 'transparent',
                }
            },
            '& input': {
                outline: 'none !important', // Force disable browser focus ring
                padding: '16.5px 14px',
            }
        },
        '& .MuiInputLabel-root': {
            fontWeight: 700,
            color: 'text.secondary',
            opacity: 0.7,
            transition: 'all 0.2s ease',
            '&.Mui-focused': {
                color: 'primary.main',
                opacity: 1,
            },
            '&.MuiInputLabel-shrink': {
                transform: 'translate(14px, -12px) scale(0.75)', // Float higher for the "no-border" look
                fontWeight: 900,
                letterSpacing: '0.02em',
            }
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            maxWidth="xs"
            fullWidth
            PaperProps={{
                sx: {
                    borderRadius: '28px',
                    boxShadow: '0 40px 100px rgba(0,0,0,0.25)',
                    border: '1px solid',
                    borderColor: 'rgba(255,255,255,0.1)',
                    overflow: 'hidden',
                    background: '#ffffff'
                }
            }}
        >
            <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 2.5, pt: 5, pb: 1, px: 5 }}>
                <Box sx={{
                    p: 1.5,
                    bgcolor: isEdit ? 'warning.main' : 'primary.main',
                    color: 'white',
                    borderRadius: '16px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    boxShadow: isEdit ? '0 10px 25px rgba(237, 108, 2, 0.3)' : '0 10px 25px rgba(25, 118, 210, 0.3)'
                }}>
                    {isEdit ? <EditIcon sx={{ fontSize: 26 }} /> : <PersonAddIcon sx={{ fontSize: 26 }} />}
                </Box>
                <Typography variant="h5" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em' }}>
                    {isEdit ? 'Modify Personnel' : 'Provision User'}
                </Typography>
            </DialogTitle>

            <DialogContent sx={{ px: 5, py: 3 }}>
                <Typography variant="body2" sx={{ color: 'text.secondary', mb: 4, fontWeight: 500, opacity: 0.8, lineHeight: 1.7 }}>
                    {isEdit
                        ? 'Adjust security credentials and organizational access levels.'
                        : 'Securely establish a new digital identity for local or global system access.'}
                </Typography>

                <Stack spacing={3.5}>
                    <TextField
                        fullWidth
                        label="FULL NAME"
                        value={formData.name}
                        onChange={(e) => handleChange('name', e.target.value)}
                        error={!!errors.name}
                        helperText={errors.name}
                        placeholder="John Doe"
                        autoComplete="off"
                        sx={inputStyles}
                    />

                    <TextField
                        fullWidth
                        label="EMAIL IDENTITY"
                        type="email"
                        value={formData.email}
                        onChange={(e) => handleChange('email', e.target.value)}
                        error={!!errors.email}
                        helperText={errors.email}
                        placeholder="john.doe@pitx.com.ph"
                        autoComplete="off"
                        sx={inputStyles}
                    />

                    <TextField
                        fullWidth
                        label={isEdit ? "NEW PASSWORD (OPTIONAL)" : "SECURITY PASSWORD"}
                        type={showPassword ? 'text' : 'password'}
                        value={formData.password}
                        onChange={(e) => handleChange('password', e.target.value)}
                        error={!!errors.password}
                        helperText={errors.password || (isEdit ? "Optional" : "Minimum 8 characters")}
                        autoComplete="new-password"
                        InputProps={{
                            endAdornment: (
                                <InputAdornment position="end">
                                    <IconButton onClick={() => setShowPassword(!showPassword)} edge="end" size="small" sx={{ mr: 0.5 }}>
                                        {showPassword ? <VisibilityOffIcon /> : <VisibilityIcon />}
                                    </IconButton>
                                </InputAdornment>
                            )
                        }}
                        sx={inputStyles}
                    />

                    <FormControl fullWidth error={!!errors.role} sx={inputStyles}>
                        <InputLabel>ASSIGNED ROLE</InputLabel>
                        <Select
                            value={formData.role}
                            label="ASSIGNED ROLE"
                            onChange={(e) => handleChange('role', e.target.value)}
                        >
                            {roles.map((role) => (
                                <MenuItem key={role.id} value={role.name} sx={{ py: 1.8, px: 2, fontWeight: 700, borderRadius: '14px', mx: 1, my: 0.5 }}>
                                    {role.name.toUpperCase()}
                                </MenuItem>
                            ))}
                        </Select>
                        {errors.role && <FormHelperText>{errors.role}</FormHelperText>}
                    </FormControl>
                </Stack>
            </DialogContent>

            <DialogActions sx={{ p: 5, pt: 1, gap: 1.5 }}>
                <Button
                    onClick={onClose}
                    variant="text"
                    sx={{
                        borderRadius: '16px',
                        fontWeight: 900,
                        px: 4,
                        py: 1.5,
                        color: 'text.secondary',
                        textTransform: 'none',
                        '&:hover': { bgcolor: 'rgba(0,0,0,0.05)' }
                    }}
                >
                    Cancel
                </Button>
                <Button
                    onClick={handleSubmit}
                    variant="contained"
                    color={isEdit ? "warning" : "primary"}
                    sx={{
                        borderRadius: '18px',
                        fontWeight: 950,
                        px: 6,
                        py: 2,
                        textTransform: 'none',
                        fontSize: '1rem',
                        boxShadow: isEdit ? '0 12px 30px rgba(237, 108, 2, 0.4)' : '0 12px 30px rgba(25, 118, 210, 0.4)',
                        '&:hover': {
                            boxShadow: isEdit ? '0 15px 40px rgba(237, 108, 2, 0.5)' : '0 15px 40px rgba(25, 118, 210, 0.5)',
                            transform: 'translateY(-2px)'
                        },
                        transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)'
                    }}
                >
                    {isEdit ? 'Update Identity' : 'Provision User'}
                </Button>
            </DialogActions>
        </Dialog>
    );
};

export default UserFormDialog;
