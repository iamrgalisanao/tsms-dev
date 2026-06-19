import React from 'react';
import { Box, Typography, Stack } from '@mui/material';

const SEVERITY = {
    error:   { border: 'rgba(239,68,68,0.25)',  bg: 'rgba(239,68,68,0.04)',  text: 'error.main',   label: 'error' },
    warning: { border: 'rgba(245,158,11,0.25)', bg: 'rgba(245,158,11,0.04)', text: 'warning.main', label: 'warning' },
    neutral: { border: 'rgba(156,163,175,0.2)', bg: 'rgba(249,250,251,0.6)', text: 'text.disabled', label: 'ok' },
    success: { border: 'rgba(16,185,129,0.2)',  bg: 'rgba(16,185,129,0.04)', text: 'success.main',  label: 'ok' },
};

/**
 * Individual exception metric card.
 *
 * Props:
 *  title      - Card label
 *  value      - Numeric count
 *  action     - CTA label (e.g. "Needs review")
 *  severity   - 'error' | 'warning' | 'neutral' | 'success'
 *  onClick    - Optional click handler
 */
export default function ExceptionCard({ title, value, action, severity = 'neutral', onClick }) {
    const s = SEVERITY[severity] ?? SEVERITY.neutral;

    return (
        <Box
            onClick={onClick}
            sx={{
                p: 3,
                borderRadius: '18px',
                border: `1px solid ${s.border}`,
                bgcolor: s.bg,
                cursor: onClick ? 'pointer' : 'default',
                transition: 'all 0.2s',
                height: '100%',
                '&:hover': onClick ? {
                    filter: 'brightness(0.97)',
                    transform: 'translateY(-2px)',
                    boxShadow: `0 8px 24px ${s.border}`,
                } : {},
            }}
        >
            <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', mb: 1.5, fontSize: '0.8rem' }}>
                {title}
            </Typography>
            <Typography
                sx={{
                    fontWeight: 950,
                    fontSize: 'clamp(1.6rem, 3vw, 2.2rem)',
                    color: s.text,
                    lineHeight: 1,
                    mb: 0.75,
                }}
            >
                {value}
            </Typography>
            {onClick && (
                <Typography variant="caption" sx={{ fontWeight: 800, color: s.text, textTransform: 'uppercase', letterSpacing: '0.06em', fontSize: '0.65rem' }}>
                    {action} →
                </Typography>
            )}
            {!onClick && (
                <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.disabled', fontSize: '0.65rem' }}>
                    {action}
                </Typography>
            )}
        </Box>
    );
}
