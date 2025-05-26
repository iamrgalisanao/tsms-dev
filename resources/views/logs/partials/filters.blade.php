<div class="card-body border-bottom">
  <!-- Simple Search -->
  <div class="row align-items-center">
    <div class="col-md-6">
      <div class="input-group">
        <input type="text" class="form-control" id="searchLogs" placeholder="Search by Transaction ID...">
        <button class="btn btn-primary" onclick="applyFilters()">
          <i class="fas fa-search me-1"></i>Search
        </button>
      </div>
    </div>
    <div class="col-md-6 text-end">
      <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
        data-bs-target="#advancedFilters">
        <i class="fas fa-filter me-1"></i>Advanced Filters
      </button>
    </div>
  </div>

  <!-- Advanced Filters -->
  <div class="collapse mt-3" id="advancedFilters">
    <div class="card card-body bg-light">
      <div class="row g-3">
        <div class="col-md-3">
          <select class="form-select" id="logType">
            <option value="">All Types</option>
            <option value="system">System</option>
            <option value="audit">Audit</option>
            <option value="webhook">Webhook</option>
            <option value="error">Error</option>
          </select>
        </div>
        <div class="col-md-3">
          <select class="form-select" id="severity">
            <option value="">All Severities</option>
            <option value="error">Error</option>
            <option value="warning">Warning</option>
            <option value="info">Info</option>
            <option value="debug">Debug</option>
          </select>
        </div>
        <div class="col-md-3">
          <input type="date" class="form-control" id="dateFilter" value="{{ date('Y-m-d') }}">
        </div>
        <div class="col-md-3 text-end">
          <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
          <button class="btn btn-outline-secondary ms-2" onclick="resetFilters()">Reset</button>
        </div>
      </div>
    </div>
  </div>
</div>