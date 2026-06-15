import React, { useEffect, useMemo, useState } from 'react';
import { Snackbar } from '@mui/material';
import ArticleIcon from '@mui/icons-material/Article';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import SearchIcon from '@mui/icons-material/Search';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import TerminalIcon from '@mui/icons-material/Terminal';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import InfoIcon from '@mui/icons-material/Info';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';

const openApiSpecUrl = '/docs/pos-provider/openapi.yaml';

const statusResponse = `{
  "success": true,
  "data": {
    "submission_uuid": "84f65461-8de1-41d1-b0ec-428a31c9340e",
    "intake_id": 1113191,
    "provider_status": "processed",
    "intake_status": "QUEUED",
    "processing_status": "PROCESSED",
    "last_error_code": null,
    "last_error_message": null,
    "received_at": "2026-05-26T05:48:33.000000Z",
    "queued_at": "2026-05-26T05:48:33.000000Z",
    "processed_at": "2026-05-26T05:48:34.000000Z",
    "tenant_id": 24,
    "terminal_id": 32,
    "transaction": {
      "transaction_id": "18f6c12c-7267-462f-a7ee-90a9574c000a",
      "receipt_no": "000002410",
      "transaction_timestamp": "2026-05-14T01:46:56Z"
    }
  }
}`;

const curlStatusLookup = `curl --request GET \\
  --url https://stagingtsms.pitx.com.ph/api/v1/submissions/{submission_uuid} \\
  --header "Accept: application/json" \\
  --header "Authorization: Bearer {provider_testing_token}"`;

const curlSandbox = `curl --request POST \\
  --url https://stagingtsms.pitx.com.ph/api/v1/sandbox/payload/validate?include_debug=true \\
  --header "Accept: application/json" \\
  --header "Content-Type: application/json" \\
  --data @payload.json`;

const officialPayloadGuidelineExample = `{
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
    "net_sales": "140.00",
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
    "payload_checksum": "748d059d894a6c3617f287211cb919b5f184a4406e96309c3be4ac601719eba0"
  },
  "payload_checksum": "63e79f5d31adfe69acfebdb213b7cd2b28bfc80309a9cc4740c30cb8b5d7c4a7"
}`;

const checksumGuidelineSnippet = `1. Build the transaction object without transaction.payload_checksum.
2. Canonicalize it:
   - sort object keys recursively
   - preserve array order
   - format gross_sales, net_sales, and amount as two-decimal strings
3. JSON encode with unescaped slashes/unicode, then SHA-256 hash.
4. Put the result in transaction.payload_checksum.
5. Build the submission without top-level payload_checksum.
6. Include the transaction object with transaction.payload_checksum.
7. Canonicalize and SHA-256 hash the submission.
8. Put the result in top-level payload_checksum.`;

const rootPayloadStructure = [
    ['submission_uuid', 'Yes', 'UUID string', 'Unique submission id. Resending the same UUID with the same checksum is idempotent; changing the payload is rejected.'],
    ['tenant_id', 'Yes', 'Integer', 'Must be the tenant that owns the authenticated terminal.'],
    ['terminal_id', 'Yes', 'Integer', 'Must match the terminal identity of the bearer token. Token sharing across terminals is rejected.'],
    ['submission_timestamp', 'Yes', 'YYYY-MM-DDTHH:mm:ssZ', 'UTC timestamp with no fractional seconds.'],
    ['transaction_count', 'Yes', 'Integer, minimum 1', 'Must exactly match the number of transaction objects submitted.'],
    ['payload_checksum', 'Yes', '64-character SHA-256', 'Computed from the canonical submission after transaction payload checksums are already present.'],
    ['transaction', 'When count is 1', 'Object', 'Required only when transaction_count is 1.'],
    ['transactions', 'When count is more than 1', 'Array, minimum 1', 'Required for batches. The array length must equal transaction_count.']
];

const transactionPayloadStructure = [
    ['transaction_id', 'Yes', 'UUID string', 'Unique transaction id inside the submitted payload.'],
    ['hardware_id', 'Yes', 'String', 'Must equal the authenticated terminal serial_number. Mismatches are rejected with HARDWARE_ID_MISMATCH.'],
    ['transaction_timestamp', 'Yes', 'YYYY-MM-DDTHH:mm:ssZ', 'UTC timestamp with no fractional seconds.'],
    ['receipt_no', 'Yes', 'Letters, numbers, dash, dot; max 128', 'Receipt identity used for duplicate detection on the same terminal and date.'],
    ['gross_sales', 'Yes', 'Numeric, minimum 0', 'Submitted value is stored after validation.'],
    ['net_sales', 'Yes', 'Numeric, minimum 0', 'Submitted value is stored after validation; TSMS does not recompute this from gross sales.'],
    ['promo_status', 'Yes', 'String', 'Submitted POS promo status.'],
    ['customer_code', 'Yes', 'String', 'Submitted POS customer code.'],
    ['payload_checksum', 'Yes', '64-character SHA-256', 'Computed from the canonical transaction object before adding this field.'],
    ['adjustments', 'Yes', 'Array, minimum 7 rows', 'Each row requires adjustment_type and amount. Include zero-amount rows for unused adjustment types.'],
    ['taxes', 'Yes', 'Array, minimum 4 rows', 'Each row requires tax_type and amount. Include VAT, VATABLE_SALES, SC_VAT_EXEMPT_SALES, and OTHER_TAX rows.']
];

const nestedPayloadStructure = [
    ['adjustments[].adjustment_type', 'Yes', 'String', 'Examples: promo_discount, senior_discount, pwd_discount, vip_card_discount, employee_discount.'],
    ['adjustments[].amount', 'Yes', 'Numeric', 'Use two-decimal numeric strings for stable checksum canonicalization.'],
    ['taxes[].tax_type', 'Yes', 'String', 'Examples: VAT, VATABLE_SALES, SC_VAT_EXEMPT_SALES, OTHER_TAX.'],
    ['taxes[].amount', 'Yes', 'Numeric', 'Use two-decimal numeric strings for stable checksum canonicalization.']
];

const curlTenantActivity = `curl --request GET \\
  --url https://stagingtsms.pitx.com.ph/api/v1/monitoring/tenants/activity?threshold_minutes=1440 \\
  --header "Accept: application/json" \\
  --header "Authorization: Bearer {provider_testing_token}"`;

const forbiddenResponse = `{
  "message": "Invalid ability provided."
}`;

const notFoundResponse = `{
  "success": false,
  "message": "Submission not found.",
  "error_code": "SUBMISSION_NOT_FOUND"
}`;

const invalidUuidResponse = `{
  "success": false,
  "message": "Invalid submission UUID.",
  "error_code": "INVALID_SUBMISSION_UUID"
}`;

const terminalTokenMismatchResponse = `{
  "success": false,
  "message": "Terminal Identity Mismatch: The terminal_id provided in the payload does not match the identity of the authenticated API token. Token sharing across multiple terminals is strictly prohibited.",
  "error_code": "TERMINAL_TOKEN_MISMATCH"
}`;

const hardwareIdMismatchResponse = `{
  "success": false,
  "message": "Forbidden - hardware ID does not match terminal",
  "error_code": "HARDWARE_ID_MISMATCH",
  "errors": {
    "hardware_id": [
      "Transaction hardware_id does not match the authenticated terminal."
    ]
  }
}`;

const submissionUuidConflictResponse = `{
  "success": false,
  "message": "Submission UUID already exists with a different payload_checksum. Correct the payload and resend with a new submission_uuid.",
  "error_code": "SUBMISSION_UUID_CONFLICT",
  "data": {
    "submission_uuid": "84f65461-8de1-41d1-b0ec-428a31c9340e",
    "intake_id": 1113191,
    "provider_status": "processed",
    "intake_status": "QUEUED",
    "processing_status": "PROCESSED",
    "last_error_code": null,
    "received_at": "2026-05-26T05:48:33.000000Z"
  }
}`;

const structuralValidationFailureResponse = `{
  "success": false,
  "message": "Structural validation failed",
  "error_code": "STRUCTURAL_VALIDATION_FAILURE",
  "errors": {
    "submission_uuid": [
      "The submission uuid field is required."
    ]
  }
}`;

const cryptographicIntegrityFailureResponse = `{
  "success": false,
  "message": "Cryptographic integrity check failed. Payload may have been tampered with or canonicalization logic is incorrect.",
  "error_code": "CRYPTOGRAPHIC_INTEGRITY_FAILURE",
  "errors": {
    "transaction": {
      "matches": false,
      "provided": "afe7b9...",
      "computed": "30cf6e..."
    }
  }
}`;

const rateLimitResponse = `{
  "success": false,
  "message": "Too many requests.",
  "retry_after": 42
}`;

const sectionLinks = [
    ['access', 'Access'],
    ['payload-guidelines', 'Payload Guidelines'],
    ['openapi', 'OpenAPI Viewer'],
    ['status', 'Status Lookup'],
    ['errors', 'Errors'],
    ['rate-limits', 'Rate Limits'],
    ['monitoring', 'Monitoring'],
    ['sandbox', 'Sandbox'],
    ['downloads', 'Downloads']
];

