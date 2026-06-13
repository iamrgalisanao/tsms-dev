import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Activity,
    AlertTriangle,
    BellOff,
    CheckCircle2,
    Clock3,
    Download,
    Loader2,
    RefreshCcw,
    Search,
    Server,
    Store,
    X
} from 'lucide-react';
import api from '../../services/api';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

function cn(...classes) {
    return classes.filter(Boolean).join(' ');
}

const numberFormat = new Intl.NumberFormat();

const statusMeta = {
    active: {
        label: 'Active',
        icon: CheckCircle2,
        classes: 'border-emerald-200 bg-emerald-50 text-emerald-700'
    },
    silent: {
        label: 'Silent',
        icon: AlertTriangle,
        classes: 'border-amber-200 bg-amber-50 text-amber-700'
    },
    no_submission_today: {
        label: 'No submission today',
        icon: Clock3,
        classes: 'border-sky-200 bg-sky-50 text-sky-700'
    },
    inactive_configured: {
        label: 'Inactive configured',
        icon: AlertTriangle,
        classes: 'border-rose-200 bg-rose-50 text-rose-700'
    }
};

const formatDateTime = (value) => {
    if (!value) return 'Never';
    return new Date(value).toLocaleString();
};

const todayDateInput = () => {
    const today = new Date();
    const offset = today.getTimezoneOffset() * 60000;
    return new Date(today.getTime() - offset).toISOString().slice(0, 10);
};

const toDateTimeLocalInput = (value) => {
    if (!value) return '';
    const date = new Date(value);
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
};

const StatusBadge = ({ status }) => {
    const meta = statusMeta[status] || {
        label: status || 'Unknown',
        icon: Clock3,
        classes: 'border-slate-200 bg-slate-50 text-slate-700'
    };
    const Icon = meta.icon;

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold', meta.classes)}>
            <Icon className="h-3.5 w-3.5" />
            {meta.label}
        </span>
    );
};

const MetricCard = ({ title, value, helper, icon: Icon, tone = 'blue' }) => {
    const tones = {
        blue: 'bg-blue-50 text-blue-700 ring-blue-100',
        emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        amber: 'bg-amber-50 text-amber-700 ring-amber-100',
        sky: 'bg-sky-50 text-sky-700 ring-sky-100'
    };
    return (
        <Card className="relative overflow-hidden rounded-2xl border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-xs font-extrabold uppercase tracking-wide text-slate-500">{title}</p>
                    <p className="mt-2 text-3xl font-black leading-none tracking-tight text-slate-950">
                        {numberFormat.format(value || 0)}
                    </p>
                    <p className="mt-2 text-sm text-slate-500">{helper}</p>
                </div>
                <div className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-xl ring-1', tones[tone], 'border-l-4 border-blue-100')}> {/* subtle accent border */}
                    <Icon className="h-4 w-4" />
                </div>
            </div>
        </Card>
    );
};

const SummaryTile = ({ label, value }) => (
    <div className="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
        <p className="text-xs font-extrabold uppercase tracking-wide text-slate-500">{label}</p>
        <p className="mt-2 text-2xl font-black text-slate-950">{numberFormat.format(value || 0)}</p>
    </div>
);

const FieldLabel = ({ children }) => (
    <label className="mb-1.5 block text-xs font-bold uppercase tracking-wide text-slate-500">{children}</label>
);

const SelectField = ({ label, value, onChange, children }) => (
    <div className="min-w-[180px] flex-1">
        <FieldLabel>{label}</FieldLabel>
        <select
            value={value}
            onChange={onChange}
            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
        >
            {children}
        </select>
    </div>
);

