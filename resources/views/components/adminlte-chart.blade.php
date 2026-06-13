<div class="col-md-6">
  <div class="card">
    <div class="card-body chart-card">
        <h5 class="card-title">{{ $title }}</h5>
        <!-- Enforce a stable chart height to avoid parent flex/column layouts stretching the canvas
             unexpectedly. Keep a min-height for small screens and a reasonable fixed height. -->
        <div class="chart-wrapper" style="position:relative; min-height:180px; height:260px; max-height:420px;">
          <canvas id="{{ $id }}" height="180" style="display:block; width:100%; height:100%;"></canvas>
        <div id="spinner-{{ $key }}" class="chart-spinner">
          <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
        </div>
        <div id="nodata-{{ $key }}" class="chart-no-data">No data</div>
      </div>
    </div>
  </div>
</div>
