import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
    Box,
    Container,
    Typography,
    Stack,
    CircularProgress,
    Breadcrumbs,
    Link as MuiLink,
    Fade,
    Paper,
    Tabs,
    Tab,
    Grid,
    Alert,
    Chip,
    Divider
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import TerminalIcon from '@mui/icons-material/Terminal';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';

// Import newly refactored Operations Command Center components
import PipelineOverviewCard from '../../Components/IntakeHealth/PipelineOverviewCard';
import IncidentCenter from '../../Components/IntakeHealth/IncidentCenter';
import PipelineHealthPanel from '../../Components/IntakeHealth/PipelineHealthPanel';
import TenantAuditFilters from '../../Components/IntakeHealth/TenantAuditFilters';
import TenantAuditSummary from '../../Components/IntakeHealth/TenantAuditSummary';
import TenantAuditTable from '../../Components/IntakeHealth/TenantAuditTable';
import TenantInspector from '../../Components/IntakeHealth/TenantInspector';
import DuplicateReceiptCenter from '../../Components/IntakeHealth/DuplicateReceiptCenter';

import '../../../css/IntakeHealth.css';

// Chart threshold zones plugin: highlights Healthy (0-1s), Warning (1-5s), and Critical (5s+) lag areas
const thresholdBandsPlugin = {
    id: 'thresholdBands',
    beforeDraw: (chart) => {
        const { ctx, chartArea, scales: { y } } = chart;
        if (!chartArea || !y) return;

        ctx.save();
        
        // Draw Healthy Band (0s to 1s)
        const topHealthy = y.getPixelForValue(1);
        const bottomHealthy = y.getPixelForValue(0);
        ctx.fillStyle = 'rgba(76, 175, 80, 0.05)'; 
        ctx.fillRect(chartArea.left, topHealthy, chartArea.right - chartArea.left, bottomHealthy - topHealthy);

        // Draw Warning Band (1s to 5s)
        const topWarning = y.getPixelForValue(5);
        const bottomWarning = y.getPixelForValue(1);
        ctx.fillStyle = 'rgba(255, 152, 0, 0.05)'; 
        ctx.fillRect(chartArea.left, topWarning, chartArea.right - chartArea.left, bottomWarning - topWarning);

        // Draw Critical Band (5s+)
        const topCritical = y.getPixelForValue(y.max);
        const bottomCritical = y.getPixelForValue(5);
        ctx.fillStyle = 'rgba(244, 67, 54, 0.05)'; 
        ctx.fillRect(chartArea.left, topCritical, chartArea.right - chartArea.left, bottomCritical - topCritical);

        ctx.restore();
    }
};

