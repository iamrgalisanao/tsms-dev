import React from 'react';
import { Card, CardContent, Typography, Box, Stack, useTheme } from '@mui/material';

const MetricCard = ({ title, value, icon, color = 'primary', trend, sparkline }) => {
    const theme = useTheme();

    const colorMap = {
        primary: theme.palette.primary.main,
        accent: theme.palette.secondary.main,
        success: theme.palette.success.main,
        danger: theme.palette.error.main, // Mapping danger to MUI error palette
    };

    const activeColor = colorMap[color] || theme.palette.primary.main;

    return (
        <Card
            sx={{
                height: '100%',
                minHeight: 180,
                borderRadius: '32px',
                position: 'relative',
                overflow: 'hidden',
                transition: 'all 0.4s ease-in-out',
                '&:hover': {
                    transform: 'translateY(-10px)',
                    boxShadow: '0 20px 40px rgba(0,0,0,0.1)',
                    '& .accent-bar': { width: '100%' }
                }
            }}
        >
            <CardContent sx={{ p: 4, height: '100%', display: 'flex', flexDirection: 'column' }}>
                <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 4 }}>
                    <Box
                        sx={{
                            width: 56,
                            height: 56,
                            borderRadius: '16px',
                            bgcolor: activeColor,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: 'white',
                            boxShadow: `0 8px 16px ${activeColor}33`,
                            transition: 'all 0.4s'
                        }}
                    >
                        <Box sx={{ display: 'flex' }}>
                            {typeof icon === 'string' ? (
                                <Typography sx={{ fontSize: '24px' }}>{icon}</Typography>
                            ) : (
                                React.cloneElement(icon, { sx: { fontSize: 28 } })
                            )}
                        </Box>
                    </Box>

                    {trend !== undefined && (
                        <Box
                            sx={{
                                display: 'flex',
                                alignItems: 'center',
                                px: 1.5,
                                py: 0.5,
                                borderRadius: '100px',
                                bgcolor: trend >= 0 ? theme.palette.success[50] : theme.palette.error[50],
                                color: trend >= 0 ? theme.palette.success.main : theme.palette.error.main,
                                fontWeight: 900,
                                fontSize: '12px'
                            }}
                        >
                            {trend >= 0 ? '↑' : '↓'} {Math.abs(trend)}%
                        </Box>
                    )}
                </Stack>

                <Box>
                    <Typography
                        variant="caption"
                        sx={{
                            fontSize: '14px',
                            fontWeight: 900,
                            color: theme.palette.grey[400],
                            textTransform: 'uppercase',
                            letterSpacing: '0.1em',
                            mb: 1,
                            display: 'block'
                        }}
                    >
                        {title}
                    </Typography>
                    <Typography
                        variant="h4"
                        sx={{
                            fontWeight: 900,
                            color: theme.palette.primary.main,
                            letterSpacing: '-0.02em'
                        }}
                    >
                        {value}
                    </Typography>
                </Box>

                {sparkline && sparkline.length > 0 && (
                    <Box sx={{ mt: 'auto', pt: 4, height: 48, opacity: 0.2, transition: 'opacity 0.4s' }}>
                        <svg viewBox="0 0 100 20" style={{ width: '100%', height: '100%', overflow: 'visible' }}>
                            <path
                                d={`M ${sparkline.map((val, i) => `${(i / (sparkline.length - 1)) * 100} ${20 - (val / Math.max(...sparkline, 1)) * 18}`).join(' L ')}`}
                                fill="none"
                                stroke={activeColor}
                                strokeWidth="3"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                        </svg>
                    </Box>
                )}
            </CardContent>

            <Box
                className="accent-bar"
                sx={{
                    position: 'absolute',
                    bottom: 0,
                    left: 0,
                    height: 6,
                    width: 0,
                    bgcolor: activeColor,
                    transition: 'width 0.6s ease'
                }}
            />
        </Card>
    );
};

export default MetricCard;
