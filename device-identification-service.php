<?php
/**
 * Device Identification Service
 * 
 * Abstracted service for flexible user/device identification
 * Supports multiple identification methods with easy swapping
 */

class DeviceIdentificationService {
    
    private $settings;
    private $current_method;
    
    public function __construct() {
        $this->settings = get_option('lem_device_settings', array(
            'identification_method' => 'session_based', // session_based, ip_address, fingerprint, custom_token, hybrid
            'ip_fallback_enabled' => true,
            'session_fallback_enabled' => false,
            'fingerprint_enabled' => false,
            'custom_token_enabled' => false,
            'strict_mode' => false, // true = exact match, false = fuzzy match
        ));
        $this->current_method = $this->settings['identification_method'];
    }
    
    /**
     * Get device identifier based on current method
     */
    public function getDeviceIdentifier($context = array()) {
        switch ($this->current_method) {
            case 'session_based':
                return $this->getSessionIdentifier($context);
            case 'ip_address':
                return $this->getIpIdentifier($context);
            case 'fingerprint':
                return $this->getFingerprintIdentifier($context);
            case 'custom_token':
                return $this->getCustomTokenIdentifier($context);
            case 'hybrid':
                return $this->getHybridIdentifier($context);
            default:
                return $this->getSessionIdentifier($context);
        }
    }
    
    /**
     * Session-based identification
     */
    private function getSessionIdentifier($context) {
        $session_id = $context['session_id'] ?? $_COOKIE['lem_session_id'] ?? null;
        
        if (!$session_id) {
            // Fallback to IP if session not available
            if ($this->settings['ip_fallback_enabled']) {
                return $this->getIpIdentifier($context);
            }
            return null;
        }
        
        return array(
            'type' => 'session_based',
            'identifier' => $session_id,
            'metadata' => array(
                'session_id' => $session_id,
                'ip' => $this->detectClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'timestamp' => time(),
                'strict' => true
            )
        );
    }
    
    /**
     * IP-based identification
     */
    private function getIpIdentifier($context) {
        $ip = $this->detectClientIp();
        
        if ($this->settings['strict_mode']) {
            // Exact IP match
            return array(
                'type' => 'ip_address',
                'identifier' => $ip,
                'metadata' => array(
                    'ip' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'timestamp' => time(),
                    'strict' => true
                )
            );
        } else {
            // Fuzzy IP match (subnet-based)
            $subnet = $this->getIpSubnet($ip);
            return array(
                'type' => 'ip_address',
                'identifier' => $subnet,
                'metadata' => array(
                    'ip' => $ip,
                    'subnet' => $subnet,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'timestamp' => time(),
                    'strict' => false
                )
            );
        }
    }
    
    /**
     * Fingerprint-based identification
     */
    private function getFingerprintIdentifier($context) {
        $fingerprint = $context['fingerprint'] ?? null;
        
        if (!$fingerprint) {
            // Fallback to IP if fingerprint not available
            if ($this->settings['ip_fallback_enabled']) {
                return $this->getIpIdentifier($context);
            }
            return null;
        }
        
        return array(
            'type' => 'fingerprint',
            'identifier' => $fingerprint,
            'metadata' => array(
                'fingerprint' => $fingerprint,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'timestamp' => time(),
                'strict' => true
            )
        );
    }
    
    /**
     * Custom token-based identification
     */
    private function getCustomTokenIdentifier($context) {
        $token = $context['custom_token'] ?? null;
        
        if (!$token) {
            // Fallback to IP if custom token not available
            if ($this->settings['ip_fallback_enabled']) {
                return $this->getIpIdentifier($context);
            }
            return null;
        }
        
        return array(
            'type' => 'custom_token',
            'identifier' => $token,
            'metadata' => array(
                'custom_token' => $token,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'timestamp' => time(),
                'strict' => true
            )
        );
    }
    
