import React, { useState, useMemo, useEffect } from 'react';
import SearchIcon from '@mui/icons-material/Search';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import ArticleIcon from '@mui/icons-material/Article';
import TerminalIcon from '@mui/icons-material/Terminal';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import CodeBlock from './CodeBlock';
import MethodBadge from './MethodBadge';

// Helper badge component for methods (placed inline or imported, inline here is cleaner for imports)
const endpointKey = (endpoint) => `${endpoint.method}:${endpoint.path}`;

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

// Mock Response Payloads mapping
const MOCK_RESPONSES = {
    200: {
        description: 'Successful transaction metadata retrieve or action completion.',
        payload: `{
  "success": true,
  "data": {
    "submission_uuid": "84f65461-8de1-41d1-b0ec-428a31c9340e",
    "intake_id": 1113191,
    "provider_status": "processed",
    "intake_status": "QUEUED",
    "processing_status": "PROCESSED",
    "last_error_code": null,
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
}`
    },
    403: {
        description: 'Sanctum capability check failed or terminal/hardware mismatch.',
        payload: `{
  "success": false,
  "message": "Terminal Identity Mismatch: The terminal_id provided in the payload does not match the identity of the authenticated API token. Token sharing across multiple terminals is strictly prohibited.",
  "error_code": "TERMINAL_TOKEN_MISMATCH"
}`
    },
    404: {
        description: 'Submission record lookup missing or outside scoped tenancy.',
        payload: `{
  "success": false,
  "message": "Submission not found.",
  "error_code": "SUBMISSION_NOT_FOUND"
}`
    },
    409: {
        description: 'Conflict state (UUID re-submitted with differing checksum).',
        payload: `{
  "success": false,
  "message": "Submission UUID already exists with a different payload_checksum. Correct the payload and resend with a new submission_uuid.",
  "error_code": "SUBMISSION_UUID_CONFLICT",
  "data": {
    "submission_uuid": "84f65461-8de1-41d1-b0ec-428a31c9340e",
    "intake_id": 1113191,
    "provider_status": "processed",
    "received_at": "2026-05-26T05:48:33.000000Z"
  }
}`
    },
    422: {
        description: 'Structural parameter error or cryptographic integrity hash mismatch.',
        payload: `{
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
}`
    },
    429: {
        description: 'Terminal client is rate limited.',
        payload: `{
  "success": false,
  "message": "Too many requests.",
  "retry_after": 42
}`
    }
};

