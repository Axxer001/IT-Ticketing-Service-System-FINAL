<?php
/**
 * Timezone Configuration
 * Location: classes/timezone_config.php
 * 
 * This file sets the default timezone for the entire application
 * Include this at the top of every PHP file that displays time
 */

// Set default timezone to Philippine Time (Asia/Manila - UTC+8)
date_default_timezone_set('Asia/Manila');

/**
 * Helper function to format datetime consistently across the application
 * 
 * @param string $datetime - DateTime string from database
 * @param string $format - Output format (default: 'M j, Y g:i A')
 * @return string - Formatted datetime string
 */
function formatDateTime($datetime, $format = 'M j, Y g:i A') {
    if (!$datetime) return 'N/A';
    
    try {
        $date = new DateTime($datetime, new DateTimeZone('Asia/Manila'));
        return $date->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Helper function to get relative time (e.g., "5 minutes ago")
 * 
 * @param string $datetime - DateTime string from database
 * @return string - Relative time string
 */
function getRelativeTime($datetime) {
    if (!$datetime) return 'Unknown';
    
    try {
        $date = new DateTime($datetime, new DateTimeZone('Asia/Manila'));
        $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $diff = $now->getTimestamp() - $date->getTimestamp();
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return $date->format('M j, Y');
        }
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Helper function to get current timestamp in Philippine time
 * 
 * @param string $format - Output format (default: 'Y-m-d H:i:s')
 * @return string - Current timestamp
 */
function getCurrentTimestamp($format = 'Y-m-d H:i:s') {
    $date = new DateTime('now', new DateTimeZone('Asia/Manila'));
    return $date->format($format);
}