import React, { useMemo, useState } from 'react';
import axios from 'axios';
import {
    Alert,
    Box,
    Button,
    Chip,
    Divider,
    Grid,
    IconButton,
    LinearProgress,
    Paper,
    Stack,
    Switch,
    Tab,
    Tabs,
    TextField,
    Tooltip,
    Typography
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import ContentPasteIcon from '@mui/icons-material/ContentPaste';
import DeleteSweepIcon from '@mui/icons-material/DeleteSweep';
import DataObjectIcon from '@mui/icons-material/DataObject';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import TerminalIcon from '@mui/icons-material/Terminal';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import TroubleshootIcon from '@mui/icons-material/Troubleshoot';
import RuleIcon from '@mui/icons-material/Rule';
import LanIcon from '@mui/icons-material/Lan';

const MAX_PAYLOAD_BYTES = 262144;

const VALID_SAMPLE = `{
  "submission_uuid": "f8c1a35a-90db-41ab-8c05-1b27cc7a40a9",
  "tenant_id": 16,
  "terminal_id": 97,
  "submission_timestamp": "2026-05-14T08:59:28Z",
  "transaction_count": 1,
  "transaction": {
    "hardware_id": "BUI-XTM80213",
    "receipt_no": "000001072840",
    "transaction_id": "f9c5dd6a-2d71-40b8-903c-df0a02b417ed",
    "transaction_timestamp": "2026-05-14T08:59:28Z",
    "gross_sales": "140.00",
    "net_sales": "125.00",
    "promo_status": "WITHOUT_APPROVAL",
    "customer_code": "C-B1028",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": "0.00" },
      { "adjustment_type": "senior_discount", "amount": "0.00" },
      { "adjustment_type": "pwd_discount", "amount": "0.00" },
      { "adjustment_type": "vip_card_discount", "amount": "0.00" },
      { "adjustment_type": "service_charge_distributed_to_employees", "amount": "0.00" },
      { "adjustment_type": "service_charge_retained_by_management", "amount": "0.00" },
      { "adjustment_type": "employee_discount", "amount": "0.00" }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": "15.00" },
      { "tax_type": "VATABLE_SALES", "amount": "125.00" },
      { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": "0.00" },
      { "tax_type": "OTHER_TAX", "amount": "0.00" }
    ],
    "payload_checksum": "afe7b98146c54ba0c80e18d6db0e3976b3204bb046dbd495b03b28152e19ae5f"
  },
  "payload_checksum": "30cf6eeb6513ca6f31e9d091e7bbd8a519f5d3264a128cc715fee6d61b684154"
}`;

const INVALID_SAMPLE = `{
  "submission_uuid": "74491558-7796-468c-931d-e937a0812305",
  "tenant_id": 16,
  "terminal_id": 97,
  "submission_timestamp": "2026-05-14T08:59:28Z",
  "transaction_count": 1,
  "hardware_id": "BUI-XTM80213",
  "transaction": {
    "receipt_no": "000001072840",
    "transaction_id": "295f39c8-b045-40c1-adb6-439294449bb7",
    "transaction_timestamp": "2026-05-14T08:59:28Z",
    "gross_sales": "140.00",
    "net_sales": "125.00",
    "promo_status": "WITHOUT_APPROVAL",
    "customer_code": "C-B1028",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": "0.00" },
      { "adjustment_type": "senior_discount", "amount": "0.00" },
      { "adjustment_type": "pwd_discount", "amount": "0.00" },
      { "adjustment_type": "vip_card_discount", "amount": "0.00" },
      { "adjustment_type": "service_charge_distributed_to_employees", "amount": "0.00" },
      { "adjustment_type": "service_charge_retained_by_management", "amount": "0.00" },
      { "adjustment_type": "employee_discount", "amount": "0.00" }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": "15.00" },
      { "tax_type": "VATABLE_SALES", "amount": "0.00" },
      { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": "0.00" },
      { "tax_type": "OTHER_TAX", "amount": "0.00" }
    ],
    "payload_checksum": "e0736715ef3e6d86aa176c455908df6b0b1d95169aa8b1a944c9d26d569804da"
  },
  "payload_checksum": "c7ec5ef62a44dca24638944f9dc7402da0d3dde697d510a3c04f4e7a1560c475"
}`;

const StatusChip = ({ status }) => {
    const passed = status === 'passed';
    const failed = status === 'failed';
    return (
        <Chip
            size="small"
            label={status || 'not run'}
            color={passed ? 'success' : failed ? 'error' : 'default'}
            variant={passed || failed ? 'filled' : 'outlined'}
        />
    );
};

const statusStyles = {
    passed: {
        color: 'success.main',
        borderColor: 'success.light',
        bgcolor: '#f0fdf4',
        icon: <CheckCircleIcon fontSize="small" />
    },
    failed: {
        color: 'error.main',
        borderColor: 'error.light',
        bgcolor: '#fef2f2',
        icon: <ErrorOutlineIcon fontSize="small" />
    },
    pending: {
        color: 'text.secondary',
        borderColor: 'divider',
        bgcolor: '#f8fafc',
        icon: <RuleIcon fontSize="small" />
    }
};

const CheckTile = ({ label, status }) => {
    const meta = statusStyles[status] || statusStyles.pending;

    return (
        <Box
            sx={{
                p: 1.5,
                border: '1px solid',
                borderColor: meta.borderColor,
                borderRadius: 1,
                bgcolor: meta.bgcolor,
                minHeight: 82
            }}
        >
            <Stack direction="row" alignItems="center" spacing={0.75} sx={{ color: meta.color, mb: 1 }}>
                {meta.icon}
                <Typography variant="caption" sx={{ textTransform: 'uppercase', fontWeight: 900, letterSpacing: 0 }}>
                    {label}
                </Typography>
            </Stack>
            <StatusChip status={status} />
        </Box>
    );
};

const CodeBlock = ({ value, minHeight = 120, maxHeight = 340 }) => (
    <Box
        component="pre"
        sx={{
            minHeight,
            maxHeight,
            overflow: 'auto',
            m: 0,
            p: 2,
            borderRadius: 1,
            bgcolor: '#0f172a',
            color: '#dbeafe',
            fontSize: 12,
            lineHeight: 1.6,
            whiteSpace: 'pre-wrap',
            wordBreak: 'break-word'
        }}
    >
        {value || 'No data'}
    </Box>
);

const CopyButton = ({ value, label = 'Copy' }) => (
    <Tooltip title={label}>
        <span>
            <IconButton
                size="small"
                disabled={!value}
                onClick={() => navigator.clipboard?.writeText(value)}
                aria-label={label}
            >
                <ContentCopyIcon fontSize="small" />
            </IconButton>
        </span>
    </Tooltip>
);

const EmptyPanel = ({ icon, title, body }) => (
    <Box
        sx={{
            border: '1px dashed',
            borderColor: 'divider',
            borderRadius: 1,
            p: 2,
            bgcolor: '#f8fafc'
        }}
    >
        <Stack direction="row" spacing={1.25} alignItems="flex-start">
            <Box sx={{ color: 'primary.main', mt: 0.25 }}>{icon}</Box>
            <Box>
                <Typography variant="subtitle2" sx={{ fontWeight: 900, mb: 0.5 }}>
                    {title}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                    {body}
                </Typography>
            </Box>
        </Stack>
    </Box>
);

const ChecksumPanel = ({ scope, item }) => (
    <Box
        sx={{
            p: 1.5,
            border: '1px solid',
            borderColor: item.matches ? 'success.light' : 'error.light',
            borderRadius: 1,
            bgcolor: item.matches ? '#f0fdf4' : '#fef2f2'
        }}
    >
        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1 }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 900, textTransform: 'capitalize' }}>
                {scope}
            </Typography>
            <Chip size="small" color={item.matches ? 'success' : 'error'} label={item.matches ? 'match' : 'mismatch'} />
        </Stack>
        <Grid container spacing={1.25}>
            <Grid item xs={12} md={6}>
                <Stack direction="row" alignItems="center" justifyContent="space-between">
                    <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 800 }}>
                        Provided
                    </Typography>
                    <CopyButton value={item.provided} label={`Copy ${scope} provided checksum`} />
                </Stack>
                <CodeBlock value={item.provided || 'missing'} minHeight={56} maxHeight={92} />
            </Grid>
            <Grid item xs={12} md={6}>
                <Stack direction="row" alignItems="center" justifyContent="space-between">
                    <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 800 }}>
                        Computed
                    </Typography>
                    <CopyButton value={item.computed} label={`Copy ${scope} computed checksum`} />
                </Stack>
                <CodeBlock value={item.computed || 'unavailable'} minHeight={56} maxHeight={92} />
            </Grid>
        </Grid>
    </Box>
);

