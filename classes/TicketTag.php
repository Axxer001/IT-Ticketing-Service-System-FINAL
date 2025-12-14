<?php
require_once "Database.php";

/**
 * Ticket Tag Management Class
 */
class TicketTag {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Get all available tags
     */
    public function getAllTags() {
        $sql = "SELECT * FROM tags ORDER BY name ASC";
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get tags for a specific ticket
     */
    public function getTicketTags($ticketId) {
        $sql = "SELECT t.* FROM tags t
                INNER JOIN ticket_tags tt ON t.id = tt.tag_id
                WHERE tt.ticket_id = ?
                ORDER BY t.name ASC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Add tag to ticket
     */
    public function addTagToTicket($ticketId, $tagId, $userId) {
        try {
            $sql = "INSERT INTO ticket_tags (ticket_id, tag_id, added_by) 
                    VALUES (?, ?, ?)";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$ticketId, $tagId, $userId]);
            
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Remove tag from ticket
     */
    public function removeTagFromTicket($ticketId, $tagId) {
        $sql = "DELETE FROM ticket_tags WHERE ticket_id = ? AND tag_id = ?";
        $stmt = $this->db->connect()->prepare($sql);
        return $stmt->execute([$ticketId, $tagId]);
    }
    
    /**
     * Create new tag
     */
    public function createTag($name, $color, $description = null) {
        try {
            $sql = "INSERT INTO tags (name, color, description) VALUES (?, ?, ?)";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$name, $color, $description]);
            
            return ['success' => true, 'tag_id' => $this->db->lastInsertId()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Search tickets by tags
     */
    public function searchTicketsByTags($tagIds) {
        if (empty($tagIds)) {
            return [];
        }
        
        $placeholders = str_repeat('?,', count($tagIds) - 1) . '?';
        
        $sql = "SELECT DISTINCT t.* FROM tickets t
                INNER JOIN ticket_tags tt ON t.id = tt.ticket_id
                WHERE tt.tag_id IN ($placeholders)";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute($tagIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get tag statistics
     */
    public function getTagStatistics() {
        $sql = "SELECT t.name, t.color, COUNT(tt.ticket_id) as ticket_count
                FROM tags t
                LEFT JOIN ticket_tags tt ON t.id = tt.tag_id
                GROUP BY t.id
                ORDER BY ticket_count DESC, t.name ASC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}