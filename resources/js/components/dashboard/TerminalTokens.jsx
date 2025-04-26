import React, { useState, useEffect } from 'react';
import axios from 'axios';

const TerminalTokens = () => {
    const [tokens, setTokens] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        terminal_id: '',
        status: 'active' // active, expired, revoked
    });
    const [newTokenForm, setNewTokenForm] = useState({
        terminal_id: '',
        expiration_days: 30,
        isOpen: false
    });

    useEffect(() => {
        const fetchTokens = async () => {
            try {
                setLoading(true);
                const response = await axios.get('/api/dashboard/tokens', {
                    params: filters
                });
                setTokens(response.data);
            } catch (error) {
                console.error('Error fetching terminal tokens:', error);
            } finally {
                setLoading(false);
            }
        };

        // Mock data for initial development
        const mockTokens = [
            {
                id: 1,
                terminal_id: 'TERM123',
                terminal_name: 'Store A Terminal',
                token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
                is_revoked: false,
                issued_at: '2025-04-15T10:00:00',
                expires_at: '2025-05-15T10:00:00',
                last_used_at: '2025-04-25T15:30:45'
            },
            {
                id: 2,
                terminal_id: 'TERM456',
                terminal_name: 'Store B Terminal',
                token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
                is_revoked: false,
                issued_at: '2025-04-20T09:15:00',
                expires_at: '2025-05-20T09:15:00',
                last_used_at: '2025-04-26T08:22:10'
            },
            {
                id: 3,
                terminal_id: 'TERM789',
                terminal_name: 'Store C Terminal',
                token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
                is_revoked: true,
                revoked_at: '2025-04-24T14:05:30',
                revoked_reason: 'Security breach',
                issued_at: '2025-04-10T14:30:00',
                expires_at: '2025-05-10T14:30:00',
                last_used_at: '2025-04-24T13:45:22'
            }
        ];

        // Use mock data for now
        setTimeout(() => {
            setTokens(mockTokens);
            setLoading(false);
        }, 500);
        
        // Uncomment when API is ready
        // fetchTokens();
    }, [filters]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({
            ...prev,
            [key]: value
        }));
    };

    const handleNewTokenChange = (key, value) => {
        setNewTokenForm(prev => ({
            ...prev,
            [key]: value
        }));
    };

    const handleRevokeToken = async (id) => {
        try {
            await axios.post(`/api/dashboard/tokens/${id}/revoke`);
            
            // Refresh the list
            // fetchTokens();
            
            // For development, just update the local state
            setTokens(prevTokens => 
                prevTokens.map(token => 
                    token.id === id 
                        ? { 
                            ...token, 
                            is_revoked: true, 
                            revoked_at: new Date().toISOString(),
                            revoked_reason: 'Revoked from dashboard'
                          } 
                        : token
                )
            );
        } catch (error) {
            console.error('Error revoking token:', error);
            alert('Failed to revoke token. Please try again.');
        }
    };

    const handleGenerateToken = async (e) => {
        e.preventDefault();
        try {
            // Submit the form
            const response = await axios.post('/api/dashboard/tokens/generate', {
                terminal_id: newTokenForm.terminal_id,
                expiration_days: newTokenForm.expiration_days
            });
            
            // Add the new token to the list
            // fetchTokens();
            
            // For development, simulate adding a new token
            const newToken = {
                id: tokens.length + 1,
                terminal_id: newTokenForm.terminal_id,
                terminal_name: `Terminal ${newTokenForm.terminal_id}`,
                token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
                is_revoked: false,
                issued_at: new Date().toISOString(),
                expires_at: new Date(Date.now() + newTokenForm.expiration_days * 86400000).toISOString(),
                last_used_at: null
            };
            setTokens(prev => [newToken, ...prev]);
            
            // Reset form and close it
            setNewTokenForm({
                terminal_id: '',
                expiration_days: 30,
                isOpen: false
            });
        } catch (error) {
            console.error('Error generating token:', error);
            alert('Failed to generate new token. Please try again.');
        }
    };

    const calculateExpirationStatus = (expiresAt, isRevoked) => {
        if (isRevoked) return { status: 'Revoked', class: 'badge-dark' };
        
        const expiry = new Date(expiresAt);
        const now = new Date();
        
        if (expiry < now) {
            return { status: 'Expired', class: 'badge-danger' };
        }
        
        const daysRemaining = Math.ceil((expiry - now) / (1000 * 60 * 60 * 24));
        
        if (daysRemaining <= 7) {
            return { status: `Expires in ${daysRemaining} days`, class: 'badge-warning' };
        }
        
        return { status: 'Active', class: 'badge-success' };
    };

    const formatDateTime = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleString();
    };

    return (
        <div className="terminal-tokens">
            <h2 className="text-xl font-semibold mb-4">Terminal Tokens</h2>
            
            <div className="flex justify-between mb-4">
                <div className="filters">
                    <div className="filter-group">
                        <label>Terminal ID:</label>
                        <input 
                            type="text" 
                            value={filters.terminal_id} 
                            onChange={(e) => handleFilterChange('terminal_id', e.target.value)}
                            placeholder="Filter by terminal ID"
                        />
                    </div>
                    
                    <div className="filter-group">
                        <label>Status:</label>
                        <select 
                            value={filters.status} 
                            onChange={(e) => handleFilterChange('status', e.target.value)}
                        >
                            <option value="active">Active</option>
                            <option value="expired">Expired</option>
                            <option value="revoked">Revoked</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <button 
                        className="btn btn-primary" 
                        onClick={() => setNewTokenForm(prev => ({ ...prev, isOpen: true }))}
                    >
                        Generate New Token
                    </button>
                </div>
            </div>
            
            {newTokenForm.isOpen && (
                <div className="modal">
                    <div className="modal-content">
                        <div className="modal-header">
                            <h3 className="text-lg font-semibold">Generate New Token</h3>
                            <button onClick={() => setNewTokenForm(prev => ({ ...prev, isOpen: false }))}>
                                &times;
                            </button>
                        </div>
                        <form onSubmit={handleGenerateToken}>
                            <div className="modal-body">
                                <div className="mb-4">
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Terminal ID
                                    </label>
                                    <input 
                                        type="text" 
                                        className="w-full border-gray-300 rounded-md"
                                        value={newTokenForm.terminal_id} 
                                        onChange={(e) => handleNewTokenChange('terminal_id', e.target.value)}
                                        required
                                    />
                                </div>
                                <div className="mb-4">
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Expiration (Days)
                                    </label>
                                    <input 
                                        type="number" 
                                        className="w-full border-gray-300 rounded-md"
                                        value={newTokenForm.expiration_days} 
                                        onChange={(e) => handleNewTokenChange('expiration_days', parseInt(e.target.value))}
                                        min="1"
                                        max="365"
                                        required
                                    />
                                </div>
                            </div>
                            <div className="modal-footer">
                                <button 
                                    type="button" 
                                    className="btn btn-secondary mr-2"
                                    onClick={() => setNewTokenForm(prev => ({ ...prev, isOpen: false }))}
                                >
                                    Cancel
                                </button>
                                <button 
                                    type="submit" 
                                    className="btn btn-primary"
                                >
                                    Generate Token
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
            
            {loading ? (
                <div className="loading">
                    <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading...
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="logs-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Terminal</th>
                                <th>Status</th>
                                <th>Issued At</th>
                                <th>Expires At</th>
                                <th>Last Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tokens.length > 0 ? (
                                tokens.map(token => {
                                    const expStatus = calculateExpirationStatus(token.expires_at, token.is_revoked);
                                    
                                    return (
                                        <tr key={token.id} className="hover:bg-gray-50">
                                            <td>{token.id}</td>
                                            <td>
                                                {token.terminal_name || token.terminal_id}
                                                <div className="text-xs text-gray-500">
                                                    {token.terminal_id}
                                                </div>
                                            </td>
                                            <td>
                                                <span className={`badge ${expStatus.class}`}>
                                                    {expStatus.status}
                                                </span>
                                                {token.is_revoked && (
                                                    <div className="text-xs text-gray-500 mt-1">
                                                        {token.revoked_reason}
                                                    </div>
                                                )}
                                            </td>
                                            <td>{formatDateTime(token.issued_at)}</td>
                                            <td>{formatDateTime(token.expires_at)}</td>
                                            <td>{formatDateTime(token.last_used_at)}</td>
                                            <td>
                                                {!token.is_revoked && (
                                                    <button 
                                                        className="btn btn-danger"
                                                        onClick={() => handleRevokeToken(token.id)}
                                                    >
                                                        Revoke
                                                    </button>
                                                )}
                                                {token.is_revoked && (
                                                    <span className="text-gray-400">
                                                        Revoked at {formatDateTime(token.revoked_at)}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan="7" className="text-center py-4">
                                        No tokens found
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
};

export default TerminalTokens;
