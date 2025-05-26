<!DOCTYPE html>
<html>

<head>
  <title>Retry History Report</title>
  <style>
  table {
    width: 100%;
    border-collapse: collapse;
  }

  th,
  td {
    padding: 8px;
    text-align: left;
    border: 1px solid #ddd;
  }

  th {
    background-color: #f4f4f4;
  }
  </style>
</head>

<body>
  <h1>Retry History Report</h1>
  <p>Generated: {{ $generated_at }}</p>

  <table>
    <thead>
      <tr>
        <th>Transaction ID</th>
        <th>Terminal</th>
        <th>Attempts</th>
        <th>Status</th>
        <th>Last Error</th>
        <th>Updated</th>
      </tr>
    </thead>
    <tbody>
      @foreach($data as $row)
      <tr>
        <td>{{ $row->transaction_id }}</td>
        <td>TERM-{{ $row->terminal_id }}</td>
        <td>{{ $row->job_attempts }}</td>
        <td>{{ $row->job_status }}</td>
        <td>{{ $row->last_error ?? 'None' }}</td>
        <td>{{ $row->updated_at->format('Y-m-d H:i:s') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>

</html>