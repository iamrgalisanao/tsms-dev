@props(['chartData'])

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('enrollmentChart').getContext('2d');
    const chartData = @json($chartData);
    console.log('Chart Data:', chartData); // Debug log

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Total Terminals',
                    data: chartData.terminalCount,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    type: 'line',
                    order: 1
                },
                {
                    label: 'Active Terminals',
                    data: chartData.activeCount,
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    type: 'line',
                    order: 2
                },
                {
                    label: 'New Enrollments',
                    data: chartData.newEnrollments,
                    borderColor: 'rgb(245, 158, 11)',
                    backgroundColor: 'rgba(245, 158, 11, 0.5)',
                    type: 'bar',
                    order: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left'
                }
            }
        }
    });
});
</script>