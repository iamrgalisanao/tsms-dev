@extends('layouts.master')
@section('title', 'Tenant Details')
@section('content')
<div class="card card-primary">
  <div class="card-header">
    <h3 class="card-title">Tenant Details</h3>
    <div class="card-tools">
      <a href="{{ route('commercial.sales-report.tenants') }}" class="btn btn-tool" title="Back to list">
        <i class="fas fa-arrow-left"></i>
      </a>
    </div>
  </div>

  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <table class="table table-sm table-borderless mb-0">
          <tbody>
            <tr>
              <th class="w-25">Tenant ID</th>
              <td>{{ $tenant->id }}</td>
            </tr>
            <tr>
              <th>Tenant Code</th>
              <td>{{ $tenant->customer_code }}</td>
            </tr>
            <tr>
              <th>Trade Name</th>
              <td>{{ $tenant->trade_name }}</td>
            </tr>
            <tr>
              <th>Location Type</th>
              <td>{{ $tenant->location_type ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="col-md-6">
        <table class="table table-sm table-borderless mb-0">
          <tbody>
            <tr>
              <th class="w-25">Unit No.</th>
              <td>{{ $tenant->unit_no ?? '' }}</td>
            </tr>
            <tr>
              <th>Level</th>
              <td>{{ $tenant->level ?? '' }}</td>
            </tr>
            <tr>
              <th>Category</th>
              <td>{{ $tenant->category ?? '' }}</td>
            </tr>
            <tr>
              <th>Zone</th>
              <td>{{ $tenant->zone ?? '' }}</td>
            </tr>
            <tr>
              <th>Terminals</th>
              <td><span class="badge badge-info">{{ $tenant->posTerminals->count() }}</span> terminals</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    @php
      use Carbon\Carbon;
      use Illuminate\Support\Facades\DB;
      use Illuminate\Support\Facades\Schema;

      // determine year (query param ?year=YYYY or current year)
      $year = request()->get('year', Carbon::now()->year);

      // prefer relationship if available, otherwise query transactions table directly
      $dateColumn = null;
      if (Schema::hasTable('transactions')) {
        if (Schema::hasColumn('transactions', 'transaction_date')) {
          $dateColumn = 'transaction_date';
        } elseif (Schema::hasColumn('transactions', 'created_at')) {
          $dateColumn = 'created_at';
        }
      }

      $annual_sales = $annual_sales ?? null;
      $transaction_count = $transaction_count ?? null;

      try {
        if (method_exists($tenant, 'transactions')) {
          $query = $tenant->transactions();
          if ($dateColumn) {
            $query = $query->whereYear($dateColumn, $year);
          }
          $annual_sales = $annual_sales ?? (float) $query->sum('gross_sales');
          $transaction_count = $transaction_count ?? (int) $query->sum('transaction_count');
        } else {
          // fallback to direct DB query
          $q = DB::table('transactions')->where('tenant_id', $tenant->id);
          if ($dateColumn) {
            $q = $q->whereYear($dateColumn, $year);
          }
          $annual_sales = $annual_sales ?? (float) $q->sum('gross_sales');
          $transaction_count = $transaction_count ?? (int) $q->sum('transaction_count');
        }
      } catch (\Exception $e) {
        // if something goes wrong, keep values null and avoid breaking the view
        $annual_sales = $annual_sales ?? 0.0;
        $transaction_count = $transaction_count ?? 0;
      }

      $avg_monthly_sales = $avg_monthly_sales ?? ($annual_sales / 12);
      $avg_daily_sales = $avg_daily_sales ?? ($transaction_count > 0 ? ($annual_sales / $transaction_count) : 0);
      $revenue_share = $tenant->revenue_share ?? 0; // expected as decimal (eg. 0.10 for 10%)
      $rent_based_on_rs = $rent_based_on_rs ?? ($annual_sales * $revenue_share);
    @endphp

    <div class="card card-outline card-secondary mt-3">
      <div class="card-header">
        <h3 class="card-title">Sales Summary (Year: {{ $year }})</h3>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm table-striped mb-0">
          <tbody>
            <tr>
              <th class="w-50">Annual Sales</th>
              <td class="text-right">{{ number_format($annual_sales, 2) }}</td>
            </tr>
            <tr>
              <th>Average Monthly Sales</th>
              <td class="text-right">{{ number_format($avg_monthly_sales, 2) }}</td>
            </tr>
            <tr>
              <th>Average Daily Sales (per transaction)</th>
              <td class="text-right">{{ $transaction_count > 0 ? number_format($avg_daily_sales, 2) : 'N/A' }}</td>
            </tr>
            <tr>
              <th>Transaction Count</th>
              <td class="text-right">{{ number_format($transaction_count) }}</td>
            </tr>
            <tr>
              <th>Rent Based on Revenue Share (RS)</th>
              <td class="text-right">{{ number_format($rent_based_on_rs, 2) }} @if($revenue_share) <small class="text-muted">(RS: {{ $revenue_share }})</small> @endif</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <h5 class="mt-4">Terminals</h5>
    <div class="table-responsive">
      <table class="table table-hover table-striped table-sm text-nowrap">
        <thead class="thead-light">
          <tr>
            <th>ID</th>
            <th>Terminal Code</th>
            <th>Type</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach($tenant->posTerminals as $terminal)
            <tr>
              <td>{{ $terminal->id }}</td>
              <td>{{ $terminal->terminal_code ?? '' }}</td>
              <td>{{ $terminal->type ?? '' }}</td>
              <td>
                @if($terminal->status)
                  <span class="badge badge-secondary">{{ $terminal->status }}</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>

@endsection
