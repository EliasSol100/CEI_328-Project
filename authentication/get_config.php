<?php
/**
 * Get system configuration values
 */

if (!defined('INCLUDE_CHECK')) {
    define('INCLUDE_CHECK', true);
}

// Include database connection
require_once __DIR__ . '/database.php';

/**
 * Get a configuration value by key
 * @param mysqli $conn Database connection (optional)
 * @param string $key Configuration key
 * @param mixed $default Default value if key not found
 * @return mixed Configuration value
 */
function getSystemConfig($key, $default = null) {
    global $conn;
    
    // If $conn is not available, try to use the global one
    if (!isset($conn) || !$conn) {
        // Try to include database.php if not already included
        if (file_exists(__DIR__ . '/database.php')) {
            require_once __DIR__ . '/database.php';
        }
        if (!isset($conn) || !$conn) {
            return $default;
        }
    }
    
    try {
        $stmt = $conn->prepare("SELECT config_value, config_type FROM system_config WHERE config_key = ?");
        if (!$stmt) {
            return $default;
        }
        
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            $value = $row['config_value'];
            $type = $row['config_type'];
            
            // Convert value based on type
            switch ($type) {
                case 'int':
                    return (int)$value;
                case 'boolean':
                    return (bool)$value;
                case 'json':
                    return json_decode($value, true);
                default:
                    return $value;
            }
        }
    } catch (Exception $e) {
        error_log("Error getting config: " . $e->getMessage());
        return $default;
    }
    
    return $default;
}

/**
 * Set a configuration value
 * @param mysqli $conn Database connection
 * @param string $key Configuration key
 * @param mixed $value Configuration value
 * @param string $type Value type (string, int, boolean, json)
 * @param string $description Optional description
 * @return bool Success
 */
function setSystemConfig($conn, $key, $value, $type = 'string', $description = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO system_config (config_key, config_value, config_type, description, updated_at) 
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                config_value = VALUES(config_value),
                config_type = VALUES(config_type),
                description = VALUES(description),
                updated_at = NOW()
        ");
        
        $stmt->bind_param("ssss", $key, $value, $type, $description);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Error setting config: " . $e->getMessage());
        return false;
    }
}

/**
 * Get multiple configuration values
 * @param mysqli $conn Database connection
 * @param array $keys Array of config keys
 * @return array Associative array of key => value
 */
function getMultipleSystemConfig($conn, $keys) {
    if (empty($keys)) return [];
    
    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $conn->prepare("SELECT config_key, config_value, config_type FROM system_config WHERE config_key IN ($placeholders)");
        
        $types = str_repeat('s', count($keys));
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $configs = [];
        while ($row = $result->fetch_assoc()) {
            $value = $row['config_value'];
            switch ($row['config_type']) {
                case 'int':
                    $configs[$row['config_key']] = (int)$value;
                    break;
                case 'boolean':
                    $configs[$row['config_key']] = (bool)$value;
                    break;
                default:
                    $configs[$row['config_key']] = $value;
            }
        }
        $stmt->close();
        
        return $configs;
    } catch (Exception $e) {
        error_log("Error getting multiple configs: " . $e->getMessage());
        return [];
    }
}
?>
