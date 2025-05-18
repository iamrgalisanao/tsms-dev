<x-app-layout>
  <x-slot name="header">
    <div class="flex justify-between items-center">
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ $provider->name }}
      </h2>
      <a href="{{ route('dashboard') }}" class="text-indigo-600 hover:text-indigo-900">
        &larr; Back to Dashboard
      </a>
    </div>
  </x-slot>

  <div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
      <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <h3 class="text-lg font-medium">Provider Summary</h3>
              <div class="mt-4 space-y-4">
                <p><strong>Code:</strong> {{ $provider->code }}</p>
                <p><strong>Status:</strong>
                  <span
                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $provider->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                    {{ ucfirst($provider->status) }}
                  </span>
                </p>
                <p><strong>Active Terminals:</strong> {{ $metrics['active_terminals'] }}</p>
                <p><strong>Total Terminals:</strong> {{ $metrics['total_terminals'] }}</p>
                <p><strong>Success Rate:</strong> {{ $metrics['success_rate'] }}%</p>
              </div>
            </div>

            <div>
              <h3 class="text-lg font-medium">Terminal History</h3>
              <div class="mt-4" style="height: 200px;">
                <canvas id="enrollmentChart"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <x-slot name="scripts">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const ctx = document.getElementById('enrollmentChart').getContext('2d');
      const data = @json($chartData);

      new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels,
          datasets: [{
            label: 'Total Terminals',
            data: data.terminalCount,
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            fill: true
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false
        }
      });
    });
    </script>
  </x-slot>
</x-app-layout>