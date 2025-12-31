# TenantOS VLAN Automation System - v2

Automated VLAN provisioning with dynamic IPv6 routed subnet allocation for TenantOS-managed servers. Supports MLAG, multi-vendor switches (Arista, Juniper, Dell, Cisco), and sophisticated subnet management through server tags.

## What's New in v2

- **Server Tags for Subnet Allocation**: Use `routedXX` tags to automatically allocate appropriate routed subnets
- **Smart Vendor Detection**: Automatically detects switch vendor from TenantOS API management settings
- **Memory-Efficient Logging**: Optimized logging for large API responses
- **PXE/OS Installation Support**: ACL configuration for network bootstrap (details vary by network)
- **Reserved VLAN Protection**: Configurable list of VLANs to never automate

## Architecture Overview

```
Server Deployment:
  1. Admin creates server with routedXX tag (e.g., routed48, routed56)
  2. Admin assigns switch port(s) to server
  3. Admin assigns /64 IPv6 host subnet to server
  4. TenantOS fires afterServerIpAssignment event
  5. Listener checks for routed subnet, allocates if needed based on tag
  6. Listener configures VLAN on both MLAG switches
  7. Listener calls external router configuration script (optional - TNSR example provided)
  8. Server boots and configures networking (method varies - PXE, DHCP, manual, etc.)
  9. Server has both host and routed subnets available (see guides at https://webofnevada.com/network-configuration-instructions/ or other loctions for OS setup)

Server Removal:
  1. Admin removes /64 IPv6 host subnet from server
  2. TenantOS fires beforeServerIpRemoval event
  3. Listener removes VLAN from switches
  4. Listener calls external router configuration script for cleanup
  5. Listener deallocates routed subnet
```

## Installation

### Prerequisites

- TenantOS with Event Listener support
- Network switches with TenantOS automation support (API management enabled)
- Optionally: External router (TNSR example provided, other vendors supported via external script)
- SSH access to TenantOS server

### Step 1: Copy Listener Files

Copy files to TenantOS-controlled directory:

```bash
cp afterIpAssignmentListener.php /var/www/html/app/Custom/EventListeners/
cp beforeIpRemovalListener.php /var/www/html/app/Custom/EventListeners/
cp vlan_automation_config.php /var/www/html/app/Custom/EventListeners/
chmod 600 /var/www/html/app/Custom/EventListeners/vlan_automation_config.php
```

Optionally copy switch templates:

```bash
mkdir -p /var/www/html/app/Custom/EventListeners/templates/
cp templates/arista_vlan.template /var/www/html/app/Custom/EventListeners/templates/
cp templates/arista_vlan-REMOVAL.template /var/www/html/app/Custom/EventListeners/templates/
```

### Step 2: Configure Credentials

Edit `/var/www/html/app/Custom/EventListeners/vlan_automation_config.php`:

```php
'api_token' => 'YOUR_TENANTOSAPI_TOKEN_HERE',
'router_script_path' => '/var/www/html/scripts/tnsr-vlan-restconf.php',  // Optional
```

Get API token from TenantOS: Manage Users → API Keys → + Button to Create new API Key

### Step 3: Configure MLAG Pairs

Edit the `port_channel.valid_pairs` in config:

```php
'valid_pairs' => [
    [19, 11, '#/#'],    // Pair 1: Switch 19 (ODD) + Switch 11 (EVEN), port format
    [25, 23, '#'],      // Pair 2: Switch 25 (ODD) + Switch 23 (EVEN), port format
],
```

Format: `[odd_switch_id, even_switch_id, port_format_pattern]`

