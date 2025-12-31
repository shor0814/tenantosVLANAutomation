# Port-Channel & MLAG Architecture - v2

Comprehensive guide to MLAG (Multi-Chassis Link Aggregation) and Port-Channel configuration in TenantOS VLAN Automation.

## Core Concepts

### MLAG (Multi-Chassis Link Aggregation)

MLAG allows two physical switches to act as a single logical switch for link aggregation purposes. This provides redundancy - if one switch fails, the other maintains connectivity.

```
    Server (Dual Uplinks)
           ↓
    ┌──────┴──────┐
    ↓             ↓
Switch A      Switch B
(Primary)     (Secondary)
    ↓             ↓
    └──────┬──────┘
           ↓
    Peer Link (VLAN 4094)
    Management IP: 10.0.0.1 ← → 10.0.0.2
    
Logical View (Server sees):
    Port-Channel39 (active on both links)
```

### Port-Channel & LACP

Port-Channel aggregates multiple physical links into one logical link:

- **Channel Group**: Port aggregation configuration (e.g., channel-group 39)
- **LACP Mode**: 
  - **Active**: Switch initiates LACP negotiation (primary)
  - **Passive**: Switch responds to LACP (secondary)
- **Port Priority**: Lower number = higher preference (1 = primary, 100 = secondary)
- **LACP Fallback**: Keeps port active even if LACP negotiation fails

### VLAN Parity

The system uses VLAN number parity to determine which switch has primary role:

| VLAN | Parity | Primary | Secondary | LACP Mode |
|------|--------|---------|-----------|-----------|
| 101  | ODD    | Switch A (ODD)  | Switch B (EVEN) | Active → Passive |
| 102  | EVEN   | Switch B (EVEN) | Switch A (ODD) | Active → Passive |
| 103  | ODD    | Switch A (ODD)  | Switch B (EVEN) | Active → Passive |
| 104  | EVEN   | Switch B (EVEN) | Switch A (ODD) | Active → Passive |

This ensures balanced load distribution - each switch is primary for approximately 50% of VLANs.

## Configuration

### Define MLAG Pairs

In `vlan_automation_config.php`:

```php
'port_channel' => [
    'enabled' => true,
    'valid_pairs' => [
        [19, 11, '#/#'],    // Pair 1: ODD, EVEN, port format
        [25, 23, '#'],      // Pair 2: ODD, EVEN, port format
    ],
```

**Format**: `[odd_switch_id, even_switch_id, port_pattern]`

- **odd_switch_id**: Position 1 switch (gets primary role on ODD VLANs)
- **even_switch_id**: Position 0 switch (gets primary role on EVEN VLANs)
- **port_pattern**: 
  - `#` = single digit ports (e.g., Ethernet39 → 39)
  - `#/#` = modular ports (e.g., Ethernet26/4 → 264)

### Primary/Secondary Configuration

```php
'primary' => [
    'lacp_mode' => 'active',      // Initiates LACP negotiation
    'lacp_priority' => 1,         // Higher priority (lower = preferred)
],
'secondary' => [
    'lacp_mode' => 'passive',     // Responds to LACP
    'lacp_priority' => 100,       // Lower priority
],
```

## Template System

### How Vendor Detection Works

The system automatically detects switch vendor from TenantOS API:

```
1. Get switch details: GET /api/networkDevices/{id}/extendedDetails
2. Extract "managementVendor" field from response
   Example: "aristaSsh"
3. Strip protocol suffix: "aristaSsh" → "arista"
4. Look up template in config
   switch_templates['arista']
5. Load and apply template
```

### Supported Vendors

#### Arista ✅ (Fully Tested)

- **Detection**: `managementVendor: "aristaSsh"`
- **Extracted Vendor**: `arista`
- **Tested Models**: DCS-7280, DCS-7048
- **CLI Syntax**: EOS

Configuration:
```php
'switch_templates' => [
    'arista' => '/var/www/html/app/Custom/EventListeners/templates/arista_vlan.template',
],
'switch_removal_templates' => [
    'arista' => '/var/www/html/app/Custom/EventListeners/templates/arista_vlan-REMOVAL.template',
],
```

#### Other Vendors ⚠️ (Detection Ready, Untested)

Vendor detection is prepared but templates haven't been tested:
- `juniperSsh` → `juniper` (Junos CLI needed)
- `dellSsh` → `dell` (FTOS CLI needed)
- `ciscoSsh` → `cisco` (IOS-XE CLI needed)

### Adding New Vendor Support

To support a new vendor:

1. **Create template file** based on `arista_vlan.template`
   - Update CLI commands for your vendor
   - Keep same placeholder names
   - Test in staging

2. **Add to configuration**:
   ```php
   'switch_templates' => [
       'juniper' => '/path/to/juniper_vlan.template',
   ],
   'switch_removal_templates' => [
       'juniper' => '/path/to/juniper_vlan-REMOVAL.template',
   ],
   ```

3. **Submit GitHub issue**:
   - Include template file
   - Include `managementVendor` value from API response
   - Include switch model and OS version
   - Testing notes appreciated

We'll review and add official support.

