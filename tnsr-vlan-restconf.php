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
    'tnsr_host'        => '10.10.10.3',
    'tnsr_username'    => 'USERNAME',
    'tnsr_password'    => 'PASSWORD',
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

function create_subinterface($subnet, $vlan) {
    global $config;

    if (!preg_match('/^[0-9a-f:]+\/56$/i', $subnet)) {
        throw new Exception("Invalid subnet: $subnet");
    }
    if ($vlan < 1 || $vlan > 4094) {
        throw new Exception("Invalid VLAN: $vlan");
    }

    $gatewayPrefix = extract_gateway_prefix($subnet, $config['ipv6_prefix']);
    $gatewayIp = $gatewayPrefix . '::1';
    $customerLinkIp = $gatewayPrefix . '::2';

    log_msg("Creating TNSR subinterface", 'INFO');
    log_msg("  Subnet: $subnet", 'INFO');
    log_msg("  VLAN: $vlan", 'INFO');
    log_msg("  Gateway: $gatewayIp/64", 'INFO');
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
                            'ip' => ["$gatewayIp/64"]
                        ]
                    ]
                ]
            ]
        ];

        tnsr_restconf_request('PUT', '/data/netgate-interface:interfaces-config/interface=' . urlencode($subifName), $interfaceConfig);
        log_msg("✓ Interface configured", 'SUCCESS');

        log_msg("Step 3: Configuring router advertisements", 'INFO');
        $raConfig = [
            'netgate-ipv6-ra:ipv6-router-advertisements' => [
                'send-advertisements' => true,
                'default-lifetime' => 1800,
                'prefix-list' => [
                    'prefix' => [
                        [
                            'prefix-spec' => $gatewayPrefix . '::/64',
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

        $newRoute = [
            'destination-prefix' => $subnet,
            'next-hop' => [
                'hop' => [
                    [
                        'hop-id' => 0,
                        'ipv6-address' => $customerLinkIp,
                        'if-name' => $subifName
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

        $routeExists = false;
        foreach ($routeConfig[$configKey]['static-routes']['route-table'] as &$table) {
            if (($table['name'] ?? 'unknown') === 'default') {
                if (!isset($table['ipv6-routes'])) {
                    $table['ipv6-routes'] = ['route' => []];
                }
                if (!isset($table['ipv6-routes']['route'])) {
                    $table['ipv6-routes']['route'] = [];
                }

                foreach ($table['ipv6-routes']['route'] as $existingRoute) {
                    if (($existingRoute['destination-prefix'] ?? '') === $subnet) {
                        $routeExists = true;
                        break;
                    }
                }

                if (!$routeExists) {
                    $table['ipv6-routes']['route'][] = $newRoute;
                }
                break;
            }
        }

        if ($routeExists) {
            log_msg("Route already exists", 'INFO');
        } else {
            tnsr_restconf_request('PATCH', '/data/netgate-route-table:route-table-config', $routeConfig);
            log_msg("✓ Route added", 'SUCCESS');
        }

        log_msg("✓ Subinterface created successfully", 'SUCCESS');
        echo "\n";

    } catch (Exception $e) {
        log_msg("✗ Error: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

function delete_subinterface($subnet, $vlan) {
    global $config;

    if (!preg_match('/^[0-9a-f:]+\/56$/i', $subnet)) {
        throw new Exception("Invalid subnet: $subnet");
    }

    log_msg("Deleting TNSR subinterface", 'INFO');
    log_msg("  Subnet: $subnet", 'INFO');
    log_msg("  VLAN: $vlan", 'INFO');
    echo "\n";

    try {
        $interface = $config['parent_interface'];
        $subifName = "$interface.$vlan";

        log_msg("Deleting route", 'DEBUG');
        $getResult = tnsr_restconf_request('GET', '/data/netgate-route-table:route-table-config');
        $routeConfig = $getResult['response'] ?? [];

        if (!empty($routeConfig)) {
            $found = false;
            $configKey = 'netgate-route-table:route-table-config';
            if (isset($routeConfig[$configKey]['static-routes']['route-table'])) {
                foreach ($routeConfig[$configKey]['static-routes']['route-table'] as &$table) {
                    if (($table['name'] ?? 'unknown') === 'default') {
                        if (isset($table['ipv6-routes']['route'])) {
                            foreach ($table['ipv6-routes']['route'] as $key => $route) {
                                if (($route['destination-prefix'] ?? '') === $subnet) {
                                    unset($table['ipv6-routes']['route'][$key]);
                                    $found = true;
                                    break;
                                }
                            }
                            $table['ipv6-routes']['route'] = array_values($table['ipv6-routes']['route']);
                        }
                        break;
                    }
                }
            }

            if ($found) {
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

function verify_subinterface($subnet, $vlan) {
    global $config;

    if (!preg_match('/^[0-9a-f:]+\/56$/i', $subnet)) {
        throw new Exception("Invalid subnet: $subnet");
    }

    $interface = $config['parent_interface'];
    $subifName = "$interface.$vlan";
    $gatewayPrefix = extract_gateway_prefix($subnet, $config['ipv6_prefix']);
    $gatewayIp = $gatewayPrefix . '::1';

    log_msg("Verifying subinterface", 'INFO');
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
                        if (($route['destination-prefix'] ?? '') === $subnet) {
                            $found = true;
                            break;
                        }
                    }
                }
            }

            if ($found) {
                log_msg("✓ Static route for $subnet exists", 'SUCCESS');
            } else {
                log_msg("✗ Static route for $subnet not found", 'ERROR');
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
    echo "║  Version: 3.4 - Production Ready                                              ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    echo "CONFIGURATION:\n";
    echo "  TNSR Host:          {$host}\n";
    echo "  Parent Interface:   {$parent}\n";
    echo "  Log File:           {$config['log_file']}\n";
    echo "\n";
    
    echo "USAGE:\n";
    echo "  php tnsr-vlan-restconf-FIXED-enhanced.php <COMMAND> [SUBNET] [VLAN_ID]\n";
    echo "\n";
    
    echo "COMMANDS:\n";
    echo "\n";
    
    echo "  ┌─ CREATE VLAN ────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf-FIXED-enhanced.php create <subnet> <vlan>   │\n";
    echo "  │                                                                              │\n";
    echo "  │ Purpose:  Create a new VLAN with IPv6 subnet and static route                │\n";
    echo "  │                                                                              │\n";
    echo "  │ Example:                                                                     │\n";
    echo "  │   php tnsr-vlan-restconf-FIXED-enhanced.php create XXXX:YYYY:0:b00::/56 103  │\n";
    echo "  │                                                                              │\n";
    echo "  │ What it does:                                                                │\n";
    echo "  │   1. Creates subinterface: FortyGigabitEthernet65/0/1.103                    │\n";
    echo "  │   2. Configures IPv6 address: XXXX:YYYY:0:b00::/56                           │\n";
    echo "  │   3. Creates static route with gateway                                       │\n";
    echo "  │                                                                              │\n";
    echo "  │ Parameters:                                                                  │\n";
    echo "  │   <subnet>  IPv6 subnet in CIDR notation (e.g., XXXX:YYYY:0:b00::/56)        │\n";
    echo "  │   <vlan>    VLAN ID as integer (e.g., 103)                                   │\n";
    echo "  │                                                                              │\n";
    echo "  │ Return:  0 on success, 1 on failure                                          │\n";
    echo "  └──────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "  ┌─ DELETE VLAN ────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf-FIXED-enhanced.php delete <subnet> <vlan>   │\n";
    echo "  │                                                                              │\n";
    echo "  │ Purpose:  Remove a VLAN and all associated configurations                    │\n";
    echo "  │                                                                              │\n";
    echo "  │ Example:                                                                     │\n";
    echo "  │   php tnsr-vlan-restconf-FIXED-enhanced.php delete XXXX:YYYY:0:b00::/56 103  │\n";
    echo "  │                                                                              │\n";
    echo "  │ What it does:                                                                │\n";
    echo "  │   1. Deletes static route for: XXXX:YYYY:0:b00::/56                          │\n";
    echo "  │   2. Removes interface config: FortyGigabitEthernet65/0/1.103                │\n";
    echo "  │   3. Deletes subinterface definition                                         │\n";
    echo "  │                                                                              │\n";
    echo "  │ Parameters:                                                                  │\n";
    echo "  │   <subnet>  IPv6 subnet to delete (e.g., XXXX:YYYY:0:b00::/56)               │\n";
    echo "  │   <vlan>    VLAN ID to delete (e.g., 103)                                    │\n";
    echo "  │                                                                              │\n";
    echo "  │ Return:  0 on success, 1 on failure                                          │\n";
    echo "  └──────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "  ┌─ VERIFY VLAN ────────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf-FIXED-enhanced.php verify <subnet> <vlan>   │\n";
    echo "  │                                                                              │\n";
    echo "  │ Purpose:  Verify that a VLAN exists and is properly configured               │\n";
    echo "  │                                                                              │\n";
    echo "  │ Example:                                                                     │\n";
    echo "  │   php tnsr-vlan-restconf-FIXED-enhanced.php verify XXXX:YYYY:0:b00::/56 103  │\n";
    echo "  │                                                                              │\n";
    echo "  │ What it checks:                                                              │\n";
    echo "  │   1. Subinterface exists: FortyGigabitEthernet65/0/1.103                     │\n";
    echo "  │   2. IPv6 subnet configured: XXXX:YYYY:0:b00::/56                            │\n";
    echo "  │   3. Static route exists for the subnet                                      │\n";
    echo "  │                                                                              │\n";
    echo "  │ Parameters:                                                                  │\n";
    echo "  │   <subnet>  IPv6 subnet to verify (e.g., XXXX:YYYY:0:b00::/56)               │\n";
    echo "  │   <vlan>    VLAN ID to verify (e.g., 103)                                    │\n";
    echo "  │                                                                              │\n";
    echo "  │ Return:  0 if all checks pass, 1 if any fail                                 │\n";
    echo "  └──────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "  ┌─ LIST ALL VLANS ─────────────────────────────────────────────────────────────┐\n";
    echo "  │ Command:  php tnsr-vlan-restconf-FIXED-enhanced.php list                     │\n";
    echo "  │                                                                              │\n";
    echo "  │ Purpose:  Display all configured VLANs and their IPv6 subnets                │\n";
    echo "  │                                                                              │\n";
    echo "  │ Example:                                                                     │\n";
    echo "  │   php tnsr-vlan-restconf-FIXED-enhanced.php list                             │\n";
    echo "  │                                                                              │\n";
    echo "  │ Output shows:                                                                │\n";
    echo "  │   Interface Name              Status  IPv6 Subnet                            │\n";
    echo "  │   FortyGigabitEthernet65/0/1.103  up      XXXX:YYYY:0:b00::/56               │\n";
    echo "  │   FortyGigabitEthernet65/0/1.104  up      XXXX:YYYY:0:b01::/56               │\n";
    echo "  │                                                                              │\n";
    echo "  │ Parameters:  None                                                            │\n";
    echo "  │                                                                              │\n";
    echo "  │ Return:  0 on success, 1 on error                                            │\n";
    echo "  └──────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    
    echo "WORKFLOW EXAMPLES:\n";
    echo "\n";
    echo "  Create 2 new VLANs:\n";
    echo "    php tnsr-vlan-restconf-FIXED-enhanced.php create XXXX:YYYY:0:100::/56 100\n";
    echo "    php tnsr-vlan-restconf-FIXED-enhanced.php create XXXX:YYYY:0:200::/56 200\n";
    echo "\n";
    echo "  List all VLANs:\n";
    echo "    php tnsr-vlan-restconf-FIXED-enhanced.php list\n";
    echo "\n";
    echo "  Verify a VLAN exists:\n";
    echo "    php tnsr-vlan-restconf-FIXED-enhanced.php verify XXXX:YYYY:0:100::/56 100\n";
    echo "\n";
    echo "  Delete a VLAN:\n";
    echo "    php tnsr-vlan-restconf-FIXED-enhanced.php delete XXXX:YYYY:0:100::/56 100\n";
    echo "\n";
    
    echo "LOGGING:\n";
    echo "  All operations are logged to: {$config['log_file']}\n";
    echo "  Tail the log with: tail -f {$config['log_file']}\n";
    echo "\n";
    
    echo "EXIT CODES:\n";
    echo "  0 = Success (create, delete, verify all checks passed)\n";
    echo "  1 = Failure (operation failed or error occurred)\n";
    echo "\n";
}
// Main
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

$command = $argv[1] ?? null;
$subnet = $argv[2] ?? null;
$vlan = $argv[3] ?? null;

if (!$command) {
    show_usage();
    exit(1);
}

try {
    switch ($command) {
        case 'create':
            if (!$subnet || !$vlan) {
                log_msg("Error: create needs subnet and vlan", 'ERROR');
                exit(1);
            }
            create_subinterface($subnet, (int)$vlan);
            break;

        case 'delete':
            if (!$subnet || !$vlan) {
                log_msg("Error: delete needs subnet and vlan", 'ERROR');
                exit(1);
            }
            delete_subinterface($subnet, (int)$vlan);
            break;

        case 'verify':
            if (!$subnet || !$vlan) {
                log_msg("Error: verify needs subnet and vlan", 'ERROR');
                exit(1);
            }
            $passed = verify_subinterface($subnet, (int)$vlan);
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
