<x-app-layout>
  <x-slot name="header">
    <div class="flex justify-between items-center">
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ $provider->name }}
      </h2>
      <a href="{{ route('dashboard.providers.index') }}" class="text-indigo-600 hover:text-indigo-900">
        &larr; Back to Providers
      </a>
    </div>
  </x-slot>

  <div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
      <!-- Provider Information -->
      <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <h3 class="text-lg font-medium">Provider Information</h3>
              <div class="mt-4 space-y-2">
                <div class="grid grid-cols-2">
                  <div class="text-sm text-gray-500">Provider Code</div>
                  <div>{{ $provider->code }}</div>
                </div>
                <div class="grid grid-cols-2">
                  <div class="text-sm text-gray-500">Contact Email</div>
                  <div>{{ $provider->contact_email }}</div>
                </div>
                <div class="grid grid-cols-2">
                  <div class="text-sm text-gray-500">Contact Phone</div>
                  <div>{{ $provider->contact_phone }}</div>
                </div>
                <div class="grid grid-cols-2">
                  <div class="text-sm text-gray-500">Status</div>
                  <div>
                    <span
                      class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                      {{ $provider->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                      {{ ucfirst($provider->status) }}
                    </span>
                  </div>
                </div>
                <div class="grid grid-cols-2">
                  <div class="text-sm text-gray-500">Created At</div>
                  <div>{{ $provider->created_at->format('Y-m-d H:i') }}</div>
                </div>
              </div>
            </div>
            <div>
              <h3 class="text-lg font-medium">Description</h3>
              <div class="mt-4">
                <p class="text-sm text-gray-600">{{ $provider->description }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Terminal Statistics -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium">Terminal Summary</h3>
            <div class="mt-4 space-y-4">
              <div class="flex justify-between items-center">
                <div class="text-sm text-gray-500">Total Terminals</div>
                <div class="text-xl font-bold">{{ $provider->terminals->count() }}</div>
              </div>
              <div class="flex justify-between items-center">
                <div class="text-sm text-gray-500">Active Terminals</div>
                <div class="text-xl font-bold">{{ $provider->terminals->where('status', 'active')->count() }}</div>
              </div>
              <div class="flex justify-between items-center">
                <div class="text-sm text-gray-500">Inactive Terminals</div>
                <div class="text-xl font-bold">{{ $provider->terminals->where('status', '!=', 'active')->count() }}
                </div>
              </div>
              <div class="flex justify-between items-center">
                <div class="text-sm text-gray-500">Growth Rate (30d)</div>
                <div class="text-xl font-bold">{{ round($provider->growth_rate, 1) }}%</div>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium">Terminals by Tenant</h3>
            <div class="mt-4 space-y-2">
              @foreach($terminalsByTenant as $item)
              <div class="flex justify-between items-center">
                <div class="text-sm text-gray-500">{{ $item->name }}</div>
                <div class="text-xl font-bold">{{ $item->total }}</div>
              </div>
              @endforeach
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium">Terminals by Status</h3>
            <div class="mt-4 space-y-2">
              @foreach($terminalsByStatus as $item)
              <div class="flex justify-between items-center">
                <div class="text-sm text-gray-500">{{ ucfirst($item->status) }}</div>
                <div class="text-xl font-bold">{{ $item->total }}</div>
              </div>
              @endforeach
            </div>
          </div>
        </div>
      </div>

      <!-- Historical Data Chart -->
      <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
        <div class="p-6">
          <h3 class="text-lg font-medium mb-4">Terminal Enrollment History</h3>
          <div style="height: 300px;">
            <canvas id="enrollmentChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Latest Terminals -->
      <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6">
          <h3 class="text-lg font-medium mb-4">Latest Terminal Enrollments</h3>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead>
                <tr>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Terminal ID</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Tenant</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Enrolled At</th>
                  <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Status</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                @foreach($latestTerminals as $terminal)
                <tr>
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {{ $terminal->terminal_uid }}</td>
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

  <!-- ChartJS Script -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('enrollmentChart').getContext('2d');

    // Parse the data from PHP
    const labels = {
      !!$chartLabels!!
    };
    const terminalCountData = {
      !!$chartData['terminalCount'] !!
    };
    const activeCountData = {
      !!$chartData['activeCount'] !!
    };
    const newEnrollmentsData = {
      !!$chartData['newEnrollments'] !!
    };

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Total Terminals',
          data: terminalCountData,
          borderColor: 'rgb(59, 130, 246)',
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          tension: 0.1,
          fill: true
        }, {
          label: 'Active Terminals',
          data: activeCountData,
          borderColor: 'rgb(16, 185, 129)',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          tension: 0.1,
          fill: true
        }, {
          label: 'New Enrollments',
          data: newEnrollmentsData,
          borderColor: 'rgb(245, 158, 11)',
          backgroundColor: 'rgba(245, 158, 11, 0.1)',
          tension: 0.1,
          fill: true
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  });
  </script>
</x-app-layout>