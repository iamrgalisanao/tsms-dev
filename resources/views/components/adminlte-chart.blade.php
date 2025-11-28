<div class="col-md-6">
  <div class="card">
    <div class="card-body chart-card">
      <h5 class="card-title">{{ $title }}</h5>
      <div class="chart-wrapper" style="position:relative; min-height:180px;">
        <canvas id="{{ $id }}" height="180"></canvas>
        <div id="spinner-{{ $key }}" class="chart-spinner">
          <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
        </div>
        <div id="nodata-{{ $key }}" class="chart-no-data">No data</div>
      </div>
    </div>
  </div>
</div>
