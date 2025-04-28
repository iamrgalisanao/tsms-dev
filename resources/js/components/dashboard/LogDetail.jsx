import React, { useState } from 'react';

const LogDetail = ({ log, onClose }) => {
    const [activeTab, setActiveTab] = useState('overview');
    
    if (!log) return null;
    
    const formatJson = (jsonData) => {
        if (!jsonData) return 'No data';
        
        let formattedData;
        
        if (typeof jsonData === 'string') {
            try {
                formattedData = JSON.stringify(JSON.parse(jsonData), null, 2);
            } catch (e) {
                formattedData = jsonData;
            }
        } else {
            formattedData = JSON.stringify(jsonData, null, 2);
        }
        
        return formattedData;
    };
    
    const renderOverviewTab = () => (
        <div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <h3 className="font-semibold mb-2">Transaction Details</h3>
                    <table className="w-full text-sm">
                        <tbody>
                            <tr>
                                <td className="py-1 font-medium">ID:</td>
                                <td>{log.id}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Transaction ID:</td>
                                <td>{log.transaction_id || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Status:</td>
                                <td>{log.status}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Validation Status:</td>
                                <td>{log.validation_status || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Terminal ID:</td>
                                <td>{log.terminal_id || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Tenant ID:</td>
                                <td>{log.tenant_id || 'N/A'}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div>
                    <h3 className="font-semibold mb-2">Response Information</h3>
                    <table className="w-full text-sm">
                        <tbody>
                            <tr>
                                <td className="py-1 font-medium">HTTP Status:</td>
                                <td>{log.http_status_code || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Response Time:</td>
                                <td>{log.response_time ? `${log.response_time}ms` : 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Source IP:</td>
                                <td>{log.source_ip || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Created At:</td>
                                <td>{log.created_at ? new Date(log.created_at).toLocaleString() : 'N/A'}</td>
                            </tr>
                            <tr>
                                <td className="py-1 font-medium">Updated At:</td>
                                <td>{log.updated_at ? new Date(log.updated_at).toLocaleString() : 'N/A'}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div className="mt-4">
                <h3 className="font-semibold mb-2">Retry Information</h3>
                <table className="w-full text-sm">
                    <tbody>
                        <tr>
                            <td className="py-1 font-medium">Retry Count:</td>
                            <td>{log.retry_count || 0}</td>
                        </tr>
                        <tr>
                            <td className="py-1 font-medium">Retry Reason:</td>
                            <td>{log.retry_reason || 'N/A'}</td>
                        </tr>
                        <tr>
                            <td className="py-1 font-medium">Next Retry At:</td>
                            <td>{log.next_retry_at ? new Date(log.next_retry_at).toLocaleString() : 'N/A'}</td>
                        </tr>
                        {log.error_message && (
                            <tr>
                                <td className="py-1 font-medium">Error Message:</td>
                                <td className="text-red-500">{log.error_message}</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            
            {log.circuit_breaker_state && (
                <div className="mt-4">
                    <h3 className="font-semibold mb-2">Circuit Breaker Information</h3>
                    <div className={`circuit-breaker ${log.circuit_breaker_state === 'OPEN' ? 'circuit-open' : 'circuit-closed'}`}>
                        <div className={`indicator ${log.circuit_breaker_state === 'OPEN' ? 'indicator-open' : 'indicator-closed'}`}></div>
                        <div>
                            <strong>State:</strong> {log.circuit_breaker_state}
                            {log.circuit_breaker_cooldown && (
                                <div><strong>Cooldown Until:</strong> {new Date(log.circuit_breaker_cooldown).toLocaleString()}</div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
    
    const renderPayloadTab = () => (
        <div>
            <h3 className="font-semibold mb-2">Request Payload</h3>
            <pre className="json-viewer mb-6">
                {formatJson(log.request_payload)}
            </pre>
            
            <h3 className="font-semibold mb-2">Response Payload</h3>
            <pre className="json-viewer">
                {formatJson(log.response_payload)}
            </pre>
        </div>
    );
    
    const renderMetadataTab = () => (
        <div>
            <h3 className="font-semibold mb-2">Response Metadata</h3>
            <pre className="json-viewer">
                {formatJson(log.response_metadata)}
            </pre>
        </div>
    );
    
    return (
        <div className="modal">
            <div className="modal-content">
                <div className="modal-header">
                    <h3 className="text-lg font-semibold">
                        Transaction Log Details
                        {log.transaction_id && (
                            <span className="ml-2 text-gray-600">
                                ({log.transaction_id})
                            </span>
                        )}
                    </h3>
                    <button 
                        className="text-2xl"
                        onClick={onClose}
                    >
                        &times;
                    </button>
                </div>
                
                <div className="px-4 border-b">
                    <nav className="flex space-x-4">
                        <button 
                            className={`py-2 px-1 border-b-2 ${activeTab === 'overview' ? 'border-blue-500 text-blue-500' : 'border-transparent'}`}
                            onClick={() => setActiveTab('overview')}
                        >
                            Overview
                        </button>
                        <button 
                            className={`py-2 px-1 border-b-2 ${activeTab === 'payload' ? 'border-blue-500 text-blue-500' : 'border-transparent'}`}
                            onClick={() => setActiveTab('payload')}
                        >
                            Request/Response
                        </button>
                        <button 
                            className={`py-2 px-1 border-b-2 ${activeTab === 'metadata' ? 'border-blue-500 text-blue-500' : 'border-transparent'}`}
                            onClick={() => setActiveTab('metadata')}
                        >
                            Metadata
                        </button>
                    </nav>
                </div>
                
                <div className="modal-body">
                    {activeTab === 'overview' && renderOverviewTab()}
                    {activeTab === 'payload' && renderPayloadTab()}
                    {activeTab === 'metadata' && renderMetadataTab()}
                </div>
                
                <div className="modal-footer">
                    <button 
                        className="btn btn-secondary"
                        onClick={onClose}
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
};

export default LogDetail;