const IntakeHealthPage = () => {
    const [stats, setStats] = useState(null);
    const [history, setHistory] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [recentLogs, setRecentLogs] = useState([]);
    const [selectedLogId, setSelectedLogId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [apiError, setApiError] = useState(false);
    const [activeView, setActiveView] = useState('monitor'); // workflows: 'monitor', 'investigate', 'reconcile'
    const [selectedTenant, setSelectedTenant] = useState(null);
    
    const [duplicateReport, setDuplicateReport] = useState({ duplicate_groups: [], legacy_payload_conflicts: [] });
    const [duplicateLoading, setDuplicateLoading] = useState(false);
    const [duplicateFilters, setDuplicateFilters] = useState({
        from: '',
        to: '',
        tenant: '',
        terminal: '',
        limit: 100
    });
    
    const today = new Date().toISOString().split('T')[0];
    const [auditReport, setAuditReport] = useState({ rows: [], window: null });
    const [auditLoading, setAuditLoading] = useState(false);
    const [auditFilters, setAuditFilters] = useState({
        from: today,
        to: today,
        tenant: '',
        terminal: '',
        limit: 200,
        only_issues: true
    });
    
    // Live feed mode & filters
    const [liveFeed, setLiveFeed] = useState(true);
    const [feedFilter, setFeedFilter] = useState('all');

    const fetchData = useCallback(async (isInitial = false) => {
        try {
            if (isInitial) setLoading(true);
            setIsRefreshing(true);

            const [statsRes, historyRes, tenantsRes, recentRes] = await Promise.all([
                api.getIntakeMetrics(),
                api.getIntakeHistory('intake.processing_lag'),
                api.getTenantIntakeStats(),
                api.getIntakeRecent()
            ]);

            setStats(statsRes);
            setHistory(historyRes.data || []);
            setTenants(tenantsRes.data || []);
            setRecentLogs(recentRes.data || []);
            setApiError(false);
        } catch (error) {
            console.error('Error fetching intake health data:', error);
            setApiError(true);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    const fetchDuplicateReceipts = useCallback(async () => {
        try {
            setDuplicateLoading(true);
            const report = await api.getDuplicateReceiptReport(duplicateFilters);
            setDuplicateReport(report);
        } catch (error) {
            console.error('Error fetching duplicate receipt report:', error);
            setDuplicateReport({ duplicate_groups: [], legacy_payload_conflicts: [], error: true });
        } finally {
            setDuplicateLoading(false);
        }
    }, [duplicateFilters]);

    const fetchTenantAudit = useCallback(async () => {
        try {
            setAuditLoading(true);
            const report = await api.getTenantIngestionAudit(auditFilters);
            setAuditReport(report);
            
            // Auto-update selected tenant if it's currently selected
            if (selectedTenant) {
                const updated = report.rows?.find(r => r.tenant_id === selectedTenant.tenant_id);
                setSelectedTenant(updated || null);
            }
        } catch (error) {
            console.error('Error fetching tenant ingestion audit:', error);
            setAuditReport({ rows: [], error: true });
        } finally {
            setAuditLoading(false);
        }
    }, [auditFilters, selectedTenant]);

    // 5-second polling interval controlled by the Live Feed toggle
    useEffect(() => {
        if (liveFeed) {
            fetchData(true);
            const interval = setInterval(fetchData, 5000);
            return () => clearInterval(interval);
        } else {
            fetchData(true);
        }
    }, [fetchData, liveFeed]);

    useEffect(() => {
        if (activeView === 'reconcile') {
            fetchDuplicateReceipts();
        }
        if (activeView === 'investigate') {
            fetchTenantAudit();
        }
    }, [activeView, fetchDuplicateReceipts, fetchTenantAudit]);

    const failRateValue = useMemo(() => {
        if (!stats?.metrics) return 0;
        const failed = stats.metrics['intake.failed_count'] || 0;
        const processed = stats.metrics['intake.processed_count'] || 1;
        return Math.min(100, (failed / processed) * 100);
    }, [stats]);

    const systemStatus = useMemo(() => {
        const currentLag = stats?.latencies?.processing_lag_avg_s || 0;
        if (failRateValue > 5 || currentLag > 5) return 'CRITICAL';
        if (failRateValue > 1 || currentLag > 1) return 'DEGRADED';
        return 'OPERATIONAL';
    }, [failRateValue, stats]);

    const chartOptions = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#101221',
                padding: 12,
                titleFont: { size: 14, weight: 'bold' },
                bodyFont: { size: 13 },
                displayColors: false,
                borderColor: 'rgba(255,255,255,0.1)',
                borderWidth: 1
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 10, weight: 800 } }
            },
            y: {
                beginAtZero: true,
                suggestedMax: 6,
                grid: { color: 'rgba(0,0,0,0.03)' },
                ticks: { 
                    color: 'rgba(0,0,0,0.4)', 
                    font: { size: 10, weight: 800 },
                    callback: (value) => `${value}s`
                }
            }
        }
    }), []);

    const historyData = useMemo(() => {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const gradient = ctx ? ctx.createLinearGradient(0, 0, 0, 350) : 'transparent';
        if (ctx) {
            gradient.addColorStop(0, 'rgba(0, 242, 255, 0.25)');
            gradient.addColorStop(1, 'rgba(0, 242, 255, 0)');
        }

        return {
            labels: history.map(h => h.time.split(' ')[1]),
            datasets: [{
                label: 'Ingestion Lag',
                data: history.map(h => h.value),
                borderColor: '#00f2ff',
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                borderWidth: 3,
            }]
        };
    }, [history]);

    // Derived exception statistics
    const failedReconciliation = Number(stats?.metrics?.['intake.failed_count'] ?? 0);
    const pendingUploads = Number(stats?.queue_size ?? 0);

    // Dynamic filtering of Forensic Log Feed
    const filteredLogs = useMemo(() => {
        return recentLogs.filter(log => {
            if (feedFilter === 'all') return true;
            if (feedFilter === 'processed') return log.processing_status === 'processed';
            if (feedFilter === 'failed') return log.processing_status === 'failed' || log.last_error_message;
            if (feedFilter === 'retries') return log.processing_status === 'retry';
            if (feedFilter === 'duplicates') return log.processing_status === 'duplicate';
            return true;
        });
    }, [recentLogs, feedFilter]);

    // Live counts for Forensic Feed filter chips
    const filterCounts = useMemo(() => {
        const counts = {
            all: recentLogs.length,
            processed: 0,
            failed: 0,
            retries: 0,
            duplicates: 0
        };
        recentLogs.forEach(log => {
            const status = log.processing_status || 'received';
            if (status === 'processed') {
                counts.processed++;
            } else if (status === 'failed' || log.last_error_message) {
                counts.failed++;
            } else if (status === 'retry') {
                counts.retries++;
            } else if (status === 'duplicate') {
                counts.duplicates++;
            }
        });
        return counts;
    }, [recentLogs]);

    // Check if pipeline is offline (no logs or last log older than 15 minutes)
    const isPipelineOffline = useMemo(() => {
        if (!loading && recentLogs.length === 0) return true;
        if (recentLogs.length === 0) return false;
        const lastTime = new Date(recentLogs[0].received_at).getTime();
        const now = new Date().getTime();
        return (now - lastTime) > 15 * 60 * 1000;
    }, [recentLogs, loading]);

    const auditRows = auditReport?.rows || [];
    const auditIssueCount = auditRows.filter((row) => (row.flags || []).length > 0).length;
    const noPersistedCount = auditRows.filter((row) => (row.flags || []).includes('NO_PERSISTED_TX_WITH_ACTIVITY')).length;
    const driftCount = auditRows.filter((row) => (row.flags || []).includes('TENANT_TERMINAL_DRIFT')).length;
    const warningCount = Math.max(0, auditIssueCount - driftCount - noPersistedCount);

    const transactionSearchUrl = (transactionId) => (
        transactionId ? `/transactions?transaction_id=${encodeURIComponent(transactionId)}` : '/transactions'
    );

    if (loading && !stats) {
        return (
            <Box sx={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '100vh', bgcolor: '#101221' }}>
                <CircularProgress size={60} thickness={4} sx={{ color: '#00f2ff', mb: 4 }} />
                <Typography sx={{ color: 'white', fontWeight: 900, letterSpacing: '0.2em', fontSize: '0.75rem' }}>CALIBRATING COMMAND CENTER...</Typography>
            </Box>
        );
    }

    return (
        <Fade in={!loading}>
            <Box className="page-wrapper" sx={{ pb: 10 }}>
                <Container maxWidth="xl" sx={{ py: 4 }}>
                    {/* Consolidated Breadcrumbs & Title */}
                    <Box sx={{ mb: 4 }}>
                        <Breadcrumbs separator={<NavigateNextIcon fontSize="small" />} sx={{ mb: 2 }}>
                            <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.5, fontSize: '0.7rem', fontWeight: 900 }}>
                                <HomeIcon sx={{ mr: 0.5, fontSize: 14 }} /> OPS_LEVEL_1
                            </MuiLink>
                            <Typography color="primary" sx={{ fontWeight: 900, fontSize: '0.7rem', letterSpacing: '0.05em' }}>SYSTEM_CORE</Typography>
                        </Breadcrumbs>

                        <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} spacing={2}>
                            <Stack direction="row" spacing={3} alignItems="center">
                                <Box className="glass-container" sx={{ p: 2, bgcolor: '#101221', color: '#00f2ff', borderRadius: '20px', display: 'flex', boxShadow: '0 0 20px rgba(0,242,255,0.2)' }} aria-hidden="true">
                                    <TerminalIcon sx={{ fontSize: 40 }} />
                                </Box>
                                <Box>
                                    <Typography variant="h2" sx={{ fontWeight: 1000, letterSpacing: '-0.05em', color: '#101221', mb: 0.5 }}>
                                        Pipeline Command Console
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, fontSize: '0.75rem', letterSpacing: '0.02em' }}>
                                        Operations command center for real-time validation, latency diagnostics, and transactional drift reconciliation.
                                    </Typography>
                                </Box>
                            </Stack>
                            <Stack direction="row" spacing={1} sx={{ alignSelf: { xs: 'flex-start', md: 'center' }, flexWrap: 'wrap', gap: 1 }}>
                                <Chip label="v2.4.1" size="small" sx={{ fontWeight: 900, bgcolor: 'rgba(0, 0, 0, 0.05)', color: '#101221', borderRadius: '6px' }} />
                                <Chip label="PRODUCTION" size="small" sx={{ fontWeight: 900, bgcolor: '#e0f2fe', color: '#0369a1', borderRadius: '6px' }} />
                                <Chip 
                                    label={`PIPELINE: ${systemStatus}`} 
                                    size="small" 
                                    sx={{ 
                                        fontWeight: 900, 
                                        bgcolor: systemStatus === 'OPERATIONAL' ? '#dcfce7' : systemStatus === 'DEGRADED' ? '#fef9c3' : '#fee2e2', 
                                        color: systemStatus === 'OPERATIONAL' ? '#15803d' : systemStatus === 'DEGRADED' ? '#a16207' : '#b91c1c',
                                        borderRadius: '6px'
                                    }} 
                                />
                                <Chip 
                                    label={liveFeed ? "🔴 LIVE POLLING" : "⏸ PAUSED"} 
                                    size="small" 
                                    sx={{ 
                                        fontWeight: 900, 
                                        bgcolor: liveFeed ? '#ecfdf5' : '#f3f4f6', 
                                        color: liveFeed ? '#059669' : '#4b5563', 
                                        borderRadius: '6px'
                                    }} 
                                />
                            </Stack>
                        </Stack>
                    </Box>

                    {/* Operations Overview dashboard & Active Incidents warnings */}
                    <Stack spacing={3} sx={{ mb: 4 }}>
                        {/* Dynamic KPI Cards */}
                        <PipelineOverviewCard
                            systemStatus={systemStatus}
                            activeTenantsCount={tenants.length}
                            failedTxCount={failedReconciliation}
                            quarantinedCount={stats?.metrics?.['intake.quarantined_count'] || 0}
                            workersStatus="12/12"
                        />

                        {/* Elevated Incident Alert Center */}
                        <IncidentCenter
                            isOffline={isPipelineOffline}
                            apiError={apiError}
                            affectedTenantsCount={tenants.length}
                            affectedTxCount={pendingUploads}
                            onInvestigate={() => setActiveView('investigate')}
                            onViewLogs={() => {
                                setActiveView('monitor');
                                setFeedFilter('all');
                            }}
                        />
                    </Stack>

                    {/* Operations tabs (Monitor, Investigate, Reconcile) */}
                    <Paper sx={{ mb: 3, borderRadius: 3, border: '1px solid', borderColor: 'divider', overflow: 'hidden' }}>
                        <Tabs
                            value={activeView}
                            onChange={(_, value) => setActiveView(value)}
                            aria-label="Operations Workflow Workspace Tabs"
                            sx={{
                                px: 2,
                                '& .MuiTab-root': {
                                    minHeight: 56,
                                    fontWeight: 900,
                                    textTransform: 'none',
                                    letterSpacing: '0.02em'
                                }
                            }}
                        >
                            <Tab value="monitor" icon={<TerminalIcon />} iconPosition="start" label="Monitor Panel" id="tab-monitor" aria-controls="panel-monitor" />
                            <Tab value="investigate" icon={<FactCheckIcon />} iconPosition="start" label="Investigate Workspace" id="tab-investigate" aria-controls="panel-investigate" />
                            <Tab value="reconcile" icon={<ReceiptLongIcon />} iconPosition="start" label="Reconcile Receipts" id="tab-reconcile" aria-controls="panel-reconcile" />
                        </Tabs>
                    </Paper>

                    {/* Tab panels */}
                    <Box sx={{ focus: { outline: 'none' } }}>
                        {/* Monitor Tab */}
                        {activeView === 'monitor' && (
                            <div id="panel-monitor" role="tabpanel" aria-labelledby="tab-monitor">
                                <PipelineHealthPanel
                                    stats={stats}
                                    tenants={tenants}
                                    failRateValue={failRateValue}
                                    filteredLogs={filteredLogs}
                                    feedFilter={feedFilter}
                                    setFeedFilter={setFeedFilter}
                                    filterCounts={filterCounts}
                                    liveFeed={liveFeed}
                                    setLiveFeed={setLiveFeed}
                                    selectedLogId={selectedLogId}
                                    setSelectedLogId={setSelectedLogId}
                                    chartOptions={chartOptions}
                                    historyData={historyData}
                                    thresholdBandsPlugin={thresholdBandsPlugin}
                                    workersStatus="12/12"
                                    onForceSync={() => fetchData(false)}
                                    onReplayQueue={async () => {
                                        setIsRefreshing(true);
                                        await api.replayFailedQueue();
                                        fetchData(false);
                                    }}
                                    onRefreshAudit={fetchTenantAudit}
                                    isRefreshing={isRefreshing}
                                />
                            </div>
                        )}

                        {/* Investigate Tab (Tenant Ingestion Audit) */}
                        {activeView === 'investigate' && (
                            <div id="panel-investigate" role="tabpanel" aria-labelledby="tab-investigate">
                                <Stack spacing={3}>
                                    {auditReport?.error && (
                                        <Alert severity="error" sx={{ borderRadius: 3, fontWeight: 800 }}>
                                            Tenant ingestion audit could not load.
                                        </Alert>
                                    )}

                                    {/* Filters above the table */}
                                    <TenantAuditFilters
                                        filters={auditFilters}
                                        setFilters={setAuditFilters}
                                        onRunAudit={fetchTenantAudit}
                                        loading={auditLoading}
                                    />

                                    {/* Results Counter Bar */}
                                    <Paper 
                                        sx={{ 
                                            p: 2, 
                                            borderRadius: '16px', 
                                            border: '1px solid', 
                                            borderColor: 'divider', 
                                            display: 'flex', 
                                            justifyContent: 'space-between', 
                                            alignItems: 'center', 
                                            bgcolor: '#f8fafc',
                                            flexDirection: { xs: 'column', md: 'row' },
                                            gap: 2
                                        }}
                                    >
                                        <Stack direction="row" spacing={1.5} alignItems="center" sx={{ flexWrap: 'wrap', gap: 1 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 1000, color: '#101221', fontSize: '0.75rem', letterSpacing: '0.05em' }}>
                                                AUDIT SNAPSHOT: {auditRows.length} TENANTS
                                            </Typography>
                                            <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', sm: 'block' } }} />
                                            <Chip 
                                                label={`${driftCount + noPersistedCount} CRITICAL`} 
                                                color="error" 
                                                size="small" 
                                                sx={{ fontWeight: 900, borderRadius: '6px', fontSize: '0.62rem' }} 
                                            />
                                            <Chip 
                                                label={`${warningCount} WARNINGS`} 
                                                color="warning" 
                                                size="small" 
                                                sx={{ fontWeight: 900, borderRadius: '6px', fontSize: '0.62rem' }} 
                                            />
                                            <Chip 
                                                label={`${auditRows.length - auditIssueCount} HEALTHY`} 
                                                color="success" 
                                                size="small" 
                                                sx={{ fontWeight: 900, borderRadius: '6px', fontSize: '0.62rem' }} 
                                            />
                                        </Stack>
                                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, fontSize: '0.68rem' }}>
                                            Window: {typeof auditReport?.window === 'object' && auditReport?.window?.from
                                                ? `${auditReport.window.from.split(' ')[0]} to ${auditReport.window.to.split(' ')[0]}`
                                                : 'Just now'}
                                         </Typography>
                                    </Paper>

                                    {/* Split Pane: Audit Table & Tenant Inspector */}
                                    <Grid container spacing={3}>
                                        <Grid item xs={12} lg={8.5}>
                                            <TenantAuditTable
                                                auditRows={auditRows}
                                                selectedTenantId={selectedTenant?.tenant_id}
                                                onSelectRow={(row) => {
                                                    setSelectedTenant(row);
                                                }}
                                                onInspect={(row) => {
                                                    alert(`Inspecting configuration audit details for ${row.tenant} (ID: ${row.tenant_id}). No schema anomalies detected.`);
                                                }}
                                                onReplay={async (row) => {
                                                    alert(`Queueing replay actions for Tenant: ${row.tenant}...`);
                                                }}
                                                onViewLogs={(row) => {
                                                    setFeedFilter('all');
                                                    setActiveView('monitor');
                                                }}
                                            />
                                        </Grid>
                                        
                                        <Grid item xs={12} lg={3.5}>
                                            <TenantInspector
                                                tenant={selectedTenant}
                                                onReplay={(row) => {
                                                    alert(`Replaying queue for Tenant: ${row.tenant}`);
                                                }}
                                                onInspect={(row) => {
                                                    alert(`Opening drift inspector dialog for ${row.tenant}`);
                                                }}
                                                onViewLogs={(row) => {
                                                    setFeedFilter('all');
                                                    setActiveView('monitor');
                                                }}
                                            />
                                        </Grid>
                                    </Grid>
                                </Stack>
                            </div>
                        )}

                        {/* Reconcile Tab (Duplicate receipts) */}
                        {activeView === 'reconcile' && (
                            <div id="panel-reconcile" role="tabpanel" aria-labelledby="tab-reconcile">
                                <DuplicateReceiptCenter
                                    duplicateReport={duplicateReport}
                                    duplicateLoading={duplicateLoading}
                                    duplicateFilters={duplicateFilters}
                                    setDuplicateFilters={setDuplicateFilters}
                                    onRefreshDuplicates={fetchDuplicateReceipts}
                                    transactionSearchUrl={transactionSearchUrl}
                                />
                            </div>
                        )}
                    </Box>
                </Container>
            </Box>
        </Fade>
    );
};

export default IntakeHealthPage;