    /**
     * Hybrid identification (multiple methods)
     */
    private function getHybridIdentifier($context) {
        $identifiers = array();
        
        // Try fingerprint first
        if ($this->settings['fingerprint_enabled']) {
            $fingerprint = $this->getFingerprintIdentifier($context);
            if ($fingerprint) {
                $identifiers[] = $fingerprint;
            }
        }
        
        // Try custom token
        if ($this->settings['custom_token_enabled']) {
            $custom_token = $this->getCustomTokenIdentifier($context);
            if ($custom_token) {
                $identifiers[] = $custom_token;
            }
        }
        
        // Always include IP as fallback
        $ip_identifier = $this->getIpIdentifier($context);
        $identifiers[] = $ip_identifier;
        
        return array(
            'type' => 'hybrid',
            'identifier' => json_encode($identifiers),
            'metadata' => array(
                'identifiers' => $identifiers,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'timestamp' => time(),
                'strict' => false
            )
        );
    }
    
    /**
     * Validate device identifier against stored data
     */
    public function validateDeviceIdentifier($stored_identifier, $current_identifier) {
        if ($stored_identifier['type'] !== $current_identifier['type']) {
            return false;
        }
        
        switch ($stored_identifier['type']) {
            case 'session_based':
                return $this->validateSessionIdentifier($stored_identifier, $current_identifier);
            case 'ip_address':
                return $this->validateIpIdentifier($stored_identifier, $current_identifier);
            case 'fingerprint':
                return $this->validateFingerprintIdentifier($stored_identifier, $current_identifier);
            case 'custom_token':
                return $this->validateCustomTokenIdentifier($stored_identifier, $current_identifier);
            case 'hybrid':
                return $this->validateHybridIdentifier($stored_identifier, $current_identifier);
            default:
                return false;
        }
    }
    
    /**
     * Validate session identifier
     */
    private function validateSessionIdentifier($stored, $current) {
        // Session-based validation is handled by the session system
        // This method just ensures the session IDs match
        return $stored['identifier'] === $current['identifier'];
    }
    
    /**
     * Validate IP identifiers
     */
    private function validateIpIdentifier($stored, $current) {
        if ($stored['metadata']['strict'] && $current['metadata']['strict']) {
            // Exact IP match
            return $stored['identifier'] === $current['identifier'];
        } else {
            // Fuzzy match (same subnet)
            return $stored['metadata']['subnet'] === $current['metadata']['subnet'];
        }
    }
    
    /**
     * Validate fingerprint identifiers
     */
    private function validateFingerprintIdentifier($stored, $current) {
        return $stored['identifier'] === $current['identifier'];
    }
    
    /**
     * Validate custom token identifiers
     */
    private function validateCustomTokenIdentifier($stored, $current) {
        return $stored['identifier'] === $current['identifier'];
    }
    
    /**
     * Validate hybrid identifiers
     */
    private function validateHybridIdentifier($stored, $current) {
        $stored_identifiers = json_decode($stored['identifier'], true);
        $current_identifiers = json_decode($current['identifier'], true);
        
        // Check if any identifier matches
        foreach ($stored_identifiers as $stored_id) {
            foreach ($current_identifiers as $current_id) {
                if ($this->validateDeviceIdentifier($stored_id, $current_id)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Detect client IP address
     */
    private function detectClientIp() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get IP subnet for fuzzy matching
     */
    private function getIpSubnet($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4: /24 subnet
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6: /64 subnet
            return $ip . '/64';
        }
        return $ip;
    }
    
    /**
     * Update identification method
     */
    public function updateIdentificationMethod($method) {
        $this->settings['identification_method'] = $method;
        update_option('lem_device_settings', $this->settings);
        $this->current_method = $method;
    }
    
    /**
     * Get current settings
     */
    public function getSettings() {
        return $this->settings;
    }
    
    /**
     * Update settings
     */
    public function updateSettings($new_settings) {
        $this->settings = array_merge($this->settings, $new_settings);
        update_option('lem_device_settings', $this->settings);
    }
}