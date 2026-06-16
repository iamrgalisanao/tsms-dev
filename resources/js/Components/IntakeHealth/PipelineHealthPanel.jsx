import React from 'react';
import { Grid, Typography, Card, CardContent, Box, Chip, Stack, Paper } from '@mui/material';
import TimerIcon from '@mui/icons-material/Timer';
import FlashOnIcon from '@mui/icons-material/FlashOn';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import TerminalIcon from '@mui/icons-material/Terminal';
import { Line } from 'react-chartjs-2';

import MetricCard from '../dashboard/MetricCard';
import TenantVolumeStrip from '../dashboard/TenantVolumeStrip';
import WorkerHealthGrid from './WorkerHealthGrid';
import OperationsActions from './OperationsActions';
import RecentEventsTimeline from './RecentEventsTimeline';

const PipelineHealthPanel = ({
    stats,
    tenants = [],
    failRateValue = 0,
    filteredLogs = [],
    feedFilter = 'all',
    setFeedFilter,
    filterCounts = {},
    liveFeed = true,
    setLiveFeed,
    selectedLogId = null,
    setSelectedLogId,
    chartOptions,
    historyData,
    thresholdBandsPlugin,
    workersStatus = '12/12',
    onForceSync,
    onReplayQueue,
    onRefreshAudit,
    isRefreshing = false,
    isReplaying = false,
    isAuditing = false
}) => {
    // Derived exceptions statistics
    const failedDispatches = Number(stats?.metrics?.['intake.failed_count'] ?? 0);
    const stuckPayloads = Number(stats?.queue_size ?? 0);
    const retryQueueCount = 3; // Placeholder or from stats if available
    const deadLettersCount = 0;

    return (
        <Stack spacing={4}>
            {/* Active Ingestion Source volume strip */}
            <Box>
                <TenantVolumeStrip title="ACTIVE INGESTION SOURCES (24H)" tenants={tenants} />
            </Box>

            {/* KPI Metrics Row 1: Latency & Ingestion Health */}
            <Box>
                <Typography variant="caption" sx={{ display: 'block', fontWeight: 800, color: 'text.secondary', mb: 1.5, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
                    Latency & Queue Health
                </Typography>
                <Grid container spacing={3}>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Ingestion Lag"
                            value={`${stats?.latencies?.processing_lag_avg_s?.toFixed(2)}s`}
                            icon={<TimerIcon />}
                            color={stats?.latencies?.processing_lag_avg_s > 5 ? 'danger' : stats?.latencies?.processing_lag_avg_s > 1 ? 'accent' : 'primary'}
                            trend={2}
                            sparkline={[1.2, 1.4, 1.1, 1.5, 1.2, 1.4, 0.9, 0.85]}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Dispatch Backlog"
                            value={stats?.queue_size || 0}
                            icon={<FlashOnIcon />}
                            color={stats?.queue_size > 20 ? 'danger' : stats?.queue_size > 5 ? 'accent' : 'success'}
                            trend={-15}
                            sparkline={[50, 45, 40, 38, 42, 35, 12, 3]}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Worker Throughput"
                            value={`${stats?.latencies?.worker_time_avg_ms?.toFixed(0)}ms`}
                            icon={<FlashOnIcon />}
                            color="success"
                            trend={stats?.latencies?.worker_time_avg_ms < 150 ? 5 : -2}
                            sparkline={[140, 142, 138, 145, 141, 139, 143, 140]}
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Pipeline Resilience"
                            value={`${(100 - failRateValue).toFixed(2)}%`}
                            icon={<CheckCircleIcon />}
                            color={failRateValue > 5 ? 'danger' : failRateValue > 1 ? 'accent' : 'success'}
                            trend={0.1}
                            sparkline={[99.8, 99.9, 99.7, 99.8, 99.9, 100, 100, 100]}
                        />
                    </Grid>
                </Grid>
            </Box>

            {/* KPI Metrics Row 2: Ingestion Volumes */}
            <Box>
                <Typography variant="caption" sx={{ display: 'block', fontWeight: 800, color: 'text.secondary', mb: 1.5, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
                    Intake Submissions & Throughput
                </Typography>
                <Grid container spacing={3}>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Received"
                            value={(stats?.metrics?.['intake.received_count'] ?? 0).toLocaleString()}
                            icon={<TimerIcon />}
                            color="primary"
                            trend={4.2}
                            subtitle="Total intake submissions"
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Processed"
                            value={(stats?.metrics?.['intake.processed_count'] ?? 0).toLocaleString()}
                            icon={<CheckCircleIcon />}
                            color="success"
                            trend={4.5}
                            subtitle="Successfully processed and stored"
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Retries"
                            value={stats?.metrics?.['intake.accepted_count'] ? Math.max(0, stats.metrics['intake.accepted_count'] - (stats.metrics['intake.processed_count'] ?? 0)) : 3}
                            icon={<FlashOnIcon />}
                            color="accent"
                            trend={-25}
                            subtitle="Retried transient ingestions"
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <MetricCard
                            title="Failed"
                            value={stats?.metrics?.['intake.failed_count'] ?? 0}
                            icon={<ErrorOutlineIcon />}
                            color="danger"
                            trend={0}
                            subtitle="Permanently failed jobs"
                        />
                    </Grid>
                </Grid>
            </Box>

            {/* Exceptions, Workers grid and Actions Panel */}
            <Grid container spacing={3} alignItems="stretch">
                {/* Exceptions Card */}
                <Grid item xs={12} md={4} sx={{ display: 'flex' }}>
                    <Card 
                        sx={{ 
                            borderRadius: 3, 
                            border: '1.5px solid', 
                            borderColor: 'error.main', 
                            height: '100%', 
                            bgcolor: 'rgba(211, 47, 47, 0.02)',
                            boxShadow: '0 4px 20px rgba(211, 47, 47, 0.05)',
                            display: 'flex',
                            flexDirection: 'column',
                            width: '100%'
                        }}
                    >
                        <CardContent sx={{ p: 3, flexGrow: 1, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 1000, mb: 1, color: 'error.main', display: 'flex', alignItems: 'center', letterSpacing: '0.02em' }}>
                                    <ErrorOutlineIcon sx={{ mr: 1, fontSize: 24 }} /> PIPELINE EXCEPTIONS
                                </Typography>
                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, mb: 3, display: 'block' }}>
                                    Quarantined logs and ingestion blocks
                                </Typography>
                            </Box>
                            
                            <Stack spacing={2} sx={{ my: 2 }}>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Failed Dispatches</Typography>
                                    <Chip label={failedDispatches} size="small" color={failedDispatches > 0 ? 'error' : 'success'} sx={{ fontWeight: 900 }} />
                                </Box>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Stuck Payloads</Typography>
                                    <Chip label={stuckPayloads} size="small" color={stuckPayloads > 0 ? 'warning' : 'success'} sx={{ fontWeight: 900 }} />
                                </Box>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Retry Queue</Typography>
                                    <Chip label={retryQueueCount} size="small" color="warning" sx={{ fontWeight: 900 }} />
                                </Box>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', pb: 0 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Dead Letters</Typography>
                                    <Chip label={deadLettersCount} size="small" color="success" sx={{ fontWeight: 900 }} />
                                </Box>
                            </Stack>
                        </CardContent>
                    </Card>
                </Grid>

                {/* Worker Node Pool visual grid */}
                <Grid item xs={12} md={4} sx={{ display: 'flex' }}>
                    <WorkerHealthGrid workersStatus={workersStatus} />
                </Grid>

                {/* Operations Actions control panel */}
                <Grid item xs={12} md={4} sx={{ display: 'flex' }}>
                    <OperationsActions
                        onForceSync={onForceSync}
                        onReplayQueue={onReplayQueue}
                        onRefreshAudit={onRefreshAudit}
                        isRefreshing={isRefreshing}
                        isReplaying={isReplaying}
                        isAuditing={isAuditing}
                    />
                </Grid>
            </Grid>

            {/* Chart and Events Live Feed split-pane */}
            <Grid container spacing={3}>
                {/* Temporal Lag Chart */}
                <Grid item xs={12} md={4}>
                    <Paper className="glass-container" sx={{ p: 4, height: 650, overflow: 'hidden', display: 'flex', flexDirection: 'column', borderRadius: '20px', border: '1px solid', borderColor: 'divider' }}>
                        <Box sx={{ mb: 4 }}>
                            <Typography variant="h6" sx={{ fontWeight: 1000, letterSpacing: '0.05em', color: '#101221', mb: 0.5 }}>
                                TEMPORAL DRIFT (LAG)
                            </Typography>
                            <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700 }}>
                                Bands: 0-1s (Healthy) • 1-5s (Warn) • 5s+ (Crit)
                            </Typography>
                        </Box>
                        <Box sx={{ flexGrow: 1, minHeight: 0 }}>
                            <Line options={chartOptions} data={historyData} plugins={[thresholdBandsPlugin]} />
                        </Box>
                    </Paper>
                </Grid>

                {/* Diagnostic Log Timeline */}
                <Grid item xs={12} md={8}>
                    <RecentEventsTimeline
                        filteredLogs={filteredLogs}
                        feedFilter={feedFilter}
                        setFeedFilter={setFeedFilter}
                        filterCounts={filterCounts}
                        liveFeed={liveFeed}
                        setLiveFeed={setLiveFeed}
                        selectedLogId={selectedLogId}
                        setSelectedLogId={setSelectedLogId}
                    />
                </Grid>
            </Grid>
        </Stack>
    );
};

export default PipelineHealthPanel;
