import React, { memo } from 'react';
import { Card, CardContent, Typography, Box, Stack, useTheme } from '@mui/material';

const COLOR_MAP = {
    primary: { light: '#cffafe', dark: '#0891b2' }, // Cyan
    accent: { light: '#fef3c7', dark: '#d97706' },   // Amber
    success: { light: '#dcfce7', dark: '#16a34a' },  // Green
    danger: { light: '#fee2e2', dark: '#dc2626' },   // Red
    indigo: { light: '#e0e7ff', dark: '#4f46e5' },   // Indigo
};

const MetricCard = memo(({ title, value, icon, color = 'primary', trend, sparkline, subtitle, onClick }) => {
    const theme = useTheme();
    // Default to cyan if color not found
    const activeColor = COLOR_MAP[color] || COLOR_MAP.primary;
    
    // Fallbacks if activeColor is just a string (for backwards compatibility if any)
    const lightBg = typeof activeColor === 'string' ? `${activeColor}1A` : activeColor.light;
    const darkText = typeof activeColor === 'string' ? activeColor : activeColor.dark;

    // Check if offline terminals style is requested
    const isDangerBorder = color === 'danger' && title.toLowerCase().includes('offline');

    // Robust sparkline path calculation
    const renderSparkline = () => {
        if (!sparkline || sparkline.length < 2) return "M 0 10 L 100 10";
        const max = Math.max(...sparkline, 1);
        const points = sparkline.map((val, i) => {
            const x = (i / (sparkline.length - 1)) * 100;
            const y = 20 - (val / max) * 18;
            return `${x} ${y}`;
        });
        return `M ${points.join(' L ')}`;
    };

    return (
        <Card
            sx={{
                height: '100%',
                minHeight: 160,
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'space-between',
                cursor: onClick ? 'pointer' : 'default',
                ...(isDangerBorder && { borderColor: 'rgba(225, 29, 45, 0.2)' }) // specific red border for offline terminals
            }}
            onClick={onClick}
        >
            <CardContent sx={{ p: '20px !important', height: '100%', display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                {/* Top Row: Icon & Trend */}
                <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2 }}>
                    <Box
                        sx={{
                            width: 40,
                            height: 40,
                            borderRadius: '12px',
                            bgcolor: lightBg,
                            color: darkText,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                        }}
                    >
                        {typeof icon === 'string' ? (
                            <span className="material-symbols-outlined">{icon}</span>
                        ) : React.isValidElement(icon) ? (
                            React.cloneElement(icon, { sx: { fontSize: 24 } })
                        ) : null}
                    </Box>

                    {trend !== undefined && (
                        <Box
                            sx={{
                                px: 1,
                                py: 0.5,
                                borderRadius: 1,
                                bgcolor: trend >= 0 ? '#f0fdf4' : '#fef2f2',
                                color: trend >= 0 ? '#16a34a' : '#dc2626',
                                fontWeight: 700,
                                fontSize: '0.65rem',
                                display: 'flex',
                                alignItems: 'center'
                            }}
                        >
                            <span style={{ marginRight: 2 }} aria-hidden="true">{trend >= 0 ? '↑' : '↓'}</span>
                            {Math.abs(trend)}%
                        </Box>
                    )}
                </Stack>

                {/* Bottom Row: Content */}
                <Box>
                    <Typography
                        variant="caption"
                        noWrap
                        sx={{
                            fontSize: '0.625rem',
                            fontWeight: 700,
                            color: '#94a3b8', // slate-400
                            textTransform: 'uppercase',
                            letterSpacing: '0.1em',
                            mb: 0.5,
                            display: 'block',
                        }}
                    >
                        {title}
                    </Typography>
                    
                    <Typography
                        variant="h3"
                        noWrap
                        sx={{
                            fontSize: '1.875rem', // 3xl
                            fontWeight: 900,
                            fontFamily: '"Hanken Grotesk", sans-serif',
                            color: '#0f172a', // slate-900
                            fontVariantNumeric: 'tabular-nums',
                            lineHeight: 1,
                        }}
                    >
                        {value}
                    </Typography>

                    {subtitle && (
                        <Typography
                            variant="caption"
                            sx={{
                                mt: 1,
                                color: isDangerBorder ? '#e11d2d' : '#94a3b8',
                                fontWeight: isDangerBorder ? 700 : 500,
                                fontSize: '0.625rem',
                                display: 'block'
                            }}
                        >
                            {subtitle}
                        </Typography>
                    )}
                </Box>

                {/* Optional Sparkline (if exists) */}
                {sparkline && sparkline.length > 0 && (
                    <Box sx={{ mt: 1, pt: 1, height: 24 }}>
                        <svg viewBox="0 0 100 20" style={{ width: '100%', height: '100%', overflow: 'visible' }}>
                            <path
                                d={renderSparkline()}
                                fill="none"
                                stroke={darkText}
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                style={{ opacity: 0.5 }}
                            />
                        </svg>
                    </Box>
                )}
            </CardContent>
        </Card>
    );
});

MetricCard.displayName = 'MetricCard';

export default MetricCard;
