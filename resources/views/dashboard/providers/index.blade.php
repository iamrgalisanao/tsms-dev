<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
      {{ __('POS Providers') }}
    </h2>
  </x-slot>

  <div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
      <!-- Provider Overview Cards -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 mb-6">
        @foreach($providers as $provider)
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium">{{ $provider->name }}</h3>
            <div class="text-sm text-gray-500 mb-4">{{ $provider->code }}</div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <div class="text-sm text-gray-500">Total Terminals</div>
                <div class="text-xl font-bold">{{ $provider->total_terminals }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Active Terminals</div>
                <div class="text-xl font-bold">{{ $provider->active_terminals }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Growth Rate (30d)</div>
                <div class="text-xl font-bold">{{ $provider->growth_rate }}%</div>
              </div>
            </div>
            <div class="mt-4">
              <a href="{{ route('dashboard.providers.show', $provider->id) }}"
                class="text-indigo-600 hover:text-indigo-900">View Details →</a>
            </div>
          </div>
        </div>
        @endforeach
      </div>

      <!-- Recent Terminal Enrollments -->
      <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6">
          <h3 class="text-lg font-medium mb-4">Recent Terminal Enrollments</h3>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead>
                <tr>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Terminal ID</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Provider</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Tenant</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Enrolled At</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Status</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                @foreach($recentTerminals as $terminal)
                <tr>
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {{ $terminal->terminal_uid }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {{ $terminal->provider->name ?? 'Unknown' }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {{ $terminal->tenant->name ?? 'Unknown' }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {{ $terminal->enrolled_at->format('Y-m-d H:i') }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <span
                      class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $terminal->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
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