<?php
/**
 * VLAN Automation Configuration - REVISED
 * 
 * SECURITY: Set permissions to 600 (owner read/write only)
 * chmod 600 /var/www/html/app/Custom/EventListeners/vlan_automation_config.php
 * Defines LACP and Port-Channel blocks that are substituted with values
 * based on VLAN parity (odd/even) to determine primary/secondary role
 */

return [
    // TenantOS API Token (from User Settings → API)
    'api_token' => 'ENTER_YOUR_TOKEN_STRING_HERE',
    
    // TNSR Script Path
    'router_script_path' => '/var/www/html/scripts/tnsr-vlan-restconf.php',

    // ================================================================
    // DEBUG CONFIGURATION
    // ================================================================
    // Debug level: 0=Off, 1=Basic, 2=Detailed, 3=Very Detailed
    // 
    // Level 0 (Off): No debug logging to file, minimal output
    // Level 1 (Basic): VLAN validation, subnet/switch discovery, critical errors
    // Level 2 (Detailed): Command execution, API calls, placeholder replacement
    // Level 3 (Very Detailed): Everything including full configs, switchfacade logs
    'debug_level' => 0,
    
    // Path to debug log file (only used if debug_level > 0)
    'debug_log_path' => '/var/www/html/storage/logs/tenantos-vlan-creation.log', 
    
    // Switch templates by vendor
    'switch_templates' => [
        'arista' => '/var/www/html/app/Custom/EventListeners/templates/arista_vlan.template',
    //    'juniper' => '/var/www/html/app/Custom/EventListeners/templates/juniper_vlan.template',
    ],

    // Removal templates by vendor (optional - uses switch_templates with -removal suffix if not set)
    'switch_removal_templates' => [
        'arista' => __DIR__ . '/templates/arista_vlan-REMOVAL.template',
    //    'juniper' => __DIR__ . '/templates/juniper_vlan-REMOVAL.template',
    ],

    // ================================================================
    // VENDOR-SPECIFIC INTERFACE NAMING PATTERNS
    // ================================================================
    // Regex patterns to extract port numbers from interface names
    // Patterns are tried in order - first match wins
    // Must have at least one capture group for the port number
    'interface_patterns' => [
        // Arista
        '/^Ethernet(\d+)$/',                    // Ethernet39
        '/^Ethernet(\d+)\/(\d+)$/',             // Ethernet2/1 → combine as "21"
        '/^Port-Channel(\d+)$/',                // Port-Channel39
        
        // Cisco IOS/IOS-XE
        // '/^GigabitEthernet(\d+\/\d+\/\d+)$/',   // GigabitEthernet0/0/1
        // '/^TenGigabitEthernet(\d+\/\d+\/\d+)$/', // TenGigabitEthernet0/0/1
        // '/^Eth(\d+\/\d+\/\d+)$/',               // Eth0/0/1 (short form)
        // '/^Port-channel(\d+)$/',                // Port-channel39 (Cisco variant, lowercase)
        
        // Juniper
        // '/^ge-(\d+\/\d+\/\d+)$/',               // ge-0/0/0
        // '/^xe-(\d+\/\d+\/\d+)$/',               // xe-0/0/0
        // '/^et-(\d+\/\d+\/\d+)$/',               // et-0/0/0 (40Gbps)
        // '/^ae(\d+)$/',                          // ae0 (aggregated ethernet)
        
        // Dell/Force10
        // '/^Ethernet(\d+\/\d+)$/',               // Ethernet1/1
        
        // Generic fallback - DO NOT USE without understanding implications
        // Uncomment only if needed and vendor patterns above don't match
        // '/(\d+)$/',                             // Extract last number sequence
    ],

    // ================================================================
    // TEMPLATE PLACEHOLDERS REFERENCE
    // ================================================================
    // These placeholders are available in all templates and removal templates:
    //
    // {SERVER_ID}                 - Server ID
    // {VLAN_ID}                   - VLAN number (101, 200, etc)
    // {VLAN_NAME}                 - VLAN name (VLAN_101, VLAN_200, etc)
    // {PORT_NAME}                 - Full port name from API (Ethernet39, Ethernet10/1, Port-Channel39, etc)
    // {PORT_STRING}               - Port format without interface type (39, 10/1, etc)
    // {PORT_NUMBER}               - Numeric format for channel groups (39 for Ethernet39, 101 for Ethernet10/1)
    // {CHANNEL_GROUP}             - Same as PORT_NUMBER
    // {DATE}                      - Timestamp (YYYY-MM-DD HH:MM:SS)
    // {LACP_CONFIG}               - Assignment: LACP configuration block (channel-group, lacp priority)
    // {LACP_CONFIG_REMOVAL}       - Removal: LACP removal block (no channel-group, no lacp priority)
    // {PORT_CHANNEL_CONFIG}       - Assignment: Port-Channel interface configuration
    // {PORT_CHANNEL_CONFIG_REMOVAL} - Removal: Port-Channel interface removal
    // {SERVER_ID}                 - Server identifier
    // {IPV4_ADDRESS}              - IPv4 address (assignment template)
    // {IPV6_ADDRESS}              - IPv6 address/subnet (assignment template)
    // {IPV6_ROUTED_SUBNET}        - IPv6 routed subnet (assignment template)
    // {IPV6_HOST_SUBNET}          - IPv6 host subnet (assignment template)
    //
    // Example use in templates:
    //   interface Ethernet{PORT_STRING}     // Works for both Ethernet39 and Ethernet10/1
    //   Port-Channel{PORT_NUMBER}           // Works for both (Port-Channel39 or Port-Channel101)
    //   channel-group {PORT_NUMBER}         // Works for both (channel-group 39 or 101)
    
    
    // ================================================================
    // LACP/Port-Channel Configuration
    // ================================================================
    'port_channel' => [
        // Enable or disable LACP/Port-Channel automation
        'enabled' => true,
        
        // Valid MLAG pairs (switch names that can form MLAG)
        'valid_pairs' => [
            [19, 11, '#/#'],  // 10G/40G/100G pair
            [25, 23, '#'],  // 1G pair
        //    [NN, YY, '#/#/#'],// Cisco Notation
        ],
        
        // ================================================================
        // PRIMARY Switch Configuration (ODD VLAN → ODD Switch)
        // ================================================================
        'primary' => [
            // LACP mode for primary (active)
            'lacp_mode' => 'active',
            
            // LACP port priority for primary
            'lacp_priority' => 1,
            
            // Port-Channel system priority
            'system_priority' => 100,
        ],
        
        // ================================================================
        // SECONDARY Switch Configuration (EVEN VLAN → EVEN Switch)
        // ================================================================
        'secondary' => [
            // LACP mode for secondary (passive)
            'lacp_mode' => 'passive',
            
            // LACP port priority for secondary
            'lacp_priority' => 100,
            
            // Port-Channel system priority
            'system_priority' => 101,
        ],
    ],
    
    // ================================================================
    // TEMPLATE BLOCKS - These define the exact syntax for LACP configs
    // ================================================================
    // 
    // These blocks are substituted with values from the config based on
    // whether the switch is PRIMARY or SECONDARY for the VLAN
    //
    // Placeholders in blocks:
    //   {CHANNEL_GROUP}         - Port channel number (e.g., 39)
    //   {LACP_MODE}             - "active" or "passive" 
    //   {LACP_PRIORITY}         - Integer priority value
    //   {SERVER_ID}             - Server identifier
    //   {VLAN_ID}               - VLAN number
    //   {MLAG_ID}               - MLAG domain ID (usually same as CHANNEL_GROUP)
    //   {IPV6_ADDRESS}          - Server host IPv6 address (e.g., 2602:f937:0:bb00::2)
    //   {IPV6_ROUTED_SUBNET}    - Routed subnet from TenantOS (e.g., 2602:f937:0:b00::/56)
    //   {IPV6_HOST_SUBNET}      - Calculated host subnet (e.g., 2602:f937:0:bb00::/64)
    //   {DATE}                  - Current date
    //
    // ================================================================
     
    // LACP configuration block
    // Inserted into physical port interface under {LACP_CONFIG} placeholder
    // Only inserted if MLAG is enabled and peer is detected
    'lacp_config_block' => <<<'EOL'
channel-group {CHANNEL_GROUP} mode {LACP_MODE}
   lacp port-priority {LACP_PRIORITY}
EOL,
    
    // Port-Channel interface configuration block
    // Inserted as separate interface configuration under {PORT_CHANNEL_CONFIG} placeholder
    // Only inserted if MLAG is enabled and peer is detected
    'port_channel_config_block' => <<<'EOL'
!
! ================================================================
! Port-Channel Configuration (MLAG) - Server {SERVER_ID}
! ================================================================
!
interface Port-Channel{CHANNEL_GROUP}
   description Server {SERVER_ID} - Port Channel - VLAN {VLAN_ID}
   no shutdown
   switchport mode trunk
   switchport trunk allowed vlan add {VLAN_ID}
   no switchport trunk native vlan {VLAN_ID}
   mlag {CHANNEL_GROUP}
   spanning-tree portfast edge
   port-channel lacp fallback static
   port-channel lacp fallback timeout 5
   ip access-group ACL_SERVER_{SERVER_ID}_IN in
!   ipv6 access-group ACL_SERVER_{SERVER_ID}_V6_IN in
EOL,

    // LACP removal block (REMOVAL)
    // Inserted under {LACP_CONFIG_REMOVAL} placeholder to remove channel-group
    'lacp_config_removal' => <<<'EOL'
   no channel-group {CHANNEL_GROUP} mode {LACP_MODE}
   no lacp port-priority {LACP_PRIORITY}
EOL,
    
    // Port-Channel removal block (REMOVAL)
    // Inserted under {PORT_CHANNEL_CONFIG_REMOVAL} placeholder to remove port-channel
    'port_channel_config_removal' => <<<'EOL'
!
! ================================================================
! Port-Channel Removal - Server {SERVER_ID}
! ================================================================
!
interface Port-Channel{CHANNEL_GROUP}
  description Server {SERVER_ID}
  no switchport trunk allowed vlan {VLAN_ID}
  switchport trunk allowed vlan 1
  switchport mode trunk
  no mlag {CHANNEL_GROUP}
  no ip access-group ACL_SERVER_{SERVER_ID}_IN in
!   no ipv6 access-group ACL_SERVER_{SERVER_ID}_V6_IN in
  spanning-tree portfast edge
  port-channel lacp fallback static
  port-channel lacp fallback timeout 5
EOL,
];
