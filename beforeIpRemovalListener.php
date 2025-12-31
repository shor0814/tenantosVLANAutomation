<?php

namespace App\Custom\EventListeners;

require_once __DIR__ . '/RequestDebugDumper.php';
use App\Events\publicEvents\serverIpAssignments\beforeServerIpRemovals;
use App\Services\networkDevices\devices\networkSwitches\helpers;
use App\Services\networkDevices\devices\networkSwitches\switchFacade;
use Illuminate\Support\Facades\Http;

/**
 * VLAN Removal Listener - Removes VLAN configuration when IP is removed
 * 
 * Triggers on: afterServerIpRemoval event
 * Action: 
 *  1. Detects VLAN assigned to removed IP
 *  2. Removes trunk VLAN from switch port
 *  3. Removes both IPv4 and IPv6 ACLs
 *  4. Calls TNSR script with DELETE command
 * 
 * Dependencies:
 * - Requires TNSR script to support: php tnsr-vlan-restconf-FIXED.php delete {subnet} {vlan}
 * - EventServiceProvider registration for afterServerIpRemoval
 */
class beforeIpRemovalListener {

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

    public function handle(beforeServerIpRemovals $event) {
        try {
            logActivity("=== beforeIpRemovalListener: Processing IP removal for server {$event->serverId} ===");
            
            // ================================================================
            // BYPASS: Check if caller explicitly set performVlanActions=none
            // ================================================================
            // This prevents processing when subnets are removed via API with
            // performVlanActions=none (e.g., routed subnet deallocations)
            try {
                $requestData = request()->all();
                $performVlanActions = $requestData['performVlanActions'] ?? 'perform';
                
                if ($performVlanActions === 'none') {
                    logActivity("Request has performVlanActions=none - skipping VLAN automation");
                    return;
                }
            } catch (\Exception $e) {
                logActivity("Could not check performVlanActions: " . $e->getMessage());
                // Continue with normal processing if check fails
            }
            
            // Skip if no IPs being deleted
            if (!isset($event->deletedIps) || empty($event->deletedIps)) {
                logActivity("No IPs being deleted - nothing to process");
                return;
            }
            
            // Extract /64 host subnet being removed
            $hostSubnet = null;
            foreach ($event->deletedIps as $ipEntry) {
                $ip = null;
    
                // Handle array of IP strings
                if (is_array($ipEntry)) {
                    foreach ($ipEntry as $item) {
                        if (is_string($item) && strpos($item, ':') !== false) {
                            $ip = $item;
                            break;
                        }
                    }
                } elseif (is_string($ipEntry) && strpos($ipEntry, ':') !== false) {
                    $ip = $ipEntry;
                }
    
                if ($ip) {
                    $hostSubnet = $ip;  // This is the /64 being removed
                    logActivity("✓ Host subnet being removed: {$ip}");
                    break;
                }
            }

            if (!$hostSubnet) {
                logActivity("No IPv6 addresses in removal - VLAN automation only applies to IPv6");
                return;
            }

            // Get switches for this server
            $getSwitches = helpers::getServerSwitchConnections($event->serverId);
            
            if (empty($getSwitches)) {
                logActivity("No switches found for server {$event->serverId}");
                return;
            }

            // Filter for all switches with advancedManagement
            $advancedMgmtSwitches = array_filter($getSwitches, function($switch) {
                return isset($switch['switchAutomationAvailable']) && $switch['switchAutomationAvailable'];
            });

            if (empty($advancedMgmtSwitches)) {
                logActivity("No switches with advancedManagement for server {$event->serverId}");
                return;
            }

            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("Found " . count($advancedMgmtSwitches) . " switches with advancedManagement");
            }

            // Separate automation and peer switches
            $automationSwitch = null;
            $peerSwitches = [];
            
            foreach ($advancedMgmtSwitches as $switch) {
                if ($switch['automationEnabled'] ?? false) {
                    $automationSwitch = $switch;
                } else {
                    $peerSwitches[] = $switch;
                }
            }
            
            // For removal, process PEER first (it keeps the trunk VLAN info)
            // Then process automation switch
            $processingQueue = array_merge($peerSwitches, ($automationSwitch ? [$automationSwitch] : []));
            
            if (empty($processingQueue)) {
                logActivity("No switches to process");
                return;
            }

            logActivity("Processing queue: " . implode(", ", array_map(function($s) { return "Switch " . $s['switchId']; }, $processingQueue)));

            // Get VLAN from the FIRST switch in queue (peer has trunk info)
            $firstSwitch = reset($processingQueue);
            $switchId = $firstSwitch['switchId'];
            
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] Querying for VLAN info to remove");
            }
            
            $switchFacade = new switchFacade($switchId);
            $switchFacade->setRelationType('server');
            $switchFacade->setRelationId($event->serverId);
            $switchFacade->setActionType('automation');
            
            $switchPort = $switchFacade->mapSnmpPortIdToInterfaceName($firstSwitch['snmpPortId']);
            logActivity("[Switch $switchId] Mapped port to interface: $switchPort");

            // Get VLAN range for this subnet to filter VLANs during detection
            $vlanRange = $this->getSubnetVlanRange($event->serverId);
            if ($vlanRange) {
                logActivity("[Switch $switchId] Accepted VLAN range: {$vlanRange['min']}-{$vlanRange['max']}");
            }
            
            // Get VLAN from API, trying both primary and alternate interfaces if needed
            // Only accept VLANs within the subnet's configured range
            $minVlan = $vlanRange['min'] ?? 100;
            $maxVlan = $vlanRange['max'] ?? 4093;
            $vlanInfo = $this->getVlanFromApiWithFallback($switchId, $switchPort, $event->serverId, $minVlan, $maxVlan);
            
            if (!$vlanInfo || !isset($vlanInfo['vlan'])) {
                logActivity("[Switch $switchId] ERROR: Could not get VLAN for removal");
                return;
            }

            $vlan = $vlanInfo['vlan'];
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] ✓ Found VLAN {$vlan} to remove");
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

            // Safety check
            $criticalVlans = [1];
            if (in_array($vlan, $criticalVlans)) {
                logActivity("ERROR: Refusing to remove critical VLAN {$vlan}");
                return;
            }

            // Get subnets for TNSR removal
            $subnets = $this->getServerSubnets($event->serverId);
            if (!empty($subnets)) {
                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("Found " . count($subnets) . " subnet(s)");
                }
            }

            // Remove from all switches in the queue
            foreach ($processingQueue as $switchToRemove) {
                $swId = $switchToRemove['switchId'];
                
                $swPort = $switchFacade->mapSnmpPortIdToInterfaceName($switchToRemove['snmpPortId']);
                
                $this->removeSwitchConfiguration(
                    $swId,
                    $event->serverId,
                    $swPort,
                    $vlan
                );
            }

            // Remove from TNSR and TenantOS
            if (!empty($subnets)) {
                $routedSubnet = $subnets[0];
                
                // Convert host subnet to gateway format for TNSR: 2602:f937:1:186::/64 → 2602:f937:1:186::1/64
                $gatewayIp = preg_replace('/::\//', '::1/', $hostSubnet);
                
                // Check if VLAN is reserved (from config)
                $reservedVlans = $this->config['reserved_vlans'] ?? [1];
                if (in_array($vlan, $reservedVlans)) {
                    logActivity("⚠ VLAN {$vlan} is reserved - skipping TNSR removal");
                } else {
                    logActivity("Removing TNSR VLAN {$vlan} with gateway IP {$gatewayIp} and routed subnet {$routedSubnet}");
                    
                    // Remove both host and routed subnets from TNSR
                    $success = $this->removeTnsrVlan($gatewayIp, $routedSubnet, $vlan);
                    if ($success) {
                        logActivity("✓ TNSR deletion succeeded");
                    } else {
                        logActivity("✗ TNSR deletion failed");
                    }
                }
                
                // Delete routed subnet from TenantOS API
                logActivity("Deleting routed subnet from TenantOS API");
                $apiSuccess = $this->deleteRoutedSubnetFromApi($event->serverId, $routedSubnet);
                if ($apiSuccess) {
                    logActivity("✓ Routed subnet deleted from TenantOS");
                } else {
                    logActivity("✗ Failed to delete routed subnet from TenantOS");
                }
            }

            logActivity("=== beforeIpRemovalListener: Complete ===");

        } catch (\Exception $e) {
            logActivity("Exception in beforeIpRemovalListener: " . $e->getMessage());
        }
    }

    /**
     * Remove switch configuration - uses removal template
     */
    private function removeSwitchConfiguration($switchId, $serverId, $portName, $vlanId) {
        try {
            $swId = $switchId;
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $swId] Removing VLAN {$vlanId} configuration");
            }

            // Get switch details from API
            $switchInfo = $this->getSwitchDetailsFromApi($switchId);
            if (!$switchInfo) {
                logActivity("[Switch $swId] ERROR: Could not get switch details");
                return false;
            }
            $switchName = $switchInfo['name'];

            // Get vendor from switch management info (from API)
            // Extract vendor name from managementVendor field: "aristaSsh" → "arista"
            $managementVendor = $switchInfo['managementVendor'] ?? '';
            $vendor = preg_replace('/Ssh$/i', '', $managementVendor);
            $vendor = strtolower($vendor);
            
            if (empty($vendor)) {
                logActivity("[Switch $swId] ERROR: Could not determine vendor from managementVendor: {$managementVendor}");
                return false;
            }
            
            // Verify removal template exists for this vendor
            $templatePath = $this->config['switch_removal_templates'][$vendor] ?? null;
            if (!$templatePath || !file_exists($templatePath)) {
                logActivity("[Switch $swId] ERROR: No removal template found for vendor '{$vendor}'. Configure in switch_removal_templates.");
                return false;
            }

            $template = file_get_contents($templatePath);
            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("[Switch $swId] Loaded removal template for vendor '{$vendor}' (from managementVendor: {$managementVendor})");
            }

            // Extract channel group and port number from port name
            // PORT_STRING: Original format (e.g., "39" or "26/4")
            // PORT_NUMBER: Converted format (e.g., "39" or "264")
            $channelGroup = '';
            $portNumber = '';
            $portString = '';
            
            // Get interface patterns from config
            $patterns = $this->config['interface_patterns'] ?? [];
            
            if (empty($patterns)) {
                logActivity("[Switch $swId] ERROR: No interface_patterns defined in config");
                return;
            }
            
            // Try each pattern in order
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $portName, $matches)) {
                    // Handle multiple capture groups (for formats like Ethernet2/1 or Ethernet10/1)
                    if (count($matches) > 2) {
                        // Multiple groups - combine for PORT_NUMBER (e.g., "10/1" → "101")
                        // but keep original format for PORT_STRING (e.g., "10/1")
                        $portNumber = '';
                        $portString = '';
                        for ($i = 1; $i < count($matches); $i++) {
                            $portNumber .= $matches[$i];
                            if ($i > 1) {
                                $portString .= '/';
                            }
                            $portString .= $matches[$i];
                        }
                    } else {
                        // Single group - check if we need to decode modular format
                        $portNumber = $matches[1];
                        $portString = $portNumber;  // Default to same
                        
                        // Check if this switch uses #/# format and we have a combined number
                        $portFormat = $this->getPortFormatForSwitch($swId);
                        if ($portFormat === '#/#' && strlen($portNumber) === 3) {
                            // Decode combined number back to modular format (264 → 26/4)
                            $portString = substr($portNumber, 0, 2) . '/' . substr($portNumber, 2, 1);
                        }
                    }
                    $channelGroup = $portNumber;
                    break;
                }
            }
            
            // If we couldn't extract, fail safely
            if (!$portNumber) {
                logActivity("[Switch $swId] ERROR: Could not extract port number from '{$portName}'. No matching interface pattern found.");
                logActivity("[Switch $swId] DEBUG: Check interface_patterns in config for supported formats");
                return;
            }

            // Determine switch role (PRIMARY/SECONDARY) based on VLAN parity
            $isPrimary = false;
            $lacp_mode = 'passive';
            $lacp_priority = 100;
            
            if ($channelGroup && isset($this->config['port_channel'])) {
                $portChannelConfig = $this->config['port_channel'];
                
                // Check VLAN parity (odd=PRIMARY, even=SECONDARY)
                $vlanIsPrimary = ($vlanId % 2) == 1;
                
                // Check switch parity in valid pairs
                if (isset($portChannelConfig['valid_pairs'])) {
                    foreach ($portChannelConfig['valid_pairs'] as $pair) {
                        if (in_array($switchId, $pair)) {
                            $switchPosition = array_search($switchId, $pair);
                            $switchIsOdd = ($switchPosition == 1);  // Position 1 is odd
                            
                            // Primary if VLAN parity matches switch parity
                            $isPrimary = ($vlanIsPrimary && $switchIsOdd) || (!$vlanIsPrimary && !$switchIsOdd);
                            break;
                        }
                    }
                }
                
                // Set LACP values based on role
                if ($isPrimary && isset($portChannelConfig['primary'])) {
                    $lacp_mode = $portChannelConfig['primary']['lacp_mode'] ?? 'active';
                    $lacp_priority = $portChannelConfig['primary']['lacp_priority'] ?? 1;
                } elseif (isset($portChannelConfig['secondary'])) {
                    $lacp_mode = $portChannelConfig['secondary']['lacp_mode'] ?? 'passive';
                    $lacp_priority = $portChannelConfig['secondary']['lacp_priority'] ?? 100;
                }
            }

            // Build LACP removal block with ALL placeholders replaced
            $lacpRemoval = '';
            if ($channelGroup && isset($this->config['lacp_config_removal'])) {
                $lacpRemoval = str_replace(
                    ['{PORT_NAME}', '{PORT_NUMBER}', '{CHANNEL_GROUP}', '{VLAN_ID}', '{LACP_MODE}', '{LACP_PRIORITY}'],
                    [$portName, $portNumber, $channelGroup, $vlanId, $lacp_mode, $lacp_priority],
                    $this->config['lacp_config_removal']
                );
            }
            
            // Build Port-Channel removal block with ALL placeholders replaced
            $portChannelRemoval = '';
            if ($channelGroup && isset($this->config['port_channel_config_removal'])) {
                $portChannelRemoval = str_replace(
                    ['{CHANNEL_GROUP}', '{SERVER_ID}', '{VLAN_ID}'],
                    [$channelGroup, $serverId, $vlanId],
                    $this->config['port_channel_config_removal']
                );
            }

            // Replace ALL template placeholders (including removal blocks)
            $config = str_replace(
                [
                    '{SERVER_ID}',
                    '{VLAN_ID}',
                    '{PORT_NAME}',
                    '{PORT_STRING}',
                    '{PORT_NUMBER}',
                    '{CHANNEL_GROUP}',
                    '{LACP_CONFIG_REMOVAL}',
                    '{PORT_CHANNEL_CONFIG_REMOVAL}',
                    '{DATE}',
                ],
                [
                    $serverId,
                    $vlanId,
                    $portName,
                    $portString,
                    $portNumber,
                    $channelGroup,
                    $lacpRemoval,
                    $portChannelRemoval,
                    date('Y-m-d H:i:s'),
                ],
                $template
            );

            // Log removal configuration for debugging
            try {
                $debugLog = "\n" . str_repeat("=", 80) . "\n";
                $debugLog .= "VLAN REMOVAL DEBUG - Switch {$swId} - Server {$serverId} - VLAN {$vlanId}\n";
                $debugLog .= str_repeat("=", 80) . "\n";
                $debugLog .= "FULL REMOVAL CONFIGURATION:\n";
                $debugLog .= str_repeat("-", 80) . "\n";
                $debugLog .= $config;
                $debugLog .= "\n" . str_repeat("-", 80) . "\n";
                $debugLog .= "END OF REMOVAL CONFIGURATION\n";
                $debugLog .= str_repeat("=", 80) . "\n";
                
                if (($this->config['debug_level'] ?? 0) >= 1) {
                    logActivity("[Switch $swId] Removal configuration prepared");
                }
                
                $debugLogPath = $this->config['debug_log_path'] ?? '/var/www/html/storage/logs/tenantos-vlan-creation.log';
                $this->ensureLogDirExists($debugLogPath);
                file_put_contents(
                    $debugLogPath,
                    date('Y-m-d H:i:s') . " - " . $debugLog . "\n",
                    FILE_APPEND
                );
            } catch (\Exception $logE) {
                logActivity("[Switch $swId] WARNING: Could not write removal config to log: " . $logE->getMessage());
            }

            $switchFacade = new switchFacade($switchId);
            $switchFacade->setRelationType('server');
            $switchFacade->setRelationId($serverId);
            $switchFacade->setActionDescription("VLAN {$vlanId} Removal");

            if (($this->config['debug_level'] ?? 0) >= 1) {
                logActivity("[Switch $swId] Executing removal commands");
            }
            
            $commandCount = 0;
            foreach (explode("\n", $config) as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '!') === 0) {
                    continue;
                }
                
                // Check for unreplaced placeholders
                if (preg_match('/\{[A-Z_]+\}/', $line)) {
                    logActivity("[Switch $swId]   [CMD] SKIPPING - Contains unreplaced placeholder: $line");
                    continue;
                }
                
                $commandCount++;
                // Debug Level 2: Command execution
                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("[Switch $swId]   [CMD $commandCount] Executing: $line");
                }
                
                try {
                    $switchFacade->executeInteractiveSshConfigureCommand($line);
                    // Debug Level 1: Command success
                    if (($this->config['debug_level'] ?? 0) >= 1) {
                        logActivity("[Switch $swId]   [CMD $commandCount] ✓ OK");
                    }
                } catch (\Exception $e) {
                    logActivity("[Switch $swId]   [CMD $commandCount] WARNING: " . $e->getMessage());
                }
            }

            $result = $switchFacade->commit();
            
            // Debug Level 3: SWITCHFACADE commit details
            if (($this->config['debug_level'] ?? 0) >= 3) {
                logActivity("[Switch $swId] [SWITCHFACADE] Commit Result:");
                logActivity("[Switch $swId]   Success: " . ($result['success'] ? 'true' : 'false'));
                if (isset($result['error'])) {
                    logActivity("[Switch $swId]   Error: {$result['error']}");
                }
            }

            if ($result['success']) {
                logActivity("[Switch $swId] ✓ VLAN {$vlanId} removal complete");
            } else {
                logActivity("[Switch $swId] ✗ VLAN {$vlanId} removal failed");
            }

            return $result['success'];

        } catch (\Exception $e) {
            logActivity("[Switch $swId] Exception in removeSwitchConfiguration: " . $e->getMessage());
            return false;
        }
    }

    private function loadRemovalTemplate($vendor) {
        try {
            // First check if there's a specific removal template path in config
            $templatePath = $this->config['switch_removal_templates'][$vendor] ?? null;
            
            // If not, try to derive from switch_templates path
            if (!$templatePath) {
                $basePath = $this->config['switch_templates'][$vendor] ?? null;
                if ($basePath) {
                    $templatePath = str_replace('.template', '-REMOVAL.template', $basePath);
                }
            }
            
            if (!$templatePath || !file_exists($templatePath)) {
                logActivity("[VLAN-REMOVAL] Removal template not found: {$templatePath}");
                return null;
            }
            
            return file_get_contents($templatePath);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get VLAN from API
     */
    private function getVlanFromApiOrAlias($switchId, $portName, $serverId, $minVlan = 100, $maxVlan = 4093) {
        try {
            $apiToken = $this->config['api_token'] ?? null;
            if (!$apiToken) {
                return null;
            }

            $baseUrl = rtrim(config('app.url'), '/');
            $url = "{$baseUrl}/api/networkDevices/{$switchId}/extendedDetails";
            
            $data = null;
            
            try {
                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 60,
                    'connect_timeout' => 10,
                ])->withHeaders([
                    'Authorization' => 'Bearer ' . $apiToken,
                ])->get($url);

                if ($response->successful()) {
                    $data = $response->json();
                }
            } catch (\Exception $e) {
                logActivity("  [API] Failed to query extendedDetails: " . $e->getMessage());
                return null;
            }

            if (!$data) {
                return null;
            }

            $interfaces = $data['result']['extendedDetails']['interfaces'] ?? [];
            
            foreach ($interfaces as $iface) {
                if (($iface['portName'] ?? $iface['name'] ?? null) === $portName) {
                    if (($this->config['debug_level'] ?? 0) >= 2) {
                        logActivity("  Found port {$portName} in API response");
                    }
                    
                    // Get port configuration info
                    $portType = $iface['portType'] ?? 'unknown';
                    if (($this->config['debug_level'] ?? 0) >= 2) {
                        logActivity("  Port type: {$portType}");
                    }
                    
                    // Look for the VLAN on this port within the accepted range
                    foreach (($iface['vlans'] ?? []) as $vlan) {
                        $vlanId = $vlan['id'] ?? null;
                        $isTagged = $vlan['isTaggedVlan'] ?? false;
                        $isAccess = $vlan['isAccessVlan'] ?? false;
                        $isNative = $vlan['isNativeVlan'] ?? false;
                        
                        if (!$vlanId) {
                            continue;
                        }
                        
                        // Check if VLAN is within accepted range
                        if ($vlanId < $minVlan || $vlanId > $maxVlan) {
                            if (($this->config['debug_level'] ?? 0) >= 2) {
                                logActivity("  Skipping VLAN {$vlanId} (outside range {$minVlan}-{$maxVlan})");
                            }
                            continue;
                        }
                        
                        // Accept VLAN from either physical or logical port if it matches port type
                        // Physical ports (Ethernet) in a channel report as access but carry tagged traffic
                        // Logical ports (Port-Channel) report as tagged/native and are the actual L3 interface
                        // Both are valid - if VLAN exists on the port, it's there
                        if ($isTagged || $isAccess || $isNative) {
                            logActivity("  ✓ Found VLAN {$vlanId} on {$portName} in range (portType={$portType}, tagged={$isTagged}, access={$isAccess}, native={$isNative})");
                            return [
                                'vlan' => (int)$vlanId,
                                'name' => $vlan['name'] ?? null,
                                'source' => 'api',
                            ];
                        }
                    }
                    
                    // Log all VLANs found for debugging if none matched range
                    $vlansFound = [];
                    foreach (($iface['vlans'] ?? []) as $vlan) {
                        $vlansFound[] = "ID:{$vlan['id']} T:{$vlan['isTaggedVlan']} A:{$vlan['isAccessVlan']} N:{$vlan['isNativeVlan']}";
                    }
                    if ($vlansFound) {
                        if (($this->config['debug_level'] ?? 0) >= 2) {
                            logActivity("  VLANs on port (none in range {$minVlan}-{$maxVlan}): " . implode(", ", $vlansFound));
                        }
                    } else {
                        if (($this->config['debug_level'] ?? 0) >= 2) {
                            logActivity("  No VLANs found on port");
                        }
                    }
                    
                    // Fallback: parse VLAN from port description and check range
                    if (($this->config['debug_level'] ?? 0) >= 2) {
                        logActivity("  Attempting fallback: parse VLAN from port description");
                    }
                    $alias = $iface['description'] ?? '';
                    $vlanFromAlias = $this->parseVlanFromDescription($alias);
                    
                    if ($vlanFromAlias && $vlanFromAlias >= $minVlan && $vlanFromAlias <= $maxVlan) {
                        if (($this->config['debug_level'] ?? 0) >= 2) {
                            logActivity("  ✓ Found VLAN {$vlanFromAlias} from description in range: {$alias}");
                        }
                        return [
                            'vlan' => (int)$vlanFromAlias,
                            'name' => "VLAN_{$vlanFromAlias}",
                            'source' => 'alias',
                        ];
                    } elseif ($vlanFromAlias) {
                        logActivity("  Description VLAN {$vlanFromAlias} outside range {$minVlan}-{$maxVlan}");
                    }
                }
            }

            logActivity("  ERROR: Could not get VLAN in range {$minVlan}-{$maxVlan} from API or description for {$portName}");
            return null;

        } catch (\Exception $e) {
            logActivity("  Exception in getVlanFromApiOrAlias: " . $e->getMessage());
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
                    // API structure: result.ports (not result.extendedDetails.interfaces)
                    $ports = $data['result']['ports'] ?? [];

                    // Find the port by name
                    foreach ($ports as $port) {
                        if (($port['name'] ?? null) === $portName) {
                            // Log only essential port info
                            try {
                                $portDebug = "\n" . str_repeat("=", 80) . "\n";
                                $portDebug .= "VLAN API LOOKUP - Port {$portName}\n";
                                $portDebug .= str_repeat("=", 80) . "\n";
                                $portDebug .= "Alias (from API): " . ($port['alias'] ?? '[EMPTY/NULL]') . "\n";
                                $portDebug .= "Assignment Type: " . (!empty($port['assignments']) ? 'server' : 'none') . "\n";
                                
                                logActivity("  [VLAN-DEBUG] " . $portDebug);
                                
                                $debugLogPath = $this->config['debug_log_path'] ?? '/var/www/html/storage/logs/tenantos-vlan-creation.log';
                                $this->ensureLogDirExists($debugLogPath);
                                file_put_contents(
                                    $debugLogPath,
                                    date('Y-m-d H:i:s') . " - " . $portDebug . "\n",
                                    FILE_APPEND
                                );
                            } catch (\Exception $logE) {
                                logActivity("  [VLAN-DEBUG] Could not write to log: " . $logE->getMessage());
                            }
                            
                            // Try to parse VLAN from port alias
                            $alias = $port['alias'] ?? '';
                            $vlanFromAlias = $this->parseVlanFromDescription($alias);
                            
                            // Log the parsing attempt
                            try {
                                $parseDebug = "\nVLAN PARSING DEBUG - Port {$portName}\n";
                                $parseDebug .= str_repeat("-", 80) . "\n";
                                $parseDebug .= "Alias value: [" . $alias . "]\n";
                                $parseDebug .= "Alias length: " . strlen($alias) . " chars\n";
                                $parseDebug .= "Alias is empty: " . ($alias === '' ? 'YES' : 'NO') . "\n";
                                $parseDebug .= "Regex pattern: /VLAN\\s+(\\d+)/\n";
                                $parseDebug .= "Parse result: " . ($vlanFromAlias ? "Found VLAN {$vlanFromAlias}" : "NO MATCH") . "\n";
                                $parseDebug .= str_repeat("-", 80) . "\n";
                                
                                logActivity("  [VLAN-PARSE-DEBUG] " . $parseDebug);
                                
                                $debugLogPath = $this->config['debug_log_path'] ?? '/var/www/html/storage/logs/tenantos-vlan-creation.log';
                                $this->ensureLogDirExists($debugLogPath);
                                file_put_contents(
                                    $debugLogPath,
                                    date('Y-m-d H:i:s') . " - " . $parseDebug . "\n",
                                    FILE_APPEND
                                );
                            } catch (\Exception $logE) {
                                logActivity("  [VLAN-PARSE-DEBUG] Could not write to log: " . $logE->getMessage());
                            }
                            
                            if ($vlanFromAlias && $vlanFromAlias != 1) {
                                if (($this->config['debug_level'] ?? 0) >= 2) {
                                    logActivity("  ✓ Found VLAN {$vlanFromAlias} from port alias: {$alias}");
                                }
                                return [
                                    'vlan' => (int)$vlanFromAlias,
                                    'name' => "VLAN_{$vlanFromAlias}",
                                    'source' => 'alias',
                                ];
                            }
                            
                            logActivity("  ERROR: Could not find VLAN in port alias");
                            return null;
                        }
                    }
                    
                    logActivity("  ERROR: Port {$portName} not found in API response");
                    return null;
                    
                } catch (\Exception $e) {
                    logActivity("  Exception in getVlanFromApi attempt {$attempt}: " . $e->getMessage());
                    if ($attempt < 3) continue;
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

    /**
     * Get IPv6 subnets
     */
    private function getServerSubnets($serverId) {
        try {
            return \DB::table('ipassignments')
                ->where('servers_id', $serverId)
                ->where('isSubnet', 1)
                ->where('ip', 'like', '%:%')
                ->where('ip', 'not like', '%/64%')  // Exclude host /64 subnets, only get routed /48 or /56
                ->pluck('ip')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate gateway using TNSR pattern
     * 2602:f937:0:b00::/56 → 2602:f937:0:bb00::1/64
     */
    /**
     * Execute TNSR script for deletion
     * Gateway IP is passed already in correct format (::1/CIDR)
     * Removes both host subnet and routed subnet from TNSR
     * Requires: php tnsr-vlan-restconf.php delete {gatewayIp} {routedSubnet} {vlan}
     */
    private function removeTnsrVlan($gatewayIp, $routedSubnet, $vlan) {
        try {
            $scriptPath = $this->config['router_script_path'] ?? '/var/www/html/scripts/tnsr-vlan-restconf.php';
            
            if (!file_exists($scriptPath)) {
                logActivity("[VLAN-REMOVAL] ERROR: Script not found: {$scriptPath}");
                return false;
            }

            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[VLAN-REMOVAL] Executing: php {$scriptPath} delete {$gatewayIp} {$routedSubnet} {$vlan}");
            }

            exec(sprintf('php %s delete %s %s %d 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($gatewayIp),
                escapeshellarg($routedSubnet),
                (int)$vlan
            ), $output, $returnCode);

            if ($returnCode === 0) {
                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("[VLAN-REMOVAL] TNSR script exit code: {$returnCode} (success)");
                }
                return true;
            } else {
                logActivity("[VLAN-REMOVAL] TNSR script exit code: {$returnCode}");
                if (!empty($output)) {
                    logActivity("[VLAN-REMOVAL] Output: " . implode("\n", $output));
                }
                return false;
            }

        } catch (\Exception $e) {
            logActivity("[VLAN-REMOVAL] Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete routed subnet from TenantOS via API
     * Calls: DELETE /api/servers/{id}/ipassignments/0
     * Body: {"ip": "{routedSubnet}", "performVlanActions": "none"}
     */
    private function deleteRoutedSubnetFromApi($serverId, $routedSubnet) {
        try {
            $baseUrl = rtrim(config('app.url'), '/');
            $apiToken = $this->config['api_token'] ?? null;
            
            if (!$apiToken) {
                logActivity("[API-REMOVAL] ERROR: API token not configured");
                return false;
            }
            
            $url = "{$baseUrl}/api/servers/{$serverId}/ipassignments/0";
            
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[API-REMOVAL] DELETE {$url} with subnet: {$routedSubnet}");
            }
            
            $payload = [
                'ip' => $routedSubnet,
                'performVlanActions' => 'none',  // Don't trigger listener again
            ];
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiToken}",
            ])->delete($url, $payload);
            
            if ($response->successful()) {
                logActivity("[API-REMOVAL] ✓ Successfully deleted subnet {$routedSubnet} from server {$serverId}");
                return true;
            } else {
                logActivity("[API-REMOVAL] ERROR: API call failed with status " . $response->status());
                logActivity("[API-REMOVAL] Response: " . $response->body());
                return false;
            }
            
        } catch (\Exception $e) {
            logActivity("[API-REMOVAL] Exception: " . $e->getMessage());
            return false;
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
     * Uses port format metadata to correctly reconstruct the interface name
     * If given Ethernet26/4 with format '#/#', returns Port-Channel264
     * If given Port-Channel264 with format '#/#', returns Ethernet26/4
     * If given Ethernet39 with format '#', returns Port-Channel39
     */
    private function getAlternatePortName($portName, $switchId) {
        // Get port format for this switch
        $portFormat = $this->getPortFormatForSwitch($switchId);
        
        // Extract the port type and number
        $isPortChannel = strpos($portName, 'Port-Channel') === 0;
        $isEthernet = strpos($portName, 'Ethernet') === 0;
        
        if ($isPortChannel) {
            // Converting Port-Channel to Ethernet
            $portNumber = str_replace('Port-Channel', '', $portName);
            
            // If format is #/# and we have combined number, decode it (264 → 26/4)
            if ($portFormat === '#/#' && strlen($portNumber) === 3) {
                $portNumber = substr($portNumber, 0, 2) . '/' . substr($portNumber, 2, 1);
            }
            return "Ethernet{$portNumber}";
        }
        
        if ($isEthernet) {
            // Converting Ethernet to Port-Channel
            $portNumber = str_replace('Ethernet', '', $portName);
            
            // If format is #/# and we have modular format, combine it (26/4 → 264)
            if ($portFormat === '#/#') {
                $portNumber = str_replace('/', '', $portNumber);
            }
            return "Port-Channel{$portNumber}";
        }
        
        return null;  // Unknown format
    }

    /**
     * Query VLAN from API, trying both primary and alternate interface names
     * Returns VLAN info if found on either interface, null otherwise
     */
    private function getVlanFromApiWithFallback($switchId, $portName, $serverId, $minVlan = 100, $maxVlan = 4093) {
        // First try the provided port name
        $vlanInfo = $this->getVlanFromApiOrAlias($switchId, $portName, $serverId, $minVlan, $maxVlan);
        
        if ($vlanInfo) {
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] Found VLAN on primary interface: {$portName}");
            }
            return $vlanInfo;
        }
        
        // Try alternate interface (Ethernet ↔ Port-Channel swap)
        $alternatePort = $this->getAlternatePortName($portName, $switchId);
        if ($alternatePort) {
            if (($this->config['debug_level'] ?? 0) >= 2) {
                logActivity("[Switch $switchId] VLAN not found on {$portName}, trying alternate: {$alternatePort}");
            }
            $vlanInfo = $this->getVlanFromApiOrAlias($switchId, $alternatePort, $serverId, $minVlan, $maxVlan);
            
            if ($vlanInfo) {
                if (($this->config['debug_level'] ?? 0) >= 2) {
                    logActivity("[Switch $switchId] Found VLAN on alternate interface: {$alternatePort}");
                }
                return $vlanInfo;
            }
        }
        
        // VLAN not found on either interface
        logActivity("[Switch $switchId] VLAN not found on {$portName} or alternate interface within range {$minVlan}-{$maxVlan}");
        return null;
    }

    /**
     * Get the VLAN range for a server's subnet
     * Returns ['min' => X, 'max' => Y] or null if not found
     */
    private function getSubnetVlanRange($serverId) {
        try {
            $apiToken = $this->config['api_token'] ?? null;
            if (!$apiToken) {
                return null;
            }

            $baseUrl = rtrim(config('app.url'), '/');
            
            // Query server IP assignments to get subnet ID
            $url = "{$baseUrl}/api/servers/{$serverId}/ipassignments";
            
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 10,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
            ])->get($url);

            if (!$response->successful()) {
                return null;
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
                return null;
            }

            // Query subnet details for VLAN range
            $subnetUrl = "{$baseUrl}/api/subnets/{$subnetId}/withDetails";
            
            $subnetResponse = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 10,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
            ])->get($subnetUrl);

            if (!$subnetResponse->successful()) {
                return null;
            }

            $subnetData = $subnetResponse->json();
            $subnet = $subnetData['result'] ?? [];

            $rangeStart = $subnet['range_start_access_vlan_id'] ?? null;
            $rangeEnd = $subnet['range_end_access_vlan_id'] ?? null;

            if ($rangeStart !== null && $rangeEnd !== null) {
                return [
                    'min' => (int)$rangeStart,
                    'max' => (int)$rangeEnd,
                ];
            }

            return null;

        } catch (\Exception $e) {
            logActivity("  Exception in getSubnetVlanRange: " . $e->getMessage());
            return null;
        }
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
                'managementVendor' => $result['managementVendor'] ?? null,
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    private function replacePlaceholders($template, $values) {
        $result = $template;
        foreach ($values as $placeholder => $value) {
            $result = str_replace('{' . $placeholder . '}', $value ?? '', $result);
        }
        return $result;
    }
}

if (!function_exists('logActivity')) {
    function logActivity($message) {
        \Log::info($message);
    }
}
