import React, { useState, useEffect } from 'react';
import axios from 'axios';

const CircuitBreakers = () => {
    const [circuitBreakers, setCircuitBreakers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        tenant_id: '',
        service_name: ''
    });

    useEffect(() => {
        const fetchCircuitBreakers = async () => {
            try {
                setLoading(true);
                const response = await axios.get('/api/dashboard/circuit-breakers', {
                    params: filters
                });
                setCircuitBreakers(response.data);
            } catch (error) {
                console.error('Error fetching circuit breakers:', error);
            } finally {
                setLoading(false);
            }
        };

        // Mock data for initial development
        const mockCircuitBreakers = [
            {
                id: 1,
                service_name: 'api.transactions',
                state: 'CLOSED',
                tenant_id: 1,
                tenant_name: 'Tenant A',
                failure_count: 0,
                failure_threshold: 5,
                last_failure_at: null,
                opened_at: null,
                cooldown_until: null
            },
            {
                id: 2,
                service_name: 'api.payments',
                state: 'OPEN',
                tenant_id: 1,
                tenant_name: 'Tenant A',
                failure_count: 7,
                failure_threshold: 5,
                last_failure_at: '2025-04-26T10:15:00',
                opened_at: '2025-04-26T10:15:00',
                cooldown_until: '2025-04-26T10:45:00'
            },
            {
                id: 3,
                service_name: 'api.transactions',
                state: 'HALF_OPEN',
                tenant_id: 2,
                tenant_name: 'Tenant B',
                failure_count: 5,
                failure_threshold: 5,
                last_failure_at: '2025-04-26T09:30:00',
                opened_at: '2025-04-26T09:30:00',
                cooldown_until: '2025-04-26T10:00:00'
            }
        ];

        // Use mock data for now
        setTimeout(() => {
            setCircuitBreakers(mockCircuitBreakers);
            setLoading(false);
        }, 500);
        
        // Uncomment when API is ready
        // fetchCircuitBreakers();
    }, [filters]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({
            ...prev,
            [key]: value
        }));
    };

    const getCircuitBreakerClass = (state) => {
        switch (state) {
            case 'CLOSED': return 'circuit-closed';
            case 'OPEN': return 'circuit-open';
            case 'HALF_OPEN': return 'circuit-half-open';
            default: return '';
        }
    };

    const getIndicatorClass = (state) => {
        switch (state) {
            case 'CLOSED': return 'indicator-closed';
            case 'OPEN': return 'indicator-open';
            case 'HALF_OPEN': return 'indicator-half-open';
            default: return '';
        }
    };

    const handleResetCircuit = async (id) => {
        try {
            await axios.post(`/api/dashboard/circuit-breakers/${id}/reset`);
            
            // Refresh the list
            // fetchCircuitBreakers();
            
            // For development, just update the local state
            setCircuitBreakers(prevBreakers => 
                prevBreakers.map(breaker => 
                    breaker.id === id 
                        ? { ...breaker, state: 'CLOSED', failure_count: 0, opened_at: null, cooldown_until: null } 
                        : breaker
                )
            );
        } catch (error) {
            console.error('Error resetting circuit breaker:', error);
            alert('Failed to reset circuit breaker. Please try again.');
        }
    };

    const formatDateTime = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleString();
    };

    const calculateTimeRemaining = (cooldownUntil) => {
        if (!cooldownUntil) return 'N/A';
        
        const cooldown = new Date(cooldownUntil);
        const now = new Date();
        
        if (cooldown <= now) return 'Cooling period ended';
        
        const diff = Math.floor((cooldown - now) / 1000);
        const minutes = Math.floor(diff / 60);
        const seconds = diff % 60;
        
        return `${minutes}m ${seconds}s remaining`;
    };

    return (
        <div className="circuit-breakers">
            <h2 className="text-xl font-semibold mb-4">Circuit Breaker Status</h2>
            
            <div className="filters">
                <div className="filter-group">
                    <label>Tenant ID:</label>
                    <input 
                        type="text" 
                        value={filters.tenant_id} 
                        onChange={(e) => handleFilterChange('tenant_id', e.target.value)}
                        placeholder="Filter by tenant ID"
                    />
                </div>
                
                <div className="filter-group">
                    <label>Service:</label>
                    <input 
                        type="text" 
                        value={filters.service_name} 
                        onChange={(e) => handleFilterChange('service_name', e.target.value)}
                        placeholder="Filter by service name"
                    />
                </div>
            </div>
            
            {loading ? (
                <div className="loading">
                    <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading...
                </div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {circuitBreakers.length > 0 ? (
                        circuitBreakers.map(circuit => (
                            <div 
                                key={circuit.id} 
                                className={`circuit-breaker ${getCircuitBreakerClass(circuit.state)}`}
                            >
                                <div className={`indicator ${getIndicatorClass(circuit.state)}`}></div>
                                <div className="flex-1">
                                    <div className="flex justify-between">
                                        <h3 className="font-medium">
                                            {circuit.service_name}
                                        </h3>
                                        <span className="text-sm font-medium">
                                            Tenant: {circuit.tenant_name || circuit.tenant_id}
                                        </span>
                                    </div>
                                    
                                    <div className="mt-2 text-sm">
                                        <div><strong>State:</strong> {circuit.state}</div>
                                        <div><strong>Failures:</strong> {circuit.failure_count}/{circuit.failure_threshold}</div>
                                        {circuit.last_failure_at && (
                                            <div><strong>Last Failure:</strong> {formatDateTime(circuit.last_failure_at)}</div>
                                        )}
                                        {circuit.opened_at && (
                                            <div><strong>Opened At:</strong> {formatDateTime(circuit.opened_at)}</div>
                                        )}
                                        {circuit.cooldown_until && (
                                            <div><strong>Cooldown Period:</strong> {calculateTimeRemaining(circuit.cooldown_until)}</div>
                                        )}
                                    </div>
                                    
                                    {circuit.state !== 'CLOSED' && (
                                        <div className="mt-3">
                                            <button 
                                                className="btn btn-primary"
                                                onClick={() => handleResetCircuit(circuit.id)}
                                            >
                                                Reset Circuit
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="no-results col-span-2">No circuit breakers found</div>
                    )}
                </div>
            )}
        </div>
    );
};

export default CircuitBreakers;
