import React from 'react';
import {
    Box,
    Stack,
    Tooltip,
    Typography,
} from '@mui/material';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import InfoIcon from '@mui/icons-material/Info';

const FinanceKpiCard = ({
    title,
    value,
    subtitle,
    trend,
    trendDirection,
    trendColor,
    icon,
    gradient,
    onClick,
    tooltip,
}) => {
    return (
        <Box
            onClick={onClick}
            sx={{
                p: 3,
                borderRadius: 2,
                background: gradient || 'rgba(255, 255, 255, 0.86)',
                border: '1px solid rgba(226, 232, 240, 0.9)',
                boxShadow: '0 10px 28px rgba(15, 23, 42, 0.06)',
                transition: 'transform 0.2s ease, box-shadow 0.2s ease',
                cursor: onClick ? 'pointer' : 'default',
                '&:hover': {
                    transform: onClick ? 'translateY(-2px)' : 'none',
                    boxShadow: onClick ? '0 16px 34px rgba(15, 23, 42, 0.1)' : '0 10px 28px rgba(15, 23, 42, 0.06)',
                },
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                color: gradient ? 'white' : 'text.primary',
                height: '100%',
                minHeight: 148,
                boxSizing: 'border-box',
            }}
        >
            <Box sx={{ minWidth: 0, width: '100%' }}>
                <Stack direction="row" alignItems="center" spacing={0.5} sx={{ mb: 1 }}>
                    <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', opacity: gradient ? 0.86 : 0.62 }}>
                        {title}
                    </Typography>
                    {tooltip && (
                        <Tooltip title={tooltip} arrow placement="top">
                            <InfoIcon sx={{ fontSize: 14, opacity: gradient ? 0.85 : 0.55, cursor: 'help', color: gradient ? 'white' : 'action.active' }} />
                        </Tooltip>
                    )}
                </Stack>
                <Typography variant="h4" sx={{ fontWeight: 900, mb: 0.5, overflowWrap: 'anywhere' }}>
                    {value}
                </Typography>
                <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                    {trend !== null && trend !== undefined && (
                        <Stack direction="row" alignItems="center" spacing={0.25} sx={{
                            color: trendColor || 'success.main',
                            bgcolor: gradient ? 'rgba(255,255,255,0.2)' : (trendDirection === 'down' ? 'rgba(235,52,46,0.1)' : 'rgba(16,185,129,0.1)'),
                            px: 1,
                            py: 0.25,
                            borderRadius: 1,
                            fontWeight: 800,
                            fontSize: '0.72rem',
                        }}>
                            {trendDirection === 'down' ? <ArrowDownwardIcon sx={{ fontSize: 12 }} /> : <ArrowUpwardIcon sx={{ fontSize: 12 }} />}
                            <span>{Math.abs(trend)}%</span>
                        </Stack>
                    )}
                    <Typography variant="caption" sx={{ fontWeight: 600, opacity: gradient ? 0.9 : 0.72 }}>
                        {subtitle}
                    </Typography>
                </Stack>
            </Box>
            <Box sx={{
                p: 1.5,
                borderRadius: 2,
                bgcolor: gradient ? 'rgba(255, 255, 255, 0.18)' : 'rgba(29, 67, 155, 0.08)',
                color: gradient ? 'white' : 'primary.main',
                display: 'flex',
                flexShrink: 0,
                ml: 2,
            }}>
                {icon}
            </Box>
        </Box>
    );
};

export default FinanceKpiCard;