## Deployment Workflow

### Scenario: Server 36 with VLAN 101 (ODD)

**Setup**:
- MLAG Pair: [25, 23, '#'] (Switch 25 ODD, Switch 23 EVEN)
- Port: Ethernet26/4 on both switches
- VLAN: 101 (ODD) → Primary = Switch 25

**Step 1: Determine Parity**

```
VLAN 101 % 2 = 1 (ODD)
ODD VLAN → Use ODD switch as primary
Primary = Switch 25 (active)
Secondary = Switch 23 (passive)
```

**Step 2: Apply Template to Primary Switch (25)**

The listener loads template and applies via SSH:

```
vlan 101
   name vlan.101

interface Ethernet26/4
   description Server 36 - VLAN 101 - 2025-12-27
   switchport mode trunk
   switchport trunk allowed vlan add 101
   channel-group 264 mode active          ← Primary: active
   lacp port-priority 1                   ← Primary: priority 1
   spanning-tree portfast edge
   ip access-group ACL_SERVER_36_IN in

interface Port-Channel264
   description Server 36 - Port Channel - VLAN 101
   switchport mode trunk
   switchport trunk allowed vlan add 101
   mlag 264
   spanning-tree portfast edge
   port-channel lacp fallback static
   port-channel lacp fallback timeout 5
   ip access-group ACL_SERVER_36_IN in

ip access-list ACL_SERVER_36_IN
   10 permit ip host XX.YY.168.33 any
   1000 deny ip any any

ipv6 access-list ACL_SERVER_36_V6_IN
   10 permit ipv6 host XXXX:YYYY:1:186::2 any
   20 permit ipv6 XXXX:YYYY:1:186::/64 any
   30 permit ipv6 XXXX:YYYY:1:200::/48 any
   1000 deny ipv6 any any
```

**Step 3: Apply Template to Secondary Switch (23)**

Same configuration, but with secondary values:

```
vlan 101
   name vlan.101

interface Ethernet26/4
   description Server 36 - VLAN 101 - 2025-12-27
   switchport mode trunk
   switchport trunk allowed vlan add 101
   channel-group 264 mode passive         ← Secondary: passive
   lacp port-priority 100                 ← Secondary: priority 100
   spanning-tree portfast edge
   ip access-group ACL_SERVER_36_IN in

interface Port-Channel264
   description Server 36 - Port Channel - VLAN 101
   [identical configuration]
   
[ACLs identical to primary]
```

**Step 4: Verify MLAG**

From Switch 25:
```
KC1-ARSW03# show mlag
MLAG Configuration:
  domain-id: mlag-artor
  local-interface: Vlan4094
  peer-address: 10.0.0.2
  peer-link: Port-Channel10


MLAG Interfaces:
  Port-Channel264 ← MLAG Active
```
```
KC1-ARSW03# show mlag interfaces 264
                                                                                           local/remote
   mlag       desc                                       state       local       remote          status
---------- ------------------------------------ ----------------- ----------- ------------ ------------
    264       Server 65 - Port Channel - VLA       active-full       Po264        Po264           up/up
```


**Result**:
- Server sees single Port-Channel264
- Both physical links active
- Traffic load-balanced between switches
- VLAN 101 available on both switches

### Scenario: Server 37 with VLAN 102 (EVEN)

Same pair, but VLAN 102 is EVEN:

```
VLAN 102 % 2 = 0 (EVEN)
EVEN VLAN → Use EVEN switch as primary
Primary = Switch 23 (active)
Secondary = Switch 25 (passive)
```

Configuration is identical except roles reversed:
- Switch 23: `mode active`, `lacp port-priority 1`
- Switch 25: `mode passive`, `lacp port-priority 100`

**Result**: Balanced load distribution
- Switch 25: Primary for VLANs 101, 103, 105, ... (ODD)
- Switch 23: Primary for VLANs 102, 104, 106, ... (EVEN)
- Each switch handles ~50% of VLAN traffic

## Template Placeholders

Available in templates:

| Placeholder | Value | Example |
|------------|-------|---------|
| `{SERVER_ID}` | Server ID | 36 |
| `{VLAN_ID}` | VLAN number | 101 |
| `{VLAN_NAME}` | VLAN name | vlan.101 |
| `{PORT_NAME}` | Full port name | Ethernet26/4 |
| `{PORT_STRING}` | Port without type | 26/4 |
| `{PORT_NUMBER}` | Numeric form | 264 |
| `{CHANNEL_GROUP}` | Port-Channel number | 264 |
| `{DATE}` | Timestamp | 2025-12-27 18:58:54 |
| `{LACP_CONFIG}` | LACP block | channel-group {CHANNEL_GROUP}... |
| `{PORT_CHANNEL_CONFIG}` | Port-Channel block | interface Port-Channel... |
| `{IPV6_ADDRESS}` | Server host IP | XXXX:YYYY:1:186::2 |
| `{IPV6_HOST_SUBNET}` | Host subnet | XXXX:YYYY:1:186::/64 |
| `{IPV6_ROUTED_SUBNET}` | Routed subnet | XXXX:YYYY:1:200::/48 |