const resourceLinks = [
    ['OpenAPI YAML', openApiSpecUrl],
    ['Postman Collection', '/docs/pos-provider/postman_collection.json'],
    ['Error Catalog', '/docs/pos-provider/error-catalog.md'],
    ['Backfill Policy', '/docs/pos-provider/backfill-policy.md']
];

const cleanYamlValue = (value = '') => value.trim().replace(/^['"]|['"]$/g, '');

const parseComponentSchemas = (lines) => {
    const schemas = {};
    let inSchemas = false;
    let currentSchema = null;
    let currentProperty = null;
    let inRequired = false;
    let inProperties = false;

    for (const line of lines) {
        if (/^  schemas:\s*$/.test(line)) {
            inSchemas = true;
            continue;
        }

        if (!inSchemas) {
            continue;
        }

        const schemaMatch = line.match(/^    ([A-Za-z][A-Za-z0-9_]*):\s*$/);
        if (schemaMatch) {
            currentSchema = {
                name: schemaMatch[1],
                required: [],
                properties: {}
            };
            schemas[currentSchema.name] = currentSchema;
            currentProperty = null;
            inRequired = false;
            inProperties = false;
            continue;
        }

        if (!currentSchema) {
            continue;
        }

        const inlineRequired = line.match(/^      required:\s*\[(.*)]\s*$/);
        if (inlineRequired) {
            currentSchema.required = inlineRequired[1].split(',').map((item) => cleanYamlValue(item)).filter(Boolean);
            inRequired = false;
            continue;
        }

        if (/^      required:\s*$/.test(line)) {
            inRequired = true;
            inProperties = false;
            continue;
        }

        if (inRequired) {
            const requiredItem = line.match(/^        -\s*(.+)$/);
            if (requiredItem) {
                currentSchema.required.push(cleanYamlValue(requiredItem[1]));
                continue;
            }
        }

        if (/^      properties:\s*$/.test(line)) {
            inProperties = true;
            inRequired = false;
            continue;
        }

        if (!inProperties) {
            continue;
        }

        const propertyMatch = line.match(/^        ([A-Za-z_][A-Za-z0-9_]*):\s*$/);
        if (propertyMatch) {
            currentProperty = {
                name: propertyMatch[1],
                type: 'object',
                format: '',
                nullable: false,
                description: ''
            };
            currentSchema.properties[currentProperty.name] = currentProperty;
            continue;
        }

        if (!currentProperty) {
            continue;
        }

        const type = line.match(/^          type:\s*(.+)$/);
        const format = line.match(/^          format:\s*(.+)$/);
        const nullable = line.match(/^          nullable:\s*(.+)$/);
        const description = line.match(/^          description:\s*(.+)$/);
        const ref = line.match(/^          \$ref:\s*['"]#\/components\/schemas\/([^'"]+)['"]$/);

        if (type) currentProperty.type = cleanYamlValue(type[1]);
        if (format) currentProperty.format = cleanYamlValue(format[1]);
        if (nullable) currentProperty.nullable = cleanYamlValue(nullable[1]) === 'true';
        if (description) currentProperty.description = cleanYamlValue(description[1]);
        if (ref) {
            currentProperty.type = ref[1];
            currentProperty.ref = ref[1];
        }
    }

    return schemas;
};

const parseOpenApiSpec = (yaml) => {
    const lines = yaml.split(/\r?\n/);
    const info = { title: 'OpenAPI Specification', version: '', description: '' };
    const servers = [];
    const endpoints = [];
    const schemas = parseComponentSchemas(lines);
    let section = null;
    let currentServer = null;
    let currentPath = null;
    let currentEndpoint = null;
    let currentParameter = null;
    let inRequestBody = false;
    let inResponses = false;

    const pushEndpoint = () => {
        if (currentEndpoint) {
            endpoints.push({
                ...currentEndpoint,
                responses: [...new Set(currentEndpoint.responses)],
                security: [...new Set(currentEndpoint.security)]
            });
        }
        currentEndpoint = null;
        currentParameter = null;
        inRequestBody = false;
        inResponses = false;
    };

    for (const line of lines) {
        if (/^info:\s*$/.test(line)) {
            section = 'info';
            continue;
        }

        if (/^servers:\s*$/.test(line)) {
            section = 'servers';
            continue;
        }

        if (/^paths:\s*$/.test(line)) {
            section = 'paths';
            continue;
        }

        if (/^components:\s*$/.test(line)) {
            pushEndpoint();
            section = 'components';
            continue;
        }

        if (section === 'info') {
            const title = line.match(/^  title:\s*(.+)$/);
            const version = line.match(/^  version:\s*(.+)$/);
            const description = line.match(/^  description:\s*>?\s*(.*)$/);

            if (title) info.title = cleanYamlValue(title[1]);
            if (version) info.version = cleanYamlValue(version[1]);
            if (description?.[1]) info.description = cleanYamlValue(description[1]);
            continue;
        }

        if (section === 'servers') {
            const server = line.match(/^  - url:\s*(.+)$/);
            const description = line.match(/^    description:\s*(.+)$/);

            if (server) {
                currentServer = { url: cleanYamlValue(server[1]), description: '' };
                servers.push(currentServer);
            }

            if (description && currentServer) {
                currentServer.description = cleanYamlValue(description[1]);
            }
            continue;
        }

        if (section !== 'paths') {
            continue;
        }

        const pathMatch = line.match(/^  (\/[^:]+):\s*$/);
        if (pathMatch) {
            pushEndpoint();
            currentPath = pathMatch[1];
            continue;
        }

        const methodMatch = line.match(/^    (get|post|put|patch|delete):\s*$/i);
        if (methodMatch && currentPath) {
            pushEndpoint();
            currentEndpoint = {
                path: currentPath,
                method: methodMatch[1].toLowerCase(),
                summary: '',
                description: '',
                tags: [],
                security: [],
                responses: [],
                parameters: [],
                schemaRef: ''
            };
            currentParameter = null;
            inRequestBody = false;
            inResponses = false;
            continue;
        }

        if (!currentEndpoint) {
            continue;
        }

        const tags = line.match(/^      tags:\s*\[(.*)]\s*$/);
        const summary = line.match(/^      summary:\s*(.+)$/);
        const description = line.match(/^      description:\s*(.+)$/);
        const publicSecurity = line.match(/^      security:\s*\[]\s*$/);
        const bearerSecurity = line.match(/bearerAuth:\s*\[(.*)]/);
        const response = line.match(/^        ['"]?(\d{3})['"]?:\s*$/);
        const parameterStart = line.match(/^        - in:\s*(.+)$/);
        const parameterName = line.match(/^          name:\s*(.+)$/);
        const parameterRequired = line.match(/^          required:\s*(.+)$/);
        const parameterDescription = line.match(/^          description:\s*(.+)$/);
        const parameterType = line.match(/^            type:\s*(.+)$/);
        const schemaRef = line.match(/^\s+\$ref:\s*['"]#\/components\/schemas\/([^'"]+)['"]$/);

        if (tags) {
            currentEndpoint.tags = tags[1].split(',').map((tag) => cleanYamlValue(tag)).filter(Boolean);
        }

        if (summary) {
            currentEndpoint.summary = cleanYamlValue(summary[1]);
        }

        if (description) {
            currentEndpoint.description = cleanYamlValue(description[1]);
        }

        if (publicSecurity) {
            currentEndpoint.security = ['Public'];
        }

        if (bearerSecurity) {
            const abilities = bearerSecurity[1].split(',').map((ability) => cleanYamlValue(ability)).filter(Boolean);
            currentEndpoint.security = abilities.length ? abilities : ['Bearer token'];
        }

        if (response) {
            currentEndpoint.responses.push(response[1]);
        }

        if (/^      requestBody:\s*$/.test(line)) {
            inRequestBody = true;
            inResponses = false;
            currentParameter = null;
        }

        if (/^      responses:\s*$/.test(line)) {
            inResponses = true;
            inRequestBody = false;
            currentParameter = null;
        }

        if (schemaRef && inRequestBody && !currentEndpoint.schemaRef) {
            currentEndpoint.schemaRef = schemaRef[1];
        }

        if (parameterStart) {
            currentParameter = {
                in: cleanYamlValue(parameterStart[1]),
                name: '',
                required: false,
                type: '',
                description: ''
            };
            currentEndpoint.parameters.push(currentParameter);
        }

        if (currentParameter) {
            if (parameterName) currentParameter.name = cleanYamlValue(parameterName[1]);
            if (parameterRequired) currentParameter.required = cleanYamlValue(parameterRequired[1]) === 'true';
            if (parameterDescription) currentParameter.description = cleanYamlValue(parameterDescription[1]);
            if (parameterType) currentParameter.type = cleanYamlValue(parameterType[1]);
        }
    }

    pushEndpoint();

    return { info, servers, endpoints, schemas };
};

const CodeBlock = ({ value }) => {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        if (!navigator.clipboard) return;
        await navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="relative group rounded-xl overflow-hidden border border-[#c6c6cd]/50 shadow-inner">
            <pre className="m-0 p-4 overflow-x-auto bg-[#0b0f19] text-[#b9c7e0] font-mono text-[13px] leading-relaxed whitespace-pre scrollbar-thin">
                <code>{value}</code>
            </pre>
            <button
                onClick={handleCopy}
                className="absolute right-3 top-3 p-1.5 rounded bg-white/10 hover:bg-white/20 text-[#b9c7e0] hover:text-white transition-all opacity-0 group-hover:opacity-100 flex items-center gap-1 border border-white/5"
                title="Copy snippet"
            >
                <ContentCopyIcon style={{ fontSize: 13 }} />
                <span className="text-[10px] font-bold uppercase">{copied ? 'Copied' : 'Copy'}</span>
            </button>
        </div>
    );
};

const endpointKey = (endpoint) => `${endpoint.method}:${endpoint.path}`;

const MethodBadge = ({ method }) => {
    const m = method.toLowerCase();
    const styleMap = {
        get: 'bg-[#dbeafe] text-[#1d4ed8] border-[#93c5fd]',
        post: 'bg-[#dcfce7] text-[#15803d] border-[#86efac]',
        put: 'bg-[#fef3c7] text-[#b45309] border-[#fcd34d]',
        patch: 'bg-[#f3e8ff] text-[#7e22ce] border-[#d8b4fe]',
        delete: 'bg-[#fee2e2] text-[#b91c1c] border-[#fca5a5]'
    };
    const classes = styleMap[m] || styleMap.get;
    return (
        <span className={`px-2 py-0.5 rounded text-[10px] font-mono font-black uppercase border ${classes}`}>
            {method.toUpperCase()}
        </span>
    );
};

const PayloadStructureTable = ({ title, rows }) => (
    <div className="border border-[#c6c6cd]/80 rounded-xl bg-white overflow-hidden">
        <div className="px-4 py-3 bg-slate-50 border-b border-[#c6c6cd]/70">
            <h4 className="text-[11px] font-black uppercase tracking-wider text-slate-600">{title}</h4>
        </div>
        <div className="overflow-x-auto">
            <table className="min-w-full text-left">
                <thead className="bg-slate-50/70">
                    <tr>
                        <th className="px-4 py-2 text-[10px] font-black uppercase tracking-wider text-slate-400">Field</th>
                        <th className="px-4 py-2 text-[10px] font-black uppercase tracking-wider text-slate-400">Required</th>
                        <th className="px-4 py-2 text-[10px] font-black uppercase tracking-wider text-slate-400">Validation</th>
                        <th className="px-4 py-2 text-[10px] font-black uppercase tracking-wider text-slate-400">TSMS Behavior</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map(([field, required, validation, behavior]) => (
                        <tr key={field}>
                            <td className="px-4 py-3 align-top text-[11px] font-mono text-slate-800 whitespace-nowrap">{field}</td>
                            <td className="px-4 py-3 align-top text-[11px] font-semibold text-slate-600 whitespace-nowrap">{required}</td>
                            <td className="px-4 py-3 align-top text-[11px] text-slate-500 min-w-[170px]">{validation}</td>
                            <td className="px-4 py-3 align-top text-[11px] text-slate-500 min-w-[260px] leading-relaxed">{behavior}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    </div>
);

const pathParameterNames = (path = '') => [...path.matchAll(/{([^}]+)}/g)].map((match) => match[1]);

const requestUrl = (endpoint, serverUrl, values = {}) => {
    const path = pathParameterNames(endpoint.path).reduce(
        (currentPath, name) => currentPath.replace(`{${name}}`, values[name] || `{${name}}`),
        endpoint.path
    );
    const queryParams = endpoint.parameters
        .filter((parameter) => parameter.in === 'query' && values[parameter.name])
        .map((parameter) => `${encodeURIComponent(parameter.name)}=${encodeURIComponent(values[parameter.name])}`);

    return `${serverUrl}${path}${queryParams.length ? `?${queryParams.join('&')}` : ''}`;
};

const sampleBody = (endpoint) => endpoint.schemaRef
    ? JSON.stringify({ [`${endpoint.schemaRef}_example`]: true }, null, 2)
    : JSON.stringify({ example: true }, null, 2);

const buildSnippet = (endpoint, serverUrl, values = {}, language = 'curl') => {
    const method = endpoint.method.toUpperCase();
    const url = requestUrl(endpoint, serverUrl, values);
    const token = values.provider_testing_token || '{provider_testing_token}';
    const hasBody = ['POST', 'PUT', 'PATCH'].includes(method);
    const body = sampleBody(endpoint);

    if (language === 'fetch') {
        return `const response = await fetch("${url}", {
  method: "${method}",
  headers: {
    "Accept": "application/json"${endpoint.security.includes('Public') ? '' : `,
    "Authorization": "Bearer ${token}"`}${hasBody ? `,
    "Content-Type": "application/json"` : ''}
  }${hasBody ? `,
  body: JSON.stringify(${body})` : ''}
});

const data = await response.json();`;
    }

    if (language === 'axios') {
        return `import axios from "axios";

const response = await axios.request({
  method: "${method}",
  url: "${url}",
  headers: {
    "Accept": "application/json"${endpoint.security.includes('Public') ? '' : `,
    "Authorization": "Bearer ${token}"`}
  }${hasBody ? `,
  data: ${body}` : ''}
});`;
    }

    if (language === 'python') {
        return `import requests

response = requests.request(
    "${method}",
    "${url}",
    headers={
        "Accept": "application/json"${endpoint.security.includes('Public') ? '' : `,
        "Authorization": "Bearer ${token}"`}${hasBody ? `,
        "Content-Type": "application/json"` : ''}
    }${hasBody ? `,
    json=${body.replace(/true/g, 'True')}` : ''}
)

print(response.json())`;
    }

    const lines = [
        `curl --request ${method}`,
        `  --url ${url}`,
        '  --header "Accept: application/json"'
    ];

    if (!endpoint.security.includes('Public')) {
        lines.push(`  --header "Authorization: Bearer ${token}"`);
    }

    if (hasBody) {
        lines.push('  --header "Content-Type: application/json"');
        lines.push(`  --data '${body.replace(/\n/g, '')}'`);
    }

    return lines.map((line, index) => index < lines.length - 1 ? `${line} \\` : line).join('\n');
};

const schemaPropertyRows = (schemaRef, schemas) => {
    const schema = schemas[schemaRef];

    if (!schema) {
        return [];
    }

    return Object.values(schema.properties).map((property) => ({
        ...property,
        required: schema.required.includes(property.name)
    }));
};

const OpenApiViewer = () => {
    const [specText, setSpecText] = useState('');
    const [loadState, setLoadState] = useState('loading');
    const [query, setQuery] = useState('');
    const [selectedTag, setSelectedTag] = useState('All');
    const [selectedEndpointKey, setSelectedEndpointKey] = useState('');
    const [codeLanguage, setCodeLanguage] = useState('curl');
    const [consoleValues, setConsoleValues] = useState({
        provider_testing_token: '',
        submission_uuid: '',
        transaction: '',
        threshold_minutes: '1440'
    });
    const [copyToastOpen, setCopyToastOpen] = useState(false);

    useEffect(() => {
        let cancelled = false;

        fetch(openApiSpecUrl, { headers: { Accept: 'text/yaml,text/plain,*/*' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unable to load OpenAPI spec (${response.status})`);
                }
                return response.text();
            })
            .then((text) => {
                if (!cancelled) {
                    setSpecText(text);
                    setLoadState('ready');
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoadState('error');
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    const parsed = useMemo(() => specText ? parseOpenApiSpec(specText) : { info: {}, servers: [], endpoints: [], schemas: {} }, [specText]);
    const tags = useMemo(() => ['All', ...new Set(parsed.endpoints.flatMap((endpoint) => endpoint.tags))], [parsed.endpoints]);
    const filteredEndpoints = useMemo(() => {
        const search = query.trim().toLowerCase();

        return parsed.endpoints.filter((endpoint) => {
            const matchesTag = selectedTag === 'All' || endpoint.tags.includes(selectedTag);
            const searchable = `${endpoint.method} ${endpoint.path} ${endpoint.summary} ${endpoint.description} ${endpoint.tags.join(' ')}`.toLowerCase();
            const matchesSearch = !search || searchable.includes(search);

            return matchesTag && matchesSearch;
        });
    }, [parsed.endpoints, query, selectedTag]);
    const selectedEndpoint = useMemo(() => {
        const endpoint = filteredEndpoints.find((item) => endpointKey(item) === selectedEndpointKey)
            || filteredEndpoints[0]
            || parsed.endpoints[0];

        return endpoint || null;
    }, [filteredEndpoints, parsed.endpoints, selectedEndpointKey]);
    const stagingServer = useMemo(
        () => parsed.servers.find((server) => /staging/i.test(server.description))?.url || parsed.servers[0]?.url || 'https://stagingtsms.pitx.com.ph/api/v1',
        [parsed.servers]
    );
    const selectedSnippet = useMemo(
        () => selectedEndpoint ? buildSnippet(selectedEndpoint, stagingServer, consoleValues, codeLanguage) : '',
        [codeLanguage, consoleValues, selectedEndpoint, stagingServer]
    );
    const selectedSchemaRows = useMemo(
        () => selectedEndpoint ? schemaPropertyRows(selectedEndpoint.schemaRef, parsed.schemas) : [],
        [parsed.schemas, selectedEndpoint]
    );
    const selectedPathParams = useMemo(
        () => selectedEndpoint ? pathParameterNames(selectedEndpoint.path) : [],
        [selectedEndpoint]
    );
    const selectedQueryParams = selectedEndpoint?.parameters.filter((parameter) => parameter.in === 'query') || [];

    useEffect(() => {
        if (!selectedEndpointKey && parsed.endpoints.length > 0) {
            setSelectedEndpointKey(endpointKey(parsed.endpoints[0]));
        }
    }, [parsed.endpoints, selectedEndpointKey]);

    const handleEndpointSelect = (endpoint) => {
        setSelectedEndpointKey(endpointKey(endpoint));
    };

    const updateConsoleValue = (key, value) => {
        setConsoleValues((current) => ({ ...current, [key]: value }));
    };

    const handleCopy = async (value) => {
        if (!navigator.clipboard) {
            return;
        }

        await navigator.clipboard.writeText(value);
        setCopyToastOpen(true);
    };

    return (
        <div id="openapi" className="bg-white rounded-2xl border border-[#c6c6cd] overflow-hidden shadow-sm">
            {/* Header */}
            <div className="px-6 py-8 bg-[#101828] text-white flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <div className="flex gap-2 mb-2">
                        <span className="bg-[#dbeafe] text-[#1d4ed8] text-[10px] font-black uppercase px-2 py-0.5 rounded border border-[#93c5fd]/30">OpenAPI 3.0</span>
                        <span className="bg-[#dcfce7] text-[#15803d] text-[10px] font-black uppercase px-2 py-0.5 rounded border border-[#86efac]/30">Staging Sandbox</span>
                    </div>
                    <h3 className="text-2xl font-bold tracking-tight">API Reference explorer</h3>
                    <p className="text-sm text-slate-300 mt-1 max-w-2xl leading-relaxed">
                        Search endpoints, review token requirements, copy requests, and inspect the published TSMS provider contract dynamically.
                    </p>
                </div>
                <div className="flex gap-2 w-full md:w-auto">
                    <a
                        href="/sandbox/payload"
                        className="flex-1 md:flex-none text-center bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-lg flex items-center justify-center gap-1.5 transition-colors shadow-sm"
                    >
                        <FactCheckIcon style={{ fontSize: 16 }} />
                        Open Sandbox
                    </a>
                    <a
                        href={openApiSpecUrl}
                        className="flex-1 md:flex-none text-center bg-slate-800 hover:bg-slate-700 text-white font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-lg flex items-center justify-center gap-1.5 transition-colors border border-slate-700"
                    >
                        <ArticleIcon style={{ fontSize: 16 }} />
                        YAML spec
                    </a>
                </div>
            </div>

            {loadState === 'loading' && (
                <div className="p-8 flex items-center gap-3 text-slate-500 text-sm">
                    <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    <span>Parsing OpenAPI document contract...</span>
                </div>
            )}

            {loadState === 'error' && (
                <div className="p-6 m-6 bg-amber-50 text-amber-800 border border-amber-200 rounded-xl flex items-center gap-3 text-sm">
                    <ErrorOutlineIcon className="text-amber-600" />
                    <span>The OpenAPI specification YAML file could not be fetched. Staging link is temporarily unavailable.</span>
                </div>
            )}

            {loadState === 'ready' && (
                <div className="p-6 space-y-6">
                    {/* Stats Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="p-4 border border-[#c6c6cd] rounded-xl bg-slate-50">
                            <span className="text-[10px] font-black uppercase tracking-wider text-slate-500">YAML Contract</span>
                            <h4 className="text-sm font-bold mt-1 text-[#0b1c30] truncate">{parsed.info.title}</h4>
                            <p className="text-xs text-slate-400 mt-0.5">Version {parsed.info.version || '1.0.0'}</p>
                        </div>
                        <div className="p-4 border border-[#c6c6cd] rounded-xl bg-slate-50">
                            <span className="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Endpoints</span>
                            <h4 className="text-xl font-black mt-0.5 text-[#0b1c30]">{parsed.endpoints.length}</h4>
                            <p className="text-xs text-slate-400 mt-0.5">Parsed from OpenAPI path nodes</p>
                        </div>
                        <div className="p-4 border border-[#c6c6cd] rounded-xl bg-slate-50">
                            <span className="text-[10px] font-black uppercase tracking-wider text-slate-500">Staging host</span>
                            <div className="text-xs font-mono font-bold mt-1.5 text-slate-800 break-all select-all">
                                {stagingServer}
                            </div>
                        </div>
                    </div>

                    {/* Three Column Grid Explorer */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                        {/* Left column - Selector */}
                        <div className="lg:col-span-3 space-y-4">
                            <div className="relative">
                                <input
                                    type="text"
                                    placeholder="Filter endpoints..."
                                    className="w-full bg-slate-50 border border-[#c6c6cd] rounded-lg pl-9 pr-3 py-2 text-sm text-[#0b1c30] placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                />
                                <div className="absolute left-3 top-2.5 text-slate-400">
                                    <SearchIcon style={{ fontSize: 18 }} />
                                </div>
                            </div>

                            {/* Tags filter list */}
                            <div className="flex gap-1.5 flex-wrap">
                                {tags.map((tag) => (
                                    <button
                                        key={tag}
                                        onClick={() => setSelectedTag(tag)}
                                        className={`px-2.5 py-1 text-[10px] font-black uppercase rounded transition-all border ${
                                            selectedTag === tag
                                                ? 'bg-[#101828] text-white border-[#101828]'
                                                : 'bg-white text-slate-600 border-[#c6c6cd] hover:bg-slate-50'
                                        }`}
                                    >
                                        {tag}
                                    </button>
                                ))}
                            </div>

                            {/* Scrollable list */}
                            <div className="max-h-[500px] overflow-y-auto space-y-1.5 pr-1 scrollbar-thin">
                                {filteredEndpoints.map((endpoint) => {
                                    const isSelected = selectedEndpoint && endpointKey(selectedEndpoint) === endpointKey(endpoint);
                                    return (
                                        <button
                                            key={endpointKey(endpoint)}
                                            onClick={() => handleEndpointSelect(endpoint)}
                                            className={`w-full p-3 rounded-lg text-left border transition-all flex flex-col gap-1.5 ${
                                                isSelected
                                                    ? 'border-blue-600 bg-blue-50/50 shadow-sm'
                                                    : 'border-[#c6c6cd]/60 bg-white hover:bg-slate-50/70'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <MethodBadge method={endpoint.method} />
                                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                                    {endpoint.tags[0] || 'API'}
                                                </span>
                                            </div>
                                            <div className="text-xs font-mono font-bold text-slate-800 break-all leading-normal">
                                                {endpoint.path}
                                            </div>
                                            {endpoint.summary && (
                                                <div className="text-[11px] text-slate-500 font-medium truncate w-full">
                                                    {endpoint.summary}
                                                </div>
                                            )}
                                        </button>
                                    );
                                })}
                                {filteredEndpoints.length === 0 && (
                                    <div className="text-xs text-slate-400 p-4 text-center border border-[#c6c6cd]/50 border-dashed rounded-lg">
                                        No endpoints match filters.
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Center column - Details */}
                        <div className="lg:col-span-5 space-y-5">
                            {selectedEndpoint ? (
                                <div className="border border-[#c6c6cd] rounded-xl overflow-hidden bg-white shadow-sm">
                                    {/* Endpoint header block */}
                                    <div className="p-5 border-b border-[#c6c6cd] bg-slate-50/60">
                                        <div className="flex items-center gap-2 mb-2 flex-wrap">
                                            <MethodBadge method={selectedEndpoint.method} />
                                            {selectedEndpoint.tags.map((tag) => (
                                                <span key={tag} className="text-[9px] font-bold border border-[#c6c6cd] text-slate-500 px-1.5 py-0.5 rounded bg-white uppercase">
                                                    {tag}
                                                </span>
                                            ))}
                                        </div>
                                        <h4 className="text-lg font-bold text-[#0b1c30] leading-snug">
                                            {selectedEndpoint.summary || 'Untitled endpoint'}
                                        </h4>
                                        <div className="text-xs font-mono font-bold text-blue-600 bg-blue-50/30 border border-blue-100 rounded p-2 mt-3 break-all select-all">
                                            {selectedEndpoint.path}
                                        </div>
                                        {selectedEndpoint.description && (
                                            <p className="text-xs text-slate-500 mt-3 leading-relaxed">
                                                {selectedEndpoint.description}
                                            </p>
                                        )}
                                    </div>

                                    {/* Middle details info panel */}
                                    <div className="p-5 space-y-5">
                                        {/* Security & Codes */}
                                        <div className="grid grid-cols-2 gap-4">
                                            <div>
                                                <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Security</span>
                                                <div className="flex flex-wrap gap-1">
                                                    {(selectedEndpoint.security.length ? selectedEndpoint.security : ['Bearer token']).map((ability) => (
                                                        <span key={ability} className="text-[10px] font-bold bg-slate-100 text-slate-700 px-2 py-0.5 rounded border border-slate-200 uppercase">
                                                            {ability}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                            <div>
                                                <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Response codes</span>
                                                <div className="flex flex-wrap gap-1">
                                                    {selectedEndpoint.responses.map((code) => {
                                                        const isSuccess = code.startsWith('2');
                                                        const color = isSuccess ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200';
                                                        return (
                                                            <span key={code} className={`text-[10px] font-bold px-2 py-0.5 rounded border uppercase ${color}`}>
                                                                {code}
                                                            </span>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Request Parameters */}
                                        <div>
                                            <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-2">Request parameters</span>
                                            {(selectedEndpoint.parameters.length || selectedPathParams.length) ? (
                                                <div className="space-y-1.5 max-h-[220px] overflow-y-auto pr-1">
                                                    {[...selectedEndpoint.parameters, ...selectedPathParams
                                                        .filter((name) => !selectedEndpoint.parameters.some((parameter) => parameter.name === name))
                                                        .map((name) => ({ name, in: 'path', required: true, type: 'string', description: '' }))].map((parameter) => (
                                                        <div
                                                            key={`${parameter.in}-${parameter.name}`}
                                                            className="p-2.5 border border-[#c6c6cd]/50 rounded-lg bg-slate-50/50 flex justify-between items-start gap-2"
                                                        >
                                                            <div>
                                                                <span className="text-xs font-mono font-bold text-slate-800">{parameter.name}</span>
                                                                {parameter.description && (
                                                                    <p className="text-[10px] text-slate-400 mt-0.5 leading-normal">{parameter.description}</p>
                                                                )}
                                                            </div>
                                                            <div className="flex gap-1 shrink-0">
                                                                <span className="text-[9px] font-bold bg-white text-slate-500 border border-[#c6c6cd] px-1 rounded uppercase">{parameter.in}</span>
                                                                <span className="text-[9px] font-bold bg-white text-slate-500 border border-[#c6c6cd] px-1 rounded uppercase">{parameter.type || 'string'}</span>
                                                                {parameter.required && <span className="text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200 px-1 rounded uppercase">Required</span>}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <div className="text-[11px] text-slate-400 italic">No URL parameters are defined for this request.</div>
                                            )}
                                        </div>

                                        {/* Request Schema Table */}
                                        <div>
                                            <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-2">Payload Schema details</span>
                                            {selectedSchemaRows.length ? (
                                                <div className="space-y-1.5 max-h-[260px] overflow-y-auto pr-1">
                                                    {selectedSchemaRows.map((property) => (
                                                        <div
                                                            key={property.name}
                                                            className="p-2.5 border border-[#c6c6cd]/50 rounded-lg bg-white flex justify-between items-start gap-2"
                                                        >
                                                            <div>
                                                                <span className="text-xs font-mono font-bold text-slate-800">{property.name}</span>
                                                                {property.description && (
                                                                    <p className="text-[10px] text-slate-400 mt-0.5 leading-normal">{property.description}</p>
                                                                )}
                                                            </div>
                                                            <div className="flex gap-1 shrink-0 flex-wrap justify-end max-w-[50%]">
                                                                <span className="text-[9px] font-bold bg-slate-50 text-slate-500 border border-slate-200 px-1 rounded uppercase">
                                                                    {property.format ? `${property.type}:${property.format}` : property.type}
                                                                </span>
                                                                {property.required && <span className="text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200 px-1 rounded uppercase">Req</span>}
                                                                {property.nullable && <span className="text-[9px] font-bold bg-slate-50 text-slate-400 border border-slate-200 px-1 rounded uppercase">Null</span>}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <div className="text-[11px] text-slate-400 italic">This endpoint does not declare a structured schema body.</div>
                                            )}
                                        </div>

                                        {/* Spec Summary JSON */}
                                        <div>
                                            <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">JSON representation metadata</span>
                                            <CodeBlock
                                                value={JSON.stringify({
                                                    method: selectedEndpoint.method.toUpperCase(),
                                                    path: selectedEndpoint.path,
                                                    tags: selectedEndpoint.tags,
                                                    security: selectedEndpoint.security,
                                                    request_schema: selectedEndpoint.schemaRef || null,
                                                    responses: selectedEndpoint.responses
                                                }, null, 2)}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="p-8 text-center text-slate-400 border border-dashed border-[#c6c6cd] rounded-xl">
                                    No endpoint selected. Select one in the sidebar to review documentation.
                                </div>
                            )}
                        </div>

                        {/* Right column - Console */}
                        <div className="lg:col-span-4 sticky top-6">
                            <div className="bg-[#131b2e] border border-white/10 rounded-xl shadow-lg overflow-hidden flex flex-col">
                                <div className="p-4 border-b border-white/10 flex justify-between items-center bg-white/5">
                                    <div className="flex items-center gap-2 text-white">
                                        <TerminalIcon fontSize="small" className="text-slate-400" />
                                        <span className="text-xs font-bold uppercase tracking-widest">API console</span>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <div className="w-2.5 h-2.5 rounded-full bg-red-500/60"></div>
                                        <div className="w-2.5 h-2.5 rounded-full bg-yellow-500/60"></div>
                                        <div className="w-2.5 h-2.5 rounded-full bg-green-500/60"></div>
                                    </div>
                                </div>

                                <div className="p-5 space-y-4">
                                    {/* Endpoint info */}
                                    <div>
                                        <span className="block text-[9px] text-slate-400/80 mb-1 uppercase tracking-widest font-black">Target host URL</span>
                                        <div className="text-xs font-mono text-slate-300 break-all select-all px-2.5 py-1.5 bg-black/20 rounded border border-white/5">
                                            {stagingServer}
                                        </div>
                                    </div>

                                    {/* Parameter inputs */}
                                    <div className="space-y-2.5">
                                        {selectedEndpoint && !selectedEndpoint.security.includes('Public') && (
                                            <div>
                                                <label className="block text-[9px] text-slate-400 uppercase tracking-widest font-black mb-1">Bearer Token</label>
                                                <input
                                                    type="text"
                                                    className="w-full bg-white/5 border border-white/10 rounded-lg py-1.5 px-3 text-white font-mono text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 placeholder-white/20"
                                                    placeholder="provider_testing_token"
                                                    value={consoleValues.provider_testing_token}
                                                    onChange={(e) => updateConsoleValue('provider_testing_token', e.target.value)}
                                                />
                                            </div>
                                        )}
                                        {selectedPathParams.map((name) => (
                                            <div key={name}>
                                                <label className="block text-[9px] text-slate-400 uppercase tracking-widest font-black mb-1">{name} (Path)</label>
                                                <input
                                                    type="text"
                                                    className="w-full bg-white/5 border border-white/10 rounded-lg py-1.5 px-3 text-white font-mono text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 placeholder-white/20"
                                                    placeholder={`{${name}}`}
                                                    value={consoleValues[name] || ''}
                                                    onChange={(e) => updateConsoleValue(name, e.target.value)}
                                                />
                                            </div>
                                        ))}
                                        {selectedQueryParams.map((parameter) => (
                                            <div key={parameter.name}>
                                                <label className="block text-[9px] text-slate-400 uppercase tracking-widest font-black mb-1">{parameter.name} (Query)</label>
                                                <input
                                                    type="text"
                                                    className="w-full bg-white/5 border border-white/10 rounded-lg py-1.5 px-3 text-white font-mono text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 placeholder-white/20"
                                                    placeholder={parameter.name === 'threshold_minutes' ? '1440' : parameter.name}
                                                    value={consoleValues[parameter.name] || ''}
                                                    onChange={(e) => updateConsoleValue(parameter.name, e.target.value)}
                                                />
                                            </div>
                                        ))}
                                    </div>

                                    {/* Code language Selector tabs */}
                                    <div>
                                        <span className="block text-[9px] text-slate-400/80 mb-1.5 uppercase tracking-widest font-black">SDK Language</span>
                                        <div className="flex bg-white/5 rounded-lg p-1 border border-white/5">
                                            {['curl', 'fetch', 'axios', 'python'].map((lang) => (
                                                <button
                                                    key={lang}
                                                    onClick={() => setCodeLanguage(lang)}
                                                    className={`flex-1 py-1 rounded text-[11px] font-bold uppercase transition-all ${
                                                        codeLanguage === lang
                                                            ? 'bg-white/15 text-white shadow-inner'
                                                            : 'text-slate-400 hover:text-white'
                                                    }`}
                                                >
                                                    {lang === 'curl' ? 'cURL' : lang}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Snippet Code block wrapper */}
                                    <div className="space-y-3">
                                        <pre className="m-0 p-3.5 bg-black/40 text-cyan-400 rounded-xl font-mono text-[12px] leading-relaxed overflow-x-auto whitespace-pre border border-white/5">
                                            <code>{selectedSnippet}</code>
                                        </pre>
                                        <div className="flex gap-2">
                                            <button
                                                onClick={() => handleCopy(selectedSnippet)}
                                                className="flex-1 bg-white text-slate-900 hover:bg-slate-100 font-bold text-xs uppercase tracking-wider py-2.5 rounded-lg flex items-center justify-center gap-1.5 transition-colors"
                                            >
                                                <ContentCopyIcon style={{ fontSize: 14 }} />
                                                Copy Snippet
                                            </button>
                                            <a
                                                href="/docs/pos-provider/postman_collection.json"
                                                className="flex-1 text-center bg-white/5 hover:bg-white/10 text-white font-bold text-xs uppercase tracking-wider py-2.5 rounded-lg border border-white/10 flex items-center justify-center transition-colors"
                                            >
                                                Postman SDK
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Collapsible raw specs block */}
                    <details className="group border border-[#c6c6cd] rounded-xl overflow-hidden bg-white shadow-sm">
                        <summary className="p-4 bg-slate-50 cursor-pointer font-bold text-sm text-[#0b1c30] select-none flex items-center justify-between group-open:border-b border-[#c6c6cd]">
                            <span>Raw OpenAPI YAML Specification file</span>
                            <span className="transition-transform group-open:rotate-180">
                                <ExpandMoreIcon />
                            </span>
                        </summary>
                        <div className="p-4 bg-slate-50">
                            <CodeBlock value={specText} />
                        </div>
                    </details>
                </div>
            )}

            <Snackbar
                open={copyToastOpen}
                autoHideDuration={2200}
                onClose={() => setCopyToastOpen(false)}
                message="Copied request snippet to clipboard"
            />
        </div>
    );
};

const EndpointSection = ({ id, icon, title, method, path, children }) => (
    <div id={id} className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#c6c6cd]">
            <div className="flex items-start gap-3">
                <div className="text-blue-600 mt-0.5 shrink-0 bg-blue-50 p-2 rounded-lg">{icon}</div>
                <div>
                    <h3 className="text-base font-bold text-[#0b1c30] leading-snug">{title}</h3>
                    <p className="text-xs text-slate-400 mt-0.5">POS provider static validation schema</p>
                </div>
            </div>
            <div className="flex items-center gap-2 flex-wrap">
                <MethodBadge method={method} />
                <span className="font-mono text-xs font-bold px-2 py-1 bg-slate-50 border border-[#c6c6cd] rounded break-all">
                    {path}
                </span>
            </div>
        </div>
        <div>
            {children}
        </div>
    </div>
);

const ProviderApiDocsPage = () => {
    useEffect(() => {
        // Add font stylesheet to head dynamically
        const link = document.createElement('link');
        link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap';
        link.rel = 'stylesheet';
        document.head.appendChild(link);
        return () => {
            document.head.removeChild(link);
        };
    }, []);

    const scrollToSection = (id) => {
        const element = document.getElementById(id);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    return (
        <div className="h-screen bg-[#f8f9ff] text-[#0b1c30] font-sans antialiased flex flex-col overflow-hidden">
            <style>{`
                ::-webkit-scrollbar { width: 6px; height: 6px; }
                ::-webkit-scrollbar-track { background: transparent; }
                ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
                ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                html { scroll-behavior: smooth; }
            `}</style>

            {/* Top Bar Header */}
            <header className="flex justify-between items-center w-full px-6 h-16 sticky top-0 z-50 bg-white border-b border-[#c6c6cd] shadow-sm shrink-0">
                <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-sm">
                        <ArticleIcon style={{ fontSize: 20 }} />
                    </div>
                    <div>
                        <span className="font-bold text-base text-[#0b1c30] leading-none block">POS Provider API Docs</span>
                        <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">TSMS Staging Sandbox Reference</span>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span className="hidden sm:inline-flex items-center gap-1 bg-[#dcfce7] text-[#15803d] text-[10px] font-black uppercase px-2 py-1 border border-[#86efac]/40 rounded-full">
                        <ShieldOutlinedIcon style={{ fontSize: 12 }} />
                        Testing Environment
                    </span>
                    <span className="hidden sm:inline-flex items-center gap-1 bg-[#dbeafe] text-[#1d4ed8] text-[10px] font-black uppercase px-2 py-1 border border-[#93c5fd]/40 rounded-full">
                        <LockOutlinedIcon style={{ fontSize: 12 }} />
                        Token-Gated
                    </span>
                </div>
            </header>

            {/* Layout Shell */}
            <div className="flex-1 flex overflow-hidden">
                {/* Left navigation sidebar */}
                <aside className="hidden md:flex flex-col w-64 bg-[#eff4ff] border-r border-[#c6c6cd] shrink-0 h-full overflow-y-auto">
                    <div className="p-5 flex-1 flex flex-col gap-6">
                        <div>
                            <h5 className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">Navigation</h5>
                            <nav className="space-y-1">
                                {sectionLinks.map(([id, label]) => {
                                    let icon = <HelpOutlineIcon style={{ fontSize: 18 }} />;
                                    if (id === 'access') icon = <LockOutlinedIcon style={{ fontSize: 18 }} />;
                                    if (id === 'payload-guidelines') icon = <FactCheckIcon style={{ fontSize: 18 }} />;
                                    if (id === 'openapi') icon = <ArticleIcon style={{ fontSize: 18 }} />;
                                    if (id === 'status') icon = <SearchIcon style={{ fontSize: 18 }} />;
                                    if (id === 'errors') icon = <ErrorOutlineIcon style={{ fontSize: 18 }} />;
                                    if (id === 'rate-limits') icon = <ShieldOutlinedIcon style={{ fontSize: 18 }} />;
                                    if (id === 'monitoring') icon = <TerminalIcon style={{ fontSize: 18 }} />;
                                    if (id === 'sandbox') icon = <FactCheckIcon style={{ fontSize: 18 }} />;
                                    if (id === 'downloads') icon = <InfoIcon style={{ fontSize: 18 }} />;

                                    return (
                                        <button
                                            key={id}
                                            onClick={() => scrollToSection(id)}
                                            className="flex items-center gap-3 text-slate-600 hover:text-[#0b1c30] px-3.5 py-2 hover:bg-[#dce9ff] rounded-lg transition-all text-left w-full text-xs font-semibold"
                                        >
                                            <span className="shrink-0">{icon}</span>
                                            <span>{label}</span>
                                        </button>
                                    );
                                })}
                            </nav>
                        </div>

                        <div>
                            <h5 className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">Resource Files</h5>
                            <nav className="space-y-1">
                                {resourceLinks.map(([label, href]) => (
                                    <a
                                        key={href}
                                        href={href}
                                        className="flex items-center gap-3 text-slate-600 hover:text-blue-600 px-3.5 py-2 hover:bg-[#dce9ff]/50 rounded-lg transition-all text-xs font-semibold"
                                    >
                                        <ArticleIcon style={{ fontSize: 16 }} className="text-slate-400 shrink-0" />
                                        <span className="truncate">{label}</span>
                                    </a>
                                ))}
                            </nav>
                        </div>
                    </div>

                    {/* Sidebar Footer info */}
                    <div className="p-5 border-t border-[#c6c6cd] bg-[#eff4ff]">
                        <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded bg-[#101828] text-white flex items-center justify-center font-bold text-xs">
                                v1
                            </div>
                            <div>
                                <p className="text-xs font-bold text-[#0b1c30]">API Version 1.0.0</p>
                                <p className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Production Stable</p>
                            </div>
                        </div>
                    </div>
                </aside>

                {/* Scrollable Center Content Area */}
                <main className="flex-1 overflow-y-auto bg-[#f8f9ff]">
                    <div className="max-w-6xl mx-auto px-6 py-8 space-y-6">
                        {/* Page Header Intro */}
                        <div className="pb-6 border-b border-[#c6c6cd]/60">
                            <div className="flex items-center gap-2 text-slate-400 text-xs font-bold uppercase tracking-widest mb-1">
                                <FactCheckIcon style={{ fontSize: 15 }} className="text-blue-600" />
                                <span>Developer testing gateway</span>
                            </div>
                            <h1 className="text-3xl font-black tracking-tight text-[#0b1c30]">Provider testing access hub</h1>
                            <p className="text-sm text-slate-500 mt-2 max-w-3xl leading-relaxed">
                                Securely validate your POS transaction ingestion integration, verify custom payload hashes, and check current pipeline processing status codes inside our sandbox system.
                            </p>
                        </div>

                        {/* Info callout */}
                        <div className="p-4 bg-[#eff4ff] border-l-4 border-blue-600 rounded-r-xl flex items-start gap-3">
                            <InfoIcon className="text-blue-600 shrink-0 mt-0.5" style={{ fontSize: 20 }} />
                            <p className="text-xs text-[#0b1c30] leading-relaxed">
                                These endpoints are specifically for validation, support backfills, and health testing. Standard transaction submission workflows must continue using the ingestion endpoint described in the core specification.
                            </p>
                        </div>

                        {/* Bento Access Grid */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6" id="access">
                            {/* Card 1 */}
                            <div className="p-6 bg-white border border-[#c6c6cd] rounded-2xl flex flex-col justify-between hover:border-blue-600/40 transition-colors shadow-sm">
                                <div className="space-y-4">
                                    <div className="flex justify-between items-start">
                                        <div className="p-2.5 bg-slate-100 rounded-lg text-slate-600">
                                            <FactCheckIcon />
                                        </div>
                                        <span className="text-[9px] font-black tracking-wider uppercase bg-[#dcfce7] text-[#15803d] px-2 py-0.5 rounded-full border border-[#86efac]/40">
                                            Open validation
                                        </span>
                                    </div>
                                    <div>
                                        <h3 className="text-base font-bold text-[#0b1c30]">Payload Validation Sandbox</h3>
                                        <p className="text-xs text-slate-400 mt-1 leading-relaxed">
                                            Validate transaction payload syntax, structure requirements, and calculated checksums without side-effects or DB persistence.
                                        </p>
                                    </div>
                                    <ul className="space-y-2 pt-2 border-t border-slate-100">
                                        <li className="flex items-center gap-2 text-xs text-slate-500">
                                            <CheckCircleIcon className="text-green-500" style={{ fontSize: 16 }} />
                                            <span>No bearer auth token required</span>
                                        </li>
                                        <li className="flex items-center gap-2 text-xs text-slate-500">
                                            <CheckCircleIcon className="text-green-500" style={{ fontSize: 16 }} />
                                            <span>Validates schemas &amp; SHA-256 hashes</span>
                                        </li>
                                        <li className="flex items-center gap-2 text-xs text-slate-500">
                                            <CheckCircleIcon className="text-green-500" style={{ fontSize: 16 }} />
                                            <span>Perfect for debugging JSON structure</span>
                                        </li>
                                    </ul>
                                </div>
                                <button
                                    onClick={() => scrollToSection('sandbox')}
                                    className="w-full mt-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider rounded-lg transition-colors shadow-sm"
                                >
                                    Review Sandbox
                                </button>
                            </div>

                            {/* Card 2 */}
                            <div className="p-6 bg-white border border-[#c6c6cd] rounded-2xl flex flex-col justify-between hover:border-blue-600/40 transition-colors shadow-sm">
                                <div className="space-y-4">
                                    <div className="flex justify-between items-start">
                                        <div className="p-2.5 bg-blue-50 rounded-lg text-blue-600">
                                            <SearchIcon />
                                        </div>
                                        <span className="text-[9px] font-black tracking-wider uppercase bg-[#dbeafe] text-[#1d4ed8] px-2 py-0.5 rounded-full border border-[#93c5fd]/40">
                                            Token authorized
                                        </span>
                                    </div>
                                    <div>
                                        <h3 className="text-base font-bold text-[#0b1c30]">Submission Status Lookup</h3>
                                        <p className="text-xs text-slate-400 mt-1 leading-relaxed">
                                            Look up queuing status, intake errors, and processing logs for specific transactional UUIDs using testing credentials.
                                        </p>
                                    </div>
                                    <ul className="space-y-2 pt-2 border-t border-slate-100">
                                        <li className="flex items-center gap-2 text-xs text-slate-500">
                                            <CheckCircleIcon className="text-blue-500" style={{ fontSize: 16 }} />
                                            <span>Requires staging bearer credential</span>
                                        </li>
                                        <li className="flex items-center gap-2 text-xs text-slate-500">
                                            <CheckCircleIcon className="text-blue-500" style={{ fontSize: 16 }} />
                                            <span>Needs ability: <code>transaction:read</code></span>
                                        </li>
                                        <li className="flex items-center gap-2 text-xs text-slate-500">
                                            <CheckCircleIcon className="text-blue-500" style={{ fontSize: 16 }} />
                                            <span>Scoped to tenant and terminal boundaries</span>
                                        </li>
                                    </ul>
                                </div>
                                <button
                                    onClick={() => scrollToSection('status')}
                                    className="w-full mt-6 py-2.5 bg-white hover:bg-slate-50 border border-[#c6c6cd] text-slate-800 font-bold text-xs uppercase tracking-wider rounded-lg transition-colors"
                                >
                                    Review Status API
                                </button>
                            </div>
                        </div>

                        {/* Critical Security Notice Warning Card */}
                        <div className="p-5 bg-amber-50/50 border border-amber-200/80 rounded-2xl flex items-start gap-4 relative overflow-hidden shadow-sm">
                            <div className="absolute right-0 top-0 opacity-5 pointer-events-none select-none">
                                <LockOutlinedIcon style={{ fontSize: 120 }} className="text-amber-900" />
                            </div>
                            <div className="p-2.5 bg-amber-100 text-amber-800 rounded-lg shrink-0 mt-0.5">
                                <LockOutlinedIcon />
                            </div>
                            <div className="space-y-1 relative z-10">
                                <h4 className="text-sm font-bold text-amber-950">Critical Security Notice</h4>
                                <p className="text-xs text-amber-900/80 leading-relaxed max-w-4xl">
                                    Do not use production ingestion tokens for provider testing. Testing tokens should be issued only for staging/debugging and should not include transaction submission abilities unless explicitly approved by the platform manager.
                                </p>
                            </div>
                        </div>

                        {/* Payload Guidelines Section */}
                        <EndpointSection
                            id="payload-guidelines"
                            icon={<FactCheckIcon />}
                            title="Official Payload Guidelines"
                            method="POST"
                            path="/api/v1/transactions/official"
                        >
                            <p className="text-xs text-slate-500 leading-relaxed mb-4 max-w-4xl">
                                TSMS currently accepts the official submission envelope only when the authenticated terminal, declared terminal_id,
                                transaction hardware_id, payload structure, and cryptographic checksums all align. The rules below reflect the live ingestion
                                implementation used by the endpoint.
                            </p>

                            <div className="space-y-4 mb-4">
                                <div className="flex flex-col gap-1">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">Payload structure reference</span>
                                    <p className="text-[11px] text-slate-500 leading-relaxed max-w-4xl">
                                        These are the fields TSMS validates for official submissions. Single-transaction payloads use <code>transaction</code>;
                                        batch payloads use <code>transactions</code>. Every transaction in either form must follow the same transaction object structure.
                                    </p>
                                </div>
                                <PayloadStructureTable title="Root submission envelope" rows={rootPayloadStructure} />
                                <PayloadStructureTable title="Transaction object" rows={transactionPayloadStructure} />
                                <PayloadStructureTable title="Adjustment and tax rows" rows={nestedPayloadStructure} />
                            </div>

                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-3">Structure requirements</span>
                                    <ul className="space-y-2 text-[11px] text-slate-500 leading-relaxed">
                                        <li><strong>Single transaction:</strong> set <code>transaction_count</code> to <code>1</code> and provide <code>transaction</code>.</li>
                                        <li><strong>Batch:</strong> set <code>transaction_count</code> to the exact number of records and provide <code>transactions</code>.</li>
                                        <li><strong>Timestamps:</strong> use UTC <code>YYYY-MM-DDTHH:mm:ssZ</code>; fractional seconds are rejected.</li>
                                        <li><strong>Receipt number:</strong> letters, numbers, dash, and dot only; maximum 128 characters.</li>
                                        <li><strong>Rows:</strong> at least seven adjustment rows and four tax rows are required per transaction.</li>
                                    </ul>
                                </div>

                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-3">Security and validation</span>
                                    <ul className="space-y-2 text-[11px] text-slate-500 leading-relaxed">
                                        <li>The bearer token must belong to the submitted <code>terminal_id</code>.</li>
                                        <li>The terminal must be active and must belong to submitted <code>tenant_id</code>.</li>
                                        <li>Each <code>transaction.hardware_id</code> must match the authenticated terminal <code>serial_number</code>.</li>
                                        <li>Duplicate <code>submission_uuid</code> with the same payload is idempotent; payload drift is rejected.</li>
                                        <li>Duplicate receipt conflicts on the same terminal/date are rejected.</li>
                                    </ul>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">Minimal official payload shape</span>
                                    <CodeBlock value={officialPayloadGuidelineExample} />
                                </div>
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">Checksum calculation order</span>
                                    <CodeBlock value={checksumGuidelineSnippet} />
                                    <div className="p-3 bg-blue-50 border border-blue-100 rounded-xl text-[11px] text-blue-900/80 leading-normal">
                                        Official ingestion stores submitted financial values after validation. It does not recompute or enforce a gross-to-net
                                        formula, so POS EOD totals should be represented directly in the payload fields.
                                    </div>
                                </div>
                            </div>
                        </EndpointSection>

                        {/* Dynamic OpenAPI Spec explorer */}
                        <OpenApiViewer />

                        {/* Status Lookup Card section */}
                        <EndpointSection
                            id="status"
                            icon={<SearchIcon />}
                            title="Submission Status Lookup"
                            method="GET"
                            path="/api/v1/submissions/{submission_uuid}"
                        >
                            <p className="text-xs text-slate-500 leading-relaxed mb-4 max-w-4xl">
                                Returns the current intake queue and processing log state for one submission UUID. Access requires a staging bearer token containing both
                                <code className="mx-1 px-1 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[10px]">transaction:read</code>
                                and
                                <code className="mx-1 px-1 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[10px]">provider:testing</code>
                                capabilities.
                            </p>
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">Request curl snippet</span>
                                    <CodeBlock value={curlStatusLookup} />
                                </div>
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">Successful JSON response</span>
                                    <CodeBlock value={statusResponse} />
                                </div>
                            </div>
                        </EndpointSection>

                        {/* Errors Card Section */}
                        <div id="errors" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
                            <div className="pb-4 border-b border-[#c6c6cd]">
                                <h3 className="text-base font-bold text-[#0b1c30]">Staging Error reference</h3>
                                <p className="text-xs text-slate-400 mt-0.5">Distinguish validation, authorization, and formatting errors during sandbox runs</p>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                {/* 403 Forbidden - Missing Ability */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-mono">403 FORBIDDEN</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Missing Sanctum Ability</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when the bearer token is valid but lacks the required capability for the endpoint.</p>
                                    <CodeBlock value={forbiddenResponse} />
                                </div>

                                {/* 403 Forbidden - Terminal Identity Mismatch */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-mono">403 FORBIDDEN</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Terminal Mismatch</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when the payload terminal_id does not match the authenticated terminal identity.</p>
                                    <CodeBlock value={terminalTokenMismatchResponse} />
                                </div>

                                {/* 403 Forbidden - Hardware ID Mismatch */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-mono">403 FORBIDDEN</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Hardware Mismatch</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when transaction.hardware_id differs from the authenticated terminal serial_number.</p>
                                    <CodeBlock value={hardwareIdMismatchResponse} />
                                </div>

                                {/* 404 Not Found - Submission Not Found */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 font-mono">404 NOT FOUND</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Submission Lookup</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when a submission UUID is not found or falls outside the token's scope boundaries.</p>
                                    <CodeBlock value={notFoundResponse} />
                                </div>

                                {/* 409 Conflict - Submission UUID Conflict */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-orange-50 text-orange-700 border border-orange-200 font-mono">409 CONFLICT</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">UUID Checksum Conflict</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when an existing submission UUID is resent with a different cryptographic checksum.</p>
                                    <CodeBlock value={submissionUuidConflictResponse} />
                                </div>

                                {/* 422 Unprocessable - Invalid Submission UUID */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 font-mono">422 UNPROCESSABLE</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Malformed UUID Format</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when the submission UUID passed in the path parameter is not in a valid UUID format.</p>
                                    <CodeBlock value={invalidUuidResponse} />
                                </div>

                                {/* 422 Unprocessable - Structural Validation Failure */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 font-mono">422 UNPROCESSABLE</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Structural Payload Failure</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when the payload fails basic schema validation (missing fields, wrong types, etc.).</p>
                                    <CodeBlock value={structuralValidationFailureResponse} />
                                </div>

                                {/* 422 Unprocessable - Cryptographic Integrity Failure */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 font-mono">422 UNPROCESSABLE</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Cryptographic Integrity Check</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when computed checksums do not match the values supplied inside the payload.</p>
                                    <CodeBlock value={cryptographicIntegrityFailureResponse} />
                                </div>

                                {/* 429 Too Many Requests - Rate Limited */}
                                <div className="p-4 border border-[#c6c6cd]/80 rounded-xl bg-slate-50/50 space-y-3">
                                    <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 font-mono">429 TOO MANY</span>
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Terminal Rate Limit</span>
                                    </div>
                                    <p className="text-[11px] text-slate-500">Returned when a terminal exceeds the current request window. Retry only after the Retry-After header duration.</p>
                                    <CodeBlock value={rateLimitResponse} />
                                </div>
                            </div>
                            <div className="p-3 bg-slate-50 border border-[#c6c6cd]/50 rounded-xl text-[11px] text-slate-500 leading-normal">
                                A standard <strong>401 Unauthorized</strong> response is returned if the Authorization header is missing, expired, or references a revoked staging key.
                            </div>
                        </div>

                        {/* Rate Limits Section */}
                        <EndpointSection
                            id="rate-limits"
                            icon={<ShieldOutlinedIcon />}
                            title="Production POS Rate Limits"
                            method="429"
                            path="Retry-After / X-RateLimit-*"
                        >
                            <p className="text-xs text-slate-500 leading-relaxed mb-4 max-w-4xl">
                                POS ingestion limits are evaluated per authenticated terminal and tenant, not by shared public IP. If TSMS returns
                                <code className="mx-1 px-1 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[10px]">429 Too Many Requests</code>
                                retry only after the
                                <code className="mx-1 px-1 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[10px]">Retry-After</code>
                                duration. For the same payload, keep the same submission_uuid so idempotency remains intact.
                            </p>
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">429 response body</span>
                                    <CodeBlock value={rateLimitResponse} />
                                </div>
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">Expected headers</span>
                                    <CodeBlock value={`Retry-After: 42
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1781348292`} />
                                </div>
                            </div>
                        </EndpointSection>

                        {/* Monitoring Card Section */}
                        <EndpointSection
                            id="monitoring"
                            icon={<TerminalIcon />}
                            title="Tenant And Terminal Activity Monitoring"
                            method="GET"
                            path="/api/v1/monitoring/tenants/activity"
                        >
                            <p className="text-xs text-slate-500 leading-relaxed mb-4 max-w-4xl">
                                Returns daily activity counters for continuous sending validations. Scoped to the authenticated token and requires
                                <code className="mx-1 px-1 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[10px]">transaction:read</code>.
                            </p>
                            <CodeBlock value={curlTenantActivity} />
                            <div className="flex gap-2 flex-wrap mt-3">
                                <span className="text-[10px] font-mono text-slate-500 border border-[#c6c6cd] bg-slate-50 px-2 py-0.5 rounded">/api/v1/monitoring/tenants/activity</span>
                                <span className="text-[10px] font-mono text-slate-500 border border-[#c6c6cd] bg-slate-50 px-2 py-0.5 rounded">/api/v1/monitoring/terminals/activity</span>
                            </div>
                        </EndpointSection>

                        {/* Payload Sandbox Section */}
                        <EndpointSection
                            id="sandbox"
                            icon={<FactCheckIcon />}
                            title="Payload Sandbox Validation"
                            method="POST"
                            path="/api/v1/sandbox/payload/validate"
                        >
                            <p className="text-xs text-slate-500 leading-relaxed mb-4 max-w-4xl">
                                Validates payload structure, required JSON properties, syntax errors, and business rules without persisting transactions or queueing intake jobs.
                            </p>
                            <CodeBlock value={curlSandbox} />
                            <div className="pt-2">
                                <a
                                    href="/sandbox/payload"
                                    className="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider px-6 py-3 rounded-xl transition-colors shadow-sm"
                                >
                                    <FactCheckIcon style={{ fontSize: 16 }} />
                                    Launch Payload Sandbox Page
                                </a>
                            </div>
                        </EndpointSection>

                        {/* Resource Files Section (Downloads) */}
                        <div id="downloads" className="pt-8 border-t border-[#c6c6cd]/60 grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="space-y-2">
                                <h4 className="text-sm font-bold text-[#0b1c30]">Integration support</h4>
                                <p className="text-xs text-slate-400 leading-relaxed">
                                    Having issues with your staging credentials, endpoint scope errors, or seeing invalid checksum calculations? Contact the TSMS helpdesk.
                                </p>
                                <a href="#" className="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:underline pt-1">
                                    Open Support request
                                    <span className="text-sm">→</span>
                                </a>
                            </div>
                            <div className="space-y-2">
                                <h4 className="text-sm font-bold text-[#0b1c30]">Documentation Status</h4>
                                <div className="space-y-1.5">
                                    <div className="flex items-center gap-2 text-xs text-slate-500">
                                        <span className="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                                        <span>Reference spec: Oct 2024</span>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs text-slate-500">
                                        <span className="w-2 h-2 bg-green-500 rounded-full"></span>
                                        <span>Sandbox Uptime: 99.9%</span>
                                    </div>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <h4 className="text-sm font-bold text-[#0b1c30]">Security compliance</h4>
                                <p className="text-[11px] text-slate-400 leading-relaxed">
                                    All testing communications are encrypted using TLS 1.3 protocol. Sandbox logs are automatically cleared after 30 days.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Global footer */}
                    <footer className="w-full px-6 py-8 mt-12 bg-[#eff4ff] border-t border-[#c6c6cd] shrink-0">
                        <div className="max-w-6xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
                            <div>
                                <span className="font-bold text-sm text-[#0b1c30]">POS Provider API reference</span>
                                <p className="text-[11px] text-slate-400 mt-0.5">© 2026 POS Provider API Testing Docs</p>
                            </div>
                            <div className="flex gap-4 text-xs font-semibold text-slate-500">
                                <a href="#" className="hover:underline">Privacy Policy</a>
                                <a href="#" className="hover:underline">Terms of Service</a>
                                <a href="#" className="hover:underline">Contact Support</a>
                            </div>
                        </div>
                    </footer>
                </main>
            </div>
        </div>
    );
};

export default ProviderApiDocsPage;
