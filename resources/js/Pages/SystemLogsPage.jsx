import React, { useState, useEffect, useCallback } from 'react';
import {
    Box,
    Typography,
    Stack,
    Tabs,
    Tab,
    Breadcrumbs,
    Link as MuiLink,
    Grid,
    Paper,
    Snackbar,
    Alert
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import ListAltIcon from '@mui/icons-material/ListAlt';
import BugReportIcon from '@mui/icons-material/BugReport';
import HistoryIcon from '@mui/icons-material/History';
import LanguageIcon from '@mui/icons-material/Language';
import CallMergeIcon from '@mui/icons-material/CallMerge';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import Tooltip from '@mui/material/Tooltip';
import LogFilterBar from '../Components/logs/LogFilterBar';
import LogTable from '../Components/logs/LogTable';
import IncidentsTable from '../Components/logs/IncidentsTable';
import { systemLogService } from '../services/systemLogService';
import { incidentService } from '../services/incidentService';

const StatCard = ({ title, value, color, icon, description }) => (
    <Paper elevation={0} sx={{ p: 2, borderRadius: 3, border: '1px solid', borderColor: 'divider', boxShadow: '0 4px 12px rgba(0,0,0,0.02)' }}>
        <Stack direction="row" spacing={2} alignItems="center">
            <Box sx={{ p: 1, borderRadius: 2, bgcolor: `${color}.50`, color: `${color}.main`, display: 'flex' }}>
                {icon}
            </Box>
            <Box sx={{ flexGrow: 1 }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
                    <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', fontSize: '0.65rem', letterSpacing: '0.05em' }}>
                        {title}
                    </Typography>
                    <Tooltip title={description} arrow placement="top">
                        <InfoOutlinedIcon sx={{ fontSize: 14, opacity: 0.5, cursor: 'pointer', '&:hover': { opacity: 0.8, color: 'primary.main' } }} />
                    </Tooltip>
                </Stack>
                <Typography variant="h5" sx={{ fontWeight: 950, color: 'text.primary', lineHeight: 1.2 }}>
                    {value}
                </Typography>
            </Box>
        </Stack>
    </Paper>
);

const SystemLogsPage = () => {
    const [activeTab, setActiveTab] = useState('system');
    const [loading, setLoading] = useState(true);
    const [logData, setLogData] = useState(null);
    const [incidents, setIncidents] = useState(null);
    const [filters, setFilters] = useState({
        type: '',
        severity: '',
        date_from: '',
        date_to: '',
        terminal: '',
        search: ''
    });

    // Pagination per tab
    const [pages, setPages] = useState({
        system: 1,
        audit: 1,
        webhook: 1,
        submission: 1
    });

    const [incidentPage, setIncidentPage] = useState(1);

    const [notification, setNotification] = useState({ open: false, message: '', severity: 'info' });

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const params = {
                ...filters,
                system_page: pages.system,
                audit_page: pages.audit,
                webhook_page: pages.webhook,
                submission_page: pages.submission
            };
            const [logsData, incidentsData] = await Promise.all([
                systemLogService.getLogs(params),
                incidentService.getIncidents({
                    from: filters.date_from || undefined,
                    to: filters.date_to || undefined,
                    terminal_id: filters.terminal || undefined,
                    page: incidentPage,
                    per_page: 15
                })
            ]);
            setLogData(logsData);
            setIncidents(incidentsData);
        } catch (error) {
            setNotification({ open: true, message: 'Identity orchestration failed to synchronize with log authority.', severity: 'error' });
        } finally {
            setLoading(false);
        }
    }, [filters, pages, incidentPage]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const handleTabChange = (event, newValue) => {
        setActiveTab(newValue);
    };

    const handlePageChange = (type, page) => {
        setPages(prev => ({ ...prev, [type]: page }));
    };

    const handlePruneClick = () => {
        if (confirm("CRITICAL INTERVENTION: This will initiate the Master Prune sequence for the telemetry archive. Are you certain?")) {
            setNotification({ open: true, message: 'Pruning sequence initiated. Refer to archival protocol.', severity: 'warning' });
        }
    };

    return (
        <Box sx={{ pb: 8 }}>
            <Box sx={{ py: 3 }}>
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        SYSTEM
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>LOG ARCHIVE</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems="flex-start" sx={{ mb: 5 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(25, 118, 210, 0.25)' }}>
                                <ListAltIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    System Telemetry Archive
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Deep-packet inspection and auditing of global system orchestration.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>
                </Stack>

                <Grid container spacing={2} sx={{ mb: 4 }}>
                    <Grid item xs={12} sm={6} md={3}>
                        <StatCard
                            title="System Errors"
                            value={logData?.stats?.errors || 0}
                            color="error"
                            icon={<BugReportIcon />}
                            description="Total volume of internal application exceptions and critical service failures detected within the last 24 hours."
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <StatCard
                            title="Audit Entries"
                            value={logData?.stats?.total || 0}
                            color="primary"
                            icon={<HistoryIcon />}
                            description="Total number of security-tracked administrative actions and system configuration changes logged."
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <StatCard
                            title="Webhook Drops"
                            value={logData?.stats?.webhook_errors || 0}
                            color="warning"
                            icon={<LanguageIcon />}
                            description="Total number of failed external data synchronizations and outgoing event notifications."
                        />
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                        <StatCard
                            title="Auth Events"
                            value={logData?.stats?.auth_events || 0}
                            color="success"
                            icon={<CallMergeIcon />}
                            description="Count of login attempts, session terminations, and identity verification sequences."
                        />
                    </Grid>
                </Grid>

                <LogFilterBar
                    filters={filters}
                    activeTab={activeTab}
                    onFilterChange={setFilters}
                    onReset={setFilters}
                    terminals={logData?.terminals || []}
                    onPruneClick={handlePruneClick}
                />

                <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 3 }}>
                    <Tabs
                        value={activeTab}
                        onChange={handleTabChange}
                        textColor="primary"
                        indicatorColor="primary"
                        sx={{
                            '& .MuiTab-root': { fontWeight: 800, textTransform: 'none', fontSize: '0.9rem' }
                        }}
                    >
                        <Tab
                            value="system"
                            label={
                                <Stack direction="row" spacing={1} alignItems="center">
                                    <span>System Registry</span>
                                    <Tooltip title="High-level engine events, system-level errors, and security-critical alerts.">
                                        <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.6 }} />
                                    </Tooltip>
                                </Stack>
                            }
                        />
                        <Tab
                            value="audit"
                            label={
                                <Stack direction="row" spacing={1} alignItems="center">
                                    <span>Audit Trail</span>
                                    <Tooltip title="Tracking user actions, administrative overrides, and system configuration modifications.">
                                        <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.6 }} />
                                    </Tooltip>
                                </Stack>
                            }
                        />
                        <Tab
                            value="webhook"
                            label={
                                <Stack direction="row" spacing={1} alignItems="center">
                                    <span>Webhook Orchestration</span>
                                    <Tooltip title="Real-time status of outgoing external notifications sent to terminal endpoints.">
                                        <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.6 }} />
                                    </Tooltip>
                                </Stack>
                            }
                        />
                        <Tab
                            value="submission"
                            label={
                                <Stack direction="row" spacing={1} alignItems="center">
                                    <span>Node Submissions</span>
                                    <Tooltip title="Direct telemetry and payload data submitted from remote terminal hardware.">
                                        <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.6 }} />
                                    </Tooltip>
                                </Stack>
                            }
                        />
                        <Tab
                            value="incidents"
                            label={
                                <Stack direction="row" spacing={1} alignItems="center">
                                    <span>Incidents</span>
                                    <Tooltip title="Aggregated submission issues and POS-facing tickets derived from failed official payloads.">
                                        <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.6 }} />
                                    </Tooltip>
                                </Stack>
                            }
                        />
                    </Tabs>
                </Box>

                <Box>
                    {activeTab === 'system' && (
                        <LogTable
                            data={logData?.systemLogs}
                            loading={loading}
                            type="system"
                            onPageChange={(p) => handlePageChange('system', p)}
                        />
                    )}
                    {activeTab === 'audit' && (
                        <LogTable
                            data={logData?.auditLogs}
                            loading={loading}
                            type="audit"
                            onPageChange={(p) => handlePageChange('audit', p)}
                        />
                    )}
                    {activeTab === 'webhook' && (
                        <LogTable
                            data={logData?.webhookLogs}
                            loading={loading}
                            type="webhook"
                            onPageChange={(p) => handlePageChange('webhook', p)}
                        />
                    )}
                    {activeTab === 'submission' && (
                        <LogTable
                            data={logData?.submissionEvents}
                            loading={loading}
                            type="submission"
                            onPageChange={(p) => handlePageChange('submission', p)}
                        />
                    )}
                    {activeTab === 'incidents' && (
                        <IncidentsTable
                            data={incidents}
                            loading={loading}
                            onPageChange={(p) => setIncidentPage(p)}
                        />
                    )}
                </Box>
            </Box>

            <Snackbar
                open={notification.open}
                autoHideDuration={5000}
                onClose={() => setNotification({ ...notification, open: false })}
                anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
            >
                <Alert severity={notification.severity} variant="filled" sx={{ borderRadius: 3, fontWeight: 700, minWidth: 250 }}>
                    {notification.message}
                </Alert>
            </Snackbar>
        </Box>
    );
};

export default SystemLogsPage;
