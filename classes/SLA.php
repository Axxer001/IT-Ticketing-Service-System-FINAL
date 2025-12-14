<?php
require_once "Database.php";

/**
 * SLA (Service Level Agreement) Management Class
 * Tracks response and resolution times for tickets
 */
class SLA {
    private $db;
    
    // SLA targets in hours based on priority
    private $slaTargets = [
        'critical' => ['response' => 1, 'resolution' => 4],
        'high' => ['response' => 4, 'resolution' => 24],
        'medium' => ['response' => 8, 'resolution' => 48],
        'low' => ['response' => 24, 'resolution' => 120]
    ];
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Get tickets at risk or breached
     */
    public function getAtRiskTickets() {
        $sql = "SELECT t.*, 
                e.first_name, e.last_name,
                sp.provider_name,
                TIMESTAMPDIFF(HOUR, t.created_at, NOW()) as hours_elapsed,
                TIMESTAMPDIFF(HOUR, t.created_at, t.assigned_at) as response_time,
                TIMESTAMPDIFF(HOUR, t.assigned_at, t.resolved_at) as resolution_time
                FROM tickets t
                LEFT JOIN employees e ON t.employee_id = e.id
                LEFT JOIN service_providers sp ON t.assigned_provider_id = sp.id
                WHERE t.status IN ('pending', 'assigned', 'in_progress')
                ORDER BY t.priority DESC, t.created_at ASC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $atRisk = [];
        foreach ($tickets as $ticket) {
            $sla = $this->calculateSLA($ticket);
            if ($sla['response']['status'] === 'breached' || 
                $sla['response']['status'] === 'at_risk' ||
                $sla['resolution']['status'] === 'breached' ||
                $sla['resolution']['status'] === 'at_risk') {
                $ticket['sla'] = $sla;
                $atRisk[] = $ticket;
            }
        }
        
        return $atRisk;
    }
    
    /**
     * Calculate SLA status for a ticket
     */
    public function calculateSLA($ticket) {
        $priority = $ticket['priority'];
        $targets = $this->slaTargets[$priority];
        
        // Response SLA
        $responseActual = $ticket['response_time'] ?? $ticket['hours_elapsed'];
        $responseTarget = $targets['response'];
        $responseStatus = $this->getSLAStatus($responseActual, $responseTarget);
        
        // Resolution SLA
        $resolutionActual = $ticket['resolution_time'] ?? $ticket['hours_elapsed'];
        $resolutionTarget = $targets['resolution'];
        $resolutionStatus = $this->getSLAStatus($resolutionActual, $resolutionTarget);
        
        return [
            'response' => [
                'actual' => round($responseActual, 1),
                'target' => $responseTarget,
                'status' => $responseStatus,
                'percentage' => round(($responseActual / $responseTarget) * 100, 1)
            ],
            'resolution' => [
                'actual' => round($resolutionActual, 1),
                'target' => $resolutionTarget,
                'status' => $resolutionStatus,
                'percentage' => round(($resolutionActual / $resolutionTarget) * 100, 1)
            ]
        ];
    }
    
    /**
     * Determine SLA status
     */
    private function getSLAStatus($actual, $target) {
        $percentage = ($actual / $target) * 100;
        
        if ($percentage >= 100) {
            return 'breached';
        } elseif ($percentage >= 80) {
            return 'at_risk';
        } else {
            return 'on_track';
        }
    }
    
    /**
     * Get SLA compliance statistics
     */
    public function getComplianceStats() {
        $sql = "SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN t.assigned_at IS NOT NULL THEN 1 ELSE 0 END) as responded_tickets,
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved_tickets,
                AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.assigned_at)) as avg_response_time,
                AVG(TIMESTAMPDIFF(HOUR, t.assigned_at, t.resolved_at)) as avg_resolution_time
                FROM tickets t
                WHERE t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate compliance percentages
        $responseCompliance = 0;
        $resolutionCompliance = 0;
        
        if ($stats['responded_tickets'] > 0) {
            $sql = "SELECT t.priority,
                    TIMESTAMPDIFF(HOUR, t.created_at, t.assigned_at) as response_time
                    FROM tickets t
                    WHERE t.assigned_at IS NOT NULL
                    AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute();
            $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $compliant = 0;
            foreach ($responses as $r) {
                if ($r['response_time'] <= $this->slaTargets[$r['priority']]['response']) {
                    $compliant++;
                }
            }
            $responseCompliance = round(($compliant / count($responses)) * 100, 1);
        }
        
        if ($stats['resolved_tickets'] > 0) {
            $sql = "SELECT t.priority,
                    TIMESTAMPDIFF(HOUR, t.assigned_at, t.resolved_at) as resolution_time
                    FROM tickets t
                    WHERE t.resolved_at IS NOT NULL
                    AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute();
            $resolutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $compliant = 0;
            foreach ($resolutions as $r) {
                if ($r['resolution_time'] <= $this->slaTargets[$r['priority']]['resolution']) {
                    $compliant++;
                }
            }
            $resolutionCompliance = round(($compliant / count($resolutions)) * 100, 1);
        }
        
        return [
            'response_compliance' => $responseCompliance,
            'resolution_compliance' => $resolutionCompliance,
            'avg_response_hours' => round($stats['avg_response_time'] ?? 0, 1),
            'avg_resolution_hours' => round($stats['avg_resolution_time'] ?? 0, 1)
        ];
    }
}