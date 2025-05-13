<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $report->name }} - Security Report</title>
  <style>
  body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    margin: 20px;
  }

  .report-header {
    margin-bottom: 30px;
  }

  .report-title {
    font-size: 24px;
    color: #333;
    margin-bottom: 10px;
  }

  .report-meta {
    color: #666;
    font-size: 14px;
  }

  .section {
    margin-bottom: 30px;
  }

  .section-title {
    font-size: 18px;
    color: #444;
    margin-bottom: 15px;
    border-bottom: 1px solid #eee;
    padding-bottom: 5px;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
  }

  th,
  td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
  }

  th {
    background-color: #f5f5f5;
  }

  .severity-info {
    color: #0066cc;
  }

  .severity-warning {
    color: #ff9900;
  }

  .severity-critical {
    color: #cc0000;
  }
  </style>
</head>

<body>
  <div class="report-header">
    <h1 class="report-title">{{ $report->name }}</h1>
    <div class="report-meta">
      <p>Generated: {{ now()->format('Y-m-d H:i:s') }}</p>
      <p>Period: {{ $report->from_date }} to {{ $report->to_date }}</p>
      @if($report->description)
      <p>Description: {{ $report->description }}</p>
      @endif
    </div>
  </div>

  @if(isset($report->summary))
  <div class="section">
    <h2 class="section-title">Summary</h2>
    <div class="summary-content">
      {!! $report->summary !!}
    </div>
  </div>
  @endif

  @if(isset($report->events) && count($report->events) > 0)
  <div class="section">
    <h2 class="section-title">Security Events</h2>
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <th>Severity</th>
          <th>Description</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        @foreach($report->events as $event)
        <tr>
          <td>{{ $event->created_at }}</td>
          <td>{{ $event->type }}</td>
          <td class="severity-{{ strtolower($event->severity) }}">
            {{ $event->severity }}
          </td>
          <td>{{ $event->description }}</td>
          <td>{{ $event->source_ip }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif

  @if(isset($report->alerts) && count($report->alerts) > 0)
  <div class="section">
    <h2 class="section-title">Security Alerts</h2>
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Rule</th>
          <th>Severity</th>
          <th>Status</th>
          <th>Actions Taken</th>
        </tr>
      </thead>
      <tbody>
        @foreach($report->alerts as $alert)
        <tr>
          <td>{{ $alert->created_at }}</td>
          <td>{{ $alert->rule->name }}</td>
          <td class="severity-{{ strtolower($alert->severity) }}">
            {{ $alert->severity }}
          </td>
          <td>{{ $alert->status }}</td>
          <td>{{ $alert->actions_taken }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif
</body>

</html>