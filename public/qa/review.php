<?php
session_start();
require_once "../../classes/QualityAssurance.php";
require_once "../../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$qaObj = new QualityAssurance();
$database = new Database();
$db = $database->getConnection();

$message = '';
$messageType = '';

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $reviewData = [
        'ticket_id' => (int)$_POST['ticket_id'],
        'reviewer_id' => $_SESSION['user_id'],
        'quality_score' => (int)$_POST['quality_score'],
        'resolution_quality' => (int)$_POST['resolution_quality'],
        'communication_quality' => (int)$_POST['communication_quality'],
        'timeliness_quality' => (int)$_POST['timeliness_quality'],
        'comments' => $_POST['comments'],
        'status' => $_POST['review_status']
    ];
    
    if ($qaObj->createReview($reviewData)) {
        $message = "Review submitted successfully!";
        $messageType = "success";
    } else {
        $message = "Failed to submit review.";
        $messageType = "error";
    }
}

// Get resolved tickets pending review
$query = "SELECT t.*, e.first_name, e.last_name, sp.provider_name,
          d.name as department_name, dt.type_name,
          (SELECT COUNT(*) FROM qa_reviews WHERE ticket_id = t.id) as has_review
          FROM tickets t
          JOIN employees e ON t.employee_id = e.id
          JOIN departments d ON t.department_id = d.id
          LEFT JOIN service_providers sp ON t.assigned_provider_id = sp.id
          JOIN device_types dt ON t.device_type_id = dt.id
          WHERE t.status = 'resolved'
          ORDER BY t.resolved_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QA Review - Nexon</title>
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

.page-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 24px;
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
    border: 1px solid #10b981;
}

.alert-error {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
    border: 1px solid #ef4444;
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

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
}

.btn-success {
    background: var(--success);
    color: white;
}

.badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.badge-reviewed {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
}

.badge-pending {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 32px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 20px;
}

label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-secondary);
}

input, select, textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-main);
    color: var(--text-primary);
    font-size: 14px;
}

textarea {
    min-height: 100px;
    resize: vertical;
    font-family: inherit;
}

.rating-group {
    display: flex;
    gap: 12px;
    align-items: center;
}

.rating-input {
    width: 80px;
}

.modal-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}
</style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">NEXON QA Review</div>
    <a href="../dashboard.php" class="back-btn">← Dashboard</a>
</nav>

<div class="container">
    <h1 class="page-title">✅ Quality Assurance Review</h1>
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Description</th>
                    <th>Employee</th>
                    <th>Provider</th>
                    <th>Resolved</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $ticket): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ticket['ticket_number']) ?></strong></td>
                    <td><?= htmlspecialchars(substr($ticket['issue_description'], 0, 40)) ?>...</td>
                    <td><?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?></td>
                    <td><?= htmlspecialchars($ticket['provider_name'] ?? 'N/A') ?></td>
                    <td><?= date('M d, Y', strtotime($ticket['resolved_at'])) ?></td>
                    <td>
                        <?php if ($ticket['has_review'] > 0): ?>
                            <span class="badge badge-reviewed">Reviewed</span>
                        <?php else: ?>
                            <span class="badge badge-pending">Pending Review</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ticket['has_review'] == 0): ?>
                            <button onclick="openReviewModal(<?= htmlspecialchars(json_encode($ticket)) ?>)" class="btn btn-primary">
                                Review
                            </button>
                        <?php else: ?>
                            <a href="dashboard.php" class="btn btn-success">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Review Modal -->
<div id="reviewModal" class="modal">
    <div class="modal-content">
        <h2 class="modal-header">Quality Assurance Review</h2>
        
        <form method="POST">
            <input type="hidden" name="ticket_id" id="ticketId">
            
            <div class="form-group">
                <label>Ticket: <span id="ticketNumber"></span></label>
            </div>
            
            <div class="form-group">
                <label>Overall Quality Score (1-5)</label>
                <div class="rating-group">
                    <input type="number" name="quality_score" min="1" max="5" required class="rating-input">
                    <span style="font-size: 12px; color: var(--text-secondary);">⭐ 1 (Poor) to 5 (Excellent)</span>
                </div>
            </div>
            
            <div class="form-group">
                <label>Resolution Quality (1-5)</label>
                <input type="number" name="resolution_quality" min="1" max="5" required class="rating-input">
            </div>
            
            <div class="form-group">
                <label>Communication Quality (1-5)</label>
                <input type="number" name="communication_quality" min="1" max="5" required class="rating-input">
            </div>
            
            <div class="form-group">
                <label>Timeliness Quality (1-5)</label>
                <input type="number" name="timeliness_quality" min="1" max="5" required class="rating-input">
            </div>
            
            <div class="form-group">
                <label>Review Status</label>
                <select name="review_status" required>
                    <option value="approved">✅ Approved</option>
                    <option value="needs_revision">⚠️ Needs Revision</option>
                    <option value="rejected">❌ Rejected</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Comments</label>
                <textarea name="comments" placeholder="Add your review comments..."></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" name="submit_review" class="btn btn-primary">Submit Review</button>
                <button type="button" onclick="closeReviewModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReviewModal(ticket) {
    document.getElementById('ticketId').value = ticket.id;
    document.getElementById('ticketNumber').textContent = ticket.ticket_number;
    document.getElementById('reviewModal').classList.add('active');
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('active');
}

// Close modal on outside click
document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReviewModal();
    }
});
</script>

</body>
</html>