import React from 'react';

const FilterBar = ({ onFilterChange, onExport, loading }) => {
    const [filters, setFilters] = React.useState({
        start_date: '',
        end_date: '',
        terminal_id: '',
        search: ''
    });

    const handleChange = (e) => {
        const { name, value } = e.target;
        const newFilters = { ...filters, [name]: value };
        setFilters(newFilters);
        onFilterChange(newFilters);
    };

    const handleClear = () => {
        const cleared = { start_date: '', end_date: '', terminal_id: '', search: '' };
        setFilters(cleared);
        onFilterChange(cleared);
    };

    return (
        <div className="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex flex-wrap items-center gap-4 mb-8">
            <div className="flex-1 min-w-[200px]">
                <input
                    type="text"
                    name="search"
                    placeholder="Search Transaction ID or Tenant..."
                    value={filters.search}
                    onChange={handleChange}
                    className="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none transition-all"
                />
            </div>

            <div className="flex items-center gap-2">
                <span className="text-xs font-bold text-gray-400 uppercase">From</span>
                <input
                    type="date"
                    name="start_date"
                    value={filters.start_date}
                    onChange={handleChange}
                    className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500"
                />
            </div>

            <div className="flex items-center gap-2">
                <span className="text-xs font-bold text-gray-400 uppercase">To</span>
                <input
                    type="date"
                    name="end_date"
                    value={filters.end_date}
                    onChange={handleChange}
                    className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500"
                />
            </div>

            <button
                onClick={handleClear}
                className="px-4 py-2 text-gray-400 hover:text-gray-600 text-sm font-bold transition-colors"
            >
                Clear
            </button>

            <div className="flex-grow"></div>

            <button
                onClick={onExport}
                disabled={loading}
                className="flex items-center space-x-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-bold transition-all shadow-sm active:scale-95 disabled:opacity-50"
            >
                <span>📥</span>
                <span>Export CSV</span>
            </button>
        </div>
    );
};

export default FilterBar;
