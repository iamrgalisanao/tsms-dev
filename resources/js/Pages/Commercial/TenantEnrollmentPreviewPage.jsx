import React, { useMemo, useState } from 'react';
import {
    Alert,
    Box,
    Button,
    Dialog,
    DialogActions,
    DialogContent,
    DialogTitle,
    Divider,
    FormControlLabel,
    IconButton,
    InputAdornment,
    MenuItem,
    Paper,
    Stack,
    Switch,
    TextField,
    ToggleButton,
    ToggleButtonGroup,
    Tooltip,
    Typography,
} from '@mui/material';
import {
    Add as AddIcon,
    Badge as BadgeIcon,
    Business as BusinessIcon,
    CheckCircle as CheckCircleIcon,
    DeleteOutline as DeleteOutlineIcon,
    HelpOutline as HelpOutlineIcon,
    LocationOn as LocationOnIcon,
    MapsHomeWork as MapsHomeWorkIcon,
    Person as PersonIcon,
    PointOfSale as PointOfSaleIcon,
    ReceiptLong as ReceiptLongIcon,
    RestartAlt as RestartAltIcon,
    SquareFoot as SquareFootIcon,
} from '@mui/icons-material';

// Prototype only: this page keeps everything in local state and never calls the API.
// It exists so the revised enrollment fields can be reviewed with the client before
// any schema or backend work is done.

const CATEGORIES = ['F&B', 'Retail', 'Services'];
const LEVELS = ['Ground Floor', 'Level 1', 'Level 2', 'Level 3', 'Level 4'];
const STATUSES = ['Operational', 'Not Operational'];

// Points still waiting on a client decision; shown beside the field when review notes are on.
const OPEN_QUESTIONS = {
    owner_name: 'Is the owner a person or the corporate owner? One owner per tenant?',
    tenant_name: 'Trade name (storefront) or registered business name?',
    machines: 'BIR MIN or internal number? Should enrollment control which machines may register?',
    location: 'Is Location the floor/level, or a free-text description?',
    tin: 'TIN per tenant (with branch code) or per company?',
    status: 'Confirm status list: Operational / Not Operational vs Operational / Closed / Pending.',
};

const EMPTY_FORM = {
    owner_name: '',
    tenant_code: '',
    tenant_name: '',
    category: '',
    status: 'Operational',
    location: '',
    location_type: 'Inline',
    unit_no: '',
    floor_area: '',
    tin: '',
    machines: [''],
};

const SAMPLE_FORM = {
    owner_name: 'Maria Santos',
    tenant_code: 'C-D1016',
    tenant_name: 'Kape Kalye',
    category: 'F&B',
    status: 'Operational',
    location: 'Level 2',
    location_type: 'Kiosk',
    unit_no: 'K-214',
    floor_area: '18.50',
    tin: '123-456-789-00000',
    machines: ['2002', '2003'],
};

// Formats raw digits as ###-###-###-##### (9-digit TIN + up to 5-digit branch code).
const formatTin = (value) => {
    const digits = value.replace(/\D/g, '').slice(0, 14);
    const parts = [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9), digits.slice(9)];
    return parts.filter(Boolean).join('-');
};

const validate = (form) => {
    const errors = {};
    const required = ['owner_name', 'tenant_code', 'tenant_name', 'category', 'location', 'floor_area', 'tin'];
    required.forEach((key) => {
        if (!String(form[key] ?? '').trim()) errors[key] = 'Required';
    });

    if (form.tenant_code && !/^[A-Z0-9-]{3,50}$/.test(form.tenant_code)) {
        errors.tenant_code = 'Use 3–50 uppercase letters, digits or dashes';
    }

    if (form.floor_area) {
        const area = Number(form.floor_area);
        if (Number.isNaN(area) || area <= 0) errors.floor_area = 'Must be a number greater than 0';
        else if (area > 999999.99) errors.floor_area = 'Too large';
    }

    if (form.tin) {
        const digits = form.tin.replace(/\D/g, '');
        if (![12, 14].includes(digits.length)) {
            errors.tin = '9-digit TIN plus a 3- or 5-digit branch code';
        }
    }

    const machineErrors = form.machines.map((m, i) => {
        const value = m.trim();
        if (!value) return i === 0 ? 'At least one machine is required' : 'Remove empty row or fill it in';
        if (form.machines.findIndex((other) => other.trim() === value) !== i) return 'Duplicate machine #';
        return null;
    });
    if (machineErrors.some(Boolean)) errors.machines = machineErrors;

    return errors;
};

