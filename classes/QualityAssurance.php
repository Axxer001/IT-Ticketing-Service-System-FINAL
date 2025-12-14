<?php
require_once "Database.php";

/**
 * Quality Assurance Management Class
 */
class QualityAssurance {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Get tickets pending QA review
     */
    public function getPendingReviews() {
        $sql = "SELECT t.*, 
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                sp.provider_name,
                d.name as department_name
                FROM tickets t
                JOIN employees e ON t.employee_id = e.id
                JOIN service_providers sp ON t.assigned_provider_id = sp.id
                JOIN departments d ON t.department_id = d.id
                LEFT JOIN qa_reviews qr ON t.id = qr.ticket_id
                WHERE t.status = 'resolved'
                AND qr.id IS NULL
                ORDER BY t.resolved_at DESC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Submit QA review
     */
    public function submitReview($ticketId, $reviewerId, $data) {
        try {
            $this->db->beginTransaction();
            
            $sql = "INSERT INTO qa_reviews 
                    (ticket_id, reviewer_id, quality_score, resolution_quality, 
                     communication_quality, timeliness_quality, comments, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([
                $ticketId,
                $reviewerId,
                $data['quality_score'],
                $data['resolution_quality'] ?? null,
                $data['communication_quality'] ?? null,
                $data['timeliness_quality'] ?? null,
                $data['comments'] ?? null,
                $data['status']
            ]);
            
            // If approved, mark ticket as closed
            if ($data['status'] === 'approved') {
                $updateSql = "UPDATE tickets SET status = 'closed', closed_at = NOW() WHERE id = ?";
                $updateStmt = $this->db->connect()->prepare($updateSql);
                $updateStmt->execute([$ticketId]);
            }
            
            // Log the review
            $logSql = "INSERT INTO ticket_updates (ticket_id, user_id, update_type, message)
                       VALUES (?, ?, 'comment', ?)";
            $logStmt = $this->db->connect()->prepare($logSql);
            $message = "QA Review: " . ucfirst($data['status']) . " - Score: " . $data['quality_score'] . "/5";
            $logStmt->execute([$ticketId, $reviewerId, $message]);
            
            $this->db->commit();
            
            return ['success' => true, 'message' => 'Review submitted successfully'];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get QA statistics
     */
    public function getQAStatistics() {
        $sql = "SELECT 
                COUNT(*) as total_reviews,
                AVG(quality_score) as avg_quality_score,
                AVG(resolution_quality) as avg_resolution,
                AVG(communication_quality) as avg_communication,
                AVG(timeliness_quality) as avg_timeliness,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'needs_revision' THEN 1 ELSE 0 END) as revision_count
                FROM qa_reviews
                WHERE reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get provider QA scores
     */
    public function getProviderScores() {
        $sql = "SELECT sp.provider_name,
                COUNT(qr.id) as review_count,
                AVG(qr.quality_score) as avg_score,
                AVG(qr.resolution_quality) as avg_resolution,
                AVG(qr.communication_quality) as avg_communication,
                AVG(qr.timeliness_quality) as avg_timeliness
                FROM service_providers sp
                LEFT JOIN tickets t ON sp.id = t.assigned_provider_id
                LEFT JOIN qa_reviews qr ON t.id = qr.ticket_id
                WHERE qr.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY sp.id
                ORDER BY avg_score DESC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get ticket review details
     */
    public function getTicketReview($ticketId) {
        $sql = "SELECT qr.*, u.email as reviewer_email
                FROM qa_reviews qr
                JOIN users u ON qr.reviewer_id = u.id
                WHERE qr.ticket_id = ?";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}