<?php

namespace App\Custom\EventListeners;

use App\Events\publicEvents\serverIpAssignments\afterServerIpAssignments;
use App\Services\networkDevices\devices\networkSwitches\helpers;
use App\Services\networkDevices\devices\networkSwitches\switchFacade;
use Illuminate\Support\Facades\Http;

/**
 * UPDATED VLAN Automation Listener - Single Routed Subnet with Calculated Host Subnet
 * 
 * SUBNET STRUCTURE (per TNSR pattern):
 * Input from TenantOS: 2602:f937:0:b00::/56 (routed subnet)
 * TNSR calculates:
 *   - Host subnet: 2602:f937:0:bb00::/64 (insert 'b' before third hextet)
 *   - Gateway: 2602:f937:0:bb00::1/64 (first IP of host subnet)
 *   - Host address: 2602:f937:0:bb00::2 (second IP of host subnet)
 * 
 * Template variables:
 *   {IPV6_ADDRESS}       - Host address (::2)
 *   {IPV6_ROUTED_SUBNET} - Original routed subnet from TenantOS
 *   {IPV6_HOST_SUBNET}   - Calculated host subnet
 * 
 * ALL OTHER LOGIC: Unchanged from proven working version
 * - Uses helpers::getServerSwitchConnections() 
 * - Checks switchAutomationAvailable && automationEnabled
 * - MLAG/LACP detection logic
 * - switchFacade integration
 */
class afterIpAssignmentListener {

    private $config = [];