const SectionHeader = ({ icon: Icon, title, subtitle }) => (
    <Stack direction="row" spacing={1.5} alignItems="center" sx={{ mb: 2 }}>
        <Box sx={{ bgcolor: 'primary.main', color: 'white', p: 0.75, borderRadius: 1.5, display: 'flex' }}>
            <Icon fontSize="small" />
        </Box>
        <Box>
            <Typography variant="subtitle1" fontWeight={800} lineHeight={1.2}>{title}</Typography>
            {subtitle && <Typography variant="caption" color="text.secondary">{subtitle}</Typography>}
        </Box>
    </Stack>
);

const OpenQuestion = ({ show, field }) => {
    if (!show || !OPEN_QUESTIONS[field]) return null;
    return (
        <Stack direction="row" spacing={0.75} alignItems="flex-start" sx={{ mt: 0.75, color: 'warning.dark' }}>
            <HelpOutlineIcon sx={{ fontSize: 16, mt: '2px' }} aria-hidden="true" />
            <Typography variant="caption" sx={{ lineHeight: 1.5 }}>
                <Box component="span" fontWeight={700}>Needs client decision: </Box>
                {OPEN_QUESTIONS[field]}
            </Typography>
        </Stack>
    );
};

const FieldGrid = ({ children }) => (
    <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        {children}
    </Box>
);

const SummaryRow = ({ label, value }) => (
    <Stack direction="row" justifyContent="space-between" spacing={2} sx={{ py: 0.75 }}>
        <Typography variant="body2" color="text.secondary">{label}</Typography>
        <Typography variant="body2" fontWeight={700} textAlign="right" sx={{ wordBreak: 'break-word' }}>
            {value || <Box component="span" sx={{ color: 'text.disabled', fontWeight: 400 }}>—</Box>}
        </Typography>
    </Stack>
);

