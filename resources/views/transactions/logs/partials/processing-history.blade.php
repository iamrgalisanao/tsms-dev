<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Processing History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Attempt</th>
                        <th>Status</th>
                        <th>Message</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($history ?? [] as $entry)
                    <tr>
                        <td>{{ $entry->attempt }}</td>
                        <td>
                            <span class="badge bg-{{ $entry->status_color }}">
                                {{ $entry->status }}
                            </span>
                        </td>
                        <td>{{ $entry->message }}</td>
                        <td>{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
