<?php
/**
 * TNSR VLAN Sync - FIXED Version
 * 
 * Fixed to handle actual API response structure:
 * response['netgate-interface:interfaces-config']['interface'] = array
 * 
 * Interface fields: name, enabled (NOT namespaced)
 */

$config = [
    'tnsr_host'        => 'XX.YY.10.3',
    'tnsr_username'    => 'TNSRUSERNAME',
    'tnsr_password'    => 'TNSRPASSWORD',
    'tnsr_port'        => 443,
    'parent_interface' => 'FortyGigabitEthernet65/0/1',
    'ipv6_prefix'      => 'XXXX:YYYY:0:',
    'dry_run'          => false,
    'verify_ssl'       => false,
    'verbose'          => true,
];

function log_msg($message, $level = 'INFO') {
    global $config;
    $colors = [
        'INFO'    => "\033[0;36m",
        'SUCCESS' => "\033[0;32m",
        'WARNING' => "\033[1;33m",
        'ERROR'   => "\033[0;31m",
        'DEBUG'   => "\033[0;37m",
    ];
    $reset = "\033[0m";
    $color = $colors[$level] ?? $colors['INFO'];
    if ($level === 'DEBUG' && !$config['verbose']) {
        return;
    }
    echo "{$color}[{$level}]{$reset} {$message}\n";
}

function extract_gateway_prefix($subnet, $ipv6_prefix) {
    $pattern = '/' . preg_quote($ipv6_prefix, '/') . '([0-9a-f]+)::/i';
    if (!preg_match($pattern, $subnet, $matches)) {
        throw new Exception("Could not extract prefix from subnet: {$subnet}");
    }
    $hextet = $matches[1];
    return $ipv6_prefix . 'b' . $hextet;
}

