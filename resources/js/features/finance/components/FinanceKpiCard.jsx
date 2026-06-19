import React from 'react';
import { Box, Typography, Stack, Tooltip } from '@mui/material';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import InfoIcon from '@mui/icons-material/Info';

/**
 * Reusable KPI card for the Finance Command Center.
 *
 * Props:
 *  title          - Card label (uppercase)
 *  value          - Primary display value
 *  subtitle       - Supporting text below the value
 *  trend          - Numeric percent (pos/neg). Pass null to hide.
 *  trendDirection - 'up' | 'down'
 *  trendPositive  - If true, up=green, down=red. If false (e.g. exceptions), invert.
 *  icon           - MUI icon element
 *  gradient       - CSS gradient string for coloured variant (e.g. error cards)
 *  onClick        - Optional click handler; adds pointer + hover lift
 *  tooltip        - Optional tooltip text shown via (i) icon
 *  minHeight      - Card min-height in px (default 136)
 */
export default function FinanceKpiCard({
    title,
    value,
    subtitle,
    trend = null,
    trendDirection = 'up',
    trendPositive = true,
    icon,
    gradient = null,
    onClick,
    tooltip,
    minHeight = 136,
}) {
    const isGradient = Boolean(gradient);
    const trendUp = trendDirection !== 'down';
    const trendColor = trendUp
        ? (trendPositive ? 'success.main' : 'error.main')
        : (trendPositive ? 'error.main' : 'success.main');
    const trendBg = trendUp
        ? (trendPositive ? 'success.light' : 'error.light')
        : (trendPositive ? 'error.light' : 'success.light');

    return (
        <Box
            onClick={onClick}
            sx={{
                p: '22px 24px',
                borderRadius: '20px',
                minHeight,
                background: gradient || 'rgba(255,255,255,0.85)',
                backdropFilter: 'blur(16px)',
                border: isGradient ? 'none' : '1px solid rgba(255,255,255,0.6)',
                boxShadow: isGradient
                    ? '0 8px 30px rgba(0,0,0,0.1)'
                    : '0 2px 16px rgba(0,0,0,0.04)',
                transition: 'transform 0.22s ease, box-shadow 0.22s ease',
                cursor: onClick ? 'pointer' : 'default',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 2,
                color: isGradient ? 'white' : 'text.primary',
                '&:hover': onClick ? {
                    transform: 'translateY(-4px)',
                    boxShadow: isGradient
                        ? '0 16px 40px rgba(0,0,0,0.15)'
                        : '0 12px 32px rgba(29,67,155,0.10)',
                } : {},
            }}
        >
            {/* Left: text content */}
            <Box sx={{ flex: 1, minWidth: 0 }}>
                {/* Label row */}
                <Stack direction="row" alignItems="center" spacing={0.5} sx={{ mb: 0.75 }}>
                    <Typography
                        variant="caption"
                        sx={{
                            fontWeight: 800,
                            textTransform: 'uppercase',
                            letterSpacing: '0.08em',
                            fontSize: '0.65rem',
                            opacity: isGradient ? 0.85 : 0.55,
                            whiteSpace: 'nowrap',
                        }}
                    >
                        {title}
                    </Typography>
                    {tooltip && (
                        <Tooltip title={tooltip} arrow placement="top">
                            <InfoIcon sx={{ fontSize: 12, opacity: 0.45, cursor: 'help' }} />
                        </Tooltip>
                    )}
                </Stack>

                {/* Primary value */}
                <Typography
                    sx={{
                        fontWeight: 950,
                        fontSize: 'clamp(1.15rem, 2vw, 1.55rem)',
                        letterSpacing: '-0.02em',
                        lineHeight: 1.1,
                        mb: 0.75,
                        color: isGradient ? 'inherit' : 'text.primary',
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                    }}
                >
                    {value}
                </Typography>

                {/* Trend + subtitle */}
                <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                    {trend !== null && trend !== undefined && (
                        <Stack
                            direction="row" alignItems="center" spacing={0.25}
                            sx={{
                                bgcolor: isGradient ? 'rgba(255,255,255,0.2)' : trendBg,
                                color: isGradient ? 'white' : trendColor,
                                px: 0.75, py: 0.2, borderRadius: '6px',
                                fontWeight: 900, fontSize: '0.6rem',
                            }}
                        >
                            {trendUp
                                ? <ArrowUpwardIcon sx={{ fontSize: 9 }} />
                                : <ArrowDownwardIcon sx={{ fontSize: 9 }} />
                            }
                            <span>{Math.abs(trend)}%</span>
                        </Stack>
                    )}
                    <Typography
                        variant="caption"
                        sx={{ fontWeight: 600, opacity: isGradient ? 0.85 : 0.6, fontSize: '0.7rem' }}
                    >
                        {subtitle}
                    </Typography>
                </Stack>
            </Box>

            {/* Right: icon badge */}
            <Box
                sx={{
                    p: 1.25,
                    borderRadius: '14px',
                    bgcolor: isGradient ? 'rgba(255,255,255,0.2)' : 'rgba(29,67,155,0.06)',
                    color: isGradient ? 'white' : 'primary.main',
                    display: 'flex',
                    flexShrink: 0,
                    boxShadow: isGradient ? 'none' : 'inset 0 1px 3px rgba(0,0,0,0.04)',
                }}
            >
                {icon}
            </Box>
        </Box>
    );
}
