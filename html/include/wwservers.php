<?php require_once __DIR__ . '/api-config.php'; ?>
<div class="container">
    <div class="row justify-content-center">
      <div class="col-12">
        <div class="card">
          <div class="card-header border-transparent">
            <h3 class="card-title" id="tbl_srvrs"><?php echo htmlspecialchars(API_NETWORK_NAME, ENT_QUOTES, 'UTF-8'); ?> DMR Global</h3>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table m-0 table-striped table-sm">
                <thead>
                  <tr>
                    <th id="tsrvrs_country">Country</th>
                    <th id="tsrvrs_dmrid">DMR-ID</th>
                    <th id="tsrvrs_ipname">IP/Name</th>
                    <th id="tsrvrs_pass">Password</th>
                    <th id="tsrvrs_port">Port</th>
                    <th id="tsrvrs_status">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $csvData = dashboardFetchApiContent(API_SERVERS_CSV_URL);
                  $lines = $csvData !== false ? explode("\n", trim($csvData)) : [];
                  $skipLines = defined('API_SERVERS_CSV_SKIP_LINES') ? (int)API_SERVERS_CSV_SKIP_LINES : 2;

                  for ($i = 0; $i < $skipLines && !empty($lines); $i++) {
                      array_shift($lines);
                  }

                  foreach ($lines as $line) {
                      if (trim($line) === '') {
                          continue;
                      }

                      $fields = str_getcsv($line);
                      $fields = array_pad($fields, 7, '');
                      list($country, $id, $host, $password, $port, $tokenStatus, $lastUpdate) = $fields;
                      $tokenStatus = empty($tokenStatus) ? 'unknown' : $tokenStatus;
                      $lastUpdate = empty($lastUpdate) ? 0 : $lastUpdate;
                      echo '<tr>';
                      echo '<td>' . htmlspecialchars($country) . '</td>';
                      echo '<td>' . htmlspecialchars($id) . '</td>';
                      echo '<td><a target="_blank" href="http://' . htmlspecialchars($host) . '">' . htmlspecialchars($host) . '</a></td>';
                      echo '<td>' . htmlspecialchars($password) . '</td>';
                      echo '<td>' . htmlspecialchars($port) . '</td>';
                      echo '<td><span class="status" data-host="' . htmlspecialchars($host) . '" data-status="' . htmlspecialchars($tokenStatus) . '" data-last-update="' . htmlspecialchars($lastUpdate) . '"><span class="badge badge-warning">Loading...</span></span></td>';
                      echo '</tr>';
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    function updateStatuses() {
        var statuses = document.querySelectorAll('.status');
        statuses.forEach(function(status) {
            var host = status.getAttribute('data-host');
            var tokenStatus = status.getAttribute('data-status');
            var lastUpdate = status.getAttribute('data-last-update');
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (this.readyState == 4 && this.status == 200) {
                    var badgeClass;
                    if (this.responseText === 'Verified') {
                        badgeClass = 'badge-success';
                    } else if (this.responseText === 'Unauthorized') {
                        badgeClass = 'badge-danger';
                    } else if (this.responseText === 'Pending') {
                        badgeClass = 'badge-warning';
                    } else if (this.responseText === 'Offline') {
                        badgeClass = 'badge-secondary';
                    } else {
                        badgeClass = 'badge-info';
                    }
                    status.innerHTML = '<span class="badge ' + badgeClass + '">' + this.responseText + '</span>';
                }
            };
            xhr.open('GET', 'include/serverstatus.php?status=' + encodeURIComponent(tokenStatus) + '&last_update=' + encodeURIComponent(lastUpdate), true);
            xhr.send();
        });
    }
    updateStatuses();
</script>