function tnsr_restconf_request($method, $path, $data = null) {
    global $config;
    $url = "https://{$config['tnsr_host']}:{$config['tnsr_port']}/restconf{$path}";
    log_msg("$method $path", 'DEBUG');
    if ($config['dry_run']) {
        log_msg("DRY-RUN: Would $method to $url", 'WARNING');
        return ['success' => true, 'dry_run' => true];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $headers = ['Accept: application/yang-data+json'];
    if ($method !== 'GET' && $data !== null) {
        $headers[] = 'Content-Type: application/yang-data+json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, "{$config['tnsr_username']}:{$config['tnsr_password']}");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $config['verify_ssl']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $config['verify_ssl'] ? 2 : 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL error: {$error}");
    }

    log_msg("Response: HTTP $httpCode", 'DEBUG');

    if ($httpCode >= 200 && $httpCode < 300 && $response) {
        $data = json_decode($response, true);
        if (isset($data['ietf-restconf:errors']['error'])) {
            $error = $data['ietf-restconf:errors']['error'];
            $errorTag = $error['error-tag'] ?? '';
            $errorMsg = $error['error-message'] ?? '';
            if ($errorTag === 'access-denied') {
                throw new Exception("NACM Access Denied: {$errorMsg}");
            }
            throw new Exception("RESTCONF Error: {$errorMsg}");
        }
    }

    if ($httpCode >= 400 && !($method === 'DELETE' && $httpCode == 404)) {
        throw new Exception("TNSR API error: HTTP $httpCode - $response");
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
        'response' => $response ? json_decode($response, true) : null
    ];
}

function create_subinterface($gatewayIp, $routedSubnet, $vlan) {
    global $config;

    // Validate gateway IP is in correct format (::1/CIDR)
    if (!preg_match('/^[0-9a-f:]+::1\/\d+$/i', $gatewayIp)) {
        throw new Exception("Invalid gateway IP format: $gatewayIp (must be ::1/CIDR, e.g., XXXX:YYYY:0:bb00::1/64)");
    }
    
    // Validate routed subnet is /48 or /56
    if (!preg_match('/^[0-9a-f:]+\/(48|56)$/i', $routedSubnet)) {
        throw new Exception("Invalid routed subnet: $routedSubnet (must be /48 or /56)");
    }
    
    if ($vlan < 1 || $vlan > 4094) {
        throw new Exception("Invalid VLAN: $vlan");
    }

    // Gateway IP is already in correct format: XXXX:YYYY:1:186::1/64
    // Derive customer link IP for next-hop: replace ::1 with ::2, remove CIDR
    $customerLinkIp = preg_replace('/::1\/\d+$/', '::2', $gatewayIp);  // XXXX:YYYY:1:186::1/64 → XXXX:YYYY:1:186::2

    log_msg("Creating TNSR subinterface", 'INFO');
    log_msg("  Gateway IP: $gatewayIp", 'INFO');
    log_msg("  Customer Link IP (next-hop): $customerLinkIp", 'INFO');
    log_msg("  Routed subnet: $routedSubnet", 'INFO');
    log_msg("  VLAN: $vlan", 'INFO');
    echo "\n";

    try {
        $interface = $config['parent_interface'];
        $subifName = "$interface.$vlan";

        log_msg("Step 1: Creating subinterface definition", 'INFO');
        $subifConfig = [
            'netgate-interface:subif-entry' => [
                [
                    'subid' => $vlan,
                    'vlan' => [
                        'exact-match' => true,
                        'outer-vlan-id' => $vlan
                    ],
                    'if-name' => $interface
                ]
            ]
        ];

        tnsr_restconf_request('POST', "/data/netgate-interface:interfaces-config/subinterfaces", $subifConfig);
        log_msg("✓ Subinterface definition created", 'SUCCESS');

        log_msg("Step 2: Configuring interface", 'INFO');
        $interfaceConfig = [
            'netgate-interface:interface' => [
                [
                    'name' => $subifName,
                    'enabled' => true,
                    'ipv6' => [
                        'address' => [
                            'ip' => [$gatewayIp]  // Already includes CIDR, e.g., XXXX:YYYY:0:bb00::1/64
                        ]
                    ]
                ]
            ]
        ];

        tnsr_restconf_request('PUT', '/data/netgate-interface:interfaces-config/interface=' . urlencode($subifName), $interfaceConfig);
        log_msg("✓ Interface configured", 'SUCCESS');

        log_msg("Step 3: Configuring router advertisements", 'INFO');
        
        // Extract prefix from gateway IP: XXXX:YYYY:1:186::1/64 → XXXX:YYYY:1:186::/64
        $prefixSpec = preg_replace('/::1\//', '::/', $gatewayIp);  // Replace ::1/ with ::/ (preserving slash)
        
        $raConfig = [
            'netgate-ipv6-ra:ipv6-router-advertisements' => [
                'send-advertisements' => true,
                'default-lifetime' => 1800,
                'prefix-list' => [
                    'prefix' => [
                        [
                            'prefix-spec' => $prefixSpec,
                            'valid-lifetime' => 2592000,
                            'preferred-lifetime' => 604800,
                            'on-link-flag' => true,
                            'autonomous-flag' => true
                        ]
                    ]
                ]
            ]
        ];

        try {
            tnsr_restconf_request('PUT', '/data/netgate-interface:interfaces-config/interface=' . urlencode($subifName) . '/ipv6/netgate-ipv6-ra:ipv6-router-advertisements', $raConfig);
            log_msg("✓ Router advertisements configured", 'SUCCESS');
        } catch (Exception $e) {
            log_msg("⚠ Warning: " . $e->getMessage(), 'WARNING');
        }

        log_msg("Step 4: Adding static route", 'INFO');
        $getResult = tnsr_restconf_request('GET', '/data/netgate-route-table:route-table-config');
        $routeConfig = $getResult['response'] ?? [];

        if (empty($routeConfig)) {
            $routeConfig = [
                'netgate-route-table:route-table-config' => [
                    'static-routes' => [
                        'route-table' => [
                            [
                                'name' => 'default',
                                'id' => 0,
                                'ipv6-routes' => [
                                    'route' => []
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }

        $newRoutes = [
            // Route for routed subnet (/48 or /56) only
            // The /64 host subnet is directly on the interface, so no explicit route needed
            [
                'destination-prefix' => $routedSubnet,
                'next-hop' => [
                    'hop' => [
                        [
                            'hop-id' => 0,
                            'ipv6-address' => $customerLinkIp,
                            'if-name' => $subifName
                        ]
                    ]
                ]
            ]
        ];

        $configKey = 'netgate-route-table:route-table-config';
        if (!isset($routeConfig[$configKey]['static-routes']['route-table'])) {
            $routeConfig[$configKey]['static-routes']['route-table'] = [
                ['name' => 'default', 'id' => 0, 'ipv6-routes' => ['route' => []]]
            ];
        }

        $routesAdded = [];
        foreach ($newRoutes as $newRoute) {
            $routeExists = false;
            $destination = $newRoute['destination-prefix'];
            
            foreach ($routeConfig[$configKey]['static-routes']['route-table'] as &$table) {
                if (($table['name'] ?? 'unknown') === 'default') {
                    if (!isset($table['ipv6-routes'])) {
                        $table['ipv6-routes'] = ['route' => []];
                    }
                    if (!isset($table['ipv6-routes']['route'])) {
                        $table['ipv6-routes']['route'] = [];
                    }

                    foreach ($table['ipv6-routes']['route'] as $existingRoute) {
                        if (($existingRoute['destination-prefix'] ?? '') === $destination) {
                            $routeExists = true;
                            break;
                        }
                    }

                    if (!$routeExists) {
                        $table['ipv6-routes']['route'][] = $newRoute;
                        $routesAdded[] = $destination;
                    } else {
                        log_msg("Route already exists for $destination", 'INFO');
                    }
                    break;
                }
            }
        }

        if (!empty($routesAdded)) {
            tnsr_restconf_request('PATCH', '/data/netgate-route-table:route-table-config', $routeConfig);
            log_msg("✓ Routes added: " . implode(', ', $routesAdded), 'SUCCESS');
        } else {
            log_msg("All routes already exist", 'INFO');
        }

        log_msg("✓ Subinterface created successfully", 'SUCCESS');
        echo "\n";

    } catch (Exception $e) {
        log_msg("✗ Error: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

function delete_subinterface($gatewayIp, $routedSubnet, $vlan) {
    global $config;

    // Validate gateway IP is in correct format (::1/CIDR)
    if (!preg_match('/^[0-9a-f:]+::1\/\d+$/i', $gatewayIp)) {
        throw new Exception("Invalid gateway IP format: $gatewayIp (must be ::1/CIDR)");
    }
    
    // Validate routed subnet is /48 or /56
    if (!preg_match('/^[0-9a-f:]+\/(48|56)$/i', $routedSubnet)) {
        throw new Exception("Invalid routed subnet: $routedSubnet (must be /48 or /56)");
    }

    log_msg("Deleting TNSR subinterface", 'INFO');
    log_msg("  Gateway IP: $gatewayIp", 'INFO');
    log_msg("  Routed subnet: $routedSubnet", 'INFO');
    log_msg("  VLAN: $vlan", 'INFO');
    echo "\n";

    try {
        $interface = $config['parent_interface'];
        $subifName = "$interface.$vlan";

        log_msg("Deleting routes", 'DEBUG');
        $getResult = tnsr_restconf_request('GET', '/data/netgate-route-table:route-table-config');
        $routeConfig = $getResult['response'] ?? [];

        if (!empty($routeConfig)) {
            $routesDeleted = [];
            $configKey = 'netgate-route-table:route-table-config';
            
            // Delete route for routed subnet only
            // The /64 host subnet is directly on the interface, so no route to delete
            $subnetsToDelete = [$routedSubnet];
            
            if (isset($routeConfig[$configKey]['static-routes']['route-table'])) {
                foreach ($routeConfig[$configKey]['static-routes']['route-table'] as &$table) {
                    if (($table['name'] ?? 'unknown') === 'default') {
                        if (isset($table['ipv6-routes']['route'])) {
                            foreach ($subnetsToDelete as $subnetToDelete) {
                                foreach ($table['ipv6-routes']['route'] as $key => $route) {
                                    if (($route['destination-prefix'] ?? '') === $subnetToDelete) {
                                        unset($table['ipv6-routes']['route'][$key]);
                                        $routesDeleted[] = $subnetToDelete;
                                        break;
                                    }
                                }
                            }
                            $table['ipv6-routes']['route'] = array_values($table['ipv6-routes']['route']);
                        }
                        break;
                    }
                }
            }

            if (!empty($routesDeleted)) {
                tnsr_restconf_request('PUT', '/data/netgate-route-table:route-table-config', $routeConfig);
                log_msg("✓ Route deleted", 'SUCCESS');
            }
        }

        log_msg("Deleting interface", 'DEBUG');
        tnsr_restconf_request('DELETE', '/data/netgate-interface:interfaces-config/interface=' . urlencode($subifName));
        log_msg("✓ Interface deleted", 'SUCCESS');

        try {
            tnsr_restconf_request('DELETE', '/data/netgate-interface:interfaces-config/subinterfaces/subif-entry=' . urlencode($interface) . ',' . $vlan);
            log_msg("✓ Subinterface definition deleted", 'SUCCESS');
        } catch (Exception $e) {
            log_msg("⚠ Warning: " . $e->getMessage(), 'WARNING');
        }

        log_msg("✓ Subinterface deleted successfully", 'SUCCESS');

    } catch (Exception $e) {
        log_msg("✗ Error: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

function verify_subinterface($gatewayIp, $routedSubnet, $vlan) {
    global $config;

    // Validate gateway IP is in correct format (::1/CIDR)
    if (!preg_match('/^[0-9a-f:]+::1\/\d+$/i', $gatewayIp)) {
        throw new Exception("Invalid gateway IP format: $gatewayIp (must be ::1/CIDR)");
    }
    
    // Validate routed subnet is /48 or /56
    if (!preg_match('/^[0-9a-f:]+\/(48|56)$/i', $routedSubnet)) {
        throw new Exception("Invalid routed subnet: $routedSubnet (must be /48 or /56)");
    }

    $interface = $config['parent_interface'];
    $subifName = "$interface.$vlan";

    log_msg("Verifying subinterface", 'INFO');
    log_msg("  Gateway IP: $gatewayIp", 'INFO');
    log_msg("  Routed subnet: $routedSubnet", 'INFO');
    echo "\n";

    $allPassed = true;

    try {
        $result = tnsr_restconf_request('GET', '/data/netgate-interface:interfaces-config/interface=' . urlencode($subifName));
        if ($result['http_code'] === 200) {
            log_msg("✓ Subinterface $subifName exists", 'SUCCESS');
        } else {
            log_msg("✗ Subinterface $subifName not found", 'ERROR');
            $allPassed = false;
        }
    } catch (Exception $e) {
        log_msg("✗ Error: " . $e->getMessage(), 'ERROR');
        $allPassed = false;
    }

    try {
        $result = tnsr_restconf_request('GET', '/data/netgate-route-table:route-table-config');
        if ($result['http_code'] === 200) {
            $found = false;
            $routeTableConfig = $result['response']['netgate-route-table:route-table-config'] ?? [];
            $staticRoutes = $routeTableConfig['static-routes'] ?? [];
            $tableConfig = $staticRoutes['route-table'] ?? [];

            foreach ($tableConfig as $table) {
                if (($table['name'] ?? 'unknown') === 'default') {
                    $ipv6Routes = $table['ipv6-routes'] ?? [];
                    $routes = $ipv6Routes['route'] ?? [];
                    foreach ($routes as $route) {
                        if (($route['destination-prefix'] ?? '') === $routedSubnet) {
                            $found = true;
                            break;
                        }
                    }
                }
            }

            if ($found) {
                log_msg("✓ Static route for $routedSubnet exists", 'SUCCESS');
            } else {
                log_msg("✗ Static route for $routedSubnet not found", 'ERROR');
                $allPassed = false;
            }
        }
    } catch (Exception $e) {
        log_msg("⚠ Warning: " . $e->getMessage(), 'WARNING');
    }

    echo "\n";
    return $allPassed;
}

function list_subinterfaces() {
    log_msg("Listing TNSR subinterfaces", 'INFO');
    echo "\n";

    try {
        $result = tnsr_restconf_request('GET', '/data/netgate-interface:interfaces-config');

        if ($result['http_code'] === 200) {
            // FIXED: Use correct key structure
            $configData = $result['response']['netgate-interface:interfaces-config'] ?? [];
            $interfaces = $configData['interface'] ?? [];

            if (empty($interfaces)) {
                log_msg("No interfaces found", 'WARNING');
            } else {
                $count = 0;
                foreach ($interfaces as $interface) {
                    // FIXED: No namespace prefix on interface fields
                    $name = $interface['name'] ?? 'unknown';

                    if (strpos($name, '.') !== false) {
                        $enabled = $interface['enabled'] ?? false;
                        $status = $enabled ? 'up' : 'down';
                        log_msg("  $name ($status)", 'INFO');
                        $count++;
                    }
                }

                if ($count === 0) {
                    log_msg("No subinterfaces found", 'WARNING');
                } else {
                    log_msg("Total: $count subinterfaces", 'INFO');
                }
            }
        } else {
            log_msg("Failed to get interfaces (HTTP {$result['http_code']})", 'WARNING');
        }
    } catch (Exception $e) {
        log_msg("Error: " . $e->getMessage(), 'ERROR');
    }

    echo "\n";
}

// ============================================================
// COMPREHENSIVE USAGE FUNCTION
// ============================================================
function show_usage() {
    global $config;
    
    $parent = $config['parent_interface'];
    $host = $config['tnsr_host'];
    
    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║  TNSR VLAN Management Script - RESTCONF API                                   ║\n";
    echo "║  Version: 3.4 - Production Ready (Fixed & Routed Subnets)                     ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    echo "CONFIGURATION:\n";
    echo "  TNSR Host:          {$host}\n";
    echo "  Parent Interface:   {$parent}\n";
    echo "\n";
    
    echo "USAGE:\n";
    echo "  php tnsr-vlan-restconf.php <COMMAND> <FIXED_SUBNET> <ROUTED_SUBNET> <VLAN_ID>\n";
    echo "\n";
    echo "  Where:\n";
    echo "    <FIXED_SUBNET>    = Host /64 subnet in gateway format (e.g., XXXX:YYYY:1:186::1/64)\n";
    echo "    <ROUTED_SUBNET>   = Routed /48 or /56 subnet (e.g., XXXX:YYYY:1:200::/48)\n";
    echo "    <VLAN_ID>         = VLAN number as integer (e.g., 101)\n";
    echo "\n";
    
    echo "COMMANDS:\n";
    echo "\n";
    
    echo "  ┌─ CREATE VLAN ───────────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf.php create <fixed/64> <routed/48-56> <vlan>        │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Purpose:  Create a VLAN with both fixed (host) and routed subnets                   │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Examples:                                                                           │\n";
    echo "  │   Create with /48 routed subnet:                                                    │\n";
    echo "  │     php tnsr-vlan-restconf.php create XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101│\n";
    echo "  │                                                                                     │\n";
    echo "  │   Create with /56 routed subnet:                                                    │\n";
    echo "  │     php tnsr-vlan-restconf.php create XXXX:YYYY:1:187::1/64 XXXX:YYYY:2::/56 102    │\n";
    echo "  │                                                                                     │\n";
    echo "  │ What it does:                                                                       │\n";
    echo "  │   1. Creates subinterface: FortyGigabitEthernet65/0/1.101                           │\n";
    echo "  │   2. Assigns gateway IP to subinterface (::1 address)                               │\n";
    echo "  │   3. Configures router advertisements for host subnet                               │\n";
    echo "  │   4. Creates static route for routed subnet pointing to server's next-hop           │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Return:  0 on success, 1 on failure                                                 │\n";
    echo "  └─────────────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "  ┌─ DELETE VLAN ───────────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf.php delete <fixed/64> <routed/48-56> <vlan>        │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Purpose:  Remove a VLAN and all associated configurations                           │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Examples:                                                                           │\n";
    echo "  │   Delete VLAN with /48 routed subnet:                                               │\n";
    echo "  │     php tnsr-vlan-restconf.php delete XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101│\n";
    echo "  │                                                                                     │\n";
    echo "  │   Delete VLAN with /56 routed subnet:                                               │\n";
    echo "  │     php tnsr-vlan-restconf.php delete XXXX:YYYY:1:187::1/64 XXXX:YYYY:2::/56 102    │\n";
    echo "  │                                                                                     │\n";
    echo "  │ What it does:                                                                       │\n";
    echo "  │   1. Removes static route for routed subnet                                         │\n";
    echo "  │   2. Deletes interface configuration                                                │\n";
    echo "  │   3. Removes subinterface definition                                                │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Return:  0 on success, 1 on failure                                                 │\n";
    echo "  └─────────────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "  ┌─ VERIFY VLAN ───────────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf.php verify <fixed/64> <routed/48-56> <vlan>        │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Purpose:  Verify VLAN exists and is correctly configured                            │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Examples:                                                                           │\n";
    echo "  │   Verify VLAN with /48 routed subnet:                                               │\n";
    echo "  │   php tnsr-vlan-restconf.php verify XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101  │\n";
    echo "  │                                                                                     │\n";
    echo "  │   Verify VLAN with /56 routed subnet:                                               │\n";
    echo "  │   php tnsr-vlan-restconf.php verify XXXX:YYYY:1:187::1/64 XXXX:YYYY:2::/56 102      │\n";
    echo "  │                                                                                     │\n";
    echo "  │ What it checks:                                                                     │\n";
    echo "  │   1. Subinterface exists and is enabled                                             │\n";
    echo "  │   2. Gateway IP configured on subinterface                                          │\n";
    echo "  │   3. Static route for routed subnet exists                                          │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Return:  0 if all checks pass, 1 if any fail                                        │\n";
    echo "  └─────────────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
 
    echo "  ┌─ LIST ALL VLANS ────────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf.php list                                           │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Purpose:  Display all configured VLAN subinterfaces                                 │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Example:                                                                            │\n";
    echo "  │   php tnsr-vlan-restconf.php list                                                   │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Output shows:FortyGigabitEthernet65/0/1.101 (up), etc.                              │\n";
    echo "  │                                                                                     │\n";
    echo "  │ Return:  0 on success, 1 on error                                                   │\n";
    echo "  └─────────────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "WORKFLOW EXAMPLES:\n";
    echo "\n";
    echo "  Example 1: Create, Verify, and Delete a VLAN\n";
    echo "    Server 36: Host XXXX:YYYY:1:186::/64, Routed XXXX:YYYY:1:200::/48\n";
    echo "    \n";
    echo "    Create:\n";
    echo "      php tnsr-vlan-restconf.php create XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101\n";
    echo "    \n";
    echo "    Verify:\n";
    echo "      php tnsr-vlan-restconf.php verify XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101\n";
    echo "    \n";
    echo "    Delete:\n";
    echo "      php tnsr-vlan-restconf.php delete XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101\n";
    echo "\n";
    
    echo "  Example 2: Multiple servers with different routed subnets\n";
    echo "    php tnsr-vlan-restconf.php create XXXX:YYYY:1:186::1/64 XXXX:YYYY:1:200::/48 101\n";
    echo "    php tnsr-vlan-restconf.php create XXXX:YYYY:1:187::1/64 XXXX:YYYY:1:201::/48 102\n";
    echo "    php tnsr-vlan-restconf.php create XXXX:YYYY:1:188::1/64 XXXX:YYYY:2::/56 103\n";
    echo "\n";
    
    echo "  List all configured VLANs:\n";
    echo "    php tnsr-vlan-restconf.php list\n";
    echo "\n";
    
    echo "PARAMETER DETAILS:\n";
    echo "\n";
    echo "  Fixed Subnet (Host /64):\n";
    echo "    - Must be gateway IP with ::1 and /64 CIDR\n";
    echo "    - Example: XXXX:YYYY:1:186::1/64\n";
    echo "    - Derived from host subnet assigned by TenantOS by adding ::1\n";
    echo "    - The ::2 will be the server's interface IP\n";
    echo "\n";
    echo "  Routed Subnet:\n";
    echo "    - Network address with /48 or /56 CIDR\n";
    echo "    - Examples: XXXX:YYYY:1:200::/48 or XXXX:YYYY:2::/56\n";
    echo "    - Points to server's interface IP (::2 address) as next-hop\n";
    echo "\n";
    echo "  VLAN ID:\n";
    echo "    - Integer from 1-4094\n";
    echo "    - Assigned by TenantOS from configured VLAN pool\n";
    echo "    - Used to create subinterface name\n";
    echo "\n";
    
    echo "EXIT CODES:\n";
    echo "  0 = Success\n";
    echo "  1 = Failure\n";
    echo "\n";
}

// Main
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

$command = $argv[1] ?? null;
$gatewayIp = $argv[2] ?? null;
$routedSubnet = $argv[3] ?? null;
$vlan = $argv[4] ?? null;

if (!$command) {
    show_usage();
    exit(1);
}

try {
    switch ($command) {
        case 'create':
            if (!$gatewayIp || !$routedSubnet || !$vlan) {
                log_msg("Error: create needs gatewayIp, routedSubnet, and vlan", 'ERROR');
                exit(1);
            }
            create_subinterface($gatewayIp, $routedSubnet, (int)$vlan);
            break;

        case 'delete':
            if (!$gatewayIp || !$routedSubnet || !$vlan) {
                log_msg("Error: delete needs gatewayIp, routedSubnet, and vlan", 'ERROR');
                exit(1);
            }
            delete_subinterface($gatewayIp, $routedSubnet, (int)$vlan);
            break;

        case 'verify':
            if (!$gatewayIp || !$routedSubnet || !$vlan) {
                log_msg("Error: verify needs gatewayIp, routedSubnet, and vlan", 'ERROR');
                exit(1);
            }
            $passed = verify_subinterface($gatewayIp, $routedSubnet, (int)$vlan);
            exit($passed ? 0 : 1);
            break;

        case 'list':
            list_subinterfaces();
            break;

        default:
            log_msg("Unknown command: $command", 'ERROR');
            show_usage();
            exit(1);
    }

} catch (Exception $e) {
    log_msg("Fatal error: " . $e->getMessage(), 'ERROR');
    exit(1);
}

exit(0);
