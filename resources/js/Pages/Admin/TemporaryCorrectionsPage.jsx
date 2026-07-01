import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Alert,
    Box,
    Button,
    Checkbox,
    Chip,
    Dialog,
    DialogActions,
    DialogContent,
    DialogContentText,
    DialogTitle,
    FormControl,
    FormControlLabel,
    Grid,
    IconButton,
    InputLabel,
    LinearProgress,
    MenuItem,
    Paper,
    Select,
    Snackbar,
    Stack,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    TextField,
    Tooltip,
    Typography
} from '@mui/material';
import BackupIcon from '@mui/icons-material/Backup';
import BuildIcon from '@mui/icons-material/Build';
import RefreshIcon from '@mui/icons-material/Refresh';
import SearchIcon from '@mui/icons-material/Search';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import { adminCorrectionService } from '../../services/adminCorrectionService';

const defaultFrom = '2026-06-01 00:00:00';
const defaultTo = '2026-06-30 23:59:59';

const statusColor = {
    eligible: 'success',
    not_eligible: 'default'
};

const formatProvider = (tenant) => {
    if (!tenant.provider_ids || tenant.provider_ids.length === 0) return 'None';
    return tenant.provider_ids.map((id) => id ?? 'None').join(', ');
};

const resultMessage = (result) => {
    if (!result) return null;
    if (!result.success) return result.message;

    const diff = result.diff;
    if (!diff) return result.message;

    return [
        `${result.message}`,
        `Transactions updated: ${diff.transactions_updated}`,
        `Terminals updated: ${diff.terminals_updated}`,
        `Provider ID: ${JSON.stringify(diff.provider_id.old)} -> ${JSON.stringify(diff.provider_id.new)}`,
        `Backup: ${result.backup?.path || 'created'}`
    ].join(' | ');
};

