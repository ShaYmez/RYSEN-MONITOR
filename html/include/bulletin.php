<div class="container">
  <div class="row justify-content-center">
    <div class="col-12">
      <div class="card">
        <div class="card-header border-transparent">
          <h3 class="card-title" id="tbl_bulletin">
            Bulletin Board 
            <a href="bulletin_rss.php" target="_blank" class="text-muted">
              <i class="fas fa-rss" data-bs-toggle="tooltip" title="RSS Feed"></i>
            </a>
          </h3>
          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
              <i class="fas fa-minus"></i>
            </button>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="btn-group btn-group-sm m-2" role="group">
            <button class="btn btn-outline-secondary active" onclick="filterBB('ALL')">All</button>
            <button class="btn btn-outline-secondary" onclick="filterBB('GENERAL')">💬 General</button>
            <button class="btn btn-outline-info" onclick="filterBB('INFO')">ℹ️ Info</button>
            <button class="btn btn-outline-success" onclick="filterBB('EVENT')">📅 Events</button>
            <button class="btn btn-outline-danger" onclick="filterBB('EMERGENCY')">🚨 Emergency</button>
          </div>
          <div class="table-responsive">
            <p id="bulletin"></p>
          </div>
        </div>
      </div>
    </div>
  </div>
  <!-- Footer fix -->
  <div><br></div>
</div>

<!-- Content check: show waiting message instead of reloading -->
<script>
  watchDashboardContent('bulletin');

  function filterBB(cat) {
    var table = document.querySelector('#bulletin table');
    if (!table) return;
    
    var rows = table.querySelectorAll('tbody tr');
    var buttons = document.querySelectorAll('.btn-group button');
    
    // Update active button
    buttons.forEach(btn => {
      if (btn.textContent.includes(cat) || (cat === 'ALL' && btn.textContent === 'All')) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });
    
    // Filter rows
    rows.forEach(row => {
      if (cat === 'ALL') {
        row.style.display = '';
      } else {
        var badge = row.querySelector('.badge');
        if (badge && badge.textContent.includes(cat)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      }
    });
  }
</script>
