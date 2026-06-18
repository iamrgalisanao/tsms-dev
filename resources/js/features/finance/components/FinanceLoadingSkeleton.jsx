import React from 'react';
import { Box, Grid, Skeleton, Stack } from '@mui/material';

const FinanceLoadingSkeleton = () => (
    <Box sx={{ pb: 10 }}>
        <Stack spacing={2} sx={{ py: 3, mb: 4 }}>
            <Skeleton variant="text" width={220} height={24} />
            <Skeleton variant="rounded" width="60%" height={72} sx={{ borderRadius: 2 }} />
        </Stack>
        <Grid container spacing={3}>
            {[0, 1, 2, 3, 4, 5, 6].map((item) => (
                <Grid item xs={12} sm={6} lg={3} key={item}>
                    <Skeleton variant="rounded" height={148} sx={{ borderRadius: 2 }} />
                </Grid>
            ))}
            <Grid item xs={12} lg={7}>
                <Skeleton variant="rounded" height={420} sx={{ borderRadius: 2 }} />
            </Grid>
            <Grid item xs={12} lg={5}>
                <Skeleton variant="rounded" height={420} sx={{ borderRadius: 2 }} />
            </Grid>
        </Grid>
    </Box>
);

export default FinanceLoadingSkeleton;
