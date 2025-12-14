<?php
session_start();
require_once "../../classes/Analytics.php";
require_once "../../classes/User.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$analyticsObj = new Analytics();
$userObj = new User();
$userType = $_SESSION['user_type'];

// Date range
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get analytics based on user type
$analytics = $analyticsObj->getTicketAnalytics($dateFrom, $dateTo);
$dashboardStats = $analyticsObj->getDashboardStats($userType, $_SESSION['user_id']);

if ($userType === 'admin') {
    $providerPerformance = $analyticsObj->getProviderPerformance();
    $departmentPerformance = $analyticsObj->getDepartmentPerformance();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Advanced Analytics - Nexon</title>
<link rel="stylesheet" href="../../assets/css/theme.css">
<script>
    const PHP_SESSION_THEME = <?= json_encode($_SESSION['theme'] ?? 'light') ?>;
</script>
<style>
:root {
    --primary: #667eea;
    --secondary: #764ba2;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #3b82f6;
    --bg-main: #f8fafc;
    --bg-card: #ffffff;
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --border-color: #e2e8f0;
    --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

[data-theme="dark"] {
    --bg-main: #0f172a;
    --bg-card: #1e293b;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --border-color: #334155;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg-main);
    color: var(--text-primary);
}

.navbar {
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-color);
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow);
}

.navbar-brand {
    font-size: 24px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.back-btn {
    padding: 8px 16px;
    background: var(--bg-main);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-primary);
    font-weight: 600;
}

.container {
    max-width: 1400px;
    margin: 24px auto;
    padding: 0 24px;
}

.page-header {
    margin-bottom: 24px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 8px;
}

.page-subtitle {
    color: var(--text-secondary);
}

.filters {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    border: 1px solid var(--border-color);
    display: flex;
    gap: 16px;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
}

.filter-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-secondary);
}

.filter-group input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-main);
    color: var(--text-primary);
    font-size: 14px;
}

.btn-primary {
    padding: 10px 20px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border-color);
}

.stat-icon {
    font-size: 32px;
    margin-bottom: 12px;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 8px;
}

.stat-label {
    font-size: 14px;
    color: var(--text-secondary);
}

.stat-change {
    font-size: 12px;
    margin-top: 8px;
}

.stat-change.positive { color: var(--success); }
.stat-change.negative { color: var(--danger); }

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 24px;
    margin-bottom: 24px;
}

.card {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border-color);
}

.card-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
}

