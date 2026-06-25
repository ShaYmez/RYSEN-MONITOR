<?php
header('Content-Type: application/rss+xml; charset=utf-8');

// Read configuration
$config = parse_ini_file('/etc/rysen/fdmr-mon.cfg', true);

if (!isset($config['BULLETIN_BOARD']) || !$config['BULLETIN_BOARD']['RSS_ENABLED']) {
    http_response_code(404);
    exit('RSS feed disabled');
}

// Database connection - require these to be set in config
if (!isset($config['SELF SERVICE']['DB_SERVER']) || 
    !isset($config['SELF SERVICE']['DB_USERNAME']) || 
    !isset($config['SELF SERVICE']['DB_PASSWORD']) ||
    !isset($config['SELF SERVICE']['DB_NAME'])) {
    http_response_code(500);
    exit('Database configuration incomplete');
}

$db_server = $config['SELF SERVICE']['DB_SERVER'];
$db_user = $config['SELF SERVICE']['DB_USERNAME'];
$db_pass = $config['SELF SERVICE']['DB_PASSWORD'];
$db_name = $config['SELF SERVICE']['DB_NAME'];
$db_port = $config['SELF SERVICE']['DB_PORT'] ?? 3306;

// Translate Docker internal IP to host-accessible address
// When PHP runs on host, it cannot reach internal Docker IPs (172.16.238.x)
// MariaDB is exposed on host as localhost:8306 (see docker-compose.yml)
if (preg_match('/^172\.16\.238\.\d+$/', $db_server)) {
    $db_server = 'localhost';
    // Use the exposed port for host access
    if ($db_port == 3306) {
        $db_port = 8306;
    }
}

$conn = new mysqli($db_server, $db_user, $db_pass, $db_name, $db_port);

if ($conn->connect_error) {
    http_response_code(500);
    exit('Database connection failed');
}

$max_items = $config['BULLETIN_BOARD']['RSS_MAX_ITEMS'] ?? 50;
$stmt = $conn->prepare("SELECT callsign, message, dmr_id, timestamp, server_name, category, priority FROM bulletin_board ORDER BY timestamp DESC LIMIT ?");
$stmt->bind_param("i", $max_items);
$stmt->execute();
$result = $stmt->get_result();

$rss_title = $config['BULLETIN_BOARD']['RSS_TITLE'] ?? 'System-X Bulletin Board';
$rss_desc = $config['BULLETIN_BOARD']['RSS_DESCRIPTION'] ?? 'System-X DMR Network Bulletin Board';

// Use configured server name or sanitized HTTP_HOST
$server_host = $config['GLOBAL']['SERVER_NAME'] ?? htmlspecialchars($_SERVER['HTTP_HOST']);

// Determine protocol (HTTPS if available, else HTTP)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0">
  <channel>
    <title><?php echo htmlspecialchars($rss_title); ?></title>
    <link><?php echo $protocol; ?>://<?php echo $server_host; ?>/dashboard/</link>
    <description><?php echo htmlspecialchars($rss_desc); ?></description>
    <language>en</language>
    <lastBuildDate><?php echo date(DATE_RSS); ?></lastBuildDate>
<?php
while ($row = $result->fetch_assoc()) {
    $pub_date = date(DATE_RSS, strtotime($row['timestamp']));
    $category_badge = strtoupper($row['category'] ?? 'GENERAL');
    $priority_text = ($row['priority'] ?? 0) > 0 ? '[URGENT] ' : '';
    
    echo "    <item>\n";
    echo "      <title>" . $priority_text . htmlspecialchars($category_badge) . " - " . htmlspecialchars($row['callsign']) . "</title>\n";
    echo "      <description>" . htmlspecialchars($row['message']) . "</description>\n";
    echo "      <author>" . htmlspecialchars($row['callsign'] . ' (' . $row['dmr_id'] . ')') . "</author>\n";
    echo "      <category>" . htmlspecialchars($category_badge) . "</category>\n";
    echo "      <pubDate>" . $pub_date . "</pubDate>\n";
    echo "      <guid isPermaLink=\"false\">bulletin-" . intval($row['dmr_id']) . "-" . strtotime($row['timestamp']) . "</guid>\n";
    echo "      <source url=\"" . $protocol . "://" . $server_host . "\">" . htmlspecialchars($row['server_name']) . "</source>\n";
    echo "    </item>\n";
}
?>
  </channel>
</rss>
<?php
$stmt->close();
$conn->close();
?>
