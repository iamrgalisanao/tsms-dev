import React from 'react';
import { Box, Grid, Skeleton, Stack } from '@mui/material';

function CardSkeleton({ height = 136 }) {
    return (
        <Skeleton
            variant="rectangular"
            width="100%"
            height={height}
            sx={{ borderRadius: '20px' }}
        />
    );
}

export default function FinanceLoadingSkeleton() {
    return (
        <Box sx={{ pb: 10 }}>
            {/* Header skeleton */}
            <Box sx={{ py: 3, mb: 2 }}>
                <Skeleton width={180} height={18} sx={{ mb: 4, borderRadius: '8px' }} />
                <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Stack direction="row" spacing={2} alignItems="center">
                        <Skeleton variant="rectangular" width={52} height={52} sx={{ borderRadius: '16px' }} />
                        <Box>
                            <Skeleton width={220} height={24} sx={{ mb: 0.5, borderRadius: '8px' }} />
                            <Skeleton width={300} height={16} sx={{ borderRadius: '8px' }} />
                        </Box>
                    </Stack>
                    <Stack direction="row" spacing={2}>
                        <Skeleton variant="rectangular" width={160} height={40} sx={{ borderRadius: '12px' }} />
                        <Skeleton variant="rectangular" width={140} height={40} sx={{ borderRadius: '12px' }} />
                        <Skeleton variant="rectangular" width={120} height={40} sx={{ borderRadius: '12px' }} />
                    </Stack>
                </Stack>
            </Box>

            {/* KPI Row 1 */}
            <Grid container spacing={3} sx={{ mb: 3 }}>
                {[1, 2, 3].map((i) => (
                    <Grid item xs={12} sm={6} lg={4} key={i}>
                        <CardSkeleton height={136} />
                    </Grid>
                ))}
            </Grid>

            {/* KPI Row 2 */}
            <Grid container spacing={3} sx={{ mb: 4 }}>
                {[1, 2, 3].map((i) => (
                    <Grid item xs={12} sm={6} lg={4} key={i}>
                        <CardSkeleton height={136} />
                    </Grid>
                ))}
            </Grid>

            {/* Alerts */}
            <Skeleton variant="rectangular" width="100%" height={110} sx={{ borderRadius: '24px', mb: 4 }} />

            {/* Exception Queue */}
            <Skeleton variant="rectangular" width="100%" height={170} sx={{ borderRadius: '24px', mb: 4 }} />

            {/* Charts */}
            <Grid container spacing={4} sx={{ mb: 4 }}>
                <Grid item xs={12} lg={6}>
                    <Skeleton variant="rectangular" width="100%" height={380} sx={{ borderRadius: '24px' }} />
                </Grid>
                <Grid item xs={12} lg={6}>
                    <Skeleton variant="rectangular" width="100%" height={380} sx={{ borderRadius: '24px' }} />
                </Grid>
            </Grid>

            {/* Top Tenants + Compliance */}
            <Grid container spacing={4} sx={{ mb: 4 }}>
                <Grid item xs={12} lg={6}>
                    <Skeleton variant="rectangular" width="100%" height={320} sx={{ borderRadius: '24px' }} />
                </Grid>
                <Grid item xs={12} lg={6}>
                    <Skeleton variant="rectangular" width="100%" height={320} sx={{ borderRadius: '24px' }} />
                </Grid>
            </Grid>

            {/* Quick Actions */}
            <Skeleton variant="rectangular" width="100%" height={200} sx={{ borderRadius: '24px' }} />
        </Box>
    );
}
