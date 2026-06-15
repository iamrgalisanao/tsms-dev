import React, { useEffect, useMemo, useState } from 'react';
import ArticleIcon from '@mui/icons-material/Article';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import InfoIcon from '@mui/icons-material/Info';
import SearchIcon from '@mui/icons-material/Search';

// Import newly refactored modular components
import SectionHeader from '../Components/ApiDocs/SectionHeader';
import StickyDocsNav from '../Components/ApiDocs/StickyDocsNav';
import CodeBlock from '../Components/ApiDocs/CodeBlock';
import AlertBanner from '../Components/ApiDocs/AlertBanner';
import SchemaExplorer from '../Components/ApiDocs/SchemaExplorer';
import OpenApiViewer from '../Components/ApiDocs/OpenApiViewer';
import ProviderReadinessDashboard from '../Components/ApiDocs/ProviderReadinessDashboard';
import QuickStartTabs from '../Components/ApiDocs/QuickStartTabs';

const openApiSpecUrl = '/docs/pos-provider/openapi.yaml';

// Constants for various code blocks and responses
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

const ProviderApiDocsPage = () => {
    // Dynamic completion state based on interactions
    const [completedSteps, setCompletedSteps] = useState({
        credentials: true, // Defaulting first two checks to true as in original mock screenshot
        schema: true,
        payload: false,
        workflow: false,
        approval: false
    });

    const [activeSection, setActiveSection] = useState('access');
    const [specText, setSpecText] = useState('');
    const [loadState, setLoadState] = useState('loading');
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    useEffect(() => {
        // Add font stylesheet to head dynamically
        const link = document.createElement('link');
        link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap';
        link.rel = 'stylesheet';
        document.head.appendChild(link);

        // Fetch OpenAPI spec YAML file
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
            document.head.removeChild(link);
            cancelled = true;
        };
    }, []);

    const parsed = useMemo(() => specText ? parseOpenApiSpec(specText) : { info: {}, servers: [], endpoints: [], schemas: {} }, [specText]);

    // Scroll Spy & Intersection Observer
    useEffect(() => {
        const sections = [
            'access',
            'payload-guidelines',
            'openapi',
            'status',
            'errors',
            'rate-limits',
            'monitoring',
            'sandbox',
            'downloads'
        ];

        const observerOptions = {
            root: null,
            rootMargin: '-20% 0px -60% 0px',
            threshold: 0
        };

        const observerCallback = (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    setActiveSection(entry.target.id);
                }
            });
        };

        const observer = new IntersectionObserver(observerCallback, observerOptions);

        sections.forEach((id) => {
            const el = document.getElementById(id);
            if (el) observer.observe(el);
        });

        // Fallback for top scroll edge case
        const handleScroll = () => {
            if (window.scrollY === 0) {
                setActiveSection('access');
            }
        };

        window.addEventListener('scroll', handleScroll, { passive: true });

        return () => {
            sections.forEach((id) => {
                const el = document.getElementById(id);
                if (el) observer.unobserve(el);
            });
            window.removeEventListener('scroll', handleScroll);
        };
    }, []);

    const scrollToSection = (id) => {
        const element = document.getElementById(id);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setActiveSection(id);
            setMobileMenuOpen(false);

            // Automatically check off relevant items when user jumps to/scrolls to sections
            if (id === 'sandbox') {
                setCompletedSteps(prev => ({ ...prev, workflow: true }));
            }
            if (id === 'rate-limits' || id === 'downloads') {
                setCompletedSteps(prev => ({ ...prev, approval: true }));
            }
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
                    {/* Hamburger menu for mobile viewports */}
                    <button
                        onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                        className="md:hidden p-1.5 rounded-lg hover:bg-slate-100 text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                        aria-label="Toggle navigation menu"
                        aria-expanded={mobileMenuOpen}
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            {mobileMenuOpen ? (
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            ) : (
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                            )}
                        </svg>
                    </button>
                    
                    <div className="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-sm shrink-0" aria-hidden="true">
                        <ArticleIcon style={{ fontSize: 20 }} />
                    </div>
                    <div>
                        <span className="font-bold text-base text-[#0b1c30] leading-none block">POS Provider API Docs</span>
                        <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider block sm:inline">TSMS Staging Sandbox Reference</span>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span className="hidden sm:inline-flex items-center gap-1 bg-[#dcfce7] text-[#15803d] text-[10px] font-black uppercase px-2 py-1 border border-[#86efac]/40 rounded-full select-none">
                        <ShieldOutlinedIcon style={{ fontSize: 12 }} />
                        Testing Environment
                    </span>
                    <span className="hidden sm:inline-flex items-center gap-1 bg-[#dbeafe] text-[#1d4ed8] text-[10px] font-black uppercase px-2 py-1 border border-[#93c5fd]/40 rounded-full select-none">
                        <LockOutlinedIcon style={{ fontSize: 12 }} />
                        Token-Gated
                    </span>
                </div>
            </header>

            {/* Mobile Drawer Overlay */}
            {mobileMenuOpen && (
                <div className="md:hidden fixed inset-0 z-40 bg-black/40 backdrop-blur-sm transition-all" onClick={() => setMobileMenuOpen(false)}>
                    <div className="w-64 h-full bg-[#eff4ff] shadow-xl border-r border-[#c6c6cd] pt-16 flex flex-col" onClick={(e) => e.stopPropagation()}>
                        <div className="p-4 flex-1 overflow-y-auto">
                            <StickyDocsNav
                                activeSection={activeSection}
                                scrollToSection={scrollToSection}
                                completedSteps={completedSteps}
                                resourceLinks={resourceLinks}
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* Layout Shell */}
            <div className="flex-1 flex overflow-hidden">
                {/* Left Desktop navigation sidebar */}
                <StickyDocsNav
                    activeSection={activeSection}
                    scrollToSection={scrollToSection}
                    completedSteps={completedSteps}
                    resourceLinks={resourceLinks}
                />

                {/* Scrollable Center Content Area */}
                <main className="flex-1 overflow-y-auto bg-[#f8f9ff]">
                    <div className="max-w-6xl mx-auto px-6 py-8 space-y-8">
                        {/* Page Header Intro */}
                        <div className="pb-6 border-b border-[#c6c6cd]/60 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
                            <div>
                                <div className="flex items-center gap-2 text-slate-400 text-xs font-bold uppercase tracking-widest mb-1 select-none">
                                    <FactCheckIcon style={{ fontSize: 15 }} className="text-blue-600" />
                                    <span>Developer Testing Gateway</span>
                                </div>
                                <h1 className="text-3xl font-black tracking-tight text-[#0b1c30]">Provider Testing Access Hub</h1>
                                <p className="text-sm text-slate-500 mt-2 max-w-3xl leading-relaxed">
                                    Securely validate your POS transaction ingestion integration, verify custom payload hashes, and check current pipeline processing status codes inside our sandbox system.
                                </p>
                            </div>
                        </div>

                        {/* Top Onboarding metrics checklist card */}
                        <ProviderReadinessDashboard
                            completedSteps={completedSteps}
                            scrollToSection={scrollToSection}
                        />

                        {/* Quick Start timeline vs Deep Dive tabs switcher */}
                        <QuickStartTabs scrollToSection={scrollToSection} />

                        {/* ============================================================== */}
                        {/* SECTION 1: CONFIGURE PROVIDER */}
                        {/* ============================================================== */}
                        <section className="space-y-6">
                            <SectionHeader
                                number="01"
                                title="Configure Provider"
                                description="Configure test endpoints, authorization credentials, and sandbox boundaries."
                            />

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6" id="access">
                                {/* Card 1 */}
                                <div className="p-6 bg-white border border-[#c6c6cd] rounded-2xl flex flex-col justify-between hover:border-blue-600/40 transition-colors shadow-sm">
                                    <div className="space-y-4">
                                        <div className="flex justify-between items-start">
                                            <div className="p-2.5 bg-slate-100 rounded-lg text-slate-600" aria-hidden="true">
                                                <FactCheckIcon />
                                            </div>
                                            <span className="text-[9px] font-black tracking-wider uppercase bg-[#dcfce7] text-[#15803d] px-2 py-0.5 rounded-full border border-[#86efac]/40 select-none">
                                                Open Validation
                                            </span>
                                        </div>
                                        <div>
                                            <h3 className="text-base font-bold text-[#0b1c30]">Payload Validation Sandbox</h3>
                                            <p className="text-xs text-slate-400 mt-1 leading-relaxed">
                                                Validate transaction payload syntax, structure requirements, and calculated checksums without side-effects or DB persistence.
                                            </p>
                                        </div>
                                        <ul className="space-y-2 pt-2 border-t border-slate-100 text-xs text-slate-500">
                                            <li className="flex items-center gap-2">
                                                <CheckCircleIcon className="text-green-500" style={{ fontSize: 16 }} />
                                                <span>No bearer auth token required</span>
                                            </li>
                                            <li className="flex items-center gap-2">
                                                <CheckCircleIcon className="text-green-500" style={{ fontSize: 16 }} />
                                                <span>Validates schemas &amp; SHA-256 hashes</span>
                                            </li>
                                        </ul>
                                    </div>
                                    <button
                                        onClick={() => {
                                            scrollToSection('sandbox');
                                            setCompletedSteps(prev => ({ ...prev, payload: true }));
                                        }}
                                        className="w-full mt-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider rounded-lg transition-colors shadow-sm focus:ring-2 focus:ring-blue-500/20 active:scale-[0.98]"
                                    >
                                        Go To Sandbox
                                    </button>
                                </div>

                                {/* Card 2 */}
                                <div className="p-6 bg-white border border-[#c6c6cd] rounded-2xl flex flex-col justify-between hover:border-blue-600/40 transition-colors shadow-sm">
                                    <div className="space-y-4">
                                        <div className="flex justify-between items-start">
                                            <div className="p-2.5 bg-blue-50 rounded-lg text-blue-600" aria-hidden="true">
                                                <SearchIcon />
                                            </div>
                                            <span className="text-[9px] font-black tracking-wider uppercase bg-[#dbeafe] text-[#1d4ed8] px-2 py-0.5 rounded-full border border-[#93c5fd]/40 select-none">
                                                Token Authorized
                                            </span>
                                        </div>
                                        <div>
                                            <h3 className="text-base font-bold text-[#0b1c30]">Submission Status Lookup</h3>
                                            <p className="text-xs text-slate-400 mt-1 leading-relaxed">
                                                Look up queuing status, intake errors, and processing logs for specific transactional UUIDs using testing credentials.
                                            </p>
                                        </div>
                                        <ul className="space-y-2 pt-2 border-t border-slate-100 text-xs text-slate-500">
                                            <li className="flex items-center gap-2">
                                                <CheckCircleIcon className="text-blue-500" style={{ fontSize: 16 }} />
                                                <span>Requires staging bearer credential</span>
                                            </li>
                                            <li className="flex items-center gap-2">
                                                <CheckCircleIcon className="text-blue-500" style={{ fontSize: 16 }} />
                                                <span>Scoped to tenant and terminal boundaries</span>
                                            </li>
                                        </ul>
                                    </div>
                                    <button
                                        onClick={() => {
                                            scrollToSection('status');
                                            setCompletedSteps(prev => ({ ...prev, payload: true }));
                                        }}
                                        className="w-full mt-6 py-2.5 bg-white hover:bg-slate-50 border border-[#c6c6cd] text-slate-800 font-bold text-xs uppercase tracking-wider rounded-lg transition-colors active:scale-[0.98]"
                                    >
                                        Review Status API
                                    </button>
                                </div>
                            </div>

                            {/* Warning Banner */}
                            <AlertBanner
                                type="warning"
                                title="Critical Security Notice"
                                message="Do not use production ingestion tokens for provider testing. Testing tokens should be issued only for staging/debugging and should not include transaction submission abilities unless explicitly approved by the platform manager."
                            />
                        </section>

                        {/* ============================================================== */}
                        {/* SECTION 2: VALIDATE DATA */}
                        {/* ============================================================== */}
                        <section className="space-y-6">
                            <SectionHeader
                                number="02"
                                title="Validate Data"
                                description="Verify JSON schemas, required structural constraints, and checksum integrity algorithms."
                            />

                            {/* Guidelines Card wrapper */}
                            <div id="payload-guidelines" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-6">
                                <div className="border-b border-[#c6c6cd]/40 pb-4">
                                    <h3 className="text-base font-bold text-[#0b1c30]">Official Payload Guidelines</h3>
                                    <p className="text-xs text-slate-400 mt-0.5">Ingestion schema reference details &amp; rules</p>
                                </div>

                                <p className="text-xs text-slate-500 leading-relaxed max-w-4xl">
                                    TSMS currently accepts the official submission envelope only when the authenticated terminal, declared terminal_id,
                                    transaction hardware_id, payload structure, and cryptographic checksums all align.
                                </p>

                                {/* Compressed SchemaExplorer replacing large static tables */}
                                <div className="space-y-2">
                                    <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">
                                        Interactive Schema Reference
                                    </span>
                                    <SchemaExplorer />
                                </div>

                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <div className="p-4 border border-[#c6c6cd]/50 rounded-xl bg-slate-50/50">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-3 select-none">Structure Requirements</span>
                                        <ul className="space-y-2 text-[11px] text-slate-500 leading-relaxed">
                                            <li><strong>Single transaction:</strong> set <code>transaction_count</code> to <code>1</code> and provide <code>transaction</code>.</li>
                                            <li><strong>Batch:</strong> set <code>transaction_count</code> to the exact number of records and provide <code>transactions</code>.</li>
                                            <li><strong>Timestamps:</strong> use UTC <code>YYYY-MM-DDTHH:mm:ssZ</code>; fractional seconds are rejected.</li>
                                            <li><strong>Receipt number:</strong> letters, numbers, dash, and dot only; maximum 128 characters.</li>
                                        </ul>
                                    </div>

                                    <div className="p-4 border border-[#c6c6cd]/50 rounded-xl bg-slate-50/50">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-3 select-none">Security and validation</span>
                                        <ul className="space-y-2 text-[11px] text-slate-500 leading-relaxed">
                                            <li>The bearer token must belong to the submitted <code>terminal_id</code>.</li>
                                            <li>The terminal must be active and must belong to submitted <code>tenant_id</code>.</li>
                                            <li>Each <code>transaction.hardware_id</code> must match the authenticated terminal <code>serial_number</code>.</li>
                                        </ul>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5 select-none">Minimal payload template</span>
                                        <CodeBlock value={officialPayloadGuidelineExample} language="json" filename="payload.json" />
                                    </div>
                                    <div className="space-y-2">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5 select-none">Checksum calculation order</span>
                                        <CodeBlock value={checksumGuidelineSnippet} language="bash" filename="checksum_logic.txt" />
                                        <div className="p-3 bg-blue-50 border border-blue-100 rounded-xl text-[11px] text-blue-900/80 leading-normal">
                                            Official ingestion stores submitted financial values after validation. It does not recompute or enforce a gross-to-net
                                            formula, so EOD totals should be represented directly.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* ============================================================== */}
                        {/* SECTION 3: TEST WORKFLOW */}
                        {/* ============================================================== */}
                        <section className="space-y-6">
                            <SectionHeader
                                number="03"
                                title="Test Workflow"
                                description="Run API calls in explorer, verify status receipts, and trigger validation scripts."
                            />

                            {/* Split pane OpenApi reference explorer */}
                            <OpenApiViewer 
                                parsedSpec={parsed} 
                                loadState={loadState} 
                                rawSpecText={specText} 
                            />

                            {/* Status Lookup */}
                            <div id="status" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#c6c6cd]">
                                    <div className="flex items-start gap-3">
                                        <div className="text-blue-600 mt-0.5 shrink-0 bg-blue-50 p-2 rounded-lg" aria-hidden="true"><SearchIcon /></div>
                                        <div>
                                            <h3 className="text-base font-bold text-[#0b1c30]">Submission Status Lookup</h3>
                                            <p className="text-xs text-slate-400 mt-0.5">Queuing state &amp; processing logs</p>
                                        </div>
                                    </div>
                                    <span className="font-mono text-xs font-bold px-2 py-1 bg-slate-50 border border-[#c6c6cd] rounded shrink-0">
                                        GET /api/v1/submissions/&#123;submission_uuid&#125;
                                    </span>
                                </div>

                                <p className="text-xs text-slate-500 leading-relaxed max-w-4xl">
                                    Returns the current intake queue and processing log state for one submission UUID. Access requires a staging bearer token containing both
                                    <code>transaction:read</code> and <code>provider:testing</code> capabilities.
                                </p>
                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5 select-none">Request curl snippet</span>
                                        <CodeBlock value={curlStatusLookup} language="shell" filename="curl_status.sh" />
                                    </div>
                                    <div className="space-y-2">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5 select-none">Successful response payload</span>
                                        <CodeBlock value={statusResponse} language="json" filename="status_success.json" />
                                    </div>
                                </div>
                            </div>

                            {/* Tenant and Terminal activity monitoring */}
                            <div id="monitoring" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#c6c6cd]">
                                    <div className="flex items-start gap-3">
                                        <div className="text-blue-600 mt-0.5 shrink-0 bg-blue-50 p-2 rounded-lg" aria-hidden="true"><ArticleIcon /></div>
                                        <div>
                                            <h3 className="text-base font-bold text-[#0b1c30]">Tenant and Terminal Activity Monitoring</h3>
                                            <p className="text-xs text-slate-400 mt-0.5">Daily activity counters</p>
                                        </div>
                                    </div>
                                    <span className="font-mono text-xs font-bold px-2 py-1 bg-slate-50 border border-[#c6c6cd] rounded shrink-0">
                                        GET /api/v1/monitoring/tenants/activity
                                    </span>
                                </div>

                                <p className="text-xs text-slate-500 leading-relaxed max-w-4xl">
                                    Returns daily activity counters for continuous sending validations. Scoped to the authenticated token and requires
                                    <code>transaction:read</code>.
                                </p>
                                <CodeBlock value={curlTenantActivity} language="shell" filename="monitoring_curl.sh" />
                            </div>

                            {/* Payload sandbox validator */}
                            <div id="sandbox" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#c6c6cd]">
                                    <div className="flex items-start gap-3">
                                        <div className="text-blue-600 mt-0.5 shrink-0 bg-blue-50 p-2 rounded-lg" aria-hidden="true"><FactCheckIcon /></div>
                                        <div>
                                            <h3 className="text-base font-bold text-[#0b1c30]">Payload Sandbox Validation</h3>
                                            <p className="text-xs text-slate-400 mt-0.5">Validate checksums &amp; schema integrity</p>
                                        </div>
                                    </div>
                                    <span className="font-mono text-xs font-bold px-2 py-1 bg-slate-50 border border-[#c6c6cd] rounded shrink-0">
                                        POST /api/v1/sandbox/payload/validate
                                    </span>
                                </div>

                                <p className="text-xs text-slate-500 leading-relaxed max-w-4xl">
                                    Validates payload structure, required JSON properties, syntax errors, and business rules without persisting transactions or queueing intake jobs.
                                </p>
                                <CodeBlock value={curlSandbox} language="shell" filename="curl_sandbox.sh" />

                                <div className="pt-2">
                                    <a
                                        href="/sandbox/payload"
                                        className="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider px-6 py-3 rounded-xl transition-all shadow-sm hover:scale-[1.02] active:scale-[0.98]"
                                    >
                                        <FactCheckIcon style={{ fontSize: 16 }} />
                                        Launch Sandbox Interface
                                    </a>
                                </div>
                            </div>
                        </section>

                        {/* ============================================================== */}
                        {/* SECTION 4: GO LIVE */}
                        {/* ============================================================== */}
                        <section className="space-y-6">
                            <SectionHeader
                                number="04"
                                title="Go Live"
                                description="Understand rate limits, error catalogues, and prepare for production access."
                            />

                            {/* Errors Reference Card */}
                            <div id="errors" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
                                <div className="pb-4 border-b border-[#c6c6cd]">
                                    <h3 className="text-base font-bold text-[#0b1c30]">Staging Error Reference</h3>
                                    <p className="text-xs text-slate-400 mt-0.5">Distinguish validation, authorization, and formatting errors during sandbox runs</p>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                    {/* 403 Forbidden - Missing Ability */}
                                    <div className="p-4 border border-[#c6c6cd]/60 rounded-xl bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-mono select-none">403 FORBIDDEN</span>
                                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none">Ability Check</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 min-h-[34px]">Returned when the bearer token is valid but lacks the required capability.</p>
                                        <CodeBlock value={forbiddenResponse} language="json" filename="403_ability.json" />
                                    </div>

                                    {/* 403 Forbidden - Terminal Identity Mismatch */}
                                    <div className="p-4 border border-[#c6c6cd]/60 rounded-xl bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-mono select-none">403 FORBIDDEN</span>
                                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none">Terminal Mismatch</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 min-h-[34px]">Returned when the payload terminal_id does not match the authenticated terminal identity.</p>
                                        <CodeBlock value={terminalTokenMismatchResponse} language="json" filename="403_terminal.json" />
                                    </div>

                                    {/* 403 Forbidden - Hardware ID Mismatch */}
                                    <div className="p-4 border border-[#c6c6cd]/60 rounded-xl bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-mono select-none">403 FORBIDDEN</span>
                                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none">Hardware Mismatch</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 min-h-[34px]">Returned when transaction.hardware_id differs from authenticated terminal serial number.</p>
                                        <CodeBlock value={hardwareIdMismatchResponse} language="json" filename="403_hardware.json" />
                                    </div>

                                    {/* 404 Not Found - Submission Not Found */}
                                    <div className="p-4 border border-[#c6c6cd]/60 rounded-xl bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 font-mono select-none">404 NOT FOUND</span>
                                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none">Lookup Fail</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 min-h-[34px]">Returned when a submission UUID is not found or falls outside boundaries.</p>
                                        <CodeBlock value={notFoundResponse} language="json" filename="404_not_found.json" />
                                    </div>

                                    {/* 409 Conflict - Submission UUID Conflict */}
                                    <div className="p-4 border border-[#c6c6cd]/60 rounded-xl bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded bg-orange-50 text-orange-700 border border-orange-200 font-mono select-none">409 CONFLICT</span>
                                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none">Checksum Drift</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 min-h-[34px]">Returned when an existing UUID is resent with a different checksum.</p>
                                        <CodeBlock value={submissionUuidConflictResponse} language="json" filename="409_conflict.json" />
                                    </div>

                                    {/* 422 Unprocessable - Invalid Submission UUID */}
                                    <div className="p-4 border border-[#c6c6cd]/60 rounded-xl bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between gap-2 border-b border-[#c6c6cd]/50 pb-2">
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 font-mono select-none">422 UNPROCESSABLE</span>
                                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none">Malformed UUID</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 min-h-[34px]">Returned when the submission UUID passed is not in a valid format.</p>
                                        <CodeBlock value={invalidUuidResponse} language="json" filename="422_invalid_uuid.json" />
                                    </div>
                                </div>
                            </div>

                            {/* Rate Limits */}
                            <div id="rate-limits" className="p-6 border border-[#c6c6cd] rounded-2xl bg-white shadow-sm space-y-4">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#c6c6cd]">
                                    <div className="flex items-start gap-3">
                                        <div className="text-blue-600 mt-0.5 shrink-0 bg-blue-50 p-2 rounded-lg" aria-hidden="true"><ShieldOutlinedIcon /></div>
                                        <div>
                                            <h3 className="text-base font-bold text-[#0b1c30]">Production POS Rate Limits</h3>
                                            <p className="text-xs text-slate-400 mt-0.5">Retry-After / X-RateLimit-* headers</p>
                                        </div>
                                    </div>
                                    <span className="font-mono text-xs font-bold px-2 py-1 bg-slate-50 border border-[#c6c6cd] rounded shrink-0">
                                        HTTP 429 Status
                                    </span>
                                </div>

                                <p className="text-xs text-slate-500 leading-relaxed max-w-4xl">
                                    POS ingestion limits are evaluated per authenticated terminal and tenant, not by shared public IP. If TSMS returns
                                    <code>429 Too Many Requests</code> retry only after the <code>Retry-After</code> duration.
                                </p>
                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5 select-none">429 response body</span>
                                        <CodeBlock value={rateLimitResponse} language="json" filename="429_limit.json" />
                                    </div>
                                    <div className="space-y-2">
                                        <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5 select-none">Expected headers</span>
                                        <CodeBlock value={`Retry-After: 42
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1781348292`} language="shell" filename="429_headers.txt" />
                                    </div>
                                </div>
                            </div>

                            {/* Resource downloads / Support */}
                            <div id="downloads" className="pt-8 border-t border-[#c6c6cd]/60 grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div className="space-y-2">
                                    <h4 className="text-sm font-bold text-[#0b1c30]">Integration Support</h4>
                                    <p className="text-xs text-slate-400 leading-relaxed">
                                        Having issues with staging credentials, endpoint scope errors, or seeing invalid checksum calculations? Contact the TSMS helpdesk.
                                    </p>
                                    <a href="#" className="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:underline pt-1">
                                        Open Support Request
                                        <span className="text-sm" aria-hidden="true">&rarr;</span>
                                    </a>
                                </div>
                                <div className="space-y-2">
                                    <h4 className="text-sm font-bold text-[#0b1c30]">Documentation Status</h4>
                                    <div className="space-y-1.5">
                                        <div className="flex items-center gap-2 text-xs text-slate-500">
                                            <span className="w-2 h-2 bg-green-500 rounded-full animate-pulse" aria-hidden="true"></span>
                                            <span>Reference Spec: Oct 2024</span>
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-slate-500">
                                            <span className="w-2 h-2 bg-green-500 rounded-full" aria-hidden="true"></span>
                                            <span>Sandbox Uptime: 99.9%</span>
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <h4 className="text-sm font-bold text-[#0b1c30]">Security Compliance</h4>
                                    <p className="text-[11px] text-slate-400 leading-relaxed">
                                        All testing communications are encrypted using TLS 1.3 protocol. Sandbox logs are automatically cleared after 30 days.
                                    </p>
                                </div>
                            </div>
                        </section>
                    </div>

                    {/* Global footer */}
                    <footer className="w-full px-6 py-8 mt-12 bg-[#eff4ff] border-t border-[#c6c6cd] shrink-0">
                        <div className="max-w-6xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
                            <div>
                                <span className="font-bold text-sm text-[#0b1c30]">POS Provider API Reference</span>
                                <p className="text-[11px] text-slate-400 mt-0.5">&copy; 2026 POS Provider API Testing Docs</p>
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