const OpenApiViewer = ({ parsedSpec, loadState, rawSpecText }) => {
    const [query, setQuery] = useState('');
    const [selectedTag, setSelectedTag] = useState('All');
    const [selectedEndpointKey, setSelectedEndpointKey] = useState('');
    const [activeTab, setActiveTab] = useState('snippet'); // 'snippet' or 'response'
    const [codeLanguage, setCodeLanguage] = useState('curl');
    const [responseCode, setResponseCode] = useState(200);
    const [consoleValues, setConsoleValues] = useState({
        provider_testing_token: '',
        submission_uuid: '',
        threshold_minutes: '1440'
    });

    const tags = useMemo(() => {
        if (!parsedSpec?.endpoints) return ['All'];
        return ['All', ...new Set(parsedSpec.endpoints.flatMap((endpoint) => endpoint.tags))];
    }, [parsedSpec?.endpoints]);

    const filteredEndpoints = useMemo(() => {
        if (!parsedSpec?.endpoints) return [];
        const search = query.trim().toLowerCase();

        return parsedSpec.endpoints.filter((endpoint) => {
            const matchesTag = selectedTag === 'All' || endpoint.tags.includes(selectedTag);
            const searchable = `${endpoint.method} ${endpoint.path} ${endpoint.summary} ${endpoint.description} ${endpoint.tags.join(' ')}`.toLowerCase();
            const matchesSearch = !search || searchable.includes(search);

            return matchesTag && matchesSearch;
        });
    }, [parsedSpec?.endpoints, query, selectedTag]);

    const selectedEndpoint = useMemo(() => {
        if (!filteredEndpoints.length) return null;
        return filteredEndpoints.find((item) => endpointKey(item) === selectedEndpointKey)
            || filteredEndpoints[0];
    }, [filteredEndpoints, selectedEndpointKey]);

    const stagingServer = useMemo(() => {
        if (!parsedSpec?.servers) return 'https://stagingtsms.pitx.com.ph/api/v1';
        return parsedSpec.servers.find((server) => /staging/i.test(server.description))?.url 
            || parsedSpec.servers[0]?.url 
            || 'https://stagingtsms.pitx.com.ph/api/v1';
    }, [parsedSpec?.servers]);

    const selectedSnippet = useMemo(() => {
        return selectedEndpoint ? buildSnippet(selectedEndpoint, stagingServer, consoleValues, codeLanguage) : '';
    }, [codeLanguage, consoleValues, selectedEndpoint, stagingServer]);

    const selectedPathParams = useMemo(() => {
        return selectedEndpoint ? pathParameterNames(selectedEndpoint.path) : [];
    }, [selectedEndpoint]);

    const selectedQueryParams = useMemo(() => {
        if (!selectedEndpoint?.parameters) return [];
        return selectedEndpoint.parameters.filter((parameter) => parameter.in === 'query');
    }, [selectedEndpoint]);

    useEffect(() => {
        if (!selectedEndpointKey && parsedSpec?.endpoints?.length > 0) {
            setSelectedEndpointKey(endpointKey(parsedSpec.endpoints[0]));
        }
    }, [parsedSpec?.endpoints, selectedEndpointKey]);

    const updateConsoleValue = (key, value) => {
        setConsoleValues((current) => ({ ...current, [key]: value }));
    };

    return (
        <div id="openapi" className="bg-white rounded-2xl border border-[#c6c6cd] overflow-hidden shadow-sm">
            {/* Header */}
            <div className="px-6 py-6 bg-[#101828] text-white flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#1f2a37]">
                <div>
                    <div className="flex gap-2 mb-1.5">
                        <span className="bg-blue-600/10 text-blue-400 text-[10px] font-black uppercase px-2.5 py-0.5 rounded border border-blue-500/20">OpenAPI 3.0</span>
                        <span className="bg-emerald-600/10 text-emerald-400 text-[10px] font-black uppercase px-2.5 py-0.5 rounded border border-emerald-500/20">Staging Sandbox</span>
                    </div>
                    <h3 className="text-xl font-bold tracking-tight">API Reference Explorer</h3>
                    <p className="text-xs text-slate-400 mt-0.5 max-w-xl">
                        Search endpoints, review inputs, copy requests, and simulate responses dynamically.
                    </p>
                </div>
                <div className="flex gap-2 w-full sm:w-auto">
                    <a
                        href="/sandbox/payload"
                        className="flex-1 sm:flex-none text-center bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-lg flex items-center justify-center gap-1.5 transition-colors shadow-sm focus:ring-2 focus:ring-blue-500/20"
                    >
                        <FactCheckIcon style={{ fontSize: 15 }} />
                        Sandbox Page
                    </a>
                </div>
            </div>

            {loadState === 'loading' && (
                <div className="p-8 flex items-center gap-3 text-slate-500 text-sm">
                    <div className="w-4 h-4 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    <span>Parsing OpenAPI contract spec...</span>
                </div>
            )}

            {loadState === 'error' && (
                <div className="p-6 m-6 bg-rose-50 text-rose-800 border border-rose-200 rounded-xl flex items-center gap-3 text-sm">
                    <ArticleIcon className="text-rose-600" />
                    <span>The OpenAPI YAML schema could not be loaded. Please ensure openapi.yaml exists in the designated path.</span>
                </div>
            )}

            {loadState === 'ready' && (
                <div className="p-6 space-y-6">
                    {/* Visual Split-Pane Workspace Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                        {/* Left Pane: Endpoint Picker & Controls Form (col-span-6) */}
                        <div className="lg:col-span-6 space-y-5">
                            {/* Selector header */}
                            <div className="space-y-3">
                                <span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    01. Select Endpoint &amp; Set Inputs
                                </span>
                                
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Filter endpoints..."
                                        className="w-full bg-slate-50 border border-[#c6c6cd] rounded-xl pl-9 pr-3 py-2 text-sm text-[#0b1c30] placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                    />
                                    <div className="absolute left-3 top-2.5 text-slate-400">
                                        <SearchIcon style={{ fontSize: 18 }} />
                                    </div>
                                </div>

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
                            </div>

                            {/* Dropdown list of endpoints */}
                            <div className="max-h-[200px] overflow-y-auto space-y-1.5 border border-[#c6c6cd]/50 rounded-xl p-2 bg-slate-50/20 scrollbar-thin">
                                {filteredEndpoints.map((endpoint) => {
                                    const isSelected = selectedEndpoint && endpointKey(selectedEndpoint) === endpointKey(endpoint);
                                    return (
                                        <button
                                            key={endpointKey(endpoint)}
                                            onClick={() => setSelectedEndpointKey(endpointKey(endpoint))}
                                            className={`w-full p-2.5 rounded-lg text-left border transition-all flex flex-col gap-1 ${
                                                isSelected
                                                    ? 'border-blue-600 bg-blue-50/50 shadow-sm'
                                                    : 'border-[#c6c6cd]/40 bg-white hover:bg-slate-50/70'
                                            }`}
                                        >
                                            <div className="flex items-center gap-2">
                                                <MethodBadge method={endpoint.method} />
                                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                                    {endpoint.tags[0] || 'API'}
                                                </span>
                                            </div>
                                            <div className="text-xs font-mono font-bold text-slate-800 break-all leading-normal">
                                                {endpoint.path}
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Endpoint info card & parameter inputs form */}
                            {selectedEndpoint ? (
                                <div className="border border-[#c6c6cd]/60 rounded-xl bg-slate-50/20 p-5 space-y-4">
                                    <div>
                                        <h4 className="text-sm font-bold text-slate-900 leading-snug">
                                            {selectedEndpoint.summary || 'Untitled Endpoint'}
                                        </h4>
                                        {selectedEndpoint.description && (
                                            <p className="text-xs text-slate-500 mt-1.5 leading-relaxed">
                                                {selectedEndpoint.description}
                                            </p>
                                        )}
                                    </div>

                                    {/* Parameters Inputs */}
                                    <div className="space-y-3 pt-3 border-t border-[#c6c6cd]/40">
                                        {/* Token Input */}
                                        {!selectedEndpoint.security.includes('Public') && (
                                            <div>
                                                <label className="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1">
                                                    Bearer Token
                                                </label>
                                                <input
                                                    type="text"
                                                    className="w-full bg-white border border-[#c6c6cd] rounded-lg py-1.5 px-3 text-slate-800 font-mono text-xs focus:ring-1 focus:ring-blue-500 placeholder-slate-300"
                                                    placeholder="provider_testing_token"
                                                    value={consoleValues.provider_testing_token}
                                                    onChange={(e) => updateConsoleValue('provider_testing_token', e.target.value)}
                                                />
                                            </div>
                                        )}

                                        {/* Path Params */}
                                        {selectedPathParams.map((name) => (
                                            <div key={name}>
                                                <label className="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1">
                                                    {name} (Path Parameter)
                                                </label>
                                                <input
                                                    type="text"
                                                    className="w-full bg-white border border-[#c6c6cd] rounded-lg py-1.5 px-3 text-slate-800 font-mono text-xs focus:ring-1 focus:ring-blue-500"
                                                    placeholder={`{${name}}`}
                                                    value={consoleValues[name] || ''}
                                                    onChange={(e) => updateConsoleValue(name, e.target.value)}
                                                />
                                            </div>
                                        ))}

                                        {/* Query Params */}
                                        {selectedQueryParams.map((parameter) => (
                                            <div key={parameter.name}>
                                                <label className="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1">
                                                    {parameter.name} (Query Parameter)
                                                </label>
                                                <input
                                                    type="text"
                                                    className="w-full bg-white border border-[#c6c6cd] rounded-lg py-1.5 px-3 text-slate-800 font-mono text-xs focus:ring-1 focus:ring-blue-500"
                                                    placeholder={parameter.name === 'threshold_minutes' ? '1440' : parameter.name}
                                                    value={consoleValues[parameter.name] || ''}
                                                    onChange={(e) => updateConsoleValue(parameter.name, e.target.value)}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="p-8 text-center text-slate-400 border border-dashed border-[#c6c6cd] rounded-xl bg-slate-50/10 select-none">
                                    No endpoint selected. Select one above.
                                </div>
                            )}
                        </div>

                        {/* Right Pane: Request & Response Explorer Console (col-span-6) */}
                        <div className="lg:col-span-6 space-y-4 lg:sticky lg:top-4">
                            <div className="bg-[#131b2e] border border-white/10 rounded-xl shadow-lg overflow-hidden flex flex-col">
                                {/* Tab switcher */}
                                <div className="p-1 border-b border-white/10 flex justify-between items-center bg-[#0b0f19]">
                                    <div className="flex bg-white/5 rounded-lg p-0.5 border border-white/5 w-full max-w-[280px]">
                                        <button
                                            onClick={() => setActiveTab('snippet')}
                                            className={`flex-1 py-1 rounded-md text-xs font-bold transition-all uppercase ${
                                                activeTab === 'snippet'
                                                    ? 'bg-blue-600 text-white shadow'
                                                    : 'text-slate-400 hover:text-white'
                                            }`}
                                        >
                                            Request Snippet
                                        </button>
                                        <button
                                            onClick={() => setActiveTab('response')}
                                            className={`flex-1 py-1 rounded-md text-xs font-bold transition-all uppercase ${
                                                activeTab === 'response'
                                                    ? 'bg-blue-600 text-white shadow'
                                                    : 'text-slate-400 hover:text-white'
                                            }`}
                                        >
                                            Response Preview
                                        </button>
                                    </div>
                                    <div className="hidden sm:flex gap-1.5 px-3">
                                        <div className="w-2 h-2 rounded-full bg-[#e06c75]"></div>
                                        <div className="w-2 h-2 rounded-full bg-[#e5c07b]"></div>
                                        <div className="w-2 h-2 rounded-full bg-[#98c379]"></div>
                                    </div>
                                </div>

                                {/* Content Body */}
                                <div className="p-4 space-y-4">
                                    {activeTab === 'snippet' ? (
                                        <div className="space-y-4">
                                            {/* SDK Language Picker */}
                                            <div className="flex bg-white/5 rounded-lg p-1 border border-white/5">
                                                {['curl', 'fetch', 'axios', 'python'].map((lang) => (
                                                    <button
                                                        key={lang}
                                                        onClick={() => setCodeLanguage(lang)}
                                                        className={`flex-1 py-1 rounded text-[10px] font-bold uppercase transition-all ${
                                                            codeLanguage === lang
                                                                ? 'bg-white/15 text-white shadow-inner'
                                                                : 'text-slate-400 hover:text-white'
                                                        }`}
                                                    >
                                                        {lang === 'curl' ? 'cURL' : lang}
                                                    </button>
                                                ))}
                                            </div>

                                            {/* Render syntax highlighted CodeBlock */}
                                            {selectedEndpoint ? (
                                                <CodeBlock
                                                    value={selectedSnippet}
                                                    language={codeLanguage === 'python' ? 'python' : 'shell'}
                                                    filename={`tsms_request.${codeLanguage === 'python' ? 'py' : 'sh'}`}
                                                />
                                            ) : (
                                                <div className="text-center text-xs text-slate-400 py-10">
                                                    Select an endpoint to view sample code.
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            {/* Response Code Selector */}
                                            <div>
                                                <span className="block text-[10px] text-slate-400/80 mb-2 uppercase tracking-widest font-black">
                                                    Simulated Response Code
                                                </span>
                                                <div className="flex flex-wrap gap-1 bg-white/5 rounded-lg p-1 border border-white/5">
                                                    {[200, 403, 404, 409, 422, 429].map((code) => {
                                                        const isSelected = responseCode === code;
                                                        const isSuccess = code === 200;
                                                        return (
                                                            <button
                                                                key={code}
                                                                onClick={() => setResponseCode(code)}
                                                                className={`flex-1 min-w-[50px] py-1 rounded text-[11px] font-bold transition-all ${
                                                                    isSelected
                                                                        ? isSuccess
                                                                            ? 'bg-[#98c379] text-[#1e2327]'
                                                                            : 'bg-[#e06c75] text-[#1e2327]'
                                                                        : 'text-slate-400 hover:text-white'
                                                                }`}
                                                            >
                                                                {code}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>

                                            {/* Response Description */}
                                            <div className="text-xs text-slate-300 bg-white/5 border border-white/5 rounded-lg p-3 leading-relaxed">
                                                <strong className="text-white block mb-0.5">Status {responseCode} Description:</strong>
                                                {MOCK_RESPONSES[responseCode]?.description}
                                            </div>

                                            {/* Render Response JSON CodeBlock */}
                                            <CodeBlock
                                                value={MOCK_RESPONSES[responseCode]?.payload}
                                                language="json"
                                                filename={`tsms_response_${responseCode}.json`}
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Raw Specs YAML Collapsible Box */}
                    <details className="group border border-[#c6c6cd] rounded-xl overflow-hidden bg-white shadow-sm">
                        <summary className="p-4 bg-slate-50 cursor-pointer font-bold text-sm text-[#0b1c30] select-none flex items-center justify-between group-open:border-b border-[#c6c6cd] hover:bg-slate-100/50">
                            <span>Raw OpenAPI YAML Contract</span>
                            <span className="transition-transform group-open:rotate-180">
                                <ExpandMoreIcon />
                            </span>
                        </summary>
                        <div className="p-4 bg-slate-50/20">
                            <CodeBlock value={rawSpecText} language="yaml" filename="openapi.yaml" />
                        </div>
                    </details>
                </div>
            )}
        </div>
    );
};

export default OpenApiViewer;
