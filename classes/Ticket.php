<?php
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/AuditLog.php";
require_once __DIR__ . "/Notification.php";
require_once __DIR__ . "/EmailNotification.php";

/**
 * FIXED Ticket Management Class with Email Notifications
 * CRITICAL FIX: Removed gmail_address column completely
 * Now uses bound_gmail from users table properly
 */
class Ticket {
    private $db;
    private $audit;
    private $notification;
    private $emailNotification;
    
    private $allowedFileTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    private $maxFileSize = 10485760; // 10MB
    private $maxFiles = 5;
    
    public function __construct() {
        $this->db = new Database();
        $this->audit = new AuditLog();
        $this->notification = new Notification();
        $this->emailNotification = new EmailNotification();
    }
    
    private function generateTicketNumber() {
        $prefix = "TKT";
        $date = date('Ymd');
        $random = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
        return "{$prefix}-{$date}-{$random}";
    }
    
    private function validateFile($file) {
        $errors = [];
        
        if ($file['size'] > $this->maxFileSize) {
            $errors[] = "File {$file['name']} exceeds maximum size of 10MB";
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedFileTypes)) {
            $errors[] = "File type .{$extension} is not allowed";
        }
        
        return $errors;
    }
    
    public function create($employeeId, $data, $attachments = []) {
        try {
            // Quick validation
            if (empty($data['device_type_id']) || empty($data['device_name']) || empty($data['issue_description'])) {
                return ['success' => false, 'message' => "Required fields are missing"];
            }
            
            // Validate priority
            $validPriorities = ['low', 'medium', 'high', 'critical'];
            if (!in_array($data['priority'] ?? 'medium', $validPriorities)) {
                $data['priority'] = 'medium';
            }
            
            // Quick file validation
            if (count($attachments) > $this->maxFiles) {
                return ['success' => false, 'message' => "Maximum {$this->maxFiles} files allowed"];
            }
            
            foreach ($attachments as $file) {
                $validationErrors = $this->validateFile($file);
                if (!empty($validationErrors)) {
                    return ['success' => false, 'message' => implode(', ', $validationErrors)];
                }
            }
            
            $this->db->beginTransaction();
            
            $ticketNumber = $this->generateTicketNumber();
            
            // FIXED: Get employee info WITH bound_gmail from users table
            $deptSql = "SELECT e.department_id, e.user_id, e.first_name, e.last_name,
                               u.bound_gmail, u.email
                        FROM employees e
                        JOIN users u ON e.user_id = u.id
                        WHERE e.id = ?";
            $deptStmt = $this->db->connect()->prepare($deptSql);
            $deptStmt->execute([$employeeId]);
            $employee = $deptStmt->fetch();
            
            if (!$employee) {
                $this->db->rollback();
                return ['success' => false, 'message' => "Employee not found"];
            }
            
            // Get device type name for email
            $deviceSql = "SELECT type_name FROM device_types WHERE id = ?";
            $deviceStmt = $this->db->connect()->prepare($deviceSql);
            $deviceStmt->execute([$data['device_type_id']]);
            $deviceType = $deviceStmt->fetch();
            
            // FIXED: Insert ticket WITHOUT gmail_address column
            $sql = "INSERT INTO tickets 
                    (ticket_number, employee_id, department_id, device_type_id, 
                     device_name, issue_description, priority) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([
                $ticketNumber,
                $employeeId,
                $employee['department_id'],
                $data['device_type_id'],
                htmlspecialchars($data['device_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($data['issue_description'], ENT_QUOTES, 'UTF-8'),
                $data['priority']
            ]);
            
            $ticketId = $this->db->lastInsertId();
            
            // Handle attachments if any
            if (!empty($attachments)) {
                $this->saveAttachments($ticketId, $attachments);
            }
            
            // Single update log
            $this->logTicketUpdate($ticketId, $employee['user_id'], 'comment', 'Ticket created');
            
            // In-app notification (async)
            $this->notification->notifyAdminNewTicketAsync($ticketId, $ticketNumber);
            
            // FIXED: Send email using bound_gmail from users table
            $employeeName = $employee['first_name'] . ' ' . $employee['last_name'];
            $emailAddress = $employee['bound_gmail'] ?: $employee['email']; // Fallback to email if no bound_gmail
            
            if (!empty($emailAddress)) {
                error_log("📧 Sending ticket creation email to: " . $emailAddress);
                $emailResult = $this->emailNotification->notifyTicketCreated(
                    $ticketNumber,
                    $emailAddress,
                    $employeeName,
                    $deviceType['type_name'],
                    $data['priority']
                );
                
                if ($emailResult) {
                    error_log("✅ Email sent successfully to: " . $emailAddress);
                } else {
                    error_log("❌ Failed to send email to: " . $emailAddress);
                }
            } else {
                error_log("⚠️ No email address found for employee: " . $employeeName);
            }
            
            $this->db->commit();
            
            return ['success' => true, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("❌ Ticket creation error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getById($ticketId) {
        // FIXED: Include bound_gmail in query
        $sql = "SELECT t.*, 
                e.first_name, e.last_name, e.contact_number, e.user_id as employee_user_id,
                u.email as employee_email, u.bound_gmail as employee_bound_gmail,
                d.name as department_name, d.category as department_category,
                dt.type_name as device_type_name,
                sp.provider_name, sp.user_id as provider_user_id,
                spu.email as provider_email, spu.bound_gmail as provider_bound_gmail
                FROM tickets t
                JOIN employees e ON t.employee_id = e.id
                JOIN users u ON e.user_id = u.id
                JOIN departments d ON t.department_id = d.id
                JOIN device_types dt ON t.device_type_id = dt.id
                LEFT JOIN service_providers sp ON t.assigned_provider_id = sp.id
                LEFT JOIN users spu ON sp.user_id = spu.id
                WHERE t.id = ?";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        
        if ($ticket) {
            $ticket['attachments'] = $this->getAttachments($ticketId);
            $ticket['updates'] = $this->getTicketUpdates($ticketId);
            $ticket['rating'] = $this->getTicketRating($ticketId);
        }
        
        return $ticket;
    }
    
    public function getTickets($filters = [], $limit = 50, $offset = 0) {
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
        
        if (!empty($filters['employee_id'])) {
            $sql .= " AND t.employee_id = ?";
            $params[] = $filters['employee_id'];
        }
        
        if (!empty($filters['provider_id'])) {
            $sql .= " AND t.assigned_provider_id = ?";
            $params[] = $filters['provider_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['priority'])) {
            $sql .= " AND t.priority = ?";
            $params[] = $filters['priority'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (t.ticket_number LIKE ? OR t.issue_description LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function assign($ticketId, $providerId, $adminUserId) {
        try {
            $this->db->beginTransaction();
            
            // FIXED: Get ticket and email info with bound_gmail
            $sql = "SELECT t.ticket_number, t.priority, 
                           e.first_name, e.last_name, e.user_id as employee_user_id,
                           u.bound_gmail as employee_bound_gmail, u.email as employee_email,
                           dt.type_name as device_type_name,
                           sp.provider_name, 
                           spu.email as provider_email, spu.bound_gmail as provider_bound_gmail
                    FROM tickets t
                    JOIN employees e ON t.employee_id = e.id
                    JOIN users u ON e.user_id = u.id
                    JOIN device_types dt ON t.device_type_id = dt.id
                    JOIN service_providers sp ON sp.id = ?
                    JOIN users spu ON sp.user_id = spu.id
                    WHERE t.id = ?";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$providerId, $ticketId]);
            $ticket = $stmt->fetch();
            
            if (!$ticket) {
                $this->db->rollback();
                return ['success' => false, 'message' => "Ticket not found"];
            }
            
            // Update ticket status
            $sql = "UPDATE tickets 
                    SET assigned_provider_id = ?, status = 'assigned', 
                        assigned_at = NOW(), updated_at = NOW() 
                    WHERE id = ?";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$providerId, $ticketId]);
            
            // Log update
            $this->logTicketUpdate($ticketId, $adminUserId, 'assignment', "Ticket assigned to service provider");
            
            // In-app notifications (async)
            $this->notification->notifyTicketAssignmentAsync($ticketId, $providerId, $ticket['ticket_number']);
            $this->notification->notifyTicketStatusChangeAsync($ticketId, $ticket['employee_user_id'], $ticket['ticket_number'], 'assigned');
            
            // FIXED: Send emails with proper email selection
            $employeeName = $ticket['first_name'] . ' ' . $ticket['last_name'];
            $employeeEmail = $ticket['employee_bound_gmail'] ?: $ticket['employee_email'];
            $providerEmail = $ticket['provider_bound_gmail'] ?: $ticket['provider_email'];
            
            // Email to employee
            if (!empty($employeeEmail)) {
                error_log("📧 Sending assignment email to employee: " . $employeeEmail);
                $result = $this->emailNotification->notifyTicketAssigned(
                    $ticket['ticket_number'],
                    $employeeEmail,
                    $employeeName,
                    $ticket['provider_name']
                );
                error_log($result ? "✅ Email sent" : "❌ Email failed");
            }
            
            // Email to provider
            if (!empty($providerEmail)) {
                error_log("📧 Sending assignment email to provider: " . $providerEmail);
                $result = $this->emailNotification->notifyProviderNewTicket(
                    $ticket['ticket_number'],
                    $providerEmail,
                    $ticket['provider_name'],
                    $employeeName,
                    $ticket['device_type_name'],
                    $ticket['priority']
                );
                error_log($result ? "✅ Email sent" : "❌ Email failed");
            }
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("❌ Ticket assignment error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function updateStatus($ticketId, $status, $userId, $comment = null) {
        try {
            $validStatuses = ['assigned', 'in_progress', 'resolved', 'closed'];
            if (!in_array($status, $validStatuses)) {
                return ['success' => false, 'message' => "Invalid status"];
            }
            
            $this->db->beginTransaction();
            
            // FIXED: Get status and bound_gmail
            $sql = "SELECT t.status, t.ticket_number, 
                           e.user_id as employee_user_id, e.first_name, e.last_name,
                           u.bound_gmail as employee_bound_gmail, u.email as employee_email
                    FROM tickets t
                    JOIN employees e ON t.employee_id = e.id
                    JOIN users u ON e.user_id = u.id
                    WHERE t.id = ?";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch();
            
            if (!$ticket) {
                $this->db->rollback();
                return ['success' => false, 'message' => "Ticket not found"];
            }
            
            $oldStatus = $ticket['status'];
            
            // Update status
            $sql = "UPDATE tickets SET status = ?, updated_at = NOW()";
            $params = [$status];
            
            if ($status === 'resolved') {
                $sql .= ", resolved_at = NOW()";
            } elseif ($status === 'closed') {
                $sql .= ", closed_at = NOW()";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $ticketId;
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute($params);
            
            // Log update
            $message = $comment ?? "Status changed from {$oldStatus} to {$status}";
            $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $this->logTicketUpdate($ticketId, $userId, 'status_change', $message, $oldStatus, $status);
            
            // In-app notification (async)
            $this->notification->notifyTicketStatusChangeAsync($ticketId, $ticket['employee_user_id'], $ticket['ticket_number'], $status);
            
            // FIXED: Send email with proper email selection
            $employeeEmail = $ticket['employee_bound_gmail'] ?: $ticket['employee_email'];
            
            if (!empty($employeeEmail)) {
                $employeeName = $ticket['first_name'] . ' ' . $ticket['last_name'];
                error_log("📧 Sending status change email to: " . $employeeEmail);
                $result = $this->emailNotification->notifyTicketStatusChange(
                    $ticket['ticket_number'],
                    $employeeEmail,
                    $employeeName,
                    $oldStatus,
                    $status,
                    $comment
                );
                error_log($result ? "✅ Email sent" : "❌ Email failed");
            }
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("❌ Ticket status update error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function addComment($ticketId, $userId, $comment) {
        $comment = htmlspecialchars($comment, ENT_QUOTES, 'UTF-8');
        
        // FIXED: Send email notification with bound_gmail
        try {
            $sql = "SELECT t.ticket_number, t.employee_id,
                           e.first_name, e.last_name,
                           u.bound_gmail as employee_bound_gmail, u.email as employee_email,
                           cu.email as commenter_email, cu.user_type,
                           CASE 
                               WHEN cu.user_type = 'employee' THEN CONCAT(emp.first_name, ' ', emp.last_name)
                               WHEN cu.user_type = 'service_provider' THEN sp.provider_name
                               ELSE 'Admin'
                           END as commenter_name
                    FROM tickets t
                    JOIN employees e ON t.employee_id = e.id
                    JOIN users u ON e.user_id = u.id
                    JOIN users cu ON cu.id = ?
                    LEFT JOIN employees emp ON emp.user_id = cu.id
                    LEFT JOIN service_providers sp ON sp.user_id = cu.id
                    WHERE t.id = ?";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$userId, $ticketId]);
            $info = $stmt->fetch();
            
            if ($info) {
                $recipientEmail = $info['employee_bound_gmail'] ?: $info['employee_email'];
                
                if (!empty($recipientEmail)) {
                    $recipientName = $info['first_name'] . ' ' . $info['last_name'];
                    error_log("📧 Sending comment email to: " . $recipientEmail);
                    $result = $this->emailNotification->notifyNewComment(
                        $info['ticket_number'],
                        $recipientEmail,
                        $recipientName,
                        $info['commenter_name'],
                        $comment
                    );
                    error_log($result ? "✅ Email sent" : "❌ Email failed");
                }
            }
        } catch (Exception $e) {
            error_log("❌ Comment email notification error: " . $e->getMessage());
        }
        
        return $this->logTicketUpdate($ticketId, $userId, 'comment', $comment);
    }
    
    private function logTicketUpdate($ticketId, $userId, $type, $message, $oldValue = null, $newValue = null) {
        $sql = "INSERT INTO ticket_updates 
                (ticket_id, user_id, update_type, message, old_value, new_value) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->connect()->prepare($sql);
        return $stmt->execute([$ticketId, $userId, $type, $message, $oldValue, $newValue]);
    }
    
    public function getTicketUpdates($ticketId) {
        $sql = "SELECT tu.*, u.email, u.user_type 
                FROM ticket_updates tu 
                JOIN users u ON tu.user_id = u.id 
                WHERE tu.ticket_id = ? 
                ORDER BY tu.created_at ASC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$ticketId]);
        
        return $stmt->fetchAll();
    }
    
    private function saveAttachments($ticketId, $files) {
        $uploadDir = __DIR__ . "/../uploads/tickets/";
        
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        foreach ($files as $file) {
            if ($file['error'] === UPLOAD_ERR_OK && !empty($file['tmp_name'])) {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fileName = uniqid() . '_' . time() . '.' . $extension;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $sql = "INSERT INTO ticket_attachments 
                            (ticket_id, file_name, file_path, file_type, file_size) 
                            VALUES (?, ?, ?, ?, ?)";
                    
                    $stmt = $this->db->connect()->prepare($sql);
                    $stmt->execute([
                        $ticketId,
                        htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'),
                        'uploads/tickets/' . $fileName,
                        $file['type'],
                        $file['size']
                    ]);
                }
            }
        }
    }
    
    public function getAttachments($ticketId) {
        $sql = "SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY uploaded_at ASC";
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }
    
    public function submitRating($ticketId, $employeeId, $providerId, $rating, $feedback = null) {
        try {
            if (!is_numeric($rating) || $rating < 1 || $rating > 5) {
                return ['success' => false, 'message' => "Invalid rating value"];
            }
            
            $this->db->beginTransaction();
            
            $sql = "INSERT INTO ticket_ratings (ticket_id, provider_id, employee_id, rating, feedback) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([
                $ticketId, 
                $providerId, 
                $employeeId, 
                $rating, 
                $feedback ? htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') : null
            ]);
            
            $this->updateProviderRating($providerId);
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Rating submission error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function updateProviderRating($providerId) {
        $sql = "UPDATE service_providers SET 
                rating_average = (SELECT AVG(rating) FROM ticket_ratings WHERE provider_id = ?),
                total_ratings = (SELECT COUNT(*) FROM ticket_ratings WHERE provider_id = ?)
                WHERE id = ?";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$providerId, $providerId, $providerId]);
    }
    
    public function getTicketRating($ticketId) {
        $sql = "SELECT * FROM ticket_ratings WHERE ticket_id = ?";
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetch();
    }
    
    public function getDeviceTypes() {
        $sql = "SELECT * FROM device_types ORDER BY type_name";
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getStatistics($filters = []) {
        $stats = [];
        
        $sql = "SELECT COUNT(*) as total FROM tickets WHERE 1=1";
        $params = [];
        
        if (!empty($filters['employee_id'])) {
            $sql .= " AND employee_id = ?";
            $params[] = $filters['employee_id'];
        }
        
        if (!empty($filters['provider_id'])) {
            $sql .= " AND assigned_provider_id = ?";
            $params[] = $filters['provider_id'];
        }
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute($params);
        $stats['total'] = $stmt->fetch()['total'];
        
        $sql = "SELECT status, COUNT(*) as count FROM tickets WHERE 1=1";
        if (!empty($params)) {
            if (!empty($filters['employee_id'])) {
                $sql .= " AND employee_id = ?";
            }
            if (!empty($filters['provider_id'])) {
                $sql .= " AND assigned_provider_id = ?";
            }
        }
        $sql .= " GROUP BY status";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute($params);
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $sql = "SELECT priority, COUNT(*) as count FROM tickets WHERE 1=1";
        if (!empty($params)) {
            if (!empty($filters['employee_id'])) {
                $sql .= " AND employee_id = ?";
            }
            if (!empty($filters['provider_id'])) {
                $sql .= " AND assigned_provider_id = ?";
            }
        }
        $sql .= " GROUP BY priority";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute($params);
        $stats['by_priority'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        return $stats;
    }
}