- Get switch IDs from TenantOS: Network Devices → Select Switch, device ID is the last number in the URL ($URL/networkDevices/manage/##)
- Port pattern: 
  - `#/#` for modular (e.g., Ethernet26/4 format)
  - `#` for single module (e.g., Ethernet39 format)

### Step 4: Cache Events

After creating or updating listeners, cache the events:

```bash
ssh user@tenantoshost
cd /var/www/html
app event:cache
```

**Important**: Event listeners are only loaded after `app event:cache` is run. See TenantOS documentation: https://documentation.tenantos.com/Tenantos/developers-system/events/#how-to-create-a-new-event-listener

## Configuration

### Credentials (Keep Private!)

```php
'api_token' => 'YOUR_TENANTOSAPI_TOKEN_HERE',
'router_script_path' => '/var/www/html/scripts/tnsr-vlan-restconf.php',
```

### VLAN Management

```php
'reserved_vlans' => [1],  // VLANs that will NEVER be automated
```

Add any management VLANs here (IPMI, internal, etc.)

### Subnet Allocation

```php
'subnet_tag_prefix' => 'routed',  // Prefix for subnet allocation tags
```

The system looks for server tags like:
- `routed48` → Allocate `/48` routed subnet from parent pool
- `routed56` → Allocate `/56` routed subnet from parent pool

### Debug Logging

```php
'debug_level' => 3,  // 0=Off, 1=Basic, 2=Detailed, 3=Very Detailed
'debug_log_path' => '/var/www/html/storage/logs/tenantos-vlan-creation.log',
```

## TenantOS Setup

### Create Host Subnet Pool

1. **IP Manager → Subnets → + to create a Child Subnet **: 
   - **Subnet**: `XXXX:YYYY:1:100::/56` (or your host pool)
   - Optional: Add tags if you restrict which servers can use this pool

This pool provides `/64` host subnets for individual server interfaces.

### Create Routed Subnet Pool

1. **Networks → Subnets → Create Subnet**:
   - **Target CIDR / Prefix**: `/56` (or your routed pool)
   - **Type**: Subnets will be divided into smaller subnets
2. **Subnet Configuration**:
   - ⚠️ **CRITICAL**: Uncheck "Enable automated VLAN actions when assigning IPs..."
   - **Only offer if server has one of these tags**: `routed56`

**Why uncheck VLAN automation?**
- Routed subnets are NOT interface assignments
- The listener allocates them via API, shouldn't trigger extra VLAN creation
- Only the host `/64` should trigger VLAN automation

### Create Server with MLAG Configuration

1. **Servers → [Your Server]**:
   - **Configuration Tab → Server Tags**: Add `routed48` or `routed56` (one per server)
   - **Switch Configuration**:
     - If connecting to first switch in MLAG pair: Select port from that switch (e.g., Ethernet26/4 on Switch 25)
     - If dual-uplink MLAG: Select `Port-Channel39` on one switch and Ethernet39 on the other.  Use the same channel on both switches
     - See switch documentation for detailed MLAG port configuration

### Assign Host IP Address

1. **Servers → [Your Server] → IP Management Tab**
2. Click **+ ADD IP**
3. Select your host subnet pool (e.g., `XXXX:YYYY:1:100::/56`)
4. Select ONE available `/64` from pool from Select IPs list
5. **DO NOT** manually assign the routed subnet
   - The listener finds and allocates it automatically based on server tags

## Subnet Allocation Strategy (Advanced)

The listener uses server tags to allocate routed subnets. Example strategy:

- Create multiple host pools by MLAG pair:
  - Pool A: `XXXX:YYYY:1:100::/56` (for servers on Switch pair [25,23])
  - Pool B: `XXXX:YYYY:2:100::/56` (for servers on Switch pair [19,11])

- Create corresponding routed pools:
  - Use Native VLAN Range to segregate VLANs to switch pairs 
  - Pool A: `XXXX:YYYY:1:200::/56` tagged `routed48` (VLAN range 101-150)
  - Pool B: `XXXX:YYYY:2:200::/56` tagged `routed48` (VLAN range 151-300)

- Tag servers with switch pair information, use VLAN pool restrictions in automation instructions

This ensures VLANs don't overlap between different switch pairs.

## Workflow Example: Server 36 with /48 Routed Subnet

**Setup**:
- Server 36 created with tag `routed48`
- Switch port: Ethernet26/4 (both switches in MLAG pair 25,23)
- Host pool: `XXXX:YYYY:1:100::/56`
- Routed pool: `XXXX:YYYY:1:200::/56` tagged `routed48`
- VLAN ID pool: 101-150 (for this switch pair)

**Example Process (Actual switch configuration determined by switch templates)**:

1. Admin assigns `/64` from host pool → TenantOS allocates `XXXX:YYYY:1:186::/64`
2. Listener triggers on `afterServerIpAssignment` event
3. Listener checks: Server 36 has tag `routed48`?
   - Yes → Look for available `/48` in pool tagged `routed48`
4. Listener queries API for available subnets
5. Listener allocates first available: `XXXX:YYYY:1:200::/48`
6. Listener assigns to server via API (with `performVlanActions=none`)
7. TenantOS assigns next available VLAN ID from pool 101-150: **VLAN 101**
8. Listener detects MLAG pair configuration:
   - Ethernet26/4 on Switch 25 (ODD)
   - Ethernet26/4 on Switch 23 (EVEN)
   - VLAN 101 is ODD → Switch 25 is primary (active), Switch 23 is secondary (passive)
9. Both switches configured with:
   - VLAN 101 on trunk
   - Port-Channel39 with MLAG
   - LACP: active/priority 1 on Switch 25, passive/priority 100 on Switch 23
   - ACLs for IPv4/IPv6 traffic filtering
10. External router script (optional) configures:
    - Subinterface for VLAN 101
    - Gateway IP `XXXX:YYYY:1:186::1/64` assigned to subinterface
    - Static route for routed subnet: `XXXX:YYYY:1:200::/48 via XXXX:YYYY:1:186::2`
    - Router advertisements for `/64` prefix
11. Server boots and configures:
    - Interface IP from `/64` subnet (e.g., `XXXX:YYYY:1:186::2/64`)
    - Routes for routed subnet (manual configuration required - see [webofnevada.com](https://webofnevada.com/network-configuration-instructions/))

**Result**:
- Server interface: `XXXX:YYYY:1:186::2/64`
- Routed subnet: `XXXX:YYYY:1:200::/48` (available for internal use)
- Both uplinks (Switch 25 and 23) have VLAN 101 configured with MLAG

## Multi-Vendor Switch Support

### Vendor Detection

The system automatically detects switch vendor from TenantOS API:

```
1. Get switch details from TenantOS API: /api/networkDevices/{id}/extendedDetails
2. Extract "managementVendor" field
   Examples: "aristaSsh", "juniperSsh", "dellSsh", "ciscoSsh"
3. Strip vendor management protocol: "aristaSsh" → "arista"
4. Look up template in config
5. Load and apply template
```

### Supported Vendors

#### Arista ✅ (Fully Tested)

- **Detection**: `managementVendor: "aristaSsh"`
- **Extracted Vendor**: `arista`
- **Models Tested**: DCS-7280, DCS-7048
- **Protocol**: EOS CLI

Configuration:
```php
'switch_templates' => [
    'arista' => '/var/www/html/app/Custom/EventListeners/templates/arista_vlan.template',
],
'switch_removal_templates' => [
    'arista' => '/var/www/html/app/Custom/EventListeners/templates/arista_vlan-REMOVAL.template',
],
```

#### Juniper, Dell, Cisco ⚠️ (Detection Ready, Untested)

Vendor detection code is prepared but templates have not been tested:
- `juniperSsh` → `juniper`
- `dellSsh` → `dell`
- `ciscoSsh` → `cisco`

To add support, create templates and add to config. See "Adding Other Vendors" below.

### Adding Other Vendors

To add vendor support:

1. **Create template file** based on `arista_vlan.template`
   - Update CLI syntax for your vendor (Junos, FTOS, IOS-XE, etc.)
   - Use same placeholders as Arista template
   - Test thoroughly in staging

2. **Add to config**:
   ```php
   'switch_templates' => [
       'arista' => '/path/to/arista_vlan.template',
       'juniper' => '/path/to/juniper_vlan.template',
   ],
   'switch_removal_templates' => [
       'arista' => '/path/to/arista_vlan-REMOVAL.template',
       'juniper' => '/path/to/juniper_vlan-REMOVAL.template',
   ],
   ```

3. **Test**:
   - Set `debug_level` to 3
   - Create test VLAN
   - Verify config applied correctly

4. **Submit GitHub issue** (not pull request):
   - Include template file
   - Include output of TenantOS API call: `GET /api/networkDevices/{switch_id}/extendedDetails`
   - Include testing notes and switch models tested
   - We'll review and add official support

## Router Configuration

The listener can call an external script to configure your router (TNSR example provided). The script is completely optional:

- If `router_script_path` is configured and file exists, the listener calls it
- If not configured or file missing, VLAN automation continues without router config
- You can use TNSR (example provided), another router brand, or skip router config entirely
- If your switch and router are the same box, you may not need this script

### TNSR Example

If using TNSR router with RESTCONF API:

```php
'router_script_path' => '/var/www/html/scripts/tnsr-vlan-restconf.php',
```

The script creates:
- VLAN subinterface with gateway IP `XXXX:YYYY:1:186::1/64`
- Static route for routed subnet via server's interface IP
- Router advertisements for host subnet prefix

For other routers, replace with appropriate script or remove the configuration.

## ACL Configuration

The template generates ACLs automatically. Example (actual rules vary by network):

```
ip access-list ACL_SERVER_36_IN
   5 permit udp any any eq bootps          ! DHCP server
   6 permit udp any any eq bootpc          ! DHCP client
   9 permit udp any any eq tftp            ! TFTP (PXE)
   50 permit udp any any eq domain         ! DNS
   51 permit tcp any any eq domain         ! DNS
   52 permit tcp any any eq www            ! HTTP
   53 permit tcp any any eq https          ! HTTPS
   10 permit ip host XX.YY.168.33 any      ! Server's IPv4
   1000 deny ip any any                    ! Deny everything else
```

⚠️ **ACLs can block network bootstrap (PXE, DHCP, etc.)**

Review your template's ACL rules to ensure they allow your bootstrap method. Modify as needed for your network.

## Network Configuration (Post-Deployment)

After server deploys, you must configure networking on the server itself. The listener configures the network infrastructure (switches, router), but the server OS needs manual setup.

For Linux systems, see detailed guides at: https://webofnevada.com/network-configuration-instructions/

Examples provided for major distributions. You'll need to:
1. Create VLAN interface on server (e.g., vlan.101)
2. Configure IPv6 address from host subnet (e.g., `XXXX:YYYY:1:186::2/64`)
3. Configure routing for routed subnet (e.g., `XXXX:YYYY:1:200::/48`)

## IPv6 Subnet Architecture

### Example Deployment

```
Datacenter Pool:     XXXX:YYYY::/36
  └─ DC Allocation:  XXXX:YYYY::/40

  └─ Management Tier: XXXX:YYYY:1::/48
      ├─ Host Pool:     XXXX:YYYY:1:100::/56 (256 possible /64s)
      │   Examples:
      │     - XXXX:YYYY:1:186::/64  (Server 36 interface)
      │     - XXXX:YYYY:1:187::/64  (Server 37 interface)
      │
      └─ Routed Pool:   XXXX:YYYY:1:200::/56 (256 possible /48s)
          Examples:
            - XXXX:YYYY:1:200::/48  (Server 36 routed)
            - XXXX:YYYY:1:201::/48  (Server 37 routed)

  └─ VPS Tier: (⚠️ Unsupported - VPS servers don't have switch ports)
      - Not currently tested with automation
      - Would require different approach
```

### IP Derivation

Given:
- Host subnet assigned: `XXXX:YYYY:1:186::/64`
- Routed subnet allocated: `XXXX:YYYY:1:200::/48`

Router configures:
- Gateway IP: `XXXX:YYYY:1:186::1/64` (assigned to VLAN subinterface)
- Server next-hop: `XXXX:YYYY:1:186::2` (server's interface IP)

Server configures:
- Interface IP: `XXXX:YYYY:1:186::2/64` (or other address from `/64`)
- Routes for routed subnet pointing to gateway `XXXX:YYYY:1:186::1`

## FAQ

**Q: Why do I get an extra VLAN in my configuration?**

A: The routed subnet has "Enable automated VLAN actions when assigning IPs..." checked. This causes TenantOS to trigger VLAN creation for the routed subnet assignment, creating an unwanted extra VLAN. Solution: Uncheck this setting in TenantOS for all routed subnets. Only host subnets (`/64`) should trigger VLAN automation.

**Q: Do I need to use a router script?**

A: No. The `router_script_path` configuration is optional. If not set or file doesn't exist, the listener skips router configuration. VLAN automation on switches continues normally. The example script (TNSR) is provided for convenience but you can use other routers or skip this step entirely.

**Q: How do servers get their IP addresses?**

A: This depends on your deployment. Options include:
- IPv6 SLAAC (Router Advertisement Prefix Delegation)
- DHCPv6 (if your server/router support it)
- Manual configuration in OS

**Q: Can I mix different switch vendors in one MLAG pair?**

A: Not in the current design. MLAG pairs must be the same vendor. However, you can have different vendor pairs in the same system (e.g., Arista pair + Juniper pair).

**Q: What if my vendor detection fails?**

A: The error "Could not determine vendor from managementVendor" means the switch's management vendor is empty, unexpected, or we guessed the vendor name wrong.

**To help us fix this:**

1. Get the switch's current management vendor setting:
   ```
   GET /api/networkDevices/{switch_id}/extendedDetails
   ```
   (Replace {switch_id} with TenantOS switch ID)

2. Find the `managementVendor` value in the response

3. **Submit GitHub issue** (not pull request) with:
   - The `managementVendor` value you see
   - Your switch model
   - Your network management setup (SSH, SNMP version, etc.)

We'll either:
- Correct the vendor name if we guessed wrong
- Add support for your vendor if new

**Q: Can I have servers without routed subnets?**

A: Yes. Don't add any `routedXX` tag to the server. The listener will configure VLAN for the host `/64` only, without allocating a routed subnet.

**Q: Can I change allocated routed subnets after assignment?**

A: Currently, no. The system allocates the first available routed subnet matching your tag. If you need a specific subnet, manage your pool allocation order in TenantOS.

**Q: What MLAG roles do the switches take?**

A: Based on VLAN number parity and your configuration:
- **ODD VLANs** (101, 103, 105...): ODD switch primary (active), EVEN switch secondary (passive)
- **EVEN VLANs** (102, 104, 106...): EVEN switch primary (active), ODD switch secondary (passive)
- This ensures ~50% load balance across both switches

## Support & Contributing

- **Issues**: GitHub - include debug logs (set `debug_level=3`) when reporting
- **New Vendors**: Submit GitHub issue with `managementVendor` API output and template file
- **Documentation**: Improvements welcome
- **Templates**: For new vendors/features

## License

AGPL v3 - See LICENSE file in repository

## Additional Resources

- TenantOS Event Listeners: https://documentation.tenantos.com/Tenantos/developers-system/events/
- Linux Network Configuration: https://webofnevada.com/network-configuration-instructions/
- GitHub Repository: https://github.com/shor0814/tenantosVLANAutomation