const ProviderActivityPage = () => {
    const [activeTab, setActiveTab] = useState('tenants');
    const [thresholdMode, setThresholdMode] = useState('configured');
    const [statusFilter, setStatusFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [reportDate, setReportDate] = useState(todayDateInput());
    const [dailyReport, setDailyReport] = useState(null);
    const [reportLoading, setReportLoading] = useState(false);
    const [tenantRows, setTenantRows] = useState([]);
    const [terminalRows, setTerminalRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [editingRow, setEditingRow] = useState(null);
    const [savingConfig, setSavingConfig] = useState(false);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);

    const thresholdOverride = thresholdMode === 'configured' ? null : Number(thresholdMode);

    const loadActivity = useCallback(async (initial = false) => {
        if (initial) setLoading(true);
        setRefreshing(true);
        setError(null);

        try {
            const [tenants, terminals] = await Promise.all([
                api.getProviderTenantActivity(thresholdOverride),
                api.getProviderTerminalActivity(thresholdOverride)
            ]);

            setTenantRows(tenants.data || []);
            setTerminalRows(terminals.data || []);
            setMeta(tenants.meta || terminals.meta || null);
        } catch (requestError) {
            setError(requestError.response?.data?.message || requestError.message || 'Unable to load provider activity.');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [thresholdOverride]);

    const loadDailyReport = useCallback(async () => {
        setReportLoading(true);
        setError(null);

        try {
            const report = await api.getProviderDailyHeartbeatReport(reportDate, thresholdOverride);
            setDailyReport(report.data || null);
        } catch (requestError) {
            setError(requestError.response?.data?.message || requestError.message || 'Unable to load daily heartbeat report.');
        } finally {
            setReportLoading(false);
        }
    }, [reportDate, thresholdOverride]);

    const downloadDailyReport = () => {
        const params = new URLSearchParams({ format: 'csv' });

        if (reportDate) params.set('date', reportDate);
        if (thresholdOverride) params.set('threshold_minutes', String(thresholdOverride));

        window.location.href = `/api/monitoring/activity/daily-report?${params.toString()}`;
    };

    useEffect(() => {
        loadActivity(true);
        loadDailyReport();
    }, [loadActivity, loadDailyReport]);

    const currentRows = activeTab === 'tenants' ? tenantRows : terminalRows;

    const filteredRows = useMemo(() => {
        const query = search.trim().toLowerCase();

        return currentRows.filter((row) => {
            const statusMatches = statusFilter === 'all' || row.status === statusFilter;
            const searchMatches = !query || [
                row.tenant_name,
                row.customer_code,
                row.serial_number,
                row.machine_number,
                row.tenant_id,
                row.terminal_id
            ].some((value) => String(value ?? '').toLowerCase().includes(query));

            return statusMatches && searchMatches;
        });
    }, [currentRows, search, statusFilter]);

    const counts = useMemo(() => ({
        total: currentRows.length,
        active: currentRows.filter((row) => row.status === 'active').length,
        silent: currentRows.filter((row) => row.status === 'silent').length,
        noSubmission: currentRows.filter((row) => row.status === 'no_submission_today').length,
        suppressed: currentRows.filter((row) => row.alert_suppressed).length
    }), [currentRows]);

    const saveMonitoringConfig = async () => {
        if (!editingRow) return;

        setSavingConfig(true);
        setError(null);

        const payload = {
            activity_monitoring_enabled: Boolean(editingRow.monitoring_enabled),
            activity_threshold_minutes: editingRow.threshold_minutes ? Number(editingRow.threshold_minutes) : null,
            activity_monitoring_notes: editingRow.monitoring_notes || null,
            activity_suppressed_until: editingRow.alert_suppressed_until || null,
            activity_suppression_reason: editingRow.alert_suppression_reason || null
        };

        try {
            if (editingRow.type === 'tenant') {
                await api.updateTenantMonitoringConfig(editingRow.id, payload);
            } else {
                await api.updateTerminalMonitoringConfig(editingRow.id, payload);
            }

            setEditingRow(null);
            await loadActivity(false);
        } catch (requestError) {
            setError(requestError.response?.data?.message || requestError.message || 'Unable to save monitoring configuration.');
        } finally {
            setSavingConfig(false);
        }
    };

    if (loading) {
        return (
            <div className="grid min-h-[420px] place-items-center">
                <div className="flex flex-col items-center gap-3 text-slate-500">
                    <Loader2 className="h-8 w-8 animate-spin text-blue-700" />
                    <p className="text-sm font-bold">Loading provider activity</p>
                </div>
            </div>
        );
    }

    const tableColumnCount = activeTab === 'tenants' ? 12 : 11;

    return (
        <div className="min-h-screen bg-slate-50/70 p-4 text-slate-950 sm:p-6 lg:p-8">
            <div className="mx-auto max-w-[1500px] space-y-6">
                <header className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div className="flex items-center gap-4">
                        <div className="grid h-12 w-12 place-items-center rounded-2xl bg-blue-700 text-white shadow-lg shadow-blue-700/20">
                            <Activity className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight text-slate-950 md:text-4xl">
                                Provider Activity Monitoring
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Daily tenant and terminal transaction activity for silent-sender detection.
                            </p>
                        </div>
                    </div>

                    <Button
                        onClick={() => loadActivity(false)}
                        disabled={refreshing}
                        className="h-11 rounded-xl bg-blue-700 px-5 font-bold shadow-lg shadow-blue-700/20 hover:bg-blue-800 text-white"
                    >
                        {refreshing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCcw className="mr-2 h-4 w-4" />}
                        Refresh
                    </Button>
                </header>

                {error && (
                    <div className="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        title="Tracked"
                        value={counts.total}
                        helper={activeTab === 'tenants' ? 'Tenants in scope' : 'Terminals in scope'}
                        icon={activeTab === 'tenants' ? Store : Server}
                    />
                    <MetricCard title="Active Today" value={counts.active} helper="Has transactions today" icon={CheckCircle2} tone="emerald" />
                    <MetricCard
                        title="Silent"
                        value={counts.silent}
                        helper={thresholdMode === 'configured' ? 'Using configured thresholds' : `Past ${thresholdMode} minutes`}
                        icon={AlertTriangle}
                        tone="amber"
                    />
                    <MetricCard title="No Submission" value={counts.noSubmission} helper="No transaction today" icon={Clock3} tone="sky" />
                    <MetricCard title="Suppressed" value={counts.suppressed} helper="Alerts snoozed by operations" icon={BellOff} tone="blue" />
                </section>

                <Card className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 className="text-xl font-black tracking-tight">Daily Heartbeat Report</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                Tenant-level daily continuity report using the same configured thresholds as the activity monitor.
                            </p>
                        </div>

                        <div className="flex flex-wrap items-end gap-2 sm:gap-3">
                            <div>
                                <FieldLabel>Report date</FieldLabel>
                                <input
                                    type="date"
                                    value={reportDate}
                                    max={todayDateInput()}
                                    onChange={(event) => setReportDate(event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                />
                            </div>
                            <Button variant="outline" onClick={loadDailyReport} disabled={reportLoading} className="h-11 rounded-xl font-bold text-slate-900 border-slate-400">
                                {reportLoading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCcw className="mr-2 h-4 w-4" />}
                                Load
                            </Button>
                            <Button onClick={downloadDailyReport} className="h-11 rounded-xl bg-blue-700 font-bold hover:bg-blue-800 text-white">
                                <Download className="mr-2 h-4 w-4" />
                                CSV
                            </Button>
                        </div>
                    </div>

                    <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                        {[
                            ['Tracked tenants', dailyReport?.summary?.tracked_tenants],
                            ['Active', dailyReport?.summary?.active],
                            ['Silent', dailyReport?.summary?.silent],
                            ['No submission', dailyReport?.summary?.no_submission_today],
                            ['Inactive configured', dailyReport?.summary?.inactive_configured],
                            ['Suppressed alerts', dailyReport?.summary?.suppressed_alerts],
                            ['Transactions', dailyReport?.summary?.transactions_today],
                            ['Active terminals', dailyReport?.summary?.active_terminals_today],
                            ['Silent terminals', dailyReport?.summary?.silent_terminals]
                        ].map(([label, value]) => (
                            <SummaryTile key={label} label={label} value={value} />
                        ))}
                    </div>
                </Card>

                <Card className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-col gap-4 border-b border-slate-200 p-4 lg:flex-row lg:items-end lg:justify-between">
                        <div className="inline-flex rounded-2xl bg-slate-100 p-1">
                            {[
                                ['tenants', Store, 'Tenants', tenantRows.length],
                                ['terminals', Server, 'Terminals', terminalRows.length]
                            ].map(([value, Icon, label, count]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setActiveTab(value)}
                                    className={cn(
                                        'inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-extrabold transition relative',
                                        activeTab === value
                                            ? 'bg-white text-blue-700 shadow-sm'
                                            : 'text-slate-500 hover:text-slate-900'
                                    )}
                                >
                                    <Icon className="h-4 w-4" />
                                    {label}
                                    <span className="ml-1 inline-flex items-center justify-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-bold text-blue-700 min-w-[1.5em]">{count}</span>
                                </button>
                            ))}
                        </div>

                        <div className="grid gap-3 md:grid-cols-[190px_210px_minmax(220px,1fr)] lg:min-w-[680px]">
                            <SelectField label="Status" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
                                <option value="all">All statuses</option>
                                <option value="active">Active</option>
                                <option value="silent">Silent</option>
                                <option value="no_submission_today">No submission today</option>
                                <option value="inactive_configured">Inactive configured</option>
                            </SelectField>

                            <SelectField label="Threshold mode" value={thresholdMode} onChange={(event) => setThresholdMode(event.target.value)}>
                                <option value="configured">Configured</option>
                                <option value="60">Override: 1 hour</option>
                                <option value="360">Override: 6 hours</option>
                                <option value="720">Override: 12 hours</option>
                                <option value="1440">Override: 24 hours</option>
                                <option value="2880">Override: 48 hours</option>
                            </SelectField>

                            <div>
                                <FieldLabel>Search</FieldLabel>
                                <div className="relative">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <input
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        placeholder="Tenant, code, terminal"
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm font-medium shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1120px] border-collapse text-sm">
                            <thead className="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-3 font-black">Status</th>
                                    <th className="px-4 py-3 font-black">Tenant</th>
                                    {activeTab === 'terminals' && <th className="px-4 py-3 font-black">Terminal</th>}
                                    <th className="px-4 py-3 text-right font-black">Today</th>
                                    <th className="px-4 py-3 text-right font-black">Yesterday</th>
                                    {activeTab === 'tenants' && <th className="px-4 py-3 text-right font-black">Active Terminals</th>}
                                    {activeTab === 'tenants' && <th className="px-4 py-3 text-right font-black">Silent Terminals</th>}
                                    <th className="px-4 py-3 text-right font-black">Threshold</th>
                                    <th className="px-4 py-3 font-black">Last Received</th>
                                    <th className="px-4 py-3 font-black">Last Transaction</th>
                                    <th className="px-4 py-3 text-right font-black">Minutes Since</th>
                                    <th className="px-4 py-3 font-black">Alert</th>
                                    <th className="px-4 py-3 text-right font-black">Config</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filteredRows.map((row) => (
                                    <tr
                                        key={activeTab === 'tenants' ? row.tenant_id : row.terminal_id}
                                        className="transition hover:bg-slate-50/80"
                                    >
                                        <td className="px-4 py-4">
                                            <StatusBadge status={row.status} />
                                        </td>
                                        <td className="px-4 py-4">
                                            <p className="font-black text-slate-950">{row.tenant_name || `Tenant ${row.tenant_id}`}</p>
                                            <p className="mt-0.5 text-xs" style={{ color: '#475569' }}>{row.customer_code || `tenant_id ${row.tenant_id}`}</p>
                                        </td>
                                        {activeTab === 'terminals' && (
                                            <td className="px-4 py-4">
                                                <p className="font-black text-slate-950">{row.serial_number || `Terminal ${row.terminal_id}`}</p>
                                                <p className="mt-0.5 text-xs" style={{ color: '#475569' }}>{row.machine_number || `terminal_id ${row.terminal_id}`}</p>
                                            </td>
                                        )}
                                        <td className="px-4 py-4 text-right font-semibold">{numberFormat.format(row.transactions_today || 0)}</td>
                                        <td className="px-4 py-4 text-right font-semibold">{numberFormat.format(row.transactions_yesterday || 0)}</td>
                                        {activeTab === 'tenants' && <td className="px-4 py-4 text-right font-semibold">{numberFormat.format(row.active_terminals_today || 0)}</td>}
                                        {activeTab === 'tenants' && <td className="px-4 py-4 text-right font-semibold">{numberFormat.format(row.silent_terminals || 0)}</td>}
                                        <td className="px-4 py-4 text-right">
                                            <p className="font-black">{numberFormat.format(row.threshold_minutes || 0)} min</p>
                                            {!row.monitoring_enabled && <p className="text-xs font-bold text-rose-600">disabled</p>}
                                        </td>
                                        <td className="px-4 py-4" style={{ color: '#475569' }}>{formatDateTime(row.last_received_at)}</td>
                                        <td className="px-4 py-4" style={{ color: '#475569' }}>{formatDateTime(row.last_transaction_timestamp)}</td>
                                        <td className="px-4 py-4 text-right font-semibold">{row.minutes_since_last_transaction ?? 'N/A'}</td>
                                        <td className="px-4 py-4">
                                            {row.alert_suppressed ? (
                                                <div className="inline-flex max-w-[220px] items-start gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-700">
                                                    <BellOff className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                    <span>
                                                        <span className="block font-black">Suppressed</span>
                                                        <span className="block">Until {formatDateTime(row.alert_suppressed_until)}</span>
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-xs font-bold text-slate-400">Eligible</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-4 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="rounded-full px-5 py-2 font-bold text-blue-700 border-blue-200 hover:bg-blue-50"
                                                onClick={() => setEditingRow({
                                                    type: activeTab === 'tenants' ? 'tenant' : 'terminal',
                                                    id: activeTab === 'tenants' ? row.tenant_id : row.terminal_id,
                                                    label: activeTab === 'tenants'
                                                        ? (row.tenant_name || `Tenant ${row.tenant_id}`)
                                                        : (row.serial_number || `Terminal ${row.terminal_id}`),
                                                    monitoring_enabled: row.monitoring_enabled,
                                                    threshold_minutes: row.threshold_minutes,
                                                    monitoring_notes: row.monitoring_notes || '',
                                                    alert_suppressed_until: toDateTimeLocalInput(row.alert_suppressed_until),
                                                    alert_suppression_reason: row.alert_suppression_reason || ''
                                                })}
                                            >
                                                Configure
                                            </Button>
                                        </td>
                                    </tr>
                                ))}

                                {filteredRows.length === 0 && (
                                    <tr>
                                        <td colSpan={tableColumnCount} className="px-4 py-14 text-center">
                                            <p className="text-sm font-bold text-slate-500">No activity rows match the current filters.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                        Generated {formatDateTime(meta?.generated_at)} using {meta?.timezone || 'application timezone'}.
                        {meta?.threshold_mode === 'configured'
                            ? ' Thresholds are read from tenant/terminal configuration.'
                            : ` Threshold override: ${meta?.threshold_minutes} minutes.`}
                    </div>
                </Card>
            </div>

            {editingRow && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="flex items-start justify-between border-b border-slate-200 p-5">
                            <div>
                                <h2 className="text-xl font-black">Monitoring Config</h2>
                                <p className="mt-1 text-sm text-slate-500">{editingRow.label}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setEditingRow(null)}
                                className="rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                aria-label="Close"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <div className="space-y-5 p-5">
                            <label className="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4">
                                <span>
                                    <span className="block text-sm font-bold text-slate-950">Activity monitoring enabled</span>
                                    <span className="text-xs text-slate-500">Disable only when this provider should not be monitored.</span>
                                </span>
                                <input
                                    type="checkbox"
                                    checked={Boolean(editingRow.monitoring_enabled)}
                                    onChange={(event) => setEditingRow((row) => ({ ...row, monitoring_enabled: event.target.checked }))}
                                    className="h-5 w-5 rounded border-slate-300 text-blue-700 focus:ring-blue-600"
                                />
                            </label>

                            <div>
                                <FieldLabel>Threshold minutes</FieldLabel>
                                <input
                                    type="number"
                                    min="5"
                                    max="10080"
                                    value={editingRow.threshold_minutes ?? ''}
                                    onChange={(event) => setEditingRow((row) => ({ ...row, threshold_minutes: event.target.value }))}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                />
                                <p className="mt-1.5 text-xs text-slate-500">
                                    Leave blank to use the system default, or tenant default for terminals.
                                </p>
                            </div>

                            <div>
                                <FieldLabel>Notes</FieldLabel>
                                <textarea
                                    value={editingRow.monitoring_notes ?? ''}
                                    maxLength={500}
                                    rows={4}
                                    onChange={(event) => setEditingRow((row) => ({ ...row, monitoring_notes: event.target.value }))}
                                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                />
                            </div>

                            <div className="rounded-xl border border-indigo-100 bg-indigo-50/70 p-4">
                                <div className="mb-3 flex items-start gap-2 text-indigo-800">
                                    <BellOff className="mt-0.5 h-4 w-4 shrink-0" />
                                    <div>
                                        <p className="text-sm font-black">Alert suppression</p>
                                        <p className="text-xs text-indigo-700">Snooze alerting without changing the factual activity status.</p>
                                    </div>
                                </div>
                                <div className="space-y-3">
                                    <div>
                                        <FieldLabel>Suppress until</FieldLabel>
                                        <input
                                            type="datetime-local"
                                            value={editingRow.alert_suppressed_until ?? ''}
                                            onChange={(event) => setEditingRow((row) => ({ ...row, alert_suppressed_until: event.target.value }))}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                        />
                                    </div>
                                    <div>
                                        <FieldLabel>Suppression reason</FieldLabel>
                                        <textarea
                                            value={editingRow.alert_suppression_reason ?? ''}
                                            maxLength={500}
                                            rows={2}
                                            onChange={(event) => setEditingRow((row) => ({ ...row, alert_suppression_reason: event.target.value }))}
                                            className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium shadow-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                        />
                                    </div>
                                    {editingRow.alert_suppressed_until && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="rounded-xl border-indigo-200 font-bold text-indigo-700 hover:bg-indigo-50"
                                            onClick={() => setEditingRow((row) => ({ ...row, alert_suppressed_until: '', alert_suppression_reason: '' }))}
                                        >
                                            Clear suppression
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 border-t border-slate-200 p-5">
                            <Button variant="outline" className="rounded-xl font-bold" onClick={() => setEditingRow(null)} disabled={savingConfig}>
                                Cancel
                            </Button>
                            <Button className="rounded-xl bg-blue-700 font-bold hover:bg-blue-800" onClick={saveMonitoringConfig} disabled={savingConfig}>
                                {savingConfig && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                {savingConfig ? 'Saving...' : 'Save'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ProviderActivityPage;
