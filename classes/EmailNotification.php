<?php
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/Database.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * FIXED Email Notification Handler using PHPMailer
 * 
 * CRITICAL FIXES:
 * 1. Better error logging with detailed messages
 * 2. Fixed SMTP configuration for Brevo
 * 3. Improved SSL/TLS handling
 * 4. Better exception handling
 * 5. Added connection testing
 */
class EmailNotification {
    private $db;
    private $mailer;
    private $enabled;
    private $debug;
    
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $fromEmail;
    private $fromName;
    
    public function __construct() {
        $this->db = new Database();
        $this->loadConfig();
        
        if ($this->enabled) {
            $this->setupMailer();
        } else {
            error_log("⚠️ EMAIL DISABLED: Emails will not be sent. Set 'enabled' => true in email_config.php");
        }
    }
    
    private function loadConfig() {
        $configFile = __DIR__ . '/email_config.php';
        
        error_log("📂 Loading email config from: " . $configFile);
        
        if (!file_exists($configFile)) {
            error_log("❌ CRITICAL ERROR: Email config file not found at: " . $configFile);
            $this->enabled = false;
            return;
        }
        
        try {
            $config = require $configFile;
            
            $this->smtpHost = $config['smtp_host'] ?? '';
            $this->smtpPort = $config['smtp_port'] ?? 587;
            $this->smtpUsername = $config['smtp_username'] ?? '';
            $this->smtpPassword = $config['smtp_password'] ?? '';
            $this->fromEmail = $config['from_email'] ?? '';
            $this->fromName = $config['from_name'] ?? 'Nexon IT Support';
            $this->enabled = $config['enabled'] ?? false;
            $this->debug = $config['debug'] ?? false;
            
            error_log("✅ Config loaded - Host: {$this->smtpHost}, Port: {$this->smtpPort}, From: {$this->fromEmail}, Enabled: " . ($this->enabled ? 'YES' : 'NO'));
            
            if (empty($this->smtpUsername) || empty($this->smtpPassword)) {
                error_log("❌ CRITICAL ERROR: SMTP username or password is empty!");
                $this->enabled = false;
            }
            
            if (empty($this->fromEmail)) {
                error_log("❌ CRITICAL ERROR: From email is empty!");
                $this->enabled = false;
            }
            
        } catch (Exception $e) {
            error_log("❌ CRITICAL ERROR loading config: " . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    private function setupMailer() {
        try {
            $this->mailer = new PHPMailer(true);
            
            // SMTP Configuration for Brevo
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->smtpHost;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->smtpUsername;
            $this->mailer->Password = $this->smtpPassword;
            
            // CRITICAL FIX: Use STARTTLS for Brevo (port 587)
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $this->smtpPort;
            
            // Debug output
            if ($this->debug) {
                $this->mailer->SMTPDebug = 2; // Enable verbose debug output
                $this->mailer->Debugoutput = function($str, $level) {
                    error_log("📧 SMTP DEBUG [$level]: $str");
                };
            }
            
            // FIXED: Permissive SSL options for XAMPP/Localhost compatibility
            $this->mailer->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Set From address
            $this->mailer->setFrom($this->fromEmail, $this->fromName);
            
            // Character encoding
            $this->mailer->CharSet = 'UTF-8';
            
            // Timeout
            $this->mailer->Timeout = 30;
            
            error_log("✅ PHPMailer configured successfully for Brevo SMTP");
            
        } catch (Exception $e) {
            error_log("❌ CRITICAL ERROR: PHPMailer setup failed - " . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    public function sendEmail($toEmail, $subject, $message) {
        if (!$this->enabled) {
            $msg = "⚠️ EMAIL DISABLED - Would send to: $toEmail, Subject: $subject";
            error_log($msg);
            return true; // Return true so system doesn't break
        }
        
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("❌ ERROR: Invalid email address - $toEmail");
            return false;
        }
        
        try {
            // Clear previous recipients
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearReplyTos();
            $this->mailer->clearAllRecipients();
            
            // Set recipient
            $this->mailer->addAddress($toEmail);
            
            // Set content
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $this->getEmailTemplate($subject, $message);
            $this->mailer->AltBody = strip_tags($message);
            
            error_log("📧 Attempting to send email...");
            error_log("   To: $toEmail");
            error_log("   Subject: $subject");
            error_log("   SMTP Host: {$this->smtpHost}:{$this->smtpPort}");
            
            // Send email
            $result = $this->mailer->send();
            
            if ($result) {
                error_log("✅ SUCCESS: Email sent to $toEmail");
                return true;
            } else {
                error_log("❌ FAILED: Email not sent to $toEmail (no exception thrown)");
                return false;
            }
            
        } catch (Exception $e) {
            $errorMsg = $this->mailer->ErrorInfo;
            error_log("❌ CRITICAL ERROR sending email to $toEmail:");
            error_log("   PHPMailer Error: " . $errorMsg);
            error_log("   Exception: " . $e->getMessage());
            error_log("   Trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    private function getEmailTemplate($subject, $message) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                .container { 
                    max-width: 600px; 
                    margin: 20px auto; 
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                }
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                    letter-spacing: 2px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    font-size: 14px;
                    opacity: 0.9;
                }
                .content { 
                    padding: 30px; 
                }
                .content h2 {
                    color: #667eea;
                    margin-top: 0;
                    font-size: 20px;
                }
                .content p {
                    margin: 15px 0;
                    color: #555;
                }
                .content ul {
                    background: #f8f9fa;
                    padding: 20px 20px 20px 40px;
                    border-left: 4px solid #667eea;
                    margin: 15px 0;
                }
                .content ul li {
                    margin: 10px 0;
                    color: #333;
                }
                .button { 
                    display: inline-block; 
                    padding: 12px 30px; 
                    background: #667eea; 
                    color: white !important; 
                    text-decoration: none; 
                    border-radius: 5px; 
                    margin-top: 20px;
                    font-weight: 600;
                }
                .footer { 
                    text-align: center; 
                    padding: 20px; 
                    font-size: 12px; 
                    color: #666; 
                    background: #f8f9fa;
                    border-top: 1px solid #e0e0e0;
                }
                .footer p {
                    margin: 5px 0;
                }
                .important {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>NEXON</h1>
                    <p>IT Ticketing System</p>
                </div>
                <div class='content'>
                    <h2>{$subject}</h2>
                    {$message}
                </div>
                <div class='footer'>
                    <p><strong>This is an automated email from Nexon IT Ticketing System.</strong></p>
                    <p>Please do not reply to this email.</p>
                    <p style='margin-top: 15px; color: #999;'>
                        © " . date('Y') . " Nexon IT Support. All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    // TEST EMAIL FUNCTION - IMPROVED
    public function testEmailConfig($testEmail) {
        if (!$this->enabled) {
            return [
                'success' => false,
                'message' => '❌ Email notifications are DISABLED. Please set enabled => true in classes/email_config.php'
            ];
        }
        
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => '❌ Invalid email address format.'
            ];
        }
        
        // Check if cacert.pem exists
        $cacertPath = 'C:/xampp/php/extras/ssl/cacert.pem';
        if (!file_exists($cacertPath)) {
            error_log("⚠️ WARNING: cacert.pem not found at $cacertPath");
            error_log("   Download from: https://curl.se/docs/caextract.html");
        }
        
        $subject = "Nexon IT Ticketing System - Email Test ✅";
        $message = "
            <p><strong>🎉 Congratulations!</strong></p>
            <p>Your email configuration is working correctly with Brevo SMTP.</p>
            <p>This is a test email sent at <strong>" . date('Y-m-d H:i:s') . "</strong></p>
            <p><strong>Configuration Details:</strong></p>
            <ul>
                <li><strong>SMTP Provider:</strong> Brevo (SendinBlue)</li>
                <li><strong>SMTP Host:</strong> {$this->smtpHost}</li>
                <li><strong>SMTP Port:</strong> {$this->smtpPort}</li>
                <li><strong>Encryption:</strong> STARTTLS</li>
                <li><strong>From Email:</strong> {$this->fromEmail}</li>
                <li><strong>Test Sent To:</strong> {$testEmail}</li>
            </ul>
            <div class='important'>
                <strong>✅ Success!</strong> Your SMTP configuration is correct. You can now receive ticket notifications via email.
            </div>
            <p style='margin-top: 20px;'>If you received this email, the system is ready to send notifications for all ticket activities.</p>
        ";
        
        error_log("📧 Starting test email to: $testEmail");
        $result = $this->sendEmail($testEmail, $subject, $message);
        
        if ($result) {
            return [
                'success' => true,
                'message' => '✅ Test email sent successfully! Please check your inbox at <strong>' . htmlspecialchars($testEmail) . '</strong> (also check spam folder)'
            ];
        } else {
            return [
                'success' => false,
                'message' => '❌ Failed to send test email. Check the PHP error log at: ' . ini_get('error_log') . '<br><br>Common issues:<br>1. Check Brevo API key is correct<br>2. Ensure port 587 is not blocked by firewall<br>3. Verify from email is verified in Brevo dashboard'
            ];
        }
    }
    
    // All existing notification methods remain unchanged
    // (notifyAdminNewVerificationRequest, notifyAccountApproved, notifyAccountRejected, 
    //  notifyTicketCreated, notifyTicketAssigned, notifyTicketStatusChange, 
    //  notifyProviderNewTicket, notifyNewComment)
    
    public function notifyAdminNewVerificationRequest($adminEmail, $requestEmail, $userType) {
        $subject = "New Account Verification Request - {$userType}";
        $message = "
            <p>A new account verification request has been submitted and requires your review.</p>
            <p><strong>Request Details:</strong></p>
            <ul>
                <li><strong>Email:</strong> {$requestEmail}</li>
                <li><strong>Account Type:</strong> " . ucfirst(str_replace('_', ' ', $userType)) . "</li>
                <li><strong>Submitted:</strong> " . date('F j, Y g:i A') . "</li>
            </ul>
            <div class='important'>
                <strong>⚡ Action Required:</strong> Please log in to the admin panel to review and approve/reject this request.
            </div>
            <p style='margin-top: 20px;'>Navigate to: <strong>Admin Panel → Account Verifications</strong></p>
        ";
        
        return $this->sendEmail($adminEmail, $subject, $message);
    }
    
    public function notifyAccountApproved($userEmail, $userName, $userType) {
        $subject = "Account Approved - Welcome to Nexon Ticketing System";
        $message = "
            <p>Dear <strong>{$userName}</strong>,</p>
            <p>🎉 Great news! Your account has been approved by the administrator.</p>
            <p><strong>Account Details:</strong></p>
            <ul>
                <li><strong>Email:</strong> {$userEmail}</li>
                <li><strong>Account Type:</strong> " . ucfirst(str_replace('_', ' ', $userType)) . "</li>
                <li><strong>Status:</strong> <span style='color: #10b981;'>✅ Active</span></li>
            </ul>
            <div class='important'>
                <strong>🔑 Next Steps:</strong>
                <ol style='margin: 10px 0; padding-left: 20px;'>
                    <li>Visit the Nexon Ticketing System login page</li>
                    <li>Use your registered email address to log in</li>
                    <li>Use the password you created during registration</li>
                </ol>
            </div>
            <p style='margin-top: 20px;'>Welcome to the team! If you have any questions, please contact your system administrator.</p>
        ";
        
        return $this->sendEmail($userEmail, $subject, $message);
    }
    
    public function notifyAccountRejected($userEmail, $userName, $reason) {
        $subject = "Account Verification - Update Required";
        $message = "
            <p>Dear <strong>{$userName}</strong>,</p>
            <p>Thank you for your interest in the Nexon Ticketing System.</p>
            <p>After reviewing your account request, we are unable to approve it at this time.</p>
            " . (!empty($reason) ? "
            <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                <strong>Administrator's Note:</strong><br>
                " . nl2br(htmlspecialchars($reason)) . "
            </div>
            " : "") . "
            <p><strong>What to do next:</strong></p>
            <ul>
                <li>Please contact your system administrator for more information</li>
                <li>Ensure all provided information is accurate</li>
                <li>You may submit a new request after addressing any issues</li>
            </ul>
            <p style='margin-top: 20px;'>If you believe this is an error, please reach out to your IT department.</p>
        ";
        
        return $this->sendEmail($userEmail, $subject, $message);
    }
    
    public function notifyTicketCreated($ticketNumber, $employeeEmail, $employeeName, $deviceType, $priority) {
        $subject = "Ticket Created - #{$ticketNumber}";
        $message = "
            <p>Dear <strong>{$employeeName}</strong>,</p>
            <p>Your support ticket has been successfully created and submitted to our IT support team.</p>
            <p><strong>Ticket Details:</strong></p>
            <ul>
                <li><strong>Ticket Number:</strong> {$ticketNumber}</li>
                <li><strong>Device Type:</strong> {$deviceType}</li>
                <li><strong>Priority:</strong> " . ucfirst($priority) . "</li>
                <li><strong>Status:</strong> Pending Assignment</li>
            </ul>
            <p>You will receive email updates as your ticket is processed by our team.</p>
            <div class='important'>
                <strong>📧 Important:</strong> Please keep this email for your reference. Your ticket number is <strong>{$ticketNumber}</strong>.
            </div>
            <p style='margin-top: 20px;'>Thank you for contacting IT Support.</p>
        ";
        
        return $this->sendEmail($employeeEmail, $subject, $message);
    }
    
    public function notifyTicketAssigned($ticketNumber, $employeeEmail, $employeeName, $providerName) {
        $subject = "Ticket Assigned - #{$ticketNumber}";
        $message = "
            <p>Dear <strong>{$employeeName}</strong>,</p>
            <p>Great news! Your support ticket has been assigned to a service provider.</p>
            <p><strong>Assignment Details:</strong></p>
            <ul>
                <li><strong>Ticket Number:</strong> {$ticketNumber}</li>
                <li><strong>Assigned To:</strong> {$providerName}</li>
                <li><strong>Status:</strong> Assigned</li>
            </ul>
            <p>The service provider will begin working on your request shortly. You will receive further updates as work progresses.</p>
            <div class='important'>
                <strong>⏱️ Expected Response:</strong> A service provider will contact you within 24 hours.
            </div>
        ";
        
        return $this->sendEmail($employeeEmail, $subject, $message);
    }
    
    public function notifyTicketStatusChange($ticketNumber, $employeeEmail, $employeeName, $oldStatus, $newStatus, $comment = null) {
        $subject = "Ticket Updated - #{$ticketNumber}";
        
        $statusMessages = [
            'in_progress' => '🔄 Work has started on your ticket.',
            'resolved' => '✅ Your issue has been resolved!',
            'closed' => '📋 Your ticket has been closed.'
        ];
        
        $statusMessage = $statusMessages[$newStatus] ?? 'Your ticket status has been updated.';
        
        $message = "
            <p>Dear <strong>{$employeeName}</strong>,</p>
            <p>{$statusMessage}</p>
            <p><strong>Update Details:</strong></p>
            <ul>
                <li><strong>Ticket Number:</strong> {$ticketNumber}</li>
                <li><strong>Previous Status:</strong> " . ucfirst(str_replace('_', ' ', $oldStatus)) . "</li>
                <li><strong>New Status:</strong> " . ucfirst(str_replace('_', ' ', $newStatus)) . "</li>
            </ul>
        ";
        
        if ($comment) {
            $message .= "
            <div style='background: #f8f9fa; padding: 15px; border-left: 3px solid #667eea; margin: 15px 0;'>
                <strong>Provider Comment:</strong><br>
                " . nl2br(htmlspecialchars($comment)) . "
            </div>";
        }
        
        if ($newStatus === 'resolved') {
            $message .= "
            <div class='important'>
                <strong>⭐ Rate Our Service:</strong> Please log in to the system to rate the service provided. Your feedback helps us improve!
            </div>";
        }
        
        return $this->sendEmail($employeeEmail, $subject, $message);
    }
    
    public function notifyProviderNewTicket($ticketNumber, $providerEmail, $providerName, $employeeName, $deviceType, $priority) {
        $priorityColors = [
            'low' => '#3b82f6',
            'medium' => '#f59e0b',
            'high' => '#ef4444',
            'critical' => '#dc2626'
        ];
        
        $priorityColor = $priorityColors[$priority] ?? '#666';
        
        $subject = "New Ticket Assigned - #{$ticketNumber}";
        $message = "
            <p>Dear <strong>{$providerName}</strong>,</p>
            <p>A new support ticket has been assigned to you and requires your attention.</p>
            <p><strong>Ticket Details:</strong></p>
            <ul>
                <li><strong>Ticket Number:</strong> {$ticketNumber}</li>
                <li><strong>Employee:</strong> {$employeeName}</li>
                <li><strong>Device Type:</strong> {$deviceType}</li>
                <li><strong>Priority:</strong> <span style='color: {$priorityColor}; font-weight: bold;'>" . strtoupper($priority) . "</span></li>
            </ul>
            <p>Please log in to the system to review the ticket details and begin working on this request.</p>
            <div class='important'>
                <strong>⚡ Action Required:</strong> Please acknowledge this ticket within 2 hours.
            </div>
        ";
        
        return $this->sendEmail($providerEmail, $subject, $message);
    }
    
    public function notifyNewComment($ticketNumber, $recipientEmail, $recipientName, $commenterName, $comment) {
        $subject = "New Comment on Ticket #{$ticketNumber}";
        $message = "
            <p>Dear <strong>{$recipientName}</strong>,</p>
            <p>A new comment has been added to your ticket.</p>
            <p><strong>Ticket Number:</strong> {$ticketNumber}</p>
            <p><strong>From:</strong> {$commenterName}</p>
            <div style='background: #f8f9fa; padding: 15px; border-left: 3px solid #667eea; margin: 15px 0;'>
                <strong>Comment:</strong><br>
                " . nl2br(htmlspecialchars($comment)) . "
            </div>
            <p>Please log in to the system to view the full conversation and respond.</p>
        ";
        
        return $this->sendEmail($recipientEmail, $subject, $message);
    }
}