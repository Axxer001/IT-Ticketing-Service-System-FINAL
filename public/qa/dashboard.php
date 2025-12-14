<?php
session_start();
require_once "../../classes/QualityAssurance.php";

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$qaObj = new QualityAssurance();
$stats = $qaObj->getQAStatistics();
$providerPerformance = $qaObj->getProviderScores();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QA Dashboard - Nexon</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --primary: #667eea;
    --secondary: #764ba2;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
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

.nav-actions {
    display: flex;
    gap: 12px;
}

.back-btn, .btn-link {
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

.page-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 24px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

.stat-value {
    font-size: 36px;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 8px;
}

.stat-label {
    font-size: 14px;
    color: var(--text-secondary);
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.chart-card {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border-color);
}

.chart-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 20px;
}

.table-card {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border-color);
}

table {
    width: 100%;
    border-collapse: collapse;
}

thead tr {
    border-bottom: 2px solid var(--border-color);
}

th {
    text-align: left;
    padding: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 13px;
}

td {
    padding: 16px 12px;
    border-bottom: 1px solid var(--border-color);
}

tbody tr:hover {
    background: var(--bg-main);
}

.score-bar {
    height: 8px;
    background: var(--bg-main);
    border-radius: 4px;
    overflow: hidden;
}

.score-fill {
    height: 100%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 4px;
}
</style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">NEXON QA Dashboard</div>
    <div class="nav-actions">
        <a href="review.php" class="btn-link">Review Tickets</a>
        <a href="../dashboard.php" class="back-btn">← Dashboard</a>
    </div>
</nav>

<div class="container">
    <h1 class="page-title">📊 Quality Assurance Dashboard</h1>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total_reviews'] ?></div>
            <div class="stat-label">Total Reviews</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['avg_quality_score'], 1) ?></div>
            <div class="stat-label">Avg Quality Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['approved_count'] ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['needs_revision_count'] ?></div>
            <div class="stat-label">Needs Revision</div>
        </div>
    </div>

    <div class="charts-grid">
        <div class="chart-card">
            <h2 class="chart-title">Review Status Distribution</h2>
            <canvas id="statusChart"></canvas>
        </div>
        
        <div class="chart-card">
            <h2 class="chart-title">Quality Metrics</h2>
            <canvas id="metricsChart"></canvas>
        </div>
    </div>

    <div class="table-card">
        <h2 class="chart-title">Provider Performance</h2>
        <table>
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Total Reviews</th>
                    <th>Avg Quality</th>
                    <th>Resolution Quality</th>
                    <th>Communication</th>
                    <th>Timeliness</th>
                    <th>Performance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providerPerformance as $provider): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($provider['provider_name']) ?></strong></td>
                    <td><?= $provider['review_count'] ?></td>
                    <td><?= number_format($provider['avg_quality_score'], 1) ?> ⭐</td>
                    <td><?= number_format($provider['avg_resolution_quality'], 1) ?></td>
                    <td><?= number_format($provider['avg_communication_quality'], 1) ?></td>
                    <td><?= number_format($provider['avg_timeliness_quality'], 1) ?></td>
                    <td>
                        <div class="score-bar">
                            <div class="score-fill" style="width: <?= ($provider['avg_quality_score'] / 5) * 100 ?>%"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Status Distribution Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Needs Revision', 'Rejected'],
        datasets: [{
            data: [
                <?= $stats['approved_count'] ?>,
                <?= $stats['needs_revision_count'] ?>,
                <?= $stats['rejected_count'] ?>
            ],
            backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Quality Metrics Chart
const metricsCtx = document.getElementById('metricsChart').getContext('2d');
new Chart(metricsCtx, {
    type: 'radar',
    data: {
        labels: ['Overall Quality', 'Resolution', 'Communication', 'Timeliness'],
        datasets: [{
            label: 'Average Scores',
            data: [
                <?= $stats['avg_quality_score'] ?>,
                <?= $stats['avg_resolution_quality'] ?>,
                <?= $stats['avg_communication_quality'] ?>,
                <?= $stats['avg_timeliness_quality'] ?>
            ],
            backgroundColor: 'rgba(102, 126, 234, 0.2)',
            borderColor: '#667eea',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        scales: {
            r: {
                min: 0,
                max: 5,
                ticks: { stepSize: 1 }
            }
        }
    }
});
</script>

</body>
</html>