.chart-container {
    height: 300px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.chart-bar {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chart-label {
    flex: 0 0 120px;
    font-size: 13px;
    font-weight: 600;
}

.chart-bar-wrapper {
    flex: 1;
    height: 32px;
    background: var(--bg-main);
    border-radius: 6px;
    overflow: hidden;
    position: relative;
}

.chart-bar-fill {
    height: 100%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    transition: width 1s ease;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 12px;
}

.chart-value {
    color: white;
    font-weight: 600;
    font-size: 12px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    text-align: left;
    padding: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 13px;
    text-transform: uppercase;
    border-bottom: 2px solid var(--border-color);
}

td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    font-size: 14px;
}

tr:hover {
    background: var(--bg-main);
}

@media (max-width: 768px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">NEXON Analytics</div>
    <a href="../dashboard.php" class="back-btn">← Dashboard</a>
</nav>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📊 Advanced Analytics</h1>
        <p class="page-subtitle">Comprehensive insights and performance metrics</p>
    </div>

    <form class="filters" method="GET">
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="date_from" value="<?= $dateFrom ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="date_to" value="<?= $dateTo ?>">
        </div>
        <button type="submit" class="btn-primary">Apply Filter</button>
    </form>

    <!-- Key Metrics -->
    <div class="stats-grid">
        <?php if ($userType === 'admin'): ?>
        <div class="stat-card">
            <div class="stat-icon">🎫</div>
            <div class="stat-value"><?= $dashboardStats['total_tickets'] ?? 0 ?></div>
            <div class="stat-label">Total Tickets</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?= $dashboardStats['pending'] ?? 0 ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔄</div>
            <div class="stat-value"><?= $dashboardStats['active'] ?? 0 ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $dashboardStats['resolved_today'] ?? 0 ?></div>
            <div class="stat-label">Resolved Today</div>
        </div>
        <?php elseif ($userType === 'employee'): ?>
        <div class="stat-card">
            <div class="stat-icon">🎫</div>
            <div class="stat-value"><?= $dashboardStats['my_tickets'] ?? 0 ?></div>
            <div class="stat-label">My Tickets</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?= $dashboardStats['pending'] ?? 0 ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔄</div>
            <div class="stat-value"><?= $dashboardStats['in_progress'] ?? 0 ?></div>
            <div class="stat-label">In Progress</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $dashboardStats['resolved'] ?? 0 ?></div>
            <div class="stat-label">Resolved</div>
        </div>
        <?php else: ?>
        <div class="stat-card">
            <div class="stat-icon">🎫</div>
            <div class="stat-value"><?= $dashboardStats['assigned'] ?? 0 ?></div>
            <div class="stat-label">Assigned</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔄</div>
            <div class="stat-value"><?= $dashboardStats['in_progress'] ?? 0 ?></div>
            <div class="stat-label">In Progress</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $dashboardStats['resolved'] ?? 0 ?></div>
            <div class="stat-label">Resolved</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value"><?= number_format($dashboardStats['avg_rating'] ?? 0, 1) ?></div>
            <div class="stat-label">Average Rating</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <!-- Tickets by Status -->
        <div class="card">
            <h2 class="card-title">Tickets by Status</h2>
            <div class="chart-container">
                <?php 
                $total = array_sum(array_column($analytics['by_status'], 'count'));
                foreach ($analytics['by_status'] as $item): 
                    $percentage = $total > 0 ? ($item['count'] / $total) * 100 : 0;
                ?>
                <div class="chart-bar">
                    <div class="chart-label"><?= ucfirst(str_replace('_', ' ', $item['status'])) ?></div>
                    <div class="chart-bar-wrapper">
                        <div class="chart-bar-fill" style="width: <?= $percentage ?>%">
                            <span class="chart-value"><?= $item['count'] ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tickets by Priority -->
        <div class="card">
            <h2 class="card-title">Tickets by Priority</h2>
            <div class="chart-container">
                <?php 
                $priorityTotal = array_sum(array_column($analytics['by_priority'], 'count'));
                foreach ($analytics['by_priority'] as $item): 
                    $percentage = $priorityTotal > 0 ? ($item['count'] / $priorityTotal) * 100 : 0;
                ?>
                <div class="chart-bar">
                    <div class="chart-label"><?= ucfirst($item['priority']) ?></div>
                    <div class="chart-bar-wrapper">
                        <div class="chart-bar-fill" style="width: <?= $percentage ?>%">
                            <span class="chart-value"><?= $item['count'] ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($userType === 'admin'): ?>
    <!-- Top Departments -->
    <div class="card" style="margin-bottom: 24px;">
        <h2 class="card-title">Top Departments by Volume</h2>
        <table>
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Total Tickets</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($analytics['by_department'], 0, 10) as $dept): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($dept['name']) ?></strong></td>
                    <td><?= $dept['count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Provider Performance -->
    <div class="card">
        <h2 class="card-title">Service Provider Performance</h2>
        <table>
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Total Tickets</th>
                    <th>Resolved</th>
                    <th>Avg Time (hrs)</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providerPerformance as $provider): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($provider['provider_name']) ?></strong></td>
                    <td><?= $provider['total_tickets'] ?></td>
                    <td><?= $provider['resolved_tickets'] ?></td>
                    <td><?= round($provider['avg_resolution_hours'] ?? 0, 1) ?></td>
                    <td><?= number_format($provider['rating_average'], 1) ?>⭐</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/notifications.js"></script>
</body>
</html>