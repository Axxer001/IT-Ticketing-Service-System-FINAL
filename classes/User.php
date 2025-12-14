<?php
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/AuditLog.php";

/**
 * User Management Class
 * UPDATED: Added email verification system
 */
class User {
    private $db;
    private $audit;
    
    public function __construct() {
        $this->db = new Database();
        $this->audit = new AuditLog();
    }
    
    /**
     * User login
     */
    public function login($email, $password) {
        try {
            $sql = "SELECT id, email, password_hash, user_type, theme, is_active FROM users WHERE email = ?";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                error_log("Login failed: User not found for email: " . $email);
                return false;
            }
            
            if ($user['is_active'] != 1) {
                error_log("Login failed: User account is inactive for email: " . $email);
                return false;
            }
            
            if (password_verify($password, $user['password_hash'])) {
                $updateSql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                $updateStmt = $this->db->connect()->prepare($updateSql);
                $updateStmt->execute([$user['id']]);
                
                try {
                    $this->audit->log($user['id'], 'user_login', 'users', $user['id']);
                } catch (Exception $e) {
                    error_log("Audit log failed: " . $e->getMessage());
                }
                
                unset($user['password_hash']);
                return $user;
            } else {
                error_log("Login failed: Invalid password for email: " . $email);
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * User registration (OLD METHOD - DEPRECATED)
     * Kept for backwards compatibility but not used anymore
     */
    public function register($email, $password, $userType, $additionalData = []) {
        try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters");
            }
            
            $checkSql = "SELECT id FROM users WHERE email = ?";
            $checkStmt = $this->db->connect()->prepare($checkSql);
            $checkStmt->execute([$email]);
            
            if ($checkStmt->fetch()) {
                throw new Exception("Email already registered");
            }
            
            $this->db->beginTransaction();
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (email, password_hash, user_type) VALUES (?, ?, ?)";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$email, $hashedPassword, $userType]);
            $userId = $this->db->lastInsertId();
            
            if ($userType === 'employee') {
                if (empty($additionalData['first_name']) || empty($additionalData['last_name']) || empty($additionalData['department_id'])) {
                    throw new Exception("Missing required employee information");
                }
                
                $profileSql = "INSERT INTO employees (user_id, first_name, last_name, department_id, contact_number) 
                               VALUES (?, ?, ?, ?, ?)";
                $profileStmt = $this->db->connect()->prepare($profileSql);
                $profileStmt->execute([
                    $userId,
                    $additionalData['first_name'],
                    $additionalData['last_name'],
                    $additionalData['department_id'],
                    $additionalData['contact_number'] ?? null
                ]);
            } elseif ($userType === 'service_provider') {
                if (empty($additionalData['provider_name'])) {
                    throw new Exception("Missing required provider information");
                }
                
                $profileSql = "INSERT INTO service_providers (user_id, provider_name, specialization, contact_number) 
                               VALUES (?, ?, ?, ?)";
                $profileStmt = $this->db->connect()->prepare($profileSql);
                $profileStmt->execute([
                    $userId,
                    $additionalData['provider_name'],
                    $additionalData['specialization'] ?? null,
                    $additionalData['contact_number'] ?? null
                ]);
            }
            
            $this->db->commit();
            
            try {
                $this->audit->log($userId, 'user_registered', 'users', $userId);
            } catch (Exception $e) {
                error_log("Audit log failed: " . $e->getMessage());
            }
            
            return $userId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Registration error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * NEW: Create account verification request instead of direct registration
     */
    public function createVerificationRequest($email, $password, $userType, $boundGmail, $additionalData = []) {
        try {
            // Validate inputs
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            if (!empty($boundGmail) && !filter_var($boundGmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid Gmail format");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters");
            }
            
            // Check if email already exists in users OR pending verifications
            $checkSql = "SELECT id FROM users WHERE email = ? 
                         UNION 
                         SELECT id FROM account_verifications WHERE email = ? AND status = 'pending'";
            $checkStmt = $this->db->connect()->prepare($checkSql);
            $checkStmt->execute([$email, $email]);
            
            if ($checkStmt->fetch()) {
                throw new Exception("Email already registered or pending verification");
            }
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert verification request
            $sql = "INSERT INTO account_verifications 
                    (email, bound_gmail, password_hash, user_type, first_name, last_name, 
                     department_id, provider_name, specialization, contact_number) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([
                $email,
                $boundGmail,
                $hashedPassword,
                $userType,
                $additionalData['first_name'] ?? null,
                $additionalData['last_name'] ?? null,
                $additionalData['department_id'] ?? null,
                $additionalData['provider_name'] ?? null,
                $additionalData['specialization'] ?? null,
                $additionalData['contact_number'] ?? null
            ]);
            
            $verificationId = $this->db->lastInsertId();
            
            // Notify all admins
            $this->notifyAdminsNewVerificationRequest($verificationId, $email, $userType);
            
            return $verificationId;
            
        } catch (Exception $e) {
            error_log("Verification request error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * NEW: Notify all admins about new verification request
     */
    private function notifyAdminsNewVerificationRequest($verificationId, $email, $userType) {
        require_once __DIR__ . "/Notification.php";
        require_once __DIR__ . "/EmailNotification.php";
        
        $notificationObj = new Notification();
        $emailNotificationObj = new EmailNotification();
        
        // Get all admins
        $sql = "SELECT id, email, bound_gmail FROM users WHERE user_type = 'admin' AND is_active = 1";
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            // In-app notification
            $notificationObj->create(
                $admin['id'],
                'New Account Verification Request',
                "New {$userType} account ({$email}) is requesting verification",
                null,
                'verification_request'
            );
            
            // Email notification (if admin has bound Gmail)
            if (!empty($admin['bound_gmail'])) {
                $emailNotificationObj->notifyAdminNewVerificationRequest(
                    $admin['bound_gmail'],
                    $email,
                    $userType
                );
            }
        }
    }
    
    /**
     * NEW: Get all pending verification requests
     */
    public function getPendingVerifications() {
        $sql = "SELECT av.*, d.name as department_name 
                FROM account_verifications av
                LEFT JOIN departments d ON av.department_id = d.id
                WHERE av.status = 'pending'
                ORDER BY av.created_at DESC";
        
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * NEW: Get pending verification count for admin
     */
    public function getPendingVerificationCount() {
        $sql = "SELECT COUNT(*) as count FROM account_verifications WHERE status = 'pending'";
        $stmt = $this->db->connect()->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }
    
    /**
     * NEW: Approve verification request and create actual user account
     */
    public function approveVerification($verificationId, $adminId, $adminComment = null) {
        try {
            $this->db->beginTransaction();
            
            // Get verification request
            $sql = "SELECT * FROM account_verifications WHERE id = ? AND status = 'pending'";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$verificationId]);
            $verification = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$verification) {
                throw new Exception("Verification request not found or already processed");
            }
            
            // Create user account
            $userSql = "INSERT INTO users (email, bound_gmail, password_hash, user_type, is_active) 
                        VALUES (?, ?, ?, ?, 1)";
            $userStmt = $this->db->connect()->prepare($userSql);
            $userStmt->execute([
                $verification['email'],
                $verification['bound_gmail'],
                $verification['password_hash'],
                $verification['user_type']
            ]);
            
            $userId = $this->db->lastInsertId();
            
            // Create profile
            if ($verification['user_type'] === 'employee') {
                $profileSql = "INSERT INTO employees (user_id, first_name, last_name, department_id, contact_number) 
                               VALUES (?, ?, ?, ?, ?)";
                $profileStmt = $this->db->connect()->prepare($profileSql);
                $profileStmt->execute([
                    $userId,
                    $verification['first_name'],
                    $verification['last_name'],
                    $verification['department_id'],
                    $verification['contact_number']
                ]);
            } elseif ($verification['user_type'] === 'service_provider') {
                $profileSql = "INSERT INTO service_providers (user_id, provider_name, specialization, contact_number) 
                               VALUES (?, ?, ?, ?)";
                $profileStmt = $this->db->connect()->prepare($profileSql);
                $profileStmt->execute([
                    $userId,
                    $verification['provider_name'],
                    $verification['specialization'],
                    $verification['contact_number']
                ]);
            }
            
            // Create user preferences
            $prefSql = "INSERT INTO user_preferences (user_id) VALUES (?)";
            $prefStmt = $this->db->connect()->prepare($prefSql);
            $prefStmt->execute([$userId]);
            
            // Update verification status
            $updateSql = "UPDATE account_verifications 
                          SET status = 'approved', admin_id = ?, admin_comment = ?, processed_at = NOW() 
                          WHERE id = ?";
            $updateStmt = $this->db->connect()->prepare($updateSql);
            $updateStmt->execute([$adminId, $adminComment, $verificationId]);
            
            $this->db->commit();
            
            // Send approval email
            if (!empty($verification['bound_gmail'])) {
                require_once __DIR__ . "/EmailNotification.php";
                $emailNotificationObj = new EmailNotification();
                
                $name = $verification['user_type'] === 'employee' 
                    ? ($verification['first_name'] . ' ' . $verification['last_name'])
                    : $verification['provider_name'];
                
                $emailNotificationObj->notifyAccountApproved(
                    $verification['bound_gmail'],
                    $name,
                    $verification['user_type']
                );
            }
            
            return ['success' => true, 'user_id' => $userId];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Approve verification error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * NEW: Reject verification request
     */
    public function rejectVerification($verificationId, $adminId, $adminComment) {
        try {
            $this->db->beginTransaction();
            
            // Get verification request
            $sql = "SELECT * FROM account_verifications WHERE id = ? AND status = 'pending'";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$verificationId]);
            $verification = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$verification) {
                throw new Exception("Verification request not found or already processed");
            }
            
            // Update verification status
            $updateSql = "UPDATE account_verifications 
                          SET status = 'rejected', admin_id = ?, admin_comment = ?, processed_at = NOW() 
                          WHERE id = ?";
            $updateStmt = $this->db->connect()->prepare($updateSql);
            $updateStmt->execute([$adminId, $adminComment, $verificationId]);
            
            $this->db->commit();
            
            // Send rejection email
            if (!empty($verification['bound_gmail'])) {
                require_once __DIR__ . "/EmailNotification.php";
                $emailNotificationObj = new EmailNotification();
                
                $name = $verification['user_type'] === 'employee' 
                    ? ($verification['first_name'] . ' ' . $verification['last_name'])
                    : $verification['provider_name'];
                
                $emailNotificationObj->notifyAccountRejected(
                    $verification['bound_gmail'],
                    $name,
                    $adminComment
                );
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Reject verification error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get user profile with related data
     */
    public function getUserProfile($userId) {
        try {
            $sql = "SELECT id, email, user_type, theme, bound_gmail, job_position, is_active, created_at, last_login 
                    FROM users WHERE id = ?";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return null;
            }
            
            $profile = null;
            if ($user['user_type'] === 'employee') {
                $profileSql = "SELECT e.*, d.name as department_name, d.category as department_category 
                               FROM employees e 
                               JOIN departments d ON e.department_id = d.id 
                               WHERE e.user_id = ?";
                $profileStmt = $this->db->connect()->prepare($profileSql);
                $profileStmt->execute([$userId]);
                $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($user['user_type'] === 'service_provider') {
                $profileSql = "SELECT * FROM service_providers WHERE user_id = ?";
                $profileStmt = $this->db->connect()->prepare($profileSql);
                $profileStmt->execute([$userId]);
                $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            return [
                'id' => $user['id'],
                'email' => $user['email'],
                'user_type' => $user['user_type'],
                'theme' => $user['theme'],
                'bound_gmail' => $user['bound_gmail'],
                'job_position' => $user['job_position'],
                'is_active' => $user['is_active'],
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login'],
                'profile' => $profile
            ];
            
        } catch (Exception $e) {
            error_log("Get profile error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update user preferences (theme)
     */
    public function updatePreferences($userId, $theme) {
        try {
            if (!in_array($theme, ['light', 'dark'])) {
                return false;
            }
            
            $sql = "UPDATE users SET theme = ? WHERE id = ?";
            $stmt = $this->db->connect()->prepare($sql);
            return $stmt->execute([$theme, $userId]);
        } catch (Exception $e) {
            error_log("Update preferences error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update Gmail binding
     */
    public function updateGmailBinding($userId, $gmail) {
        try {
            if (!empty($gmail) && !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid email format'];
            }
            
            $sql = "UPDATE users SET bound_gmail = ? WHERE id = ?";
            $stmt = $this->db->connect()->prepare($sql);
            $result = $stmt->execute([$gmail, $userId]);
            
            if ($result) {
                return ['success' => true, 'message' => 'Gmail address updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update Gmail address'];
            }
        } catch (Exception $e) {
            error_log("Update Gmail error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred'];
        }
    }
    
    /**
     * Update job position (employee only)
     */
    public function updateJobPosition($userId, $jobPosition) {
        try {
            $sql = "UPDATE users SET job_position = ? WHERE id = ?";
            $stmt = $this->db->connect()->prepare($sql);
            $result = $stmt->execute([$jobPosition, $userId]);
            
            if ($result) {
                return ['success' => true, 'message' => 'Job position updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update job position'];
            }
        } catch (Exception $e) {
            error_log("Update job position error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred'];
        }
    }
    
    /**
     * Get user statistics
     */
    public function getUserStatistics($userId) {
        try {
            $user = $this->getUserProfile($userId);
            if (!$user) return null;
            
            $stats = [];
            
            if ($user['user_type'] === 'employee') {
                $sql = "SELECT 
                        COUNT(*) as total_tickets,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status IN ('assigned', 'in_progress') THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
                        FROM tickets 
                        WHERE employee_id = ?";
                
                $stmt = $this->db->connect()->prepare($sql);
                $stmt->execute([$user['profile']['id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
            } elseif ($user['user_type'] === 'service_provider') {
                $sql = "SELECT 
                        COUNT(*) as total_tickets,
                        SUM(CASE WHEN status IN ('assigned', 'in_progress') THEN 1 ELSE 0 END) as active,
                        AVG(CASE WHEN status = 'resolved' THEN TIMESTAMPDIFF(HOUR, assigned_at, resolved_at) END) as avg_resolution_hours
                        FROM tickets 
                        WHERE assigned_provider_id = ?";
                
                $stmt = $this->db->connect()->prepare($sql);
                $stmt->execute([$user['profile']['id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $ratingSql = "SELECT rating_average, total_ratings FROM service_providers WHERE id = ?";
                $ratingStmt = $this->db->connect()->prepare($ratingSql);
                $ratingStmt->execute([$user['profile']['id']]);
                $rating = $ratingStmt->fetch(PDO::FETCH_ASSOC);
                
                $stats['avg_rating'] = $rating['rating_average'] ?? 0;
                $stats['total_ratings'] = $rating['total_ratings'] ?? 0;
                
            } elseif ($user['user_type'] === 'admin') {
                $sql = "SELECT 
                        COUNT(*) as total_tickets,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status IN ('assigned', 'in_progress') THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
                        FROM tickets";
                
                $stmt = $this->db->connect()->prepare($sql);
                $stmt->execute();
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Get statistics error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all departments grouped by category
     */
    public function getDepartmentsByCategory() {
        try {
            $sql = "SELECT * FROM departments ORDER BY category, name";
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute();
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $grouped = [];
            foreach ($departments as $dept) {
                $grouped[$dept['category']][] = $dept;
            }
            
            return $grouped;
        } catch (Exception $e) {
            error_log("Get departments error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get available service providers
     */
    public function getAvailableServiceProviders() {
        try {
            $sql = "SELECT sp.*, 
                    (SELECT COUNT(*) FROM tickets WHERE assigned_provider_id = sp.id AND status IN ('assigned', 'in_progress')) as current_assignments
                    FROM service_providers sp
                    JOIN users u ON sp.user_id = u.id
                    WHERE u.is_active = 1
                    ORDER BY current_assignments ASC, sp.rating_average DESC";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get service providers error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all service providers
     */
    public function getAllServiceProviders() {
        try {
            $sql = "SELECT sp.*, u.email, u.is_active 
                    FROM service_providers sp
                    JOIN users u ON sp.user_id = u.id
                    ORDER BY sp.provider_name";
            
            $stmt = $this->db->connect()->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get all providers error: " . $e->getMessage());
            return [];
        }
    }
}