import React, { useState, useEffect } from 'react';
import axios from 'axios';

const CircuitBreakers = () => {
    const [breakers, setBreakers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filter, setFilter] = useState('all');

    const fetchBreakers = async () => {
        try {
            setLoading(true);
            setError(null);
            const response = await axios.get('/api/web/dashboard/circuit-breakers', {
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                withCredentials: true
            });
            setBreakers(response.data.data || []);
        } catch (error) {
            console.error('Error fetching circuit breakers:', error);
            setError('Failed to fetch circuit breakers');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchBreakers();
    }, []);

    const handleReset = async (id) => {
        try {
            const response = await axios.post(`/api/web/dashboard/circuit-breakers/${id}/reset`, {}, {
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                withCredentials: true
            });
            if (response.data.status === 'success') {
                fetchBreakers(); // Refresh the list after reset
            }
        } catch (error) {
            console.error('Error resetting circuit breaker:', error);
            alert('Failed to reset circuit breaker. Please try again.');
        }
    };

    const getStatusClass = (status) => {
        switch (status.toLowerCase()) {
            case 'open':
                return 'bg-red-100 text-red-800';
            case 'half-open':
                return 'bg-yellow-100 text-yellow-800';
            case 'closed':
                return 'bg-green-100 text-green-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const renderBreakerRows = () => {
        const filteredBreakers = filter === 'all' 
            ? breakers 
            : breakers.filter(breaker => breaker.status.toLowerCase() === filter.toLowerCase());

        return filteredBreakers.map(breaker => (
            <tr key={breaker.id} className="hover:bg-gray-50">
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {breaker.name}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusClass(breaker.status)}`}>
                        {breaker.status}
                    </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {breaker.trip_count}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {breaker.last_failure_at ? new Date(breaker.last_failure_at).toLocaleString() : 'N/A'}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {breaker.cooldown_until ? new Date(breaker.cooldown_until).toLocaleString() : 'N/A'}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    {breaker.status.toLowerCase() !== 'closed' && (
                        <button
                            onClick={() => handleReset(breaker.id)}
                            className="text-indigo-600 hover:text-indigo-900"
                        >
                            Reset
                        </button>
                    )}
                </td>
            </tr>
        ));
    };

    if (error) {
        return <div className="text-red-600">{error}</div>;
    }

    return (
        <div className="circuit-breakers">
            <div className="flex justify-between items-center mb-4">
                <h2 className="text-xl font-semibold">Circuit Breakers</h2>
                <div className="filter-group">
                    <label className="mr-2">Status:</label>
                    <select 
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        className="rounded border-gray-300"
                    >
                        <option key="filter-all" value="all">All</option>
                        <option key="filter-open" value="open">Open</option>
                        <option key="filter-half" value="half-open">Half Open</option>
                        <option key="filter-closed" value="closed">Closed</option>
                    </select>
                </div>
            </div>

            {loading ? (
                <div className="text-center py-4">Loading...</div>
            ) : (
                <div className="shadow overflow-hidden border-b border-gray-200 sm:rounded-lg">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Service
                                </th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Trip Count
                                </th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Last Failure
                                </th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Cooldown Until
                                </th>
                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {renderBreakerRows()}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
};

export default CircuitBreakers;