const TemporaryCorrectionsPage = () => {
    const [tenants, setTenants] = useState([]);
    const [providers, setProviders] = useState([]);
    const [loading, setLoading] = useState(false);
    const [processing, setProcessing] = useState({});
    const [selected, setSelected] = useState([]);
    const [search, setSearch] = useState('');
    const [eligibility, setEligibility] = useState('');
    const [from, setFrom] = useState(defaultFrom);
    const [to, setTo] = useState(defaultTo);
    const [utcOffset, setUtcOffset] = useState('+08:00');
    const [results, setResults] = useState({});
    const [notice, setNotice] = useState({ open: false, severity: 'success', message: '' });
    const [dialog, setDialog] = useState({ open: false, mode: 'apply', tenants: [] });
    const [form, setForm] = useState({
        correct_timestamps: true,
        provider_id: '',
        update_provider: false
    });

    const selectedTenants = useMemo(
        () => tenants.filter((tenant) => selected.includes(tenant.id)),
        [selected, tenants]
    );

    const loadTenants = useCallback(async () => {
        setLoading(true);
        try {
            const response = await adminCorrectionService.listTenants({
                search,
                eligibility,
                from,
                to,
                utc_offset: utcOffset
            });
            setTenants(response.data || []);
            setProviders(response.providers || []);
        } catch (error) {
            setNotice({
                open: true,
                severity: 'error',
                message: error.response?.data?.message || 'Could not load correction eligibility.'
            });
        } finally {
            setLoading(false);
        }
    }, [eligibility, from, search, to, utcOffset]);

    useEffect(() => {
        document.title = 'Temporary Tenant Corrections | TSMS';
        loadTenants();
    }, [loadTenants]);

    const toggleTenant = (tenantId) => {
        setSelected((current) => current.includes(tenantId)
            ? current.filter((id) => id !== tenantId)
            : [...current, tenantId]
        );
    };

    const toggleAllVisible = (checked) => {
        setSelected(checked ? tenants.map((tenant) => tenant.id) : []);
    };

    const openDialog = (mode, targetTenants) => {
        setForm({
            correct_timestamps: mode === 'apply',
            provider_id: '',
            update_provider: false
        });
        setDialog({ open: true, mode, tenants: targetTenants });
    };

    const closeDialog = () => {
        setDialog({ open: false, mode: 'apply', tenants: [] });
    };

    const markProcessing = (tenantIds, value) => {
        setProcessing((current) => {
            const next = { ...current };
            tenantIds.forEach((id) => {
                next[id] = value;
            });
            return next;
        });
    };

    const runBackup = async (targetTenants) => {
        const tenantIds = targetTenants.map((tenant) => tenant.id);
        markProcessing(tenantIds, true);
        try {
            const response = await adminCorrectionService.backup({ tenant_ids: tenantIds, from, to, utc_offset: utcOffset });
            applyResults(response.results || []);
            setNotice({
                open: true,
                severity: response.summary?.failed ? 'warning' : 'success',
                message: `Backup complete: ${response.summary?.succeeded || 0} succeeded, ${response.summary?.failed || 0} failed.`
            });
        } catch (error) {
            setNotice({
                open: true,
                severity: 'error',
                message: error.response?.data?.message || 'Backup failed before any update was made.'
            });
        } finally {
            markProcessing(tenantIds, false);
            closeDialog();
        }
    };

    const runApply = async (targetTenants) => {
        const tenantIds = targetTenants.map((tenant) => tenant.id);
        const payload = {
            tenant_ids: tenantIds,
            from,
            to,
            utc_offset: utcOffset,
            correct_timestamps: form.correct_timestamps
        };

        if (form.update_provider) {
            payload.provider_id = form.provider_id === '' ? null : Number(form.provider_id);
        }

        markProcessing(tenantIds, true);
        try {
            const response = await adminCorrectionService.apply(payload);
            applyResults(response.results || []);
            setNotice({
                open: true,
                severity: response.summary?.failed ? 'warning' : 'success',
                message: `Correction complete: ${response.summary?.succeeded || 0} succeeded, ${response.summary?.failed || 0} failed.`
            });
            await loadTenants();
        } catch (error) {
            setNotice({
                open: true,
                severity: 'error',
                message: error.response?.data?.message || 'Correction failed. No tenant update is committed unless its backup succeeded.'
            });
        } finally {
            markProcessing(tenantIds, false);
            closeDialog();
        }
    };

    const applyResults = (items) => {
        setResults((current) => {
            const next = { ...current };
            items.forEach((item) => {
                next[item.tenant_id] = item;
            });
            return next;
        });
    };

    const confirmDialog = async () => {
        if (dialog.mode === 'backup') {
            await runBackup(dialog.tenants);
            return;
        }

        await runApply(dialog.tenants);
    };

    return (
        <Box sx={{ p: 3, bgcolor: '#f8fafc', minHeight: '100vh' }}>
            <Stack spacing={3}>
                <Box>
                    <Stack direction="row" alignItems="center" spacing={1}>
                        <WarningAmberIcon color="warning" />
                        <Typography variant="h4" fontWeight={800}>Temporary Tenant Corrections</Typography>
                        <Chip label="Admin only" color="error" size="small" />
                        <Chip label="Temporary" color="warning" size="small" />
                    </Stack>
                    <Typography color="text.secondary" sx={{ mt: 1 }}>
                        Back up tenant records, normalize provider local timestamps to true UTC, and update provider assignments.
                    </Typography>
                </Box>

                <Paper sx={{ p: 2, borderRadius: 2 }}>
                    <Grid container spacing={2} alignItems="center">
                        <Grid item xs={12} md={3}>
                            <TextField
                                fullWidth
                                label="Search tenant"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                InputProps={{ startAdornment: <SearchIcon fontSize="small" sx={{ mr: 1, color: 'text.secondary' }} /> }}
                            />
                        </Grid>
                        <Grid item xs={12} md={2}>
                            <FormControl fullWidth>
                                <InputLabel>Eligibility</InputLabel>
                                <Select label="Eligibility" value={eligibility} onChange={(event) => setEligibility(event.target.value)}>
                                    <MenuItem value="">All</MenuItem>
                                    <MenuItem value="eligible">Eligible</MenuItem>
                                    <MenuItem value="not_eligible">Not eligible</MenuItem>
                                </Select>
                            </FormControl>
                        </Grid>
                        <Grid item xs={12} md={2}>
                            <TextField fullWidth label="From" value={from} onChange={(event) => setFrom(event.target.value)} />
                        </Grid>
                        <Grid item xs={12} md={2}>
                            <TextField fullWidth label="To" value={to} onChange={(event) => setTo(event.target.value)} />
                        </Grid>
                        <Grid item xs={12} md={1.5}>
                            <TextField fullWidth label="UTC offset" value={utcOffset} onChange={(event) => setUtcOffset(event.target.value)} />
                        </Grid>
                        <Grid item xs={12} md={1.5}>
                            <Button fullWidth variant="contained" startIcon={<RefreshIcon />} onClick={loadTenants} disabled={loading}>
                                Evaluate
                            </Button>
                        </Grid>
                    </Grid>
                </Paper>

                <Paper sx={{ p: 2, borderRadius: 2 }}>
                    <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ xs: 'stretch', md: 'center' }} justifyContent="space-between">
                        <Typography fontWeight={700}>
                            {selected.length} selected
                        </Typography>
                        <Stack direction="row" spacing={1}>
                            <Button
                                variant="outlined"
                                startIcon={<BackupIcon />}
                                disabled={selected.length === 0}
                                onClick={() => openDialog('backup', selectedTenants)}
                            >
                                Backup Selected
                            </Button>
                            <Button
                                variant="contained"
                                startIcon={<BuildIcon />}
                                disabled={selected.length === 0}
                                onClick={() => openDialog('apply', selectedTenants)}
                            >
                                Apply Selected
                            </Button>
                        </Stack>
                    </Stack>
                </Paper>

                {loading && <LinearProgress />}

                <TableContainer component={Paper} sx={{ borderRadius: 2 }}>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                <TableCell padding="checkbox">
                                    <Checkbox
                                        checked={tenants.length > 0 && selected.length === tenants.length}
                                        indeterminate={selected.length > 0 && selected.length < tenants.length}
                                        onChange={(event) => toggleAllVisible(event.target.checked)}
                                    />
                                </TableCell>
                                <TableCell>Tenant</TableCell>
                                <TableCell>Eligibility</TableCell>
                                <TableCell>Current Values</TableCell>
                                <TableCell>Counts</TableCell>
                                <TableCell>Last Modified</TableCell>
                                <TableCell align="right">Actions</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {tenants.map((tenant) => {
                                const result = results[tenant.id];
                                return (
                                    <React.Fragment key={tenant.id}>
                                        <TableRow hover>
                                            <TableCell padding="checkbox">
                                                <Checkbox checked={selected.includes(tenant.id)} onChange={() => toggleTenant(tenant.id)} />
                                            </TableCell>
                                            <TableCell>
                                                <Typography fontWeight={700}>{tenant.trade_name}</Typography>
                                                <Typography variant="caption" color="text.secondary">Tenant #{tenant.id}</Typography>
                                            </TableCell>
                                            <TableCell sx={{ maxWidth: 420 }}>
                                                <Stack spacing={0.75}>
                                                    <Chip
                                                        size="small"
                                                        color={statusColor[tenant.eligibility.status] || 'default'}
                                                        label={tenant.eligibility.status.replace('_', ' ')}
                                                        sx={{ width: 'fit-content', textTransform: 'capitalize' }}
                                                    />
                                                    <Typography variant="body2">{tenant.eligibility.reason}</Typography>
                                                </Stack>
                                            </TableCell>
                                            <TableCell>
                                                <Typography variant="body2">UTC offset: {utcOffset}</Typography>
                                                <Typography variant="body2">provider_id: {formatProvider(tenant)}</Typography>
                                                <Typography variant="caption" color="text.secondary">
                                                    Terminals: {tenant.terminal_count}
                                                </Typography>
                                            </TableCell>
                                            <TableCell>
                                                <Typography variant="body2">Transactions: {tenant.transaction_count}</Typography>
                                                <Typography variant="body2">Payload timestamps: {tenant.payload_timestamp_count}</Typography>
                                                <Typography variant="body2">Eligible rows: {tenant.eligible_correction_count}</Typography>
                                            </TableCell>
                                            <TableCell>{tenant.last_modified_at || '-'}</TableCell>
                                            <TableCell align="right">
                                                <Stack direction="row" spacing={1} justifyContent="flex-end">
                                                    {processing[tenant.id] && <LinearProgress sx={{ width: 80, alignSelf: 'center' }} />}
                                                    <Tooltip title="Create backup">
                                                        <span>
                                                            <IconButton disabled={processing[tenant.id]} onClick={() => openDialog('backup', [tenant])}>
                                                                <BackupIcon />
                                                            </IconButton>
                                                        </span>
                                                    </Tooltip>
                                                    <Tooltip title="Correct tenant">
                                                        <span>
                                                            <IconButton disabled={processing[tenant.id]} color="primary" onClick={() => openDialog('apply', [tenant])}>
                                                                <BuildIcon />
                                                            </IconButton>
                                                        </span>
                                                    </Tooltip>
                                                </Stack>
                                            </TableCell>
                                        </TableRow>
                                        {result && (
                                            <TableRow>
                                                <TableCell />
                                                <TableCell colSpan={6}>
                                                    <Alert severity={result.success ? 'success' : 'error'} sx={{ my: 1 }}>
                                                        {resultMessage(result)}
                                                    </Alert>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </React.Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                </TableContainer>
            </Stack>

            <Dialog open={dialog.open} onClose={closeDialog} maxWidth="sm" fullWidth>
                <DialogTitle>{dialog.mode === 'backup' ? 'Confirm backup' : 'Confirm correction'}</DialogTitle>
                <DialogContent>
                    <DialogContentText sx={{ mb: 2 }}>
                        {dialog.mode === 'backup'
                            ? 'A backup file will be created for the selected tenant records.'
                            : 'A backup will be created first. If backup fails for a tenant, its update will be blocked.'}
                    </DialogContentText>
                    <Alert severity="warning" sx={{ mb: 2 }}>
                        Affected tenants: {dialog.tenants.map((tenant) => `${tenant.trade_name} (#${tenant.id})`).join(', ') || 'None'}
                    </Alert>
                    {dialog.mode === 'apply' && (
                        <Stack spacing={2}>
                            <FormControlLabel
                                control={
                                    <Checkbox
                                        checked={form.correct_timestamps}
                                        onChange={(event) => setForm((current) => ({ ...current, correct_timestamps: event.target.checked }))}
                                    />
                                }
                                label={`Apply UTC correction using offset ${utcOffset}`}
                            />
                            <FormControlLabel
                                control={
                                    <Checkbox
                                        checked={form.update_provider}
                                        onChange={(event) => setForm((current) => ({ ...current, update_provider: event.target.checked }))}
                                    />
                                }
                                label="Update provider_id"
                            />
                            <FormControl fullWidth disabled={!form.update_provider}>
                                <InputLabel>New provider_id</InputLabel>
                                <Select
                                    label="New provider_id"
                                    value={form.provider_id}
                                    onChange={(event) => setForm((current) => ({ ...current, provider_id: event.target.value }))}
                                >
                                    {providers.map((provider) => (
                                        <MenuItem key={provider.id} value={provider.id}>
                                            {provider.id} - {provider.name} ({provider.timestamp_mode || 'no mode'})
                                        </MenuItem>
                                    ))}
                                </Select>
                            </FormControl>
                        </Stack>
                    )}
                </DialogContent>
                <DialogActions>
                    <Button onClick={closeDialog}>Cancel</Button>
                    <Button
                        variant="contained"
                        color={dialog.mode === 'backup' ? 'primary' : 'warning'}
                        onClick={confirmDialog}
                        disabled={
                            dialog.tenants.length === 0
                            || (dialog.mode === 'apply' && !form.correct_timestamps && !form.update_provider)
                            || (dialog.mode === 'apply' && form.update_provider && form.provider_id === '')
                        }
                    >
                        Confirm
                    </Button>
                </DialogActions>
            </Dialog>

            <Snackbar
                open={notice.open}
                autoHideDuration={6000}
                onClose={() => setNotice((current) => ({ ...current, open: false }))}
            >
                <Alert severity={notice.severity} onClose={() => setNotice((current) => ({ ...current, open: false }))}>
                    {notice.message}
                </Alert>
            </Snackbar>
        </Box>
    );
};

export default TemporaryCorrectionsPage;