const PayloadSandboxPage = () => {
    const [payloadText, setPayloadText] = useState('');
    const [includeDebug, setIncludeDebug] = useState(true);
    const [loading, setLoading] = useState(false);
    const [report, setReport] = useState(null);
    const [requestError, setRequestError] = useState(null);
    const [activeDebugTab, setActiveDebugTab] = useState('transaction');

    const prettyReport = useMemo(() => {
        if (!report) return '';
        return JSON.stringify(report, null, 2);
    }, [report]);

    const validatePayload = async () => {
        const byteLength = new TextEncoder().encode(payloadText).length;

        if (byteLength > MAX_PAYLOAD_BYTES) {
            setRequestError('Payload is too large for sandbox validation. Limit the request body to 256 KB.');
            return;
        }

        setLoading(true);
        setRequestError(null);
        try {
            const response = await axios.post(
                `/api/v1/sandbox/payload/validate${includeDebug ? '?include_debug=true' : ''}`,
                payloadText,
                {
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json'
                    },
                    transformRequest: [(data) => data]
                }
            );
            setReport(response.data);
        } catch (error) {
            const data = error.response?.data;
            if (data) {
                setReport(data);
            } else {
                setRequestError(error.message || 'Unable to validate payload.');
            }
        } finally {
            setLoading(false);
        }
    };

    const errors = report?.errors || [];
    const warnings = report?.warnings || [];
    const checks = report?.checks || {};
    const checksums = report?.checksums || {};
    const payloadByteLength = new TextEncoder().encode(payloadText).length;

    return (
        <Box sx={{ minHeight: '100vh', bgcolor: '#f6f8fb', pb: 5 }}>
            <Box
                sx={{
                    borderBottom: '1px solid',
                    borderColor: 'divider',
                    bgcolor: '#ffffff'
                }}
            >
                <Box sx={{ maxWidth: 1480, mx: 'auto', px: { xs: 2, md: 3 }, py: 2.25 }}>
                    <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" spacing={2.5}>
                        <Box>
                            <Stack direction="row" alignItems="center" spacing={1.25} sx={{ mb: 0.75 }}>
                                <Box
                                    sx={{
                                        width: 38,
                                        height: 38,
                                        display: 'grid',
                                        placeItems: 'center',
                                        borderRadius: 1,
                                        bgcolor: 'primary.main',
                                        color: '#fff'
                                    }}
                                >
                                    <FactCheckIcon />
                                </Box>
                                <Box>
                                    <Typography variant="h4" sx={{ fontWeight: 900, color: '#0f172a', lineHeight: 1.1 }}>
                                        Payload Sandbox
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', letterSpacing: 0 }}>
                                        TSMS V2.1 provider validation workbench
                                    </Typography>
                                </Box>
                            </Stack>
                            <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 760 }}>
                                Validate POS payload structure, canonical checksum behavior, contract rules, and sale reconciliation before production ingestion.
                            </Typography>
                        </Box>

                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ xs: 'stretch', sm: 'center' }}>
                            <Button href="/docs/pos-provider/api-testing" variant="outlined" size="small" sx={{ bgcolor: '#fff', fontWeight: 900 }}>
                                API Docs
                            </Button>
                            <Chip icon={<LanIcon />} label="Public sandbox" variant="outlined" sx={{ bgcolor: '#fff', fontWeight: 800 }} />
                            <Chip icon={<ShieldOutlinedIcon />} label="No transaction submission" variant="outlined" sx={{ bgcolor: '#fff', fontWeight: 800 }} />
                            {report && (
                                <Chip
                                    icon={report.valid ? <CheckCircleIcon /> : <ErrorOutlineIcon />}
                                    color={report.valid ? 'success' : 'error'}
                                    label={report.valid ? 'Payload valid' : `${report.summary?.error_count || errors.length} issue(s) found`}
                                    sx={{ fontWeight: 900 }}
                                />
                            )}
                        </Stack>
                    </Stack>
                </Box>
            </Box>

            {loading && <LinearProgress />}

            <Box sx={{ maxWidth: 1480, mx: 'auto', px: { xs: 2, md: 3 }, pt: 3 }}>
                {requestError && <Alert severity="error" sx={{ mb: 2 }}>{requestError}</Alert>}

                <Grid container spacing={2.5}>
                    <Grid item xs={12} xl={8} lg={7}>
                        <Paper
                            elevation={0}
                            sx={{
                                border: '1px solid',
                                borderColor: 'divider',
                                borderRadius: 1,
                                height: '100%',
                                overflow: 'hidden',
                                bgcolor: '#fff'
                            }}
                        >
                            <Box sx={{ px: 2, py: 1.5, borderBottom: '1px solid', borderColor: 'divider', bgcolor: '#ffffff' }}>
                                <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={1.5}>
                                    <Stack direction="row" spacing={1} alignItems="center">
                                        <DataObjectIcon color="primary" />
                                        <Box>
                                            <Typography variant="h6" sx={{ fontWeight: 900, lineHeight: 1.2 }}>
                                                Request Payload
                                            </Typography>
                                            <Typography variant="caption" color="text.secondary">
                                                Paste raw JSON only. The sample below is placeholder text.
                                            </Typography>
                                        </Box>
                                    </Stack>
                                    <Stack direction="row" spacing={1} flexWrap="wrap">
                                        <Tooltip title="Load known valid V2.1 sample">
                                            <Button size="small" variant="outlined" startIcon={<ContentPasteIcon />} onClick={() => setPayloadText(VALID_SAMPLE)}>
                                                Valid
                                            </Button>
                                        </Tooltip>
                                        <Tooltip title="Load sample with checksum, hardware_id, and tax issues">
                                            <Button size="small" variant="outlined" color="warning" startIcon={<ContentPasteIcon />} onClick={() => setPayloadText(INVALID_SAMPLE)}>
                                                Invalid
                                            </Button>
                                        </Tooltip>
                                        <Tooltip title="Clear editor">
                                            <Button size="small" variant="outlined" color="inherit" startIcon={<DeleteSweepIcon />} onClick={() => setPayloadText('')}>
                                                Clear
                                            </Button>
                                        </Tooltip>
                                    </Stack>
                                </Stack>
                            </Box>

                            <Box sx={{ p: 2 }}>
                                <TextField
                                    value={payloadText}
                                    onChange={(event) => setPayloadText(event.target.value)}
                                    placeholder={VALID_SAMPLE}
                                    multiline
                                    minRows={27}
                                    fullWidth
                                    spellCheck={false}
                                    inputProps={{
                                        wrap: 'off',
                                        maxLength: MAX_PAYLOAD_BYTES,
                                        style: {
                                            fontFamily: 'Menlo, Monaco, Consolas, monospace',
                                            fontSize: 12,
                                            lineHeight: 1.6,
                                            whiteSpace: 'pre',
                                            overflow: 'auto'
                                        }
                                    }}
                                    sx={{
                                        '& .MuiOutlinedInput-root': {
                                            alignItems: 'flex-start',
                                            bgcolor: '#0f172a',
                                            color: '#dbeafe',
                                            borderRadius: 1
                                        },
                                        '& textarea': {
                                            color: '#dbeafe'
                                        },
                                        '& textarea::placeholder': {
                                            color: '#dbeafe',
                                            opacity: 0.68
                                        },
                                        '& textarea:focus::placeholder': {
                                            color: 'transparent',
                                            opacity: 0
                                        },
                                        '& fieldset': {
                                            borderColor: '#1e293b'
                                        }
                                    }}
                                />

                                <Stack
                                    direction={{ xs: 'column', sm: 'row' }}
                                    alignItems={{ xs: 'stretch', sm: 'center' }}
                                    justifyContent="space-between"
                                    spacing={2}
                                    sx={{ mt: 2 }}
                                >
                                    <Stack direction="row" spacing={1} alignItems="center">
                                        <Switch checked={includeDebug} onChange={(event) => setIncludeDebug(event.target.checked)} />
                                        <Box>
                                            <Typography variant="body2" sx={{ fontWeight: 800 }}>
                                                Canonical debug
                                            </Typography>
                                            <Typography variant="caption" color="text.secondary">
                                                Show exact JSON used for checksum hashing.
                                            </Typography>
                                        </Box>
                                    </Stack>
                                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                                        <Chip
                                            size="small"
                                            variant="outlined"
                                            label={`${payloadByteLength.toLocaleString()} / ${MAX_PAYLOAD_BYTES.toLocaleString()} bytes`}
                                            color={payloadByteLength > MAX_PAYLOAD_BYTES ? 'error' : 'default'}
                                            sx={{ alignSelf: 'center', fontWeight: 800 }}
                                        />
                                        <CopyButton value={payloadText} label="Copy payload JSON" />
                                        <Button
                                            variant="contained"
                                            startIcon={<PlayArrowIcon />}
                                            onClick={validatePayload}
                                            disabled={loading || !payloadText.trim()}
                                            sx={{ minWidth: 160, fontWeight: 900 }}
                                        >
                                            {loading ? 'Validating...' : 'Validate'}
                                        </Button>
                                    </Stack>
                                </Stack>
                            </Box>
                        </Paper>
                    </Grid>

                    <Grid item xs={12} xl={4} lg={5}>
                        <Stack spacing={2.5}>
                            <Paper elevation={0} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 1, bgcolor: '#fff' }}>
                                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1.5} sx={{ mb: 2 }}>
                                    <Stack direction="row" alignItems="center" spacing={1}>
                                        <TroubleshootIcon color="primary" />
                                        <Box>
                                            <Typography variant="h6" sx={{ fontWeight: 900, lineHeight: 1.2 }}>
                                                Validation Summary
                                            </Typography>
                                            <Typography variant="caption" color="text.secondary">
                                                Schema, checksum, contract, and business-rule checks.
                                            </Typography>
                                        </Box>
                                    </Stack>
                                    {report?.validation_id && (
                                        <Chip size="small" label={report.validation_id} variant="outlined" sx={{ fontFamily: 'monospace', maxWidth: '100%' }} />
                                    )}
                                </Stack>
                            {report ? (
                                <Grid container spacing={1.5}>
                                        {['schema', 'checksum', 'contract', 'business_rules'].map((key) => (
                                        <Grid item xs={6} md={3} key={key}>
                                                <CheckTile label={key.replace('_', ' ')} status={checks[key]} />
                                        </Grid>
                                    ))}
                                        <Grid item xs={12}>
                                            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.25} sx={{ mt: 0.5 }}>
                                                <Chip color={errors.length ? 'error' : 'success'} label={`${errors.length} errors`} sx={{ fontWeight: 900 }} />
                                                <Chip color={warnings.length ? 'warning' : 'default'} label={`${warnings.length} warnings`} sx={{ fontWeight: 900 }} />
                                                <Chip variant="outlined" label={`Version ${report.version || 'v2.1'}`} sx={{ fontWeight: 900 }} />
                                            </Stack>
                                        </Grid>
                                </Grid>
                            ) : (
                                    <EmptyPanel
                                        icon={<TerminalIcon />}
                                        title="Ready to validate"
                                        body="Submit a payload to receive provider-friendly diagnostics and checksums computed by the same service used by TSMS."
                                    />
                            )}
                        </Paper>

                            <Paper elevation={0} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 1, bgcolor: '#fff' }}>
                                <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 2 }}>
                                    <Box>
                                        <Typography variant="h6" sx={{ fontWeight: 900, lineHeight: 1.2 }}>
                                            Checksum Comparison
                                        </Typography>
                                        <Typography variant="caption" color="text.secondary">
                                            Provided values compared against canonical SHA-256 output.
                                        </Typography>
                                    </Box>
                                    <ShieldOutlinedIcon color="action" />
                                </Stack>
                            {report ? (
                                <Stack spacing={2}>
                                    {['transaction', 'submission'].map((scope) => {
                                        const item = checksums[scope] || {};
                                            return <ChecksumPanel key={scope} scope={scope} item={item} />;
                                    })}
                                </Stack>
                            ) : (
                                    <EmptyPanel
                                        icon={<ShieldOutlinedIcon />}
                                        title="Checksum details will appear here"
                                        body="The sandbox computes transaction and root checksums using canonical JSON so providers can compare exact mismatches."
                                    />
                            )}
                        </Paper>

                        {(errors.length > 0 || warnings.length > 0) && (
                                <Paper elevation={0} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 1, bgcolor: '#fff' }}>
                                    <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 2 }}>
                                        <Box>
                                            <Typography variant="h6" sx={{ fontWeight: 900, lineHeight: 1.2 }}>
                                                Diagnostics
                                            </Typography>
                                            <Typography variant="caption" color="text.secondary">
                                                Actionable messages with JSON pointers and expected values.
                                            </Typography>
                                        </Box>
                                        <Chip color={errors.length ? 'error' : 'warning'} label={`${errors.length + warnings.length} findings`} sx={{ fontWeight: 900 }} />
                                    </Stack>
                                <Stack spacing={1.5}>
                                    {[...errors, ...warnings].map((item, index) => (
                                            <Alert
                                                key={`${item.code}-${index}`}
                                                severity={item.severity === 'warning' ? 'warning' : 'error'}
                                                sx={{ alignItems: 'flex-start', borderRadius: 1 }}
                                            >
                                            <Stack spacing={0.5}>
                                                <Typography variant="subtitle2" sx={{ fontWeight: 900 }}>
                                                    {item.code}
                                                </Typography>
                                                <Typography variant="body2">{item.message}</Typography>
                                                {item.pointer && (
                                                        <Typography variant="caption" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
                                                        {item.pointer}
                                                    </Typography>
                                                )}
                                                {(item.expected !== undefined || item.actual !== undefined) && (
                                                    <CodeBlock value={JSON.stringify({ expected: item.expected, actual: item.actual }, null, 2)} minHeight={52} />
                                                )}
                                            </Stack>
                                        </Alert>
                                    ))}
                                </Stack>
                            </Paper>
                        )}

                        {report?.debug && (
                                <Paper elevation={0} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 1, bgcolor: '#fff' }}>
                                <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
                                        <Box>
                                            <Typography variant="h6" sx={{ fontWeight: 900, lineHeight: 1.2 }}>
                                                Canonical Debug
                                            </Typography>
                                            <Typography variant="caption" color="text.secondary">
                                                Exact compact JSON used during checksum computation.
                                            </Typography>
                                        </Box>
                                        <CopyButton
                                            value={
                                                activeDebugTab === 'transaction'
                                                    ? report.debug.canonical_transaction_json
                                                    : activeDebugTab === 'submission'
                                                      ? report.debug.canonical_submission_json
                                                      : prettyReport
                                            }
                                            label="Copy visible debug output"
                                        />
                                </Stack>
                                <Tabs value={activeDebugTab} onChange={(event, value) => setActiveDebugTab(value)} sx={{ mb: 1 }}>
                                    <Tab value="transaction" label="Transaction" />
                                    <Tab value="submission" label="Submission" />
                                    <Tab value="full" label="Full Report" />
                                </Tabs>
                                <Divider sx={{ mb: 1.5 }} />
                                {activeDebugTab === 'transaction' && <CodeBlock value={report.debug.canonical_transaction_json} minHeight={180} />}
                                {activeDebugTab === 'submission' && <CodeBlock value={report.debug.canonical_submission_json} minHeight={180} />}
                                {activeDebugTab === 'full' && <CodeBlock value={prettyReport} minHeight={220} />}
                            </Paper>
                        )}
                    </Stack>
                </Grid>
                </Grid>
            </Box>
        </Box>
    );
};

export default PayloadSandboxPage;
