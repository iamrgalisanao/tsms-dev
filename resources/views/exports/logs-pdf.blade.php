<!DOCTYPE html>
<html>

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>System Logs Export</title>
  <style>
  body {
    font-family: Arial, sans-serif;
    font-size: 10pt;
  }

  h1 {
    font-size: 16pt;
    color: #333;
    margin-bottom: 10px;
  }

  h2 {
    font-size: 14pt;
    color: #666;
    margin-top: 20px;
    margin-bottom: 10px;
  }

  .filters {
    margin-bottom: 20px;
    color: #666;
    font-size: 9pt;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
  }

  th {
    background-color: #f2f2f2;
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
    font-size: 10pt;
  }

  td {
    border: 1px solid #ddd;
    padding: 6px;
    font-size: 9pt;
    vertical-align: top;
  }

  .error {
    background-color: #ffeeee;
  }

  .warning {
    background-color: #ffffee;
  }

  .footer {
    text-align: center;
    margin-top: 30px;
    font-size: 9pt;
    color: #777;
  }

  .badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 8pt;
    color: white;
  }

  .badge-error {
    background-color: #dc3545;
  }

  .badge-warning {
    background-color: #ffc107;
    color: #333;
  }

  .badge-info {
    background-color: #17a2b8;
  }

  .badge-debug {
    background-color: #6c757d;
  }

  .page-break {
    page-break-after: always;
  }
  </style>
</head>

<body>
  <h1>System Logs Export</h1>
  <p>Generated: {{ now()->format('Y-m-d H:i:s') }}</p>

  <h2>Applied Filters</h2>
  <div class="filters">
    @if (count($filters) > 0)
    <ul>
      @foreach($filters as $key => $value)
      <li><strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong> {{ $value }}</li>
      @endforeach
    </ul>
    @else
    <p>No filters applied</p>
    @endif
  </div>

  <h2>Log Records</h2>
  <table>
    <thead>
      <tr>
        <th>Time</th>
        <th>Type</th>
        <th>Severity</th>
        <th>Terminal</th>
        <th>Message</th>
      </tr>
    </thead>
    <tbody>
      @forelse($logs as $log)
      <tr class="{{ $log->severity === 'error' ? 'error' : ($log->severity === 'warning' ? 'warning' : '') }}">
        <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        <td>{{ $log->log_type ?? 'general' }}</td>
        <td
          class="text-{{ $log->severity === 'error' ? 'danger' : ($log->severity === 'warning' ? 'warning' : 'info') }}">
          <span class="badge badge-{{ $log->severity ?? 'info' }}">
            {{ strtoupper($log->severity ?? 'info') }}
          </span>
        </td>
        <td>{{ $log->posTerminal->terminal_uid ?? 'N/A' }}</td>
        <td>{{ $log->message ?? 'No message' }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="5">No logs found</td>
      </tr>
      @endforelse
    </tbody>
  </table>

  <div class="footer">
    TSMS Log Export &copy; {{ date('Y') }} | Confidential - Internal Use Only
  </div>
</body>

</html>