    public function __construct() {
        $configFile = __DIR__ . '/vlan_automation_config.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            logActivity("WARNING: Config file not found: $configFile");
            $this->config = [];
        }
    }

    public function handle(afterServerIpAssignments $event) {
        try {
            logActivity("=== afterIpAssignmentListener: Processing server {$event->serverId} ===");
            
            // Skip if no IPs added
            if (!isset($event->addedIps) || empty($event->addedIps)) {
                logActivity("No IPs added - nothing to process");
                return;
            }
            
            // Skip IPv4 assignments - VLAN automation only for IPv6
            $hasIpv6 = false;
            foreach ($event->addedIps as $ipEntry) {
                $ip = null;
                
                // Handle array of IP strings
                if (is_array($ipEntry)) {
                    // If array of strings, first one is usually the IP
                    foreach ($ipEntry as $item) {
                        if (is_string($item) && (strpos($item, '.') !== false || strpos($item, ':') !== false)) {
                            $ip = $item;
                            break;
                        }
                    }
                } elseif (is_string($ipEntry)) {
                    $ip = $ipEntry;
                }
                
                if (!$ip) {
                    // Log the actual structure for debugging
                    $ipStructure = is_array($ipEntry) ? json_encode($ipEntry) : var_export($ipEntry, true);
                    $this->debugLog("WARNING: Could not extract IP from addedIps entry. Structure: {$ipStructure}", 2);
                    continue;
                }
                
                $this->debugLog("DEBUG: Checking IP Address: {$ip}", 2);
                
                // IPv6 addresses contain colons, IPv4 contain dots
                if (strpos($ip, ':') !== false) {
                    $hasIpv6 = true;
                    $this->debugLog("DEBUG: Found IPv6 address: {$ip}", 2);
                    break;
                }
            }
            
            if (!$hasIpv6) {
                logActivity("No IPv6 addresses in assignment - VLAN automation only applies to IPv6");
                return;
            }

            $getSwitches = helpers::getServerSwitchConnections($event->serverId);
            
            if (empty($getSwitches)) {
                logActivity("No switches found for server {$event->serverId}");
                return;
            }

            // Filter to switches with advancedManagement capability
            $advancedMgmtSwitches = array_filter($getSwitches, function($switch) {
                return isset($switch['switchAutomationAvailable']) && $switch['switchAutomationAvailable'];
            });

            if (empty($advancedMgmtSwitches)) {
                logActivity("No switches with advancedManagement capability for server {$event->serverId}");
                return;
            }

            logActivity("Found " . count($advancedMgmtSwitches) . " switches with advancedManagement capability");

            // Build list of switches to process (includes MLAG peers)
            // Also track MLAG peer info to avoid calling detectMlagPeer twice
            $switchesToProcess = [];
            $mlagPeerMap = [];  // switchId => peerInfo

            foreach ($advancedMgmtSwitches as $switch) {
                $switchId = $switch['switchId'];
                
                // Already in list?
                if (isset($switchesToProcess[$switchId])) {
                    continue;
                }
                
                $switchesToProcess[$switchId] = $switch;
                
                // Check if MLAG is enabled in config
                $mlagEnabled = $this->config['port_channel']['enabled'] ?? false;
                
                if ($mlagEnabled) {
                    // Check for MLAG peer
                    $mlagPeer = $this->detectMlagPeer($switch, $getSwitches, $event->serverId);
                    
                    if ($mlagPeer) {
                        // Store peer info for both directions
                        $mlagPeerMap[$switchId] = $mlagPeer;
                        $peerId = $mlagPeer['switchId'];
                        if (!isset($switchesToProcess[$peerId])) {
                            // Find peer in $getSwitches and add it
                            $peerSwitches = array_filter($getSwitches, function($s) use ($peerId) {
                                return $s['switchId'] === $peerId;
                            });
                            if (!empty($peerSwitches)) {
                                $switchesToProcess[$peerId] = reset($peerSwitches);
                                if (($this->config['debug_level'] ?? 0) >= 1) {
                                    logActivity("  Including MLAG peer: Switch $peerId");
                                }
                            }
                        }
                    }
                }
            }

            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("Processing " . count($switchesToProcess) . " switches (including MLAG pairs)");
            }

            // Check if TenantOS has exactly one switch with automation enabled
            // (This is a TenantOS requirement)
            $automationEnabledCount = 0;
            foreach ($getSwitches as $switch) {
                if ($switch['automationEnabled'] ?? false) {
                    $automationEnabledCount++;
                }
            }
            
            if ($automationEnabledCount > 1) {
                logActivity("ERROR: Multiple switches have automationEnabled=true - TenantOS misconfiguration!");
                return;
            }

            // Find the automation-enabled switch (TenantOS configured only this one)
            $automationSwitch = null;
            $peerSwitches = [];
            
            foreach ($switchesToProcess as $switch) {
                if ($switch['automationEnabled'] ?? false) {
                    $automationSwitch = $switch;
                } else {
                    $peerSwitches[] = $switch;
                }
            }
            
            if (!$automationSwitch) {
                logActivity("ERROR: No automation-enabled switch found in list");
                return;
            }
            
            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("Found automation-enabled switch: {$automationSwitch['switchId']}");
            }
            
            // Initialize switchFacade for automation switch to get port mapping
            $switchId = $automationSwitch['switchId'];
            $switchFacade = new switchFacade($switchId);
            $switchFacade->setRelationType('server');
            $switchFacade->setRelationId($event->serverId);
            $switchFacade->setActionType('automation');
            
            // Map SNMP port ID to interface name (needed to query VLAN)
            $switchPort = $switchFacade->mapSnmpPortIdToInterfaceName($automationSwitch['snmpPortId']);
            logActivity("[Switch $switchId] Mapped port to interface: $switchPort");
            
            // Get VLAN info from the automation-enabled switch (TenantOS created the port there)
            $serverIpv4 = $this->getServerIpv4($event->serverId);
            
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] Querying VLAN from API");
            }
            $vlanInfo = $this->getVlanFromApi($switchId, $switchPort, $event->serverId);
            if (!$vlanInfo || !isset($vlanInfo['vlan'])) {
                logActivity("[Switch $switchId] ERROR: Could not get VLAN from API");
                logActivity("[Switch $switchId] Port queried: $switchPort");
                logActivity("[Switch $switchId] Response: " . json_encode($vlanInfo));
                return;
            }
            $vlan = $vlanInfo['vlan'];
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] ✓ Got VLAN from API: $vlan");
            }

            // Validate VLAN is in the allowed range for this subnet
            if ($this->config['debug_level'] ?? 0 >= 1) {
                logActivity("Validating VLAN {$vlan} against subnet restrictions...");
            }
            $isValidVlan = $this->validateVlanRange($event->serverId, $vlan);
            if (!$isValidVlan) {
                logActivity("ERROR: VLAN {$vlan} is not in the allowed range for this subnet");
                return;
            }

            // Get subnets once (same for all switches in a pair)
            $subnets = $this->getServerSubnets($event->serverId);
            if (empty($subnets)) {
                logActivity("ERROR: Could not find subnet for server {$event->serverId}");
                return;
            }
            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("✓ Routed subnet: {$subnets['routed']}");
                logActivity("✓ Host subnet: {$subnets['host']}");
            }

            // Build processing queue: automation switch first, then peers
            $processingQueue = array_merge([$automationSwitch], $peerSwitches);
            
            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("Processing queue: " . implode(", ", array_map(function($s) { return "Switch " . $s['switchId']; }, $processingQueue)));
            }

            // Process each switch
            foreach ($processingQueue as $switchToProcess) {
                $switch = $switchToProcess;
                $switchId = $switch['switchId'];
                $isAutomation = $switch['automationEnabled'] ?? false;
                
                if (($this->config['debug_level'] ?? 0) >= 1) {
                    logActivity("[Switch $switchId] Processing switch $switchId for server {$event->serverId}" . ($isAutomation ? " (AUTOMATION ENABLED)" : " (PEER)"));
                }

                $switchFacade = new switchFacade($switch['switchId']);
                $switchFacade->setRelationType('server');
                $switchFacade->setRelationId($event->serverId);
                $switchFacade->setActionType('automation');
                
                // Check MLAG status for this specific switch
                $mlagEnabled = $this->config['port_channel']['enabled'] ?? false;
                $mlagPeerInfo = null;
                
                if ($mlagEnabled && isset($mlagPeerMap[$switchId])) {
                    // Use pre-computed MLAG peer info instead of calling detectMlagPeer again
                    $mlagPeerInfo = $mlagPeerMap[$switchId];
                } elseif ($mlagEnabled) {
                    // If not found in map, it means this switch wasn't in the first detection loop
                    // (This shouldn't happen in normal operation, but handle it gracefully)
                    $mlagPeerInfo = $this->detectMlagPeer($switch, $getSwitches, $event->serverId);
                    if (!$mlagPeerInfo) {
                        $mlagEnabled = false;
                    }
                } else {
                    $mlagEnabled = false;
                }
                
                $switchFacade->setActionDescription('VLAN Automation with ACLs' . ($mlagEnabled ? ' and MLAG' : ''));

                $switchPort = $switchFacade->mapSnmpPortIdToInterfaceName($switch['snmpPortId']);
                if (($this->config['debug_level'] ?? 0) >= 1) {
                    logActivity("[Switch $switchId] Mapped port to interface: $switchPort");
                }

                $commit = $switchFacade->commit();
                
                // ===== DETAILED SWITCHFACADE LOGGING (Debug Level 3) =====
                if (($this->config['debug_level'] ?? 0) >= 3) {
                    logActivity("[Switch $switchId] [SWITCHFACADE] Initial Commit Result:");
                    logActivity("[Switch $switchId]   Success: " . ($commit['success'] ? 'true' : 'false'));
                    if (isset($commit['message'])) {
                        logActivity("[Switch $switchId]   Message: {$commit['message']}");
                    }
                    if (isset($commit['error'])) {
                        logActivity("[Switch $switchId]   Error: {$commit['error']}");
                    }
                    
                    // Get switchFacade logs for detailed debugging
                    try {
                        $logs = $switchFacade->getLogs();
                        if ($logs && is_array($logs)) {
                            logActivity("[Switch $switchId] [SWITCHFACADE] Logs (" . count($logs) . " entries):");
                            foreach ($logs as $index => $log) {
                                if (is_array($log)) {
                                    $logStr = isset($log['message']) ? $log['message'] : json_encode($log);
                                } else {
                                    $logStr = (string)$log;
                                }
                                logActivity("[Switch $switchId]   [{$index}] {$logStr}");
                            }
                        } else {
                            logActivity("[Switch $switchId] [SWITCHFACADE] No logs available");
                        }
                    } catch (\Exception $e) {
                        logActivity("[Switch $switchId] [SWITCHFACADE] Could not retrieve logs: " . $e->getMessage());
                    }
                }
                // ===== END SWITCHFACADE LOGGING =====
                
                if (!$commit['success']) {
                    logActivity("[Switch $switchId] ERROR: Switch commit failed");
                    return;
                }

                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("[Switch $switchId] ✓ Switch commit succeeded");
                }

                // Apply switch configuration with MLAG context
                $configSuccess = $this->applySwitchConfiguration(
                    $switch['switchId'],
                    $event->serverId,
                    $switchPort,
                    $vlan,
                    $subnets,
                    $serverIpv4,
                    $mlagEnabled,
                    $mlagEnabled ? $mlagPeerInfo : null
                );

                if (!$configSuccess) {
                    logActivity("[Switch $switchId] WARNING: Advanced configuration failed");
                }
            }

            // Create TNSR VLAN once (same for all switches)
            // CRITICAL: Never send VLAN 1 to TNSR (it's reserved for management)
            if ($vlan == 1) {
                logActivity("⚠ VLAN 1 is reserved for management - skipping TNSR configuration");
            } else {
                logActivity("Configuring TNSR for VLAN $vlan...");
                $routedSubnet = $subnets['routed'];
                $gateway = $this->calculateGateway($routedSubnet, $vlan);
                if (!$gateway) {
                    logActivity("ERROR: Could not calculate gateway for $routedSubnet");
                } else {
                    logActivity("✓ Calculated gateway for $routedSubnet: $gateway");
                    
                    $success = $this->createTnsrVlan($routedSubnet, $vlan, $gateway);
                    if ($success) {
                        logActivity("✓ SUCCESS: VLAN $vlan automated on TNSR for $routedSubnet");
                    } else {
                        logActivity("✗ FAILED: TNSR automation failed for $routedSubnet");
                    }
                }
            }

            logActivity("=== afterIpAssignmentListener: Complete ===");

        } catch (\Exception $e) {
            logActivity("Exception in afterIpAssignmentListener: " . $e->getMessage());
        }
    }

    private function detectMlagPeer($managedSwitch, $allSwitches, $serverId) {
        try {
            // Get all switch connections for this server
            $switchConnections = $this->getServerSwitchConnections($serverId);
            
            if (count($switchConnections) < 2) {
                logActivity("    MLAG Peer: Server has less than 2 switch connections");
                return null;
            }

            // Extract all switch IDs from connections
            $connectedSwitchIds = array_unique(array_map(function($conn) {
                return $conn['switchId'];
            }, $switchConnections));

            $managedSwitchId = $managedSwitch['switchId'];
            logActivity("    MLAG Peer: Checking switches " . implode(", ", $connectedSwitchIds));

            // Valid pairs now use switch IDs, not names
            $validPairs = $this->config['port_channel']['valid_pairs'] ?? [
                [1, 2],      // Position 0=EVEN, Position 1=ODD
                [23, 25],    // Position 0=EVEN, Position 1=ODD
                [3, 4],
            ];

            // Look for a valid pair that includes the managed switch
            foreach ($validPairs as $pairIndex => $pair) {
                $peerSwitchId = null;
                $switchPosition = null;

                // Check if managed switch is in this pair
                if ($pair[0] === $managedSwitchId) {
                    // Managed switch is at position 0 (EVEN parity)
                    $peerSwitchId = $pair[1];
                    $switchPosition = 0;  // Position 0 = EVEN
                } elseif ($pair[1] === $managedSwitchId) {
                    // Managed switch is at position 1 (ODD parity)
                    $peerSwitchId = $pair[0];
                    $switchPosition = 1;  // Position 1 = ODD
                }

                if ($peerSwitchId === null) {
                    // This pair doesn't include our switch
                    continue;
                }

                // Check if peer is connected to same server
                if (in_array($peerSwitchId, $connectedSwitchIds)) {
                    if (($this->config['debug_level'] ?? 0) >= 2) {
                        logActivity("    MLAG Peer: Found peer switch {$peerSwitchId} in pair [{$pair[0]}, {$pair[1]}]");
                    }
                    if (($this->config['debug_level'] ?? 0) >= 1) {
                        logActivity("    MLAG Peer: Managed switch at position {$switchPosition} (parity: " . ($switchPosition === 0 ? "EVEN" : "ODD") . ")");
                    }

                    return [
                        'switchId' => $peerSwitchId,
                        'pairIndex' => $pairIndex,
                        'switchPosition' => $switchPosition,  // 0=EVEN, 1=ODD
                    ];
                }
            }

            logActivity("    MLAG Peer: No valid peer found in configured pairs");
            return null;

        } catch (\Exception $e) {
            logActivity("    MLAG Peer: Exception - " . $e->getMessage());
            return null;
        }
    }

    private function getServerSwitchConnections($serverId) {
        try {
            $apiToken = $this->config['api_token'] ?? null;
            if (!$apiToken) {
                return [];
            }

            $baseUrl = rtrim(config('app.url'), '/');
            $url = "{$baseUrl}/api/servers/{$serverId}/connections";
            
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
            ])->get($url);

            if (!$response->successful()) {
                return [];
            }

            $connections = $response->json()['result'] ?? [];
            
            // Extract switch connections only
            $switchConnections = [];
            foreach ($connections as $connection) {
                $relatedData = $connection['related_data'] ?? [];
                if ($relatedData['type'] === 'snmp_switch') {
                    $meta = $relatedData['meta'] ?? [];
                    if (isset($meta['switchId'])) {
                        $switchConnections[] = [
                            'switchId' => $meta['switchId'],
                            'portId' => $meta['portId'] ?? null,
                            'portName' => $meta['portName'] ?? null,
                        ];
                    }
                }
            }
            
            return $switchConnections;
            
        } catch (\Exception $e) {
            logActivity("    Error fetching server connections: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get port format metadata for a switch from valid_pairs
     * Returns format string like '#' or '#/#' or null if not found
     */
    private function getPortFormatForSwitch($switchId) {
        $portChannelConfig = $this->config['port_channel'] ?? [];
        $validPairs = $portChannelConfig['valid_pairs'] ?? [];
        
        foreach ($validPairs as $pair) {
            if (in_array($switchId, $pair)) {
                // Format is at index 2 if it exists, otherwise default to '#'
                return $pair[2] ?? '#';
            }
        }
        
        return null;  // Switch not in a pair
    }

    /**
     * Generate alternate port name for querying
     * If given Ethernet39, returns Port-Channel39
     * If given Port-Channel39, returns Ethernet39
     * If given Ethernet10/1, returns Port-Channel10/1
     */
    private function getAlternatePortName($portName) {
        // If it's Port-Channel, convert to Ethernet
        if (strpos($portName, 'Port-Channel') === 0) {
            $portNumber = str_replace('Port-Channel', '', $portName);
            return "Ethernet{$portNumber}";
        }
        
        // If it's Ethernet, convert to Port-Channel
        if (strpos($portName, 'Ethernet') === 0) {
            $portNumber = str_replace('Ethernet', '', $portName);
            return "Port-Channel{$portNumber}";
        }
        
        return null;  // Unknown format
    }

    private function validateVlanRange($serverId, $vlanId) {
        try {
            $apiToken = $this->config['api_token'] ?? null;
            $debugLevel = $this->config['debug_level'] ?? 0;
            
            if (!$apiToken) {
                if ($debugLevel >= 1) {
                    $this->debugLog("[VLAN-VALIDATION] No API token available, skipping validation", 1);
                }
                return true;
            }

            $baseUrl = rtrim(config('app.url'), '/');
            
            // First, query server IP assignments to get subnet ID
            $url = "{$baseUrl}/api/servers/{$serverId}/ipassignments";
            
            try {
                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 60,
                    'connect_timeout' => 10,
                ])->withHeaders([
                    'Authorization' => 'Bearer ' . $apiToken,
                ])->get($url);

                if (!$response->successful()) {
                    if ($debugLevel >= 1) {
                        $this->debugLog("[VLAN-VALIDATION] Failed to query server IP assignments", 1);
                    }
                    return true;  // Don't block on validation failure
                }

                $data = $response->json();
                $assignments = $data['result'] ?? [];

                // Find a subnet with vlanAutomationAvailable = true
                $subnetId = null;
                foreach ($assignments as $assignment) {
                    $subnetInfo = $assignment['subnetinformation'] ?? [];
                    if (($subnetInfo['vlanAutomationAvailable'] ?? false) && isset($subnetInfo['id'])) {
                        $subnetId = $subnetInfo['id'];
                        break;
                    }
                }

                if (!$subnetId) {
                    if ($debugLevel >= 1) {
                        $this->debugLog("[VLAN-VALIDATION] No subnet with vlanAutomationAvailable found", 1);
                    }
                    return true;
                }

                // Now query the subnet details for VLAN range
                $subnetUrl = "{$baseUrl}/api/subnets/{$subnetId}/withDetails";
                
                $subnetResponse = Http::withOptions([
                    'verify' => false,
                    'timeout' => 60,
                    'connect_timeout' => 10,
                ])->withHeaders([
                    'Authorization' => 'Bearer ' . $apiToken,
                ])->get($subnetUrl);

                if (!$subnetResponse->successful()) {
                    if ($debugLevel >= 1) {
                        $this->debugLog("[VLAN-VALIDATION] Failed to query subnet {$subnetId} details", 1);
                    }
                    return true;
                }

                $subnetData = $subnetResponse->json();
                $subnet = $subnetData['result'] ?? [];

                $rangeStart = $subnet['range_start_access_vlan_id'] ?? null;
                $rangeEnd = $subnet['range_end_access_vlan_id'] ?? null;
                $trunkVlans = $subnet['trunk_vlans'] ?? [];

                if ($debugLevel >= 1) {
                    $this->debugLog("[VLAN-VALIDATION] Subnet {$subnetId} allowed VLAN range: {$rangeStart}-{$rangeEnd}", 1);
                    $this->debugLog("[VLAN-VALIDATION] Trunk VLANs (excluded): " . implode(', ', $trunkVlans), 1);
                    $this->debugLog("[VLAN-VALIDATION] Validating VLAN {$vlanId}", 1);
                }

                // Check if VLAN is in trunk_vlans (excluded list)
                if (in_array($vlanId, $trunkVlans)) {
                    if ($debugLevel >= 1) {
                        $this->debugLog("[VLAN-VALIDATION] ✗ VLAN {$vlanId} is in trunk_vlans exclusion list", 1);
                    }
                    return false;
                }

                // Check if VLAN is in the allowed range
                if ($rangeStart !== null && $rangeEnd !== null) {
                    if ($vlanId >= $rangeStart && $vlanId <= $rangeEnd) {
                        if ($debugLevel >= 1) {
                            $this->debugLog("[VLAN-VALIDATION] ✓ VLAN {$vlanId} is within allowed range ({$rangeStart}-{$rangeEnd})", 1);
                        }
                        return true;
                    } else {
                        if ($debugLevel >= 1) {
                            $this->debugLog("[VLAN-VALIDATION] ✗ VLAN {$vlanId} is OUTSIDE allowed range ({$rangeStart}-{$rangeEnd})", 1);
                        }
                        return false;
                    }
                }

                if ($debugLevel >= 1) {
                    $this->debugLog("[VLAN-VALIDATION] Could not determine VLAN range from subnet", 1);
                }
                return true;

            } catch (\Exception $e) {
                if ($debugLevel >= 1) {
                    $this->debugLog("[VLAN-VALIDATION] Exception validating VLAN range: " . $e->getMessage(), 1);
                }
                return true;  // Don't block on validation failure
            }

        } catch (\Exception $e) {
            if (($this->config['debug_level'] ?? 0) >= 1) {
                $this->debugLog("[VLAN-VALIDATION] Exception in validateVlanRange: " . $e->getMessage(), 1);
            }
            return true;
        }
    }

    /**
     * Log to debug file only if debug level is enabled
     * @param string $message Message to log
     * @param int $minLevel Minimum debug level required (1, 2, or 3)
     */
    /**
     * Ensure log directory exists and is writable before writing
     */
    private function ensureLogDirExists($logPath) {
        try {
            $logDir = dirname($logPath);
            
            // If directory doesn't exist, create it
            if (!is_dir($logDir)) {
                if (!@mkdir($logDir, 0755, true)) {
                    // mkdir failed, try without the @ to see the actual error
                    mkdir($logDir, 0755, true);
                }
            }
            
            // Ensure directory is writable
            if (!is_writable($logDir)) {
                @chmod($logDir, 0755);
            }
            
            // Create empty file if it doesn't exist
            if (!file_exists($logPath)) {
                @touch($logPath);
                @chmod($logPath, 0644);
            }
        } catch (\Exception $e) {
            // Log to system error log if our log file fails
            error_log("VLAN Automation: Could not ensure log directory for {$logPath}: " . $e->getMessage());
        }
    }

    private function debugLog($message, $minLevel = 1) {
        $debugLevel = $this->config['debug_level'] ?? 0;
        
        if ($debugLevel < $minLevel) {
            return;  // Don't log if debug level is too low
        }
        
        $debugLogPath = $this->config['debug_log_path'] ?? '/var/www/html/storage/logs/tenantos-vlan-creation.log';
        
        try {
            // Ensure directory exists
            $this->ensureLogDirExists($debugLogPath);
            
            file_put_contents(
                $debugLogPath,
                date('Y-m-d H:i:s') . " [L{$minLevel}] " . $message . "\n",
                FILE_APPEND
            );
        } catch (\Exception $e) {
            // Silently fail if we can't write to debug log
        }
    }

    private function getSwitchDetailsFromApi($switchId) {
        try {
            $apiToken = $this->config['api_token'] ?? null;
            if (!$apiToken) {
                return null;
            }

            $baseUrl = rtrim(config('app.url'), '/');
            $url = "{$baseUrl}/api/networkDevices/{$switchId}/extendedDetails";
            
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
            ])->get($url);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            $result = $data['result'] ?? null;
            
            if (!$result) {
                return null;
            }

            return [
                'name' => $result['name'] ?? null,
                'vendor' => $result['statusCache']['content']['result'] ?? null,
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    private function getSwitchParity($switchPosition) {
        // switchPosition: 0 = EVEN, 1 = ODD (based on array position in pair)
        return $switchPosition;
    }

    private function applySwitchConfiguration($switchId, $serverId, $portName, $vlanId, $subnets, $ipv4Address, $mlagEnabled, $mlagPeer) {
        try {
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] applySwitchConfiguration called");
            }

            // Get switch details from API
            $switchInfo = $this->getSwitchDetailsFromApi($switchId);
            if (!$switchInfo) {
                logActivity("[Switch $switchId] ERROR: Could not get switch details");
                return false;
            }
            $switchName = $switchInfo['name'];
            
            $switchFacade = new switchFacade($switchId);

            $vendor = 'arista';
            if (stripos($switchName, 'juniper') !== false) {
                $vendor = 'juniper';
            }

            $template = $this->loadTemplate($vendor);
            if (!$template) {
                logActivity("[Switch $switchId] ERROR: No template for vendor $vendor");
                return false;
            }

            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("[Switch $switchId] Loaded template for $vendor");
            }

            $portDescription = "Server {$serverId} - VLAN {$vlanId} - " . date('Y-m-d');

            // Extract subnet values
            $routedSubnet = $subnets['routed'] ?? '';
            $hostSubnet = $subnets['host'] ?? '';
            $ipv6Address = $this->calculateHostAddress($hostSubnet);  // ::2 address
            
            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("[Switch $switchId] IPv4: $ipv4Address, Routed Subnet: $routedSubnet, Host Subnet: $hostSubnet, Host Address: $ipv6Address");
            }

            // Prepare LACP/Port-Channel configuration using block templates from config
            $lacpConfig = '';
            $portChannelConfig = '';
            $channelGroup = '';
            
            if ($mlagEnabled && $mlagPeer) {
                // switchPosition from pair: 0=EVEN, 1=ODD
                $switchParity = $this->getSwitchParity($mlagPeer['switchPosition']);
                $vlanParity = $vlanId % 2;
                $isPrimary = ($vlanParity === $switchParity);
                $switchRole = $isPrimary ? "PRIMARY" : "SECONDARY";
                
                $positionName = $mlagPeer['switchPosition'] === 0 ? "EVEN (position 0)" : "ODD (position 1)";
                if (($this->config['debug_level'] ?? 0) >= 1) {
                    logActivity("    VLAN $vlanId is " . ($vlanParity ? "ODD" : "EVEN"));
                    logActivity("    Switch {$switchName} is $positionName in pair");
                    logActivity("    This switch is $switchRole for LACP");
                }

                // Extract both PORT_STRING (original format) and PORT_NUMBER (converted format)
                // e.g., Ethernet39 → string="39", number="39"
                // e.g., Ethernet26/4 → string="26/4", number="264"
                // e.g., Port-Channel264 → string="26/4", number="264" (with format #/#)
                $portNumbers = $this->extractPortNumbers($portName, $switchId);
                
                // CRITICAL: If we can't safely extract port number, abort MLAG configuration
                if ($portNumbers === null) {
                    logActivity("    ERROR: Cannot safely extract port number from '{$portName}', aborting MLAG configuration");
                    logActivity("    Skipping LACP/Port-Channel setup for this server");
                    $mlagEnabled = false;  // Disable MLAG for this attempt
                } else {
                    $portString = $portNumbers['string'];
                    $channelGroup = $portNumbers['number'];
                    if (($this->config['debug_level'] ?? 0) >= 1) {
                        logActivity("    Port String: {$portString}, Channel Group: {$channelGroup}");
                    }

                // Get LACP configuration block template from config
                $lacpConfigBlock = $this->config['lacp_config_block'] ?? '';
                if ($lacpConfigBlock) {
                    // Get role-specific configuration values
                    $rolePath = $isPrimary ? 'port_channel.primary' : 'port_channel.secondary';
                    $lacpMode = $this->getConfigValue($rolePath . '.lacp_mode', 'passive');
                    $lacpPriority = $this->getConfigValue($rolePath . '.lacp_priority', '100');
                    
                    if (($this->config['debug_level'] ?? 0) >= 1) {
                        logActivity("    LACP Mode: {$lacpMode}, Priority: {$lacpPriority}");
                    }
                    
                    // Fill the LACP block template with actual values
                    $lacpConfig = $this->replacePlaceholders($lacpConfigBlock, [
                        'CHANNEL_GROUP' => $channelGroup,
                        'PORT_STRING' => $portString,
                        'PORT_NUMBER' => $channelGroup,
                        'LACP_MODE' => $lacpMode,
                        'LACP_PRIORITY' => $lacpPriority,
                    ]);
                }

                // Get Port-Channel configuration block template from config
                $portChannelConfigBlock = $this->config['port_channel_config_block'] ?? '';
                if ($portChannelConfigBlock) {
                    // Fill the Port-Channel block template with actual values
                    $portChannelConfig = $this->replacePlaceholders($portChannelConfigBlock, [
                        'CHANNEL_GROUP' => $channelGroup,
                        'PORT_STRING' => $portString,
                        'PORT_NUMBER' => $channelGroup,
                        'MLAG_ID' => $channelGroup,
                        'SERVER_ID' => $serverId,
                        'VLAN_ID' => $vlanId,
                        'DATE' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            } else {
                logActivity("    MLAG/LACP: Not configured (disabled or no peer)");
            }

            // Two-level substitution:
            // 1. Blocks are filled with role-specific values above
            // 2. Blocks are then inserted into template below
            $config = $this->replacePlaceholders($template, [
                'SERVER_ID' => $serverId,
                'VLAN_ID' => $vlanId,
                'VLAN_NAME' => "VLAN_{$vlanId}",
                'PORT_NAME' => $portName,
                'PORT_STRING' => $portString ?? '',
                'PORT_NUMBER' => $channelGroup,
                'PORT_DESCRIPTION' => $portDescription,
                'CHANNEL_GROUP' => $channelGroup,
                'MLAG_ID' => $channelGroup,
                'LACP_CONFIG' => $lacpConfig,
                'PORT_CHANNEL_CONFIG' => $portChannelConfig,
                'IPV4_ADDRESS' => $ipv4Address,
                'IPV6_ADDRESS' => $ipv6Address,
                'IPV6_ROUTED_SUBNET' => $routedSubnet,
                'IPV6_HOST_SUBNET' => $hostSubnet,
                'MLAG_DOMAIN' => '1',
                'MLAG_PRIORITY' => '100',
                'DATE' => date('Y-m-d H:i:s'),
                'TIMESTAMP' => date('Y-m-d H:i:s'),
            ]);

            // Log full configuration with substituted variables (Debug Level 3)
            $debugLog = "\n[CONFIGURATION DEBUG] Server {$serverId} - Switch {$switchId}\n";
            $debugLog .= "Switch Name: {$switchName}\n";
            $debugLog .= "Port: {$portName}\n";
            $debugLog .= "VLAN: {$vlanId}\n";
            $debugLog .= "\nSUBSTITUTED VARIABLES:\n";
            $debugLog .= "  SERVER_ID: {$serverId}\n";
            $debugLog .= "  VLAN_ID: {$vlanId}\n";
            $debugLog .= "  VLAN_NAME: VLAN_{$vlanId}\n";
            $debugLog .= "  PORT_NAME: {$portName}\n";
            $debugLog .= "  PORT_STRING: " . ($portString ?? 'N/A') . "\n";
            $debugLog .= "  PORT_NUMBER: {$channelGroup}\n";
            $debugLog .= "  PORT_DESCRIPTION: {$portDescription}\n";
            $debugLog .= "  CHANNEL_GROUP: {$channelGroup}\n";
            $debugLog .= "  MLAG_ID: {$channelGroup}\n";
            $debugLog .= "  IPV4_ADDRESS: {$ipv4Address}\n";
            $debugLog .= "  IPV6_ADDRESS: {$ipv6Address}\n";
            $debugLog .= "  IPV6_ROUTED_SUBNET: {$routedSubnet}\n";
            $debugLog .= "  IPV6_HOST_SUBNET: {$hostSubnet}\n";
            $debugLog .= "  LACP_CONFIG: " . ($lacpConfig ? "[configured]" : "[empty]") . "\n";
            $debugLog .= "  PORT_CHANNEL_CONFIG: " . ($portChannelConfig ? "[configured]" : "[empty]") . "\n";
            $debugLog .= "  MLAG_DOMAIN: 1\n";
            $debugLog .= "  MLAG_PRIORITY: 100\n";
            $debugLog .= "\n[FULL CONFIGURATION TO BE APPLIED]\n";
            $debugLog .= str_repeat("-", 80) . "\n";
            $debugLog .= $config;
            $debugLog .= "\n" . str_repeat("-", 80) . "\n";
            $debugLog .= "[END OF CONFIGURATION]\n";
            
            // Log to Laravel log as debug level 3
            $this->debugLog($debugLog, 3);
            
            // Also log to test file for easy manual testing
            try {
                $debugLogPath = $this->config['debug_log_path'] ?? '/var/www/html/storage/logs/tenantos-vlan-creation.log';
                $this->ensureLogDirExists($debugLogPath);
                file_put_contents(
                    $debugLogPath,
                    date('Y-m-d H:i:s') . " - " . $debugLog . "\n",
                    FILE_APPEND
                );
            } catch (\Exception $e) {
                logActivity("[Switch $switchId] WARNING: Could not write to debug log: " . $e->getMessage());
            }

            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("[Switch $switchId] Executing configuration commands");
            }
            
            $switchFacade->setRelationType('server');
            $switchFacade->setRelationId($serverId);
            $switchFacade->setActionDescription("VLAN {$vlanId} ACLs" . ($mlagEnabled ? " and MLAG" : ""));

            $commandCount = 0;
            $failedCommand = null;
            
            foreach (explode("\n", $config) as $lineNum => $line) {
                $line = trim($line);
                
                // Skip empty lines and comments
                if (empty($line) || strpos($line, '!') === 0) {
                    continue;
                }
                
                $commandCount++;
                // Debug Level 2: Command execution
                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("[Switch $switchId] [CMD $commandCount] Executing: $line");
                }
                
                try {
                    $result = $switchFacade->executeInteractiveSshConfigureCommand($line);
                    
                    // Check if command was successful
                    // Handle different return types: false, array with success field, etc.
                    $commandFailed = false;
                    $resultMsg = '';
                    
                    if ($result === false) {
                        $commandFailed = true;
                        $resultMsg = "returned false";
                    } elseif (is_array($result)) {
                        if (isset($result['success']) && !$result['success']) {
                            $commandFailed = true;
                            $resultMsg = json_encode($result);
                        } elseif (isset($result['error'])) {
                            $commandFailed = true;
                            $resultMsg = $result['error'];
                        }
                    }
                    
                    if ($commandFailed) {
                        // Debug Level 1: Command failures
                        logActivity("[Switch $switchId] [CMD $commandCount] ❌ FAILED");
                        logActivity("[Switch $switchId] [CMD $commandCount] Result: $resultMsg");
                        $failedCommand = [
                            'number' => $commandCount,
                            'line' => $line,
                            'result' => $resultMsg,
                        ];
                        logActivity("[Switch $switchId] [CMD $commandCount] Stopping command execution");
                        break;
                    } else {
                        // Debug Level 1: Command success
                        if (($this->config['debug_level'] ?? 0) >= 1) {
                            logActivity("[Switch $switchId] [CMD $commandCount] ✓ OK");
                        }
                    }
                    
                } catch (\Exception $e) {
                    logActivity("[Switch $switchId] [CMD $commandCount] ❌ EXCEPTION");
                    logActivity("[Switch $switchId] [CMD $commandCount] Error: " . $e->getMessage());
                    logActivity("[Switch $switchId] [CMD $commandCount] Line: $line");
                    $failedCommand = [
                        'number' => $commandCount,
                        'line' => $line,
                        'error' => $e->getMessage(),
                    ];
                    logActivity("[Switch $switchId] [CMD $commandCount] Stopping command execution");
                    break;
                }
            }

            $failureInfo = '';
            if ($failedCommand) {
                $failureInfo = " - FAILED at command " . $failedCommand['number'] . ": " . $failedCommand['line'];
            }
            logActivity("[Switch $switchId] Command execution complete: $commandCount commands executed" . $failureInfo);

            $result = $switchFacade->commit();
            
            // ===== DETAILED SWITCHFACADE LOGGING (Debug Level 3) =====
            if (($this->config['debug_level'] ?? 0) >= 3) {
                logActivity("[Switch $switchId] [SWITCHFACADE] Commit Result:");
                logActivity("[Switch $switchId]   Success: " . ($result['success'] ? 'true' : 'false'));
                if (isset($result['message'])) {
                    logActivity("[Switch $switchId]   Message: {$result['message']}");
                }
                if (isset($result['error'])) {
                    logActivity("[Switch $switchId]   Error: {$result['error']}");
                }
                
                if (isset($failedCommand)) {
                    logActivity("[Switch $switchId] [CONTEXT] Failed command:");
                    logActivity("[Switch $switchId]   Command #" . $failedCommand['number']);
                    logActivity("[Switch $switchId]   Text: " . $failedCommand['line']);
                    if (isset($failedCommand['result'])) {
                        logActivity("[Switch $switchId]   Result: " . $failedCommand['result']);
                    } elseif (isset($failedCommand['error'])) {
                        logActivity("[Switch $switchId]   Error: " . $failedCommand['error']);
                    }
                }
                
                // Get switchFacade logs for detailed debugging
                try {
                    $logs = $switchFacade->getLogs();
                    if ($logs && is_array($logs)) {
                        logActivity("[Switch $switchId] [SWITCHFACADE] Logs (" . count($logs) . " entries):");
                        foreach ($logs as $index => $log) {
                            if (is_array($log)) {
                                $logStr = isset($log['message']) ? $log['message'] : json_encode($log);
                            } else {
                                $logStr = (string)$log;
                            }
                            logActivity("[Switch $switchId]   [{$index}] {$logStr}");
                        }
                    } else {
                        logActivity("[Switch $switchId] [SWITCHFACADE] No logs available");
                    }
                } catch (\Exception $e) {
                    logActivity("[Switch $switchId] [SWITCHFACADE] Could not retrieve logs: " . $e->getMessage());
                }
            }
            // ===== END SWITCHFACADE LOGGING =====
            
            if ($result['success']) {
                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("[Switch $switchId] ✓ Switch configuration applied successfully");
                }
                return true;
            } else {
                logActivity("[Switch $switchId] ❌ Switch configuration commit failed");
                return false;
            }

        } catch (\Exception $e) {
            logActivity("[Switch $switchId] Exception in applySwitchConfiguration: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract channel group number from port name
     * Ethernet39 → 39
     * Ethernet2/1 → 21
     * Ethernet10/1 → 101
     */
    private function extractChannelGroupNumber($portName) {
        // Get interface patterns from config
        $patterns = $this->config['interface_patterns'] ?? [];
        
        if (empty($patterns)) {
            logActivity("ERROR: No interface_patterns defined in config");
            return null;
        }
        
        // Try each pattern in order
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $portName, $matches)) {
                // Handle multiple capture groups (for formats like Ethernet2/1)
                if (count($matches) > 2) {
                    // Multiple groups - combine them (e.g., Ethernet2/1 → "21")
                    $result = '';
                    for ($i = 1; $i < count($matches); $i++) {
                        $result .= $matches[$i];
                    }
                    logActivity("DEBUG: Matched pattern '{$pattern}' with groups, result: {$result}");
                    return $result;
                } else {
                    // Single group - use it directly
                    logActivity("DEBUG: Matched pattern '{$pattern}' with result: {$matches[1]}");
                    return $matches[1];
                }
            }
        }
        
        // No pattern matched
        logActivity("ERROR: Could not extract port number from '{$portName}'. No matching interface pattern found.");
        logActivity("DEBUG: Configured patterns: " . implode(', ', array_slice($patterns, 0, 3)) . "...");
        return null;
    }

    /**
     * Extract both PORT_STRING (original format) and PORT_NUMBER (converted format)
     * Returns array: ['string' => '10/1', 'number' => '101'] or ['string' => '39', 'number' => '39']
     */
    private function extractPortNumbers($portName, $switchId = null) {
        $patterns = $this->config['interface_patterns'] ?? [];
        
        if (empty($patterns)) {
            return null;
        }
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $portName, $matches)) {
                if (count($matches) > 2) {
                    // Multiple groups: keep original format for STRING, combine for NUMBER
                    $portString = '';
                    $portNumber = '';
                    for ($i = 1; $i < count($matches); $i++) {
                        $portNumber .= $matches[$i];
                        if ($i > 1) {
                            $portString .= '/';
                        }
                        $portString .= $matches[$i];
                    }
                    return [
                        'string' => $portString,
                        'number' => $portNumber,
                    ];
                } else {
                    // Single group - check if we need to decode modular format
                    $portNumber = $matches[1];
                    $portString = $portNumber;  // Default to same
                    
                    // If we have switchId, check if this port needs decoding
                    if ($switchId) {
                        $portFormat = $this->getPortFormatForSwitch($switchId);
                        
                        // If switch uses #/# format and we have a 3-digit combined number,
                        // decode it back to modular format for PORT_STRING
                        if ($portFormat === '#/#' && strlen($portNumber) === 3) {
                            $portString = substr($portNumber, 0, 2) . '/' . substr($portNumber, 2, 1);
                        }
                    }
                    
                    return [
                        'string' => $portString,
                        'number' => $portNumber,
                    ];
                }
            }
        }
        
        return null;
    }

    /**
     * Get configuration value using dot notation
     * Example: getConfigValue('port_channel.primary.lacp_mode', 'passive')
     */
    private function getConfigValue($path, $default = null) {
        $keys = explode('.', $path);
        $value = $this->config;
        
        foreach ($keys as $key) {
            if (isset($value[$key])) {
                $value = $value[$key];
            } else {
                return $default;
            }
        }
        
        return $value;
    }

    private function loadTemplate($vendor) {
        try {
            $templatePath = $this->config['switch_templates'][$vendor] ?? null;
            
            if (!$templatePath || !file_exists($templatePath)) {
                return null;
            }

            $result = file_get_contents($templatePath);
            return $result !== false ? $result : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    private function replacePlaceholders($template, $values) {
        foreach ($values as $key => $value) {
            $template = str_replace("{{$key}}", $value, $template);
        }
        return $template;
    }

    /**
     * UPDATED: Calculate IPv6 :2 address (server-side)
     * Example: 2602:f937:0:b00::/56 → 2602:f937:0:b00::2
     * Previously calculated ::1 (gateway)
     */
    private function calculateIpv6ServerAddress($subnet) {
        try {
            if (!$subnet) {
                return null;
            }
            
            if (preg_match('/^([a-f0-9:]+)\/\d+$/', $subnet, $matches)) {
                $baseAddress = $matches[1];
                if (!str_ends_with($baseAddress, '::')) {
                    $baseAddress .= '::';
                }
                return $baseAddress . '2';  // CHANGED: :2 for server (was :1 gateway)
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getServerIpv4($serverId) {
        try {
            $ip = \DB::table('ipassignments')
                ->where('servers_id', $serverId)
                ->whereRaw("ip NOT LIKE '%:%'")
                ->first();
            return $ip ? $ip->ip : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get routed subnet from database and calculate host subnet and address
     * The TNSR script converts: 2602:f937:0:b00::/56 → 2602:f937:0:bb00::
     * Returns array with calculated values for template
     */
    private function getServerSubnets($serverId) {
        try {
            $routedSubnet = \DB::table('ipassignments')
                ->where('servers_id', $serverId)
                ->where('isSubnet', 1)
                ->where('ip', 'like', '%:%')
                ->value('ip');
            
            if (!$routedSubnet) {
                return [];
            }
            
            // Calculate the host subnet using TNSR pattern
            // 2602:f937:0:b00::/56 → 2602:f937:0:bb00::/64
            $hostSubnet = $this->calculateHostSubnet($routedSubnet);
            
            return [
                'routed' => $routedSubnet,
                'host' => $hostSubnet,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate host subnet following TNSR pattern
     * Takes third hextet and inserts 'b' before it
     * 2602:f937:0:b00::/56 → 2602:f937:0:bb00::/64
     */
    private function calculateHostSubnet($routedSubnet) {
        try {
            // Match pattern: 2602:f937:0:XYZ::/56
            if (preg_match('/^([0-9a-f]+:[0-9a-f]+:[0-9a-f]+:)([0-9a-f]+)(::\/56)$/i', $routedSubnet, $matches)) {
                $prefix = $matches[1];     // 2602:f937:0:
                $hextet = $matches[2];     // b00
                
                // Insert 'b' before the hextet: b00 → bb00
                $hostHextet = 'b' . $hextet;
                
                // Return as /64 (host subnet)
                return $prefix . $hostHextet . '::/64';
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Calculate host IP address from host subnet
     * 2602:f937:0:bb00::/64 → 2602:f937:0:bb00::2
     */
    private function calculateHostAddress($hostSubnet) {
        try {
            if (!$hostSubnet) {
                return null;
            }
            
            // Remove /64 and append ::2
            return str_replace('::/64', '::2', $hostSubnet);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getVlanFromApi($switchId, $portName, $serverId) {
        try {
            $apiToken = $this->config['api_token'] ?? null;
            if (!$apiToken) {
                return null;
            }

            $baseUrl = rtrim(config('app.url'), '/');
            $url = "{$baseUrl}/api/networkDevices/{$switchId}/extendedDetails";
            
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if ($attempt > 1) {
                    sleep(2);
                }
                
                try {
                    $response = Http::withOptions([
                        'verify' => false,
                        'timeout' => 60,
                        'connect_timeout' => 10,
                    ])->withHeaders([
                        'Authorization' => 'Bearer ' . $apiToken,
                    ])->get($url);

                    if (!$response->successful()) {
                        if ($attempt < 3) continue;
                        return null;
                    }

                    $data = $response->json();
                    $interfaces = $data['result']['extendedDetails']['interfaces'] ?? [];
                    
                    foreach ($interfaces as $iface) {
                        if (($iface['portName'] ?? $iface['name'] ?? null) === $portName) {
                            foreach (($iface['vlans'] ?? []) as $vlan) {
                                if ((isset($vlan['isNativeVlan']) && $vlan['isNativeVlan']) ||
                                    (isset($vlan['isAccessVlan']) && $vlan['isAccessVlan'])) {
                                    if ($vlanId = $vlan['id'] ?? null) {
                                        logActivity("  ✓ Found VLAN {$vlanId} on port {$portName}");
                                        return [
                                            'vlan' => (int)$vlanId,
                                            'name' => $vlan['name'] ?? null,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    
                    logActivity("  ERROR: Port {$portName} not found in API response or has no active VLAN");
                    return null;
                    
                } catch (\Exception $e) {
                    if ($attempt < 3) {
                        logActivity("  [API] Attempt $attempt failed: " . $e->getMessage());
                        continue;
                    }
                    logActivity("  ERROR: API request failed: " . $e->getMessage());
                    return null;
                }
            }
            
            return null;

        } catch (\Exception $e) {
            logActivity("  Exception in getVlanFromApi: " . $e->getMessage());
            return null;
        }
    }

    private function parseVlanFromDescription($description) {
        // Parse VLAN from description like "Server 36 - VLAN 101 - 2025-11-29 20:27:19"
        if (preg_match('/VLAN\s+(\d+)/', $description, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function calculateGateway($subnet, $vlan) {
        try {
            // Calculate gateway using same TNSR pattern as host subnet
            // 2602:f937:0:b00::/56 → 2602:f937:0:bb00::1/64
            if (preg_match('/^([0-9a-f]+:[0-9a-f]+:[0-9a-f]+:)([0-9a-f]+)(::\/56)$/i', $subnet, $matches)) {
                $prefix = $matches[1];     // 2602:f937:0:
                $hextet = $matches[2];     // b00
                
                // Insert 'b' before the hextet: b00 → bb00
                $gatewayHextet = 'b' . $hextet;
                
                // Return as gateway with ::1
                return $prefix . $gatewayHextet . '::1/64';
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function createTnsrVlan($subnet, $vlan, $gateway) {
        try {
            $scriptPath = $this->config['router_script_path'] ?? '/var/www/html/scripts/tnsr-vlan-restconf-FIXED.php';
            
            if (!file_exists($scriptPath)) {
                return false;
            }

            exec(sprintf('php %s create %s %d 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($subnet),
                (int)$vlan
            ), $output, $returnCode);

            return $returnCode === 0;

        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('logActivity')) {
    function logActivity($message) {
        \Log::info("[VLAN-LISTENER] $message");
    }
}
