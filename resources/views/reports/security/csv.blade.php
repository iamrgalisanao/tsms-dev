{{ '"Time","Type","Severity","Description","Source"' . PHP_EOL }}
@if(isset($report->events) && count($report->events) > 0)
@foreach($report->events as $event)
{{ '"' . $event->created_at . '","' . $event->type . '","' . $event->severity . '","' . str_replace('"', '""', $event->description) . '","' . $event->source_ip . '"' . PHP_EOL }}
@endforeach
@endif

@if(isset($report->alerts) && count($report->alerts) > 0)
{{ '"Time","Rule","Severity","Status","Actions Taken"' . PHP_EOL }}
@foreach($report->alerts as $alert)
{{ '"' . $alert->created_at . '","' . $alert->rule->name . '","' . $alert->severity . '","' . $alert->status . '","' . str_replace('"', '""', $alert->actions_taken) . '"' . PHP_EOL }}
@endforeach
@endif
