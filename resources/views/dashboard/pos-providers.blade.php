<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('POS Providers') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 bg-white border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Provider Summary</h3>
                    
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($providers as $provider)
                        <div class="bg-gray-50 p-4 rounded-lg shadow">
                            <h4 class="text-md font-bold">{{ $provider->name }}</h4>
                            <p class="text-sm text-gray-600">{{ $provider->code }}</p>
                            <div class="mt-2">
                                <p><span class="font-semibold">Total Terminals:</span> {{ $provider->terminals_count }}</p>
                                <p><span class="font-semibold">Active Terminals:</span> {{ $provider->active_terminals_count }}</p>
                                <p><span class="font-semibold">Growth Rate:</span> {{ number_format($provider->growth_rate, 1) }}%</p>
                            </div>
                            <div class="mt-3">
                                <a href="{{ route('dashboard.provider-detail', $provider->id) }}" class="text-blue-600 hover:text-blue-800">View Details →</a>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <h3 class="text-lg font-medium text-gray-900 mt-8">Terminal Enrollment Trends</h3>
                    
                    <div class="mt-4">
                        <!-- This would be a chart in a real implementation -->
                        <div class="bg-gray-100 p-6 rounded-lg">
                            <p class="text-center text-gray-500">Terminal enrollment chart would be displayed here</p>
                        </div>
                    </div>
                    
                    <h3 class="text-lg font-medium text-gray-900 mt-8">Recent Terminal Enrollments</h3>
                    
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Terminal ID</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Provider</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tenant</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enrolled At</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($recentTerminals as $terminal)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $terminal->terminal_uid }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $terminal->provider->name ?? 'Unknown' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $terminal->tenant->trade_name ?? 'Unknown' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $terminal->enrolled_at->format('Y-m-d H:i') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            {{ $terminal->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ ucfirst($terminal->status) }}
                                        </span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
