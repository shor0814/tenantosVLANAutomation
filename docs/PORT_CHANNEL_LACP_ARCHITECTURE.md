# PORT_CHANNEL and LACP Configuration Architecture

## Overview

PORT_CHANNEL and LACP configuration in TenantOS VLAN Automation is **centralized in the configuration file**, not embedded in templates. This separation of concerns provides flexibility, maintainability, and consistency across all deployments.

## Why Configuration File Instead of Template?

**Benefits:**
- **Centralized Control** - All LACP settings in one place
- **Consistency** - Same configuration blocks for all switch vendors
- **Flexibility** - Change LACP behavior without editing templates
- **Reusability** - Blocks used for both creation and removal
- **Testability** - Configuration easily modified for different scenarios

## How It Works

### 1. Define Configuration Blocks in vlan_automation_config.php

The configuration file contains four key blocks:

```php
'port_channel' => [
    'enabled' => true,
    'valid_pairs' => [
        [19, 11, '#/#'],  // Pair definition with port format
        [25, 23, '#'],
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

### 2. Define LACP Configuration Block

This block is substituted into the template at the `{LACP_CONFIG}` placeholder:

```php
'lacp_config_block' => <<<'EOL'
channel-group {CHANNEL_GROUP} mode {LACP_MODE}
   lacp port-priority {LACP_PRIORITY}
EOL,
```

### 3. Define Port-Channel Configuration Block

This separate block creates the full Port-Channel interface:

```php
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
EOL,
```

### 4. Define Removal Blocks

Separate blocks for clean removal:

```php
'lacp_config_removal' => <<<'EOL'
   no channel-group {CHANNEL_GROUP} mode {LACP_MODE}
   no lacp port-priority {LACP_PRIORITY}
EOL,

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
EOL,
```

## Placeholder Substitution

The listeners substitute placeholders based on the switch's LACP role:

### For PRIMARY Switch (VLAN parity matches switch position)

```php
// Listener substitutes:
'{LACP_MODE}' => 'active'
'{LACP_PRIORITY}' => 1
```

**Result in template:**
```
channel-group 264 mode active
   lacp port-priority 1
```

### For SECONDARY Switch (VLAN parity doesn't match switch position)

```php
// Listener substitutes:
'{LACP_MODE}' => 'passive'
'{LACP_PRIORITY}' => 100
```

**Result in template:**
```
channel-group 264 mode passive
   lacp port-priority 100
```

## Template Integration

The main template (e.g., `arista_vlan.template`) includes placeholders for these blocks:

```
interface Ethernet{PORT_STRING}
   description Server {SERVER_ID}
   switchport mode trunk
   switchport trunk allowed vlan {VLAN_ID}
{LACP_CONFIG}

{PORT_CHANNEL_CONFIG}

interface Vlan{VLAN_ID}
   ip address {IPV4_ADDRESS} 255.255.255.0
   ipv6 address {IPV6_ROUTED_SUBNET}
   ipv6 address {IPV6_HOST_SUBNET} secondary
   no shutdown
```

When executed:
- `{LACP_CONFIG}` is replaced with the LACP block (active or passive)
- `{PORT_CHANNEL_CONFIG}` is replaced with the Port-Channel block
- All other placeholders are replaced with actual values

## Example: VLAN 101 on Switches 19 & 11

**Configuration:**
```php
'valid_pairs' => [
    [19, 11, '#/#'],  // Pos 0=19 (EVEN), Pos 1=11 (ODD)
],
'primary' => ['lacp_mode' => 'active', 'lacp_priority' => 1],
'secondary' => ['lacp_mode' => 'passive', 'lacp_priority' => 100],
```

**VLAN 101 (ODD):**

**Switch 19 (Position 0, EVEN):** ODD ≠ EVEN → SECONDARY
```
channel-group 264 mode passive
   lacp port-priority 100
```

**Switch 11 (Position 1, ODD):** ODD = ODD → PRIMARY
```
channel-group 264 mode active
   lacp port-priority 1
```

Same Port-Channel block applied to both:
```
interface Port-Channel264
   description Server 65 - Port Channel - VLAN 101
   no shutdown
   switchport mode trunk
   switchport trunk allowed vlan add 101
   mlag 264
   spanning-tree portfast edge
   port-channel lacp fallback static
   port-channel lacp fallback timeout 5
```

## Customization

### Changing LACP Behavior

To change LACP priority values globally, edit vlan_automation_config.php:

```php
'primary' => [
    'lacp_mode' => 'active',
    'lacp_priority' => 5,  // Changed from 1
],

'secondary' => [
    'lacp_mode' => 'passive',
    'lacp_priority' => 200,  // Changed from 100
],
```

No template changes needed. All future VLANs use new values.

### Modifying Port-Channel Interface

To add features (e.g., additional MLAG settings), update the block:

```php
'port_channel_config_block' => <<<'EOL'
interface Port-Channel{CHANNEL_GROUP}
   description Server {SERVER_ID} - Port Channel - VLAN {VLAN_ID}
   no shutdown
   switchport mode trunk
   switchport trunk allowed vlan add {VLAN_ID}
   mlag {CHANNEL_GROUP}
   mlag heartbeat-interval 200  // Added
   spanning-tree portfast edge
   port-channel lacp fallback static
   port-channel lacp fallback timeout 5
EOL,
```

Template unchanged. Feature applied to all servers.

## Advantages Over Template-Based Configuration

| Aspect | Config-Based | Template-Based |
|--------|--------------|----------------|
| Change LACP priority | Edit config, immediate | Edit template, affects all |
| Test different LACP modes | Simple: change config | Complex: need test template |
| Consistency across vendors | ✅ Centralized | ❌ Per-template |
| Override per-pair | ✅ Easy (add to valid_pairs) | ❌ Requires template logic |
| Documentation | ✅ Self-documenting config | ❌ Hidden in template |

## Removal Configuration

Same principle applies to removal. Separate blocks ensure clean removal without affecting other VLANs:

```php
'lacp_config_removal' => <<<'EOL'
   no channel-group {CHANNEL_GROUP} mode {LACP_MODE}
   no lacp port-priority {LACP_PRIORITY}
EOL,
```

This removes ONLY the channel-group and lacp priority, leaving other config intact.

## Summary

**PORT_CHANNEL and LACP configuration is config-driven because:**

1. **Separation of Concerns** - Configuration logic separate from template
2. **Centralized Management** - All LACP settings in one file
3. **Flexibility** - Change behavior without template editing
4. **Consistency** - Same blocks applied uniformly
5. **Maintainability** - Easy to understand and modify
6. **Testability** - Simple to try different configurations

This architecture ensures the system is:
- Easy to customize per deployment
- Simple to troubleshoot
- Maintainable long-term
- Vendor-independent (blocks work for Arista, Juniper, etc.)
