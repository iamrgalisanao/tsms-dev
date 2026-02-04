import React, { useEffect, useState } from 'react';
import { Box, Typography, IconButton, Paper, Stack } from '@mui/material';
import ErrorIcon from '@mui/icons-material/Error';
import WarningIcon from '@mui/icons-material/Warning';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import InfoIcon from '@mui/icons-material/Info';
import CloseIcon from '@mui/icons-material/Close';

const NotificationToast = ({ message, type = 'info', onClose }) => {
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        const timer = setTimeout(() => {
            setVisible(false);
            if (onClose) setTimeout(onClose, 300); // Wait for fade out
        }, 5000);
        return () => clearTimeout(timer);
    }, [onClose]);

    const iconMap = {
        error: <ErrorIcon />,
        warning: <WarningIcon />,
        success: <CheckCircleIcon />,
        info: <InfoIcon />
    };

    const colors = {
        error: 'error.main',
        warning: 'warning.main',
        success: 'success.main',
        info: 'info.main'
    };

    if (!visible) return null;

    return (
        <Paper
            elevation={12}
            sx={{
                position: 'fixed',
                top: 24,
                right: 24,
                zIndex: 2000,
                bgcolor: 'background.paper',
                borderLeft: '6px solid',
                borderColor: colors[type],
                p: 2,
                minWidth: 320,
                borderRadius: '16px',
                animation: 'slideInRight 0.3s ease-out'
            }}
        >
            <Stack direction="row" spacing={2} alignItems="center">
                <Box sx={{ color: colors[type], display: 'flex' }}>
                    {iconMap[type]}
                </Box>
                <Box sx={{ flex: 1 }}>
                    <Typography variant="subtitle2" sx={{ fontWeight: 900, color: 'text.primary' }}>
                        {message}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.1em' }}>
                        System Notification
                    </Typography>
                </Box>
                <IconButton size="small" onClick={() => setVisible(false)} sx={{ color: 'grey.400' }}>
                    <CloseIcon fontSize="small" />
                </IconButton>
            </Stack>
        </Paper>
    );
};

export default NotificationToast;
