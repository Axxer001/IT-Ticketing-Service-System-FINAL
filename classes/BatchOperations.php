<?php
require_once "Database.php";

/**
 * Batch Operations Class
 * Handle bulk ticket operations
 */
class BatchOperations {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Get tickets eligible for batch operations
     */
    public function getEligibleTickets($filters = []) {
        $sql = "SELECT t.*, 
                e.first_name, e.last_name,
                d.name as department_name,
                dt.type_name as device_type_name,
                sp.provider_name
                FROM tickets t
                JOIN employees e ON t.employee_id = e.id
                JOIN departments d ON t.department_id = d.id
                JOIN device_types dt ON t.device_type_id = dt.id
                LEFT JOIN service_providers sp ON t.assigned_provider_id = sp.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['priority'])) {
            $sql .= " AND t.priority = ?";
            $params[] = $filters['priority'];
        }
        
        $sql .= " ORDER BY t.created_at DESC LIMIT 100";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Batch assign tickets to provider
     */
    public function batchAssign($ticketIds, $providerId, $userId) {
        try {
            $this->db->beginTransaction();
            
            $successCount = 0;
            foreach ($ticketIds as $ticketId) {
                $sql = "UPDATE tickets 
                        SET assigned_provider_id = ?, 
                            status = 'assigned',
                            assigned_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?";
                
                $stmt = $this->db->connect()->prepare($sql);
                if ($stmt->execute([$providerId, $ticketId])) {
                    // Log the update
                    $logSql = "INSERT INTO ticket_updates (ticket_id, user_id, update_type, message)
                               VALUES (?, ?, 'assignment', 'Batch assigned to service provider')";
                    $logStmt = $this->db->connect()->prepare($logSql);
                    $logStmt->execute([$ticketId, $userId]);
                    
                    $successCount++;
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true, 
                'message' => "$successCount tickets assigned successfully"
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Batch update ticket status
     */
    public function batchUpdateStatus($ticketIds, $newStatus, $userId) {
        try {
            $validStatuses = ['assigned', 'in_progress', 'resolved', 'closed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                return ['success' => false, 'message' => 'Invalid status'];
            }
            
            $this->db->beginTransaction();
            
            $successCount = 0;
            foreach ($ticketIds as $ticketId) {
                $sql = "UPDATE tickets SET status = ?, updated_at = NOW()";
                $params = [$newStatus];
                
                if ($newStatus === 'resolved') {
                    $sql .= ", resolved_at = NOW()";
                } elseif ($newStatus === 'closed') {
                    $sql .= ", closed_at = NOW()";
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $ticketId;
                
                $stmt = $this->db->connect()->prepare($sql);
                if ($stmt->execute($params)) {
                    // Log the update
                    $logSql = "INSERT INTO ticket_updates (ticket_id, user_id, update_type, message)
                               VALUES (?, ?, 'status_change', ?)";
                    $logStmt = $this->db->connect()->prepare($logSql);
                    $logStmt->execute([$ticketId, $userId, "Batch status update to $newStatus"]);
                    
                    $successCount++;
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "$successCount tickets updated successfully"
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Batch update ticket priority
     */
    public function batchUpdatePriority($ticketIds, $newPriority, $userId) {
        try {
            $validPriorities = ['low', 'medium', 'high', 'critical'];
            if (!in_array($newPriority, $validPriorities)) {
                return ['success' => false, 'message' => 'Invalid priority'];
            }
            
            $this->db->beginTransaction();
            
            $successCount = 0;
            foreach ($ticketIds as $ticketId) {
                $sql = "UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $this->db->connect()->prepare($sql);
                
                if ($stmt->execute([$newPriority, $ticketId])) {
                    // Log the update
                    $logSql = "INSERT INTO ticket_updates (ticket_id, user_id, update_type, message)
                               VALUES (?, ?, 'comment', ?)";
                    $logStmt = $this->db->connect()->prepare($logSql);
                    $logStmt->execute([$ticketId, $userId, "Batch priority update to $newPriority"]);
                    
                    $successCount++;
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "$successCount tickets updated successfully"
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}