## LACP Fallback

Port configuration includes LACP fallback for reliability:

```
port-channel lacp fallback static
port-channel lacp fallback timeout 5
```

- **static**: If LACP negotiation fails, keep port active
- **timeout 5**: Wait 5 seconds before giving up

This keeps server connected even if LACP has temporary issues.

## Port Priority

LACP selects primary link based on port priority:

```
Primary (lacp port-priority 1):
  - Higher preference
  - Carries primary traffic if both links available

Secondary (lacp port-priority 100):
  - Lower preference
  - Fallback if primary congested
```

In practice, most load-balancing algorithms distribute traffic ~50/50 anyway.

## Removal Process

When server is removed, the template removal section undoes configuration:

**Removal template generates**:

```
interface Ethernet26/4
   no channel-group 264 mode active
   no lacp port-priority 1
   switchport mode trunk
   switchport trunk allowed vlan 1
   no switchport trunk allowed vlan 101
   description Server 36
   no ip access-group ACL_SERVER_36_IN in

interface Port-Channel264
   no switchport trunk allowed vlan 101
   no mlag 264

no vlan 101
```

**Result** (done on both switches):
- VLAN 101 removed from allowed list
- Port-Channel configuration removed
- Physical port returned to neutral state
- VLAN 101 deleted
- ACLs removed

## Failover Scenarios

### One Physical Link Fails

```
Normal:
  Server ← Port 1 → Switch A
           Port 2 → Switch B

Port 1 fails:
  Server ← Port 2 → Switch B
           Port 1 → [failed]

LACP detects failure:
  - Port 1 marked down
  - Port 2 carries all traffic
  - Server stays connected

Port 1 recovers:
  - LACP re-negotiates
  - Traffic rebalances
```

### Primary Switch Fails

```
Normal:
  Switch A (primary, active) ←→ MLAG Peer Link ←→ Switch B (secondary, passive)

Switch A fails:
  Switch B detects peer loss:
    - Peer link down
    - MLAG timeout (~10s)
  Switch B assumes primary role:
    - Activates MLAG interfaces
    - Server Port-Channel stays up
    - Traffic continues (via Switch B)

Switch A recovers:
  - Rejoins MLAG domain
  - Primary role restored
  - Traffic rebalances
```

## Monitoring & Troubleshooting

### Check MLAG Status

```bash
show mlag
show mlag interfaces
show interface Port-Channel264
```

### Check LACP Status

```bash
show lacp peer
show lacp interface  
```

### Common Issues

**Issue: Port-Channel Down**
- Check both physical links: `show interface status`
- Verify LACP negotiation: `show lacp peer`
- Ensure VLAN configured on both switches: `show vlan id 101`
- Fix: Verify LACP mode (active ↔ passive), check cables

**Issue: Traffic Only on One Link**
- This may be normal behavior depending on load-balancing algorithm
- Verify both links up: `show interface status`
- Check LACP priorities: Primary=1, Secondary=100

**Issue: MLAG Peer Link Down**
- Check VLAN 4094 exists: `show interface Vlan4094`
- Verify IP reachable: `ping 10.0.0.2`
- Check peer link physical port up
- Fix: Verify VLAN 4094 config on both switches

## Router Configuration

Optional external script can configure router for VLAN traffic:

- **Host subnet gateway**: Assigned to VLAN subinterface with `::1` suffix
  - Example: VLAN 101 gets subinterface with IP `XXXX:YYYY:1:186::1/64`
  
- **Server next-hop**: Server's interface IP (`::2` address)
  - Server interface: `XXXX:YYYY:1:186::2/64`
  - Server routes back to: `XXXX:YYYY:1:186::1` (gateway)

If not using external router script, switches handle local VLAN traffic only.

## Multi-Vendor Deployment

### Mixed Vendors in Same System

The system can handle different vendors automatically:

```
Switch 19: managementVendor="aristaSsh" → loads arista template
Switch 25: managementVendor="aristaSsh" → loads arista template

Switch 11: managementVendor="juniperSsh" → loads juniper template  
Switch 23: managementVendor="juniperSsh" → loads juniper template

MLAG Pair 1: [19, 11] with Arista + Juniper (different vendors)
  - Both templates applied correctly
  - LACP negotiates between different vendors (RFC 802.3ad)
  - Should work fine
```

However, within a single MLAG pair, both switches should ideally be same vendor.

### Adding New Vendor Support

**To add Juniper (for example)**:

1. Create `/path/to/juniper_vlan.template`
   - Use Junos CLI syntax
   - Keep same placeholders

2. Add to config:
   ```php
   'switch_templates' => [
       'juniper' => '/path/to/juniper_vlan.template',
   ],
   'switch_removal_templates' => [
       'juniper' => '/path/to/juniper_vlan-REMOVAL.template',
   ],
   ```

3. Test thoroughly

4. Submit GitHub issue with:
   - Template file
   - `managementVendor` API output
   - Switch model and OS version
   - Testing results

## References

- IEEE 802.3ad - LACP Standard
- RFC 3373 - LACP Details
- Arista EOS Port-Channel Configuration
- TenantOS API Documentation
