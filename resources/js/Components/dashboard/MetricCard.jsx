import React, { memo } from 'react';
import { Card, CardContent, Typography, Box, Stack } from '@mui/material';

// Move configuration outside to prevent re-creation on every render
const COLOR_MAP = {
    primary: '#00f2ff', // Electric Blue
    accent: '#feb700',  // Amber
    success: '#00e676', // Success Green
    danger: '#ff005c',  // Vibrant Rose
};

const MetricCard = memo(({ title, value, icon, color = 'primary', trend, sparkline }) => {
    const activeColor = COLOR_MAP[color] || COLOR_MAP.primary;

    // Robust sparkline path calculation
    const renderSparkline = () => {
        if (!sparkline || sparkline.length < 2) {
            // Return a simple horizontal line if data is insufficient to prevent NaN
            return "M 0 10 L 100 10";
        }
        
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
            className="glass-container stagger-item"
            sx={{
                height: '100%',
                minHeight: 180,
                borderRadius: '24px',
                position: 'relative',
                overflow: 'hidden',
                bgcolor: 'rgba(255, 255, 255, 0.6)',
                '&:hover': {
                    '& .metric-icon-box': { 
                        transform: 'scale(1.1) rotate(5deg)',
                        boxShadow: `0 0 20px ${activeColor}66`
                    },
                    '& .sparkline-path': { opacity: 0.8 }
                }
            }}
        >
            <CardContent sx={{ p: 4, height: '100%', display: 'flex', flexDirection: 'column' }}>
                <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 3 }}>
                    <Box
                        className="metric-icon-box"
                        sx={{
                            width: 52,
                            height: 52,
                            borderRadius: '16px',
                            bgcolor: activeColor,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: 'white',
                            boxShadow: `0 8px 16px ${activeColor}33`,
                            transition: 'all 0.4s cubic-bezier(0.16, 1, 0.3, 1)'
                        }}
                    >
                        {typeof icon === 'string' ? (
                            <Typography sx={{ fontSize: '24px' }}>{icon}</Typography>
                        ) : React.isValidElement(icon) ? (
                            React.cloneElement(icon, { sx: { fontSize: 26 } })
                        ) : null}
                    </Box>

                    {trend !== undefined && (
                        <Box
                            sx={{
                                display: 'flex',
                                alignItems: 'center',
                                px: 1.5,
                                py: 0.75,
                                borderRadius: '12px',
                                bgcolor: 'rgba(0,0,0,0.03)',
                                border: '1px solid rgba(0,0,0,0.05)',
                                color: trend >= 0 ? '#00c853' : '#ff1744',
                                fontWeight: 900,
                                fontSize: '0.7rem',
                                letterSpacing: '0.02em'
                            }}
                        >
                            <span style={{ marginRight: 4 }} aria-hidden="true">{trend >= 0 ? '↑' : '↓'}</span>
                            {Math.abs(trend)}%
                        </Box>
                    )}
                </Stack>

                <Box sx={{ position: 'relative', zIndex: 1 }}>
                    <Typography
                        variant="caption"
                        noWrap
                        sx={{
                            fontSize: '0.7rem',
                            fontWeight: 900,
                            color: 'text.secondary',
                            textTransform: 'uppercase',
                            letterSpacing: '0.12em',
                            mb: 0.5,
                            display: 'block',
                            opacity: 0.6,
                            textOverflow: 'ellipsis'
                        }}
                    >
                        {title}
                    </Typography>
                    <Typography
                        variant="h3"
                        role="status"
                        aria-live="polite"
                        noWrap
                        sx={{
                            fontWeight: 1000,
                            color: '#101221',
                            letterSpacing: '-0.04em',
                            lineHeight: 1,
                            textOverflow: 'ellipsis'
                        }}
                    >
                        {value}
                    </Typography>
                </Box>

                {sparkline && sparkline.length > 0 && (
                    <Box sx={{ mt: 'auto', pt: 3, height: 40, transition: 'all 0.4s' }}>
                        <svg viewBox="0 0 100 20" style={{ width: '100%', height: '100%', overflow: 'visible' }}>
                            <path
                                className="sparkline-path"
                                d={renderSparkline()}
                                fill="none"
                                stroke={activeColor}
                                strokeWidth="2.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                style={{ opacity: 0.3, transition: 'opacity 0.4s' }}
                            />
                        </svg>
                    </Box>
                )}
            </CardContent>
        </Card>
    );
});

// Set display name for easier debugging with memo
MetricCard.displayName = 'MetricCard';

export default MetricCard;
