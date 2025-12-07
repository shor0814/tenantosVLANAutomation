# TenantOS VLAN Automation for Arista Switches

Automatically provision VLANs across dual-switch MLAG pairs when servers are assigned IPv6 addresses in TenantOS. Includes intelligent gateway calculation, LACP configuration, template based switch configuration, and router integration.

[![License](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4+-blue)](https://php.net)

## Features

✅ **Automatic VLAN provisioning** across switch pairs when servers get IPv6 addresses

✅ **Dual-interface redundancy** - both Port-Channel and Ethernet configured on both switches

✅ **LACP/MLAG automation** - active/passive roles determined by VLAN parity

✅ **Smart gateway calculation** - derives host subnet from routed subnet

✅ **IPv6-native** - optimized for modern infrastructure

✅ **Multi-vendor ready** - Arista, Juniper, or any vendor with TenantOS Switch Automation support (Arista tested)

✅ **Comprehensive logging** - 4 debug levels from production-quiet to verbose

✅ **Graceful fallbacks** - VLAN detection via API, description, or alias

✅ **Infrastructure-safe** - filters VLAN 1 and respects TenantOS VLAN ranges

✅ **Upgrade-safe** - all files in EventListeners directory (not wiped on upgrade)

✅ **Template-driven** - free-form templates support any switch configuration

## How It Works

### What Happens When a Server Gets an IPv6 Address

```
TenantOS: Assign IPv6 to server from child subnet with VLAN automation
    ↓
Event Listener Triggered (afterIpAssignmentListener)
    ↓
Query connected switches via API
    ↓
Identify MLAG pair from valid_pairs config
    ↓
Retrieve VLAN assigned to server
    ↓
Calculate IPv6 gateway (XXXX:YYYY:0:b00::/56 → XXXX:YYYY:0:bb00::1)
    ↓
Determine PRIMARY/SECONDARY based on VLAN parity
    ↓
Apply SAME template to BOTH switches
  ├─ Switch 19: Receives active LACP (primary role)
  └─ Switch 11: Receives passive LACP (secondary role)
  └─ Other switch configurations applied from template
    ↓
Call router script to configure IPv6
    ↓
✓ VLAN complete with full MLAG redundancy
```

### Switch Positions and LACP Roles

Define switch pairs in config:

```php
'valid_pairs' => [
    [19, 11, '#/#'],  // Position 0=19 (EVEN), Position 1=11 (ODD)
    [25, 23, '#'],    // Position 0=25 (EVEN), Position 1=23 (ODD)
],
```

**Position 0** = First switch in array (EVEN parity, 0 = even)  
**Position 1** = Second switch in array (ODD parity, 1 = odd)

**LACP Role Logic:**
```
If VLAN parity matches switch position → PRIMARY (active, priority 1)
If VLAN parity doesn't match → SECONDARY (passive, priority 100)

Example with [19, 11, '#/#']:
  Switch 19 is position 0 (EVEN)
  Switch 11 is position 1 (ODD)

  For VLAN 101 (ODD):
    Switch 19: 1 ≠ 0 → SECONDARY (passive lacp port-priority 100)
    Switch 11: 1 = 1 → PRIMARY (active lacp port-priority 1)

  For VLAN 102 (EVEN):
    Switch 19: 0 = 0 → PRIMARY (active lacp port-priority 1)
    Switch 11: 0 ≠ 1 → SECONDARY (passive lacp port-priority 100)
```

This ensures automatic failover without manual configuration and balancing of switch port-priority

### Template Processing

Both switches receive the **exact same template** with the same placeholders replaced:

```
arista_vlan.template:
interface Ethernet{PORT_STRING}
   description Server {SERVER_ID} VLAN {VLAN_ID}
   switchport mode trunk
   switchport trunk allowed vlan {VLAN_ID}
   ip access-group ACL_SERVER_{SERVER_ID}_IN in
   ipv6 access-group ACL_SERVER_{SERVER_ID}_V6_IN in
   {LACP_CONFIG}
!
{PORT_CHANNEL_CONFIG}

! ACL for IPv4 Traffic - Server {SERVER_ID}
! Permits traffic from server's IPv4 address, denies all else
ip access-list ACL_SERVER_{SERVER_ID}_IN
   10 permit ip host {IPV4_ADDRESS} any
   1000 deny ip any any

! ACL for IPv6 Traffic - Server {SERVER_ID}
! Permits traffic from server's IPv6 /64 address and full subnet
ipv6 access-list ACL_SERVER_{SERVER_ID}_V6_IN
   10 permit ipv6 host {IPV6_ADDRESS} any
   20 permit ipv6 {IPV6_HOST_SUBNET} any
   30 permit ipv6 {IPV6_ROUTED_SUBNET} any
   1000 deny ipv6 any any
```

The `{LACP_CONFIG}` and `{PORT_CHANNEL_CONFIG}` placeholders are replaced with different values based on whether the switch is PRIMARY or SECONDARY:

**Switch 19 (SECONDARY for VLAN 101):**
```
channel-group 264 mode passive
   lacp port-priority 100
```

**Switch 11 (PRIMARY for VLAN 101):**
```
channel-group 264 mode active
   lacp port-priority 1
```

Same template, different substitutions = active/passive roles handled automatically.

> **Deep Dive:** See [PORT_CHANNEL_LACP_ARCHITECTURE.md](docs/PORT_CHANNEL_LACP_ARCHITECTURE.md) for detailed explanation of how LACP/Port-Channel configuration is managed in the config file rather than templates.

### Port Format Handling

System handles different port naming conventions:

**Modular Format** (`#/#` - Arista 7280):
```
Both switches must have:
  ├─ Ethernet26/4 (physical port)
  └─ Port-Channel264 (logical port)

Template uses {PORT_STRING}=26/4 and {PORT_NUMBER}=264
```

**Simple Format** (`#` - Arista 7050):
```
Both switches must have:
  ├─ Ethernet39 (physical port)
  └─ Port-Channel39 (logical port)

Template uses {PORT_STRING}=39 and {PORT_NUMBER}=39
```

**Critical requirement:** Both physical and Port-Channel ports must exist on BOTH switches with identical port numbers. If either is missing on either switch, configuration will fail.

## Installation

### File Structure

```
TenantOS Installation/
└── app/Custom/EventListeners/
    ├── afterIpAssignmentListener.php       (listener for VLAN creation)
    ├── beforeIpRemovalListener.php         (listener for VLAN removal)
    ├── vlan_automation_config.php          (configuration)
    ├── tnsr-vlan-restconf.php              (router tool)
    └── templates/
        ├── arista_vlan.template            (Arista creation template)
        └── arista_vlan-REMOVAL.template    (Arista removal template)
```

### Setup Steps

1. **Copy listener files:**
   ```bash
   cp afterIpAssignmentListener.php app/Custom/EventListeners/
   cp beforeIpRemovalListener.php app/Custom/EventListeners/
   ```

2. **Copy configuration:**
   ```bash
   cp vlan_automation_config.php app/Custom/EventListeners/
   chmod 600 app/Custom/EventListeners/vlan_automation_config.php
   ```

3. **Copy templates:**
   ```bash
   mkdir -p app/Custom/EventListeners/templates
   cp arista_vlan.template app/Custom/EventListeners/templates/
   cp arista_vlan-REMOVAL.template app/Custom/EventListeners/templates/
   ```

4. **Copy router tool:**
   ```bash
   cp tnsr-vlan-restconf.php app/Custom/EventListeners/
   chmod +x app/Custom/EventListeners/tnsr-vlan-restconf.php
   ```

5. **Cache event listeners:**
   ```bash
   app event:cache
   ```

6. **Verify installation:**
   ```bash
   ls -la app/Custom/EventListeners/
   # Should show all files with www-data readable
   ```

## TenantOS Configuration

### Step 1: Add Switches to Server

In TenantOS → Server → Connections:

1. Add both switches
2. For each switch:
   - **Automation switch:** Select Port-Channel (e.g., Port-Channel264)
   - **Peer switch:** Select physical Ethernet port (e.g., Ethernet26/4)
   - Mark automation switch: `Enable Switch Automation`

Example from your setup:
```
Switch KC1-ARSW01 (Switch 19):
  - Port: Port-Channel264
  - Enable Switch Automation: ✓ (checked)

Switch KC1-ARSW02 (Switch 11):
  - Port: Ethernet26/4
  - Enable Switch Automation: ☐ (unchecked)
```

**Why Port-Channel on automation switch?** If both use Ethernet ports, enabling MLAG causes an error and the switch becomes unmanageable for that server.  I assume this is an SNMP issue but not sure.

### Step 2: Create Child Subnets with VLAN Automation

In TenantOS → IP Manager:

1. Create parent IPv6 subnet
2. For each switch pair, create separate child subnet:

**For Arista 7280 pair (Switches 19, 11):**
   - Subnet: `XXXX:YYYY:0:b00::/56`
   - VLAN Automation: Enable
   - VLAN Type: Native + Trunk
   - Native VLAN ID: Create New VLAN (auto-increment, range 150-199)
   - Trunk VLAN IDs: `150-199`
   - Layer 3 Mode: Depends on your architecture
   - Only offer if server has one of these tags (optional) - Apply "7280" tag (restricts which servers see this subnet)

**For Arista 7050 pair (Switches 25, 23):**
   - Subnet: `XXXX:YYYY:0:c00::/56`
   - VLAN Automation: Enable
   - VLAN Type: Native + Trunk
   - Native VLAN ID: Create New VLAN (auto-increment, range 150-199)
   - Trunk VLAN IDs: `100-149`
   - Layer 3 Mode: Depends on your architecture
   - Only offer if server has one of these tags (optional) - Apply "7050" tag (restricts which servers see this subnet)

### Step 3: Tag Servers and Assign IPs

1. Tag each server with switch type: "7280" or "7050"
2. Assign IPv6 address from appropriate child subnet
3. **Listener automatically triggers** - VLAN created on both switches

## Configuration

### vlan_automation_config.php

**Location:** `app/Custom/EventListeners/vlan_automation_config.php`

**API Token:**
```php
'api_token' => 'YOUR_TOKEN_HERE',  // From TenantOS: User Settings → API → Create Token
```

**Router Script Path:**
```php
'router_script_path' => '/var/www/html/app/Custom/EventListeners/tnsr-vlan-restconf.php',
```

**Switch Pairs (Position Mapping):**
```php
'port_channel' => [
    'enabled' => true,
    
    'valid_pairs' => [
        [19, 11, '#/#'],  // Position 0=19, Position 1=11, Format modular
        [25, 23, '#'],    // Position 0=25, Position 1=23, Format simple
    ],
    
    'primary' => [
        'lacp_mode' => 'active',
        'lacp_priority' => 1,
    ],
    
    'secondary' => [
        'lacp_mode' => 'passive',
        'lacp_priority' => 100,
    ],
],
```

**Debug Logging:**
```php
'debug_level' => 0,  // 0=off, 1=basic, 2=detailed, 3=verbose
'debug_log_path' => '/var/www/html/storage/logs/tenantos-vlan-creation.log',
```

Monitor logs:
```bash
tail -f app/Custom/EventListeners/../../../storage/logs/tenantos-vlan-creation.log
```

## Templates

Templates are free-form and fully customizable. Both switches receive identical templates with placeholders replaced based on their LACP role.

### Available Placeholders

```
{SERVER_ID}                    Server ID number
{VLAN_ID}                      VLAN number (101, 200, etc)
{VLAN_NAME}                    VLAN name (VLAN_101, etc)
{PORT_NAME}                    Full port name (Ethernet26/4, Port-Channel264)
{PORT_STRING}                  Port without interface type (26/4, 264, 39)
{PORT_NUMBER}                  Numeric format for channel groups (264, 39)
{CHANNEL_GROUP}                Same as PORT_NUMBER
{DATE}                         Timestamp (YYYY-MM-DD HH:MM:SS)
{LACP_CONFIG}                  LACP block with mode and priority (active/passive)
{PORT_CHANNEL_CONFIG}          Port-Channel interface config block
{LACP_CONFIG_REMOVAL}          LACP removal block (removal template)
{PORT_CHANNEL_CONFIG_REMOVAL}  Port-Channel removal block (removal template)
{IPV4_ADDRESS}                 IPv4 address (if configured)
{IPV6_ADDRESS}                 IPv6 server address
{IPV6_ROUTED_SUBNET}           Routed subnet (XXXX:YYYY:0:b00::/56)
{IPV6_HOST_SUBNET}             Host subnet (XXXX:YYYY:0:bb00::/64)
```

### Template Example (Arista)

See `arista_vlan.template` for actual template. Key sections:

```
interface Ethernet{PORT_STRING}
   description Server {SERVER_ID}
   switchport mode trunk
   switchport trunk allowed vlan {VLAN_ID}
   {LACP_CONFIG}

{PORT_CHANNEL_CONFIG}

```

## Creating a Custom Router Tool

Your tool must support:

```bash
php your-tool.php create <subnet> <vlan>    # Returns 0 on success
php your-tool.php delete <subnet> <vlan>    # Returns 0 on success
```

**Arguments:**
- Action: `create` or `delete`
- Subnet: IPv6 subnet string (e.g., `XXXX:YYYY:0:b00::/56`)
- VLAN: Integer (e.g., `101`)

**Return codes:**
- `0` = Success
- Non-zero = Failure

**Example (TNSR via RESTCONF):**
```php
<?php
$action = $argv[1];
$subnet = $argv[2];
$vlanId = (int)$argv[3];

if ($action === 'create') {
    // Configure router interface/VLAN with IPv6 subnet
    // Via RESTCONF API, SSH, or CLI
    exit(0);  // Success
} else {
    // Remove VLAN interface from router
    exit(0);  // Success
}
```

See `tnsr-vlan-restconf.php` for working TNSR example.

## How the Code Works

### Assignment Flow (afterIpAssignmentListener)

1. **Event trigger:** Server assigned IPv6 in TenantOS
2. **IPv6 validation:** Check for IPv6 (IPv4-only servers ignored)
3. **Switch discovery:** Get all switches connected to server
4. **MLAG detection:** Find peer using `valid_pairs` config
5. **VLAN query:** Get VLAN from TenantOS assignment
6. **Gateway calculation:** Derive host subnet from routed subnet
7. **Role assignment:** Determine PRIMARY/SECONDARY based on parity
8. **Template execution:** Apply same template to both switches
9. **Router configuration:** Call router tool with subnet and VLAN

### Removal Flow (beforeIpRemovalListener)

1. **Event trigger:** IPv6 address removed from server
2. **VLAN detection:** Find VLAN on switches
3. **Template execution:** Apply removal template to both switches
4. **Router cleanup:** Call router tool to remove VLAN

## Important Caveats

⚠️ **IPv6 Only** - IPv4 servers ignored. VLAN automation IPv6-specific.

⚠️ **VLAN Sanity Checking** - Management VLAN 1 never touched, even if found on port.  Detected VLANs checked against subnet VLAN range for safety.

⚠️ **Port Format Must Match** - If config says `#/#` but switch uses `#`, port decoding fails. Must match actual switch port naming.

⚠️ **Both Ports on Both Switches** - Both physical AND Port-Channel must exist on BOTH switches with identical port numbers:
```
Switch 19:              Switch 11:
├─ Ethernet26/4        ├─ Ethernet26/4
└─ Port-Channel264     └─ Port-Channel264
```
If either is missing, configuration fails.

⚠️ **Port-Channel Required on Automation Switch** - If both switches configured with Ethernet ports, when MLAG is enabled TenantOS generates error and switch becomes unmanageable on the server and Listeners fail. Automation switch MUST use Port-Channel.

⚠️ **Same Template Both Switches** - Both switches receive identical template. Different LACP modes (active/passive) come from template placeholders `{LACP_MODE}` and `{LACP_PRIORITY}` which are substituted based on switch role.

⚠️ **Gateway Calculation** - Assumes subnets follow pattern:
```
Routed:  XXXX:YYYY:0:b00::/56   → extracted: XXXX:YYYY:0:b
Host:    XXXX:YYYY:0:bb00::/64  → incremented: b → bb
Gateway: XXXX:YYYY:0:bb00::1
```
If your subnets use different pattern, customize gateway calculation in listener.  *Potential Ehanncement to include in config file

⚠️ **API Token Required** - Valid TenantOS API token needed. Without it, switch queries fail.

⚠️ **VLAN Range from TenantOS** - VLAN range filtering comes from TenantOS IP Manager subnet configuration (native VLAN range), not from `vlan_ranges` in config file. Config `vlan_ranges` is reference only.

⚠️ **VLAN Detection May Fail** - System tries: API → alternate port → description/alias. If all fail, VLAN removal skipped safely (no harm).

⚠️ **Manual Server Assignment** - TenantOS does NOT auto-select ports. You must manually:
   1. Add both switches to server
   2. Select Port-Channel for automation switch
   3. Select Ethernet for peer switch
   4. Tag server with switch pair identifier
   5. Assign IPv6 from correct child subnet

## Troubleshooting

### VLANs Not Appearing

1. **Enable debug logging:**
   ```php
   'debug_level' => 2,  // See what's happening
   ```

2. **Check logs:**
   ```bash
   tail -f app/Custom/EventListeners/../../../storage/logs/tenantos-vlan-creation.log
   ```

3. **Verify TenantOS setup:**
   - [ ] Both switches added to server
   - [ ] Automation switch on Port-Channel
   - [ ] Peer switch on Ethernet
   - [ ] Both switches have different ports selected
   - [ ] Child subnet has VLAN automation enabled
   - [ ] Server has correct tag for subnet
   - [ ] IPv6 assigned from correct subnet

4. **Verify switch configuration:**
   - [ ] Both switches in `valid_pairs`
   - [ ] Both switches have `advancedManagement = true` in TenantOS
   - [ ] Both have both Ethernet and Port-Channel ports with same numbers
   - [ ] Ports exist and are accessible

5. **Test API token:**
   - [ ] Token valid in TenantOS
   - [ ] Token has read permission on switches
   - [ ] Token in config file matches

### VLAN on Switch But Not Router

1. **Verify script path:**
   ```bash
   ls -la app/Custom/EventListeners/tnsr-vlan-restconf.php
   ```

2. **Test script manually:**
   ```bash
   php app/Custom/EventListeners/tnsr-vlan-restconf.php create XXXX:YYYY:0:b00::/56 101
   echo $?  # Should be 0
   ```

3. **Check debug logs** for script output/errors

### Port Format Issues

1. **Check actual switch port names:**
   ```
   SSH to switch
   show interfaces | include "Ethernet\|Port-Channel"
   ```

2. **Update config format:**
   ```php
   [19, 11, '#/#']   // If modular: Ethernet26/4, Port-Channel264
   [25, 23, '#']     // If simple: Ethernet39, Port-Channel39
   ```

### LACP Role Incorrect

1. **Verify parity logic:**
   ```
   VLAN 101 (ODD):
     Switch 19 (pos 0, EVEN) → SECONDARY (passive) ✓
     Switch 11 (pos 1, ODD) → PRIMARY (active) ✓
   ```

2. **Check actual switch config:**
   ```
   show interfaces Port-Channel264
   ```
   Should show one `active` and one `passive`

## Requirements

- TenantOS with Event Listeners support
- PHP 7.4 or higher
- Arista switches (or other vendor with TenantOS Switch Automation support)
- Router with provisioning script (TNSR, VyOS, Juniper, Cisco, etc)
- TenantOS API token with switch read access

## License: AGPL v3

- Contact the author for other options

## Files Included

- `afterIpAssignmentListener.php` - Listener for VLAN creation
- `beforeIpRemovalListener.php` - Listener for VLAN removal
- `vlan_automation_config.php` - Configuration (actual working example)
- `arista_vlan.template` - Arista VLAN creation template
- `arista_vlan-REMOVAL.template` - Arista VLAN removal template
- `tnsr-vlan-restconf.php` - TNSR router tool example
- `README.md` - This file

## Support

- 📖 Enable `debug_level => 2` for detailed output
- 📝 Check logs at `storage/logs/tenantos-vlan-creation.log`
- 🔍 Test router script manually
- 📧 Include debug output when reporting issues

---

**Version:** 1.0.0  
**Last Updated:** December 2025  
**Status:** Production Ready  
**License:** AGPL v3 (see LICENSE file)