const TenantEnrollmentPreviewPage = () => {
    const [form, setForm] = useState(EMPTY_FORM);
    const [touched, setTouched] = useState({});
    const [submitAttempted, setSubmitAttempted] = useState(false);
    const [showNotes, setShowNotes] = useState(true);
    const [previewOpen, setPreviewOpen] = useState(false);

    const errors = useMemo(() => validate(form), [form]);
    const showError = (key) => (submitAttempted || touched[key]) && errors[key];

    const set = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }));
    const touch = (key) => () => setTouched((prev) => ({ ...prev, [key]: true }));

    const fieldProps = (key) => ({
        value: form[key],
        onChange: set(key),
        onBlur: touch(key),
        error: Boolean(showError(key)),
        helperText: showError(key) || undefined,
        fullWidth: true,
    });

    const setMachine = (index, value) => setForm((prev) => ({
        ...prev,
        machines: prev.machines.map((m, i) => (i === index ? value : m)),
    }));
    const addMachine = () => setForm((prev) => ({ ...prev, machines: [...prev.machines, ''] }));
    const removeMachine = (index) => setForm((prev) => ({
        ...prev,
        machines: prev.machines.filter((_, i) => i !== index),
    }));

    const requiredKeys = ['owner_name', 'tenant_code', 'tenant_name', 'category', 'location', 'floor_area', 'tin', 'machines'];
    const completed = requiredKeys.filter((k) => !errors[k]).length;

    const reset = (next) => {
        setForm(next);
        setTouched({});
        setSubmitAttempted(false);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setSubmitAttempted(true);
        if (Object.keys(errors).length === 0) setPreviewOpen(true);
    };

    const payload = {
        ...form,
        tenant_code: form.tenant_code.trim(),
        floor_area: form.floor_area ? Number(form.floor_area) : null,
        machines: form.machines.map((m) => m.trim()).filter(Boolean),
    };

    return (
        <Box sx={{ maxWidth: 1200, mx: 'auto', p: { xs: 2, md: 3 } }}>
            <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ md: 'center' }} spacing={2} sx={{ mb: 2 }}>
                <Box>
                    <Typography variant="h5" fontWeight={800}>Tenant Enrollment</Typography>
                    <Typography variant="body2" color="text.secondary">
                        Revised enrollment form for client review.
                    </Typography>
                </Box>
                <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                    <FormControlLabel
                        control={<Switch checked={showNotes} onChange={(e) => setShowNotes(e.target.checked)} />}
                        label={<Typography variant="body2">Show review notes</Typography>}
                    />
                    <Button size="small" variant="outlined" onClick={() => reset(SAMPLE_FORM)}>Load sample</Button>
                    <Button size="small" startIcon={<RestartAltIcon />} onClick={() => reset(EMPTY_FORM)}>Clear</Button>
                </Stack>
            </Stack>

            <Alert severity="info" sx={{ mb: 3 }}>
                <strong>Prototype preview.</strong> Nothing on this page is saved. Submitting only shows the data that would be sent.
            </Alert>

            <Box
                component="form"
                noValidate
                onSubmit={handleSubmit}
                sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', lg: '1fr 340px' }, alignItems: 'start' }}
            >
                <Stack spacing={3}>
                    <Paper variant="outlined" sx={{ p: 3, borderRadius: 3 }}>
                        <SectionHeader icon={MapsHomeWorkIcon} title="Tenant Identity" subtitle="How the tenant appears in reports and on POS payloads." />
                        <FieldGrid>
                            <Box>
                                <TextField
                                    {...fieldProps('tenant_code')}
                                    onChange={(e) => setForm((prev) => ({ ...prev, tenant_code: e.target.value.toUpperCase() }))}
                                    label="Tenant's Code"
                                    required
                                    placeholder="C-D1016"
                                    helperText={showError('tenant_code') || 'Used by POS terminals to register. Locked once a terminal is active.'}
                                    InputProps={{ startAdornment: <InputAdornment position="start"><BadgeIcon fontSize="small" color="action" /></InputAdornment> }}
                                />
                            </Box>
                            <Box>
                                <TextField
                                    {...fieldProps('tenant_name')}
                                    label="Tenant's Name"
                                    required
                                    placeholder="e.g., Kape Kalye"
                                    InputProps={{ startAdornment: <InputAdornment position="start"><BusinessIcon fontSize="small" color="action" /></InputAdornment> }}
                                />
                                <OpenQuestion show={showNotes} field="tenant_name" />
                            </Box>
                            <Box>
                                <Typography variant="caption" color={showError('category') ? 'error' : 'text.secondary'} fontWeight={600} sx={{ display: 'block', mb: 0.75 }}>
                                    Category type *
                                </Typography>
                                <ToggleButtonGroup
                                    exclusive
                                    fullWidth
                                    size="small"
                                    color="primary"
                                    value={form.category}
                                    onChange={(_, value) => {
                                        if (value !== null) setForm((prev) => ({ ...prev, category: value }));
                                        setTouched((prev) => ({ ...prev, category: true }));
                                    }}
                                >
                                    {CATEGORIES.map((c) => (
                                        <ToggleButton key={c} value={c} sx={{ fontWeight: 700, textTransform: 'none' }}>{c}</ToggleButton>
                                    ))}
                                </ToggleButtonGroup>
                                {showError('category') && (
                                    <Typography variant="caption" color="error" sx={{ display: 'block', mt: 0.5, ml: 1.75 }}>Required</Typography>
                                )}
                            </Box>
                            <Box>
                                <TextField {...fieldProps('status')} select label="Status">
                                    {STATUSES.map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
                                </TextField>
                                <OpenQuestion show={showNotes} field="status" />
                            </Box>
                        </FieldGrid>
                    </Paper>

                    <Paper variant="outlined" sx={{ p: 3, borderRadius: 3 }}>
                        <SectionHeader icon={PersonIcon} title="Owner & Tax" subtitle="Ownership and BIR registration details." />
                        <FieldGrid>
                            <Box>
                                <TextField
                                    {...fieldProps('owner_name')}
                                    label="Owner's Name"
                                    required
                                    placeholder="e.g., Maria Santos"
                                    InputProps={{ startAdornment: <InputAdornment position="start"><PersonIcon fontSize="small" color="action" /></InputAdornment> }}
                                />
                                <OpenQuestion show={showNotes} field="owner_name" />
                            </Box>
                            <Box>
                                <TextField
                                    {...fieldProps('tin')}
                                    onChange={(e) => setForm((prev) => ({ ...prev, tin: formatTin(e.target.value) }))}
                                    label="TIN #"
                                    required
                                    placeholder="000-000-000-00000"
                                    inputProps={{ inputMode: 'numeric' }}
                                    helperText={showError('tin') || 'Tax Identification Number with branch code'}
                                    InputProps={{ startAdornment: <InputAdornment position="start"><ReceiptLongIcon fontSize="small" color="action" /></InputAdornment> }}
                                />
                                <OpenQuestion show={showNotes} field="tin" />
                            </Box>
                        </FieldGrid>
                    </Paper>

                    <Paper variant="outlined" sx={{ p: 3, borderRadius: 3 }}>
                        <SectionHeader icon={LocationOnIcon} title="Location" subtitle="Where the tenant sits in the property." />
                        <FieldGrid>
                            <Box>
                                <TextField {...fieldProps('location')} select label="Location" required>
                                    {LEVELS.map((l) => <MenuItem key={l} value={l}>{l}</MenuItem>)}
                                </TextField>
                                <OpenQuestion show={showNotes} field="location" />
                            </Box>
                            <Box>
                                <TextField {...fieldProps('unit_no')} label="Unit No." placeholder="e.g., K-214" />
                            </Box>
                            <Box>
                                <Typography variant="caption" color="text.secondary" fontWeight={600} sx={{ display: 'block', mb: 0.75 }}>
                                    Location type
                                </Typography>
                                <ToggleButtonGroup
                                    exclusive
                                    fullWidth
                                    size="small"
                                    color="primary"
                                    value={form.location_type}
                                    onChange={(_, value) => value !== null && setForm((prev) => ({ ...prev, location_type: value }))}
                                >
                                    <ToggleButton value="Inline" sx={{ fontWeight: 700, textTransform: 'none' }}>Inline</ToggleButton>
                                    <ToggleButton value="Kiosk" sx={{ fontWeight: 700, textTransform: 'none' }}>Kiosk</ToggleButton>
                                </ToggleButtonGroup>
                            </Box>
                            <Box>
                                <TextField
                                    {...fieldProps('floor_area')}
                                    label="Floor Area"
                                    required
                                    type="number"
                                    placeholder="0.00"
                                    inputProps={{ min: 0, step: '0.01' }}
                                    InputProps={{
                                        startAdornment: <InputAdornment position="start"><SquareFootIcon fontSize="small" color="action" /></InputAdornment>,
                                        endAdornment: <InputAdornment position="end">sqm</InputAdornment>,
                                    }}
                                />
                            </Box>
                        </FieldGrid>
                    </Paper>

                    <Paper variant="outlined" sx={{ p: 3, borderRadius: 3 }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
                            <SectionHeader icon={PointOfSaleIcon} title="POS Machines" subtitle="A tenant can run more than one machine." />
                            <Button size="small" startIcon={<AddIcon />} onClick={addMachine}>Add machine</Button>
                        </Stack>
                        <Stack spacing={1.5}>
                            {form.machines.map((machine, i) => {
                                const err = (submitAttempted || touched[`machine_${i}`]) && errors.machines?.[i];
                                return (
                                    <Stack key={i} direction="row" spacing={1} alignItems="flex-start">
                                        <TextField
                                            fullWidth
                                            size="small"
                                            label={`Machine # ${i + 1}`}
                                            required={i === 0}
                                            placeholder="e.g., 2002"
                                            value={machine}
                                            onChange={(e) => setMachine(i, e.target.value)}
                                            onBlur={() => setTouched((prev) => ({ ...prev, [`machine_${i}`]: true }))}
                                            error={Boolean(err)}
                                            helperText={err || undefined}
                                        />
                                        <Tooltip title={form.machines.length === 1 ? 'At least one machine is required' : 'Remove'}>
                                            <span>
                                                <IconButton
                                                    onClick={() => removeMachine(i)}
                                                    disabled={form.machines.length === 1}
                                                    aria-label={`Remove machine ${i + 1}`}
                                                >
                                                    <DeleteOutlineIcon />
                                                </IconButton>
                                            </span>
                                        </Tooltip>
                                    </Stack>
                                );
                            })}
                        </Stack>
                        <OpenQuestion show={showNotes} field="machines" />
                    </Paper>
                </Stack>

                <Paper variant="outlined" sx={{ p: 3, borderRadius: 3, position: { lg: 'sticky' }, top: { lg: 24 } }}>
                    <Typography variant="subtitle1" fontWeight={800}>Enrollment Summary</Typography>
                    <Typography variant="caption" color="text.secondary">
                        {completed} of {requiredKeys.length} required fields complete
                    </Typography>
                    <Box sx={{ height: 6, bgcolor: 'grey.200', borderRadius: 3, mt: 1, mb: 2, overflow: 'hidden' }}>
                        <Box sx={{ height: '100%', width: `${(completed / requiredKeys.length) * 100}%`, bgcolor: 'primary.main', transition: 'width .2s' }} />
                    </Box>
                    <Divider sx={{ mb: 1 }} />
                    <SummaryRow label="Owner" value={form.owner_name} />
                    <SummaryRow label="Tenant code" value={form.tenant_code} />
                    <SummaryRow label="Tenant name" value={form.tenant_name} />
                    <SummaryRow label="Category" value={form.category} />
                    <SummaryRow label="Location" value={[form.location, form.unit_no, form.location_type].filter(Boolean).join(' · ')} />
                    <SummaryRow label="Floor area" value={form.floor_area && `${form.floor_area} sqm`} />
                    <SummaryRow label="TIN" value={form.tin} />
                    <SummaryRow label="Machines" value={payload.machines.join(', ')} />
                    <Divider sx={{ my: 2 }} />
                    <Button type="submit" variant="contained" size="large" fullWidth sx={{ fontWeight: 700, borderRadius: 2 }}>
                        Enroll Tenant
                    </Button>
                    {submitAttempted && Object.keys(errors).length > 0 && (
                        <Typography variant="caption" color="error" sx={{ display: 'block', mt: 1, textAlign: 'center' }}>
                            Fix the highlighted fields to continue.
                        </Typography>
                    )}
                </Paper>
            </Box>

            <Dialog open={previewOpen} onClose={() => setPreviewOpen(false)} maxWidth="sm" fullWidth>
                <DialogTitle>
                    <Stack direction="row" spacing={1} alignItems="center">
                        <CheckCircleIcon color="success" />
                        <span>Form is valid</span>
                    </Stack>
                </DialogTitle>
                <DialogContent dividers>
                    <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                        In the live version this would create the tenant. In this preview nothing was saved; this is the data that would be sent.
                    </Typography>
                    <Box component="pre" sx={{ m: 0, p: 2, bgcolor: 'grey.100', borderRadius: 2, fontSize: 12, overflowX: 'auto' }}>
                        {JSON.stringify(payload, null, 2)}
                    </Box>
                </DialogContent>
                <DialogActions>
                    <Button onClick={() => setPreviewOpen(false)}>Close</Button>
                </DialogActions>
            </Dialog>
        </Box>
    );
};

export default TenantEnrollmentPreviewPage;
