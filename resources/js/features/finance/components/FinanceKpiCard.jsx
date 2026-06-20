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
    minHeight = 120,
}) {
    const isGradient = Boolean(gradient);
    const trendUp = trendDirection !== 'down';
    
    // Exact semantic colors from redesign specification
    const trendColor = trendUp
        ? (trendPositive ? '#16A34A' : '#DC2626')
        : (trendPositive ? '#DC2626' : '#16A34A');
    const trendBg = trendUp
        ? (trendPositive ? '#DCFCE7' : '#FEE2E2')
        : (trendPositive ? '#FEE2E2' : '#DCFCE7');

    let accentColor = '#CBD5E1';
    if (title === 'Gross Sales') accentColor = '#1A56DB';
    else if (title === 'Net Sales') accentColor = '#16A34A';
    else if (title === 'Reconciled') accentColor = '#1A56DB';
    else if (title === 'Refunds') accentColor = '#DC2626';
    else if (title === 'Voided Transactions') accentColor = '#D97706';

    return (
        <Box
            onClick={onClick}
            sx={{
                width: '100%',
                p: '18px 20px',
                borderRadius: '12px',
                minHeight,
                background: gradient || '#FFFFFF',
                border: isGradient ? 'none' : '1px solid #E2E8F0',
                boxShadow: isGradient
                    ? '0 8px 30px rgba(0,0,0,0.1)'
                    : '0 10px 24px rgba(15,23,42,0.045), 0 1px 2px rgba(15,23,42,0.06)',
                transition: 'transform 0.2s ease, box-shadow 0.2s ease',
                cursor: onClick ? 'pointer' : 'default',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 2,
                color: isGradient ? 'white' : '#0F172A',
                '&:hover': onClick ? {
                    transform: 'translateY(-2px)',
                    boxShadow: isGradient
                        ? '0 16px 40px rgba(0,0,0,0.15)'
                        : '0 14px 30px rgba(15,23,42,0.08), 0 2px 4px rgba(15,23,42,0.06)',
                } : {},
            }}
        >
            {/* Left: text content */}
            <Box sx={{ flex: 1, minWidth: 0 }}>
                <Box sx={{ width: 28, height: 3, borderRadius: 999, bgcolor: accentColor, mb: 1.5 }} />
                {/* Label row */}
                <Stack direction="row" alignItems="center" spacing={0.5} sx={{ mb: 1 }}>
                    <Typography
                        sx={{
                            fontWeight: 800,
                            fontSize: '12px',
                            color: isGradient ? 'rgba(255,255,255,0.85)' : '#475569',
                            whiteSpace: 'nowrap',
                        }}
                    >
                        {title}
                    </Typography>
                    {tooltip && (
                        <Tooltip title={tooltip} arrow placement="top">
                            <InfoIcon sx={{ fontSize: 13, opacity: 0.45, cursor: 'help', color: isGradient ? 'inherit' : '#94A3B8' }} />
                        </Tooltip>
                    )}
                </Stack>

                {/* Primary value */}
                {typeof value === 'string' || typeof value === 'number' ? (
                    <Typography
                        sx={{
                            fontWeight: 800,
                            fontSize: title === 'Gross Sales' || title === 'Net Sales' || title === 'Reconciled' ? '24px' : '20px',
                            letterSpacing: '-0.02em',
                            lineHeight: 1.2,
                            mb: 1,
                            color: isGradient ? 'inherit' : '#0F172A',
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        {value}
                    </Typography>
                ) : (
                    <Box sx={{ mb: 1 }}>{value}</Box>
                )}

                {/* Trend + subtitle */}
                <Stack direction="row" spacing={1.25} alignItems="center" flexWrap="wrap">
                    {trend !== null && trend !== undefined && (
                        <Stack
                            direction="row" alignItems="center" spacing={0.25}
                            sx={{
                                bgcolor: isGradient ? 'rgba(255,255,255,0.2)' : trendBg,
                                color: isGradient ? 'white' : trendColor,
                                px: 1, py: 0.25, borderRadius: '999px',
                                fontWeight: 800, fontSize: '11px',
                            }}
                        >
                            {trendUp
                                ? <ArrowUpwardIcon sx={{ fontSize: 10 }} />
                                : <ArrowDownwardIcon sx={{ fontSize: 10 }} />
                            }
                            <span>{Math.abs(trend)}%</span>
                        </Stack>
                    )}
                    <Typography
                        sx={{ fontWeight: 600, opacity: isGradient ? 0.85 : 1, fontSize: '12px', color: isGradient ? 'inherit' : '#64748B' }}
                    >
                        {subtitle}
                    </Typography>
                </Stack>
            </Box>

            {/* Right: icon badge */}
            <Box
                sx={{
                    p: 1.25,
                    borderRadius: '12px',
                    bgcolor: isGradient ? 'rgba(255,255,255,0.2)' : `${accentColor}12`,
                    color: isGradient ? 'white' : accentColor,
                    display: 'flex',
                    flexShrink: 0,
                    boxShadow: isGradient ? 'none' : 'inset 0 1px 3px rgba(0,0,0,0.02)',
                }}
            >
                {icon}
            </Box>
        </Box>
    );
}
