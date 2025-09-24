
@extends('layouts.master')

@section('content')
<div class="card mt-4">
  <div class="card-header">
    <h5>System Settings</h5>
  </div>
  <div class="card-body">
    @if(session('status'))
      <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}">
      @csrf

      <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="allow_previous_day_transactions" name="allow_previous_day_transactions" value="1" {{ $allow_previous_day_transactions ? 'checked' : '' }}>
        <label class="form-check-label" for="allow_previous_day_transactions">Allow previous-day transactions</label>
        <div class="form-text">When enabled, transactions with a timestamp from previous calendar days (within the configured maximum age) will be accepted by background validation. Default: disabled.</div>
      </div>

  <button type="submit" class="btn btn-primary">Save Settings</button>
  <a href="{{ route('transactions.logs.index') }}" class="btn btn-secondary ms-2">Back to transactions</a>
    </form>
  </div>
</div>
@endsection

