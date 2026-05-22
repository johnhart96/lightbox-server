<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SystemManager.php';
require_once __DIR__ . '/../src/UserManager.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\SystemManager;
use App\UserManager;
use App\Auth;

$db = Database::getInstance();
$userManager = new UserManager($db);
Auth::requireLogin($userManager);

$settings = $db->getSettings();
$systemName = htmlspecialchars($settings['system_name'] ?? 'Lightbox-Server');
$currentUser = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $systemName ?> | Lightbox Server</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo-icon"></div>
                <div class="logo-text">
                    <a href="/">
                        <h1>Lightbox Server</h1>
                    </a>
                    <span>Entertainment Network Manager</span>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="#dashboard" class="nav-link active" data-tab="dashboard">
                            <span class="icon">📊</span> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="#devices" class="nav-link" data-tab="devices">
                            <span class="icon">📡</span> Devices
                        </a>
                    </li>
                    <li>
                        <a href="#dns" class="nav-link" data-tab="dns">
                            <span class="icon">🌐</span> DNS Settings
                        </a>
                    </li>
                    <li>
                        <a href="#dhcp" class="nav-link" data-tab="dhcp">
                            <span class="icon">🔌</span> DHCP Server
                        </a>
                    </li>
                    <li>
                        <a href="#ntp" class="nav-link" data-tab="ntp">
                            <span class="icon">⏰</span> NTP (Time)
                        </a>
                    </li>
                    <li>
                        <a href="#samba" class="nav-link" data-tab="samba">
                            <span class="icon">📁</span> File Sharing
                        </a>
                    </li>
                    <li>
                        <a href="#network" class="nav-link" data-tab="network">
                            <span class="icon">🔧</span> Network Interfaces
                        </a>
                    </li>
                    <li>
                        <a href="#logs" class="nav-link" data-tab="logs">
                            <span class="icon">📝</span> Service Logs
                        </a>
                    </li>
                    <li>
                        <a href="#users" class="nav-link" data-tab="users">
                            <span class="icon">👤</span> User Accounts
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <div class="status-pulse-container">
                    <span class="status-pulse green"></span>
                    <span class="status-label">System Online</span>
                </div>
                <div class="uptime-display">
                    Uptime: <span id="sidebar-uptime">--</span>
                </div>
                <?php if ($currentUser): ?>
                <div class="sidebar-user">
                    <span class="sidebar-user-name">
                        <?= htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']) ?>
                    </span>
                    <button id="logout-btn" class="btn-logout" title="Sign out">Sign out</button>
                </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <header class="main-header">
                <div class="header-title">
                    <h2 id="current-tab-title">Dashboard</h2>
                    <p id="current-tab-desc">System health overview and active leases</p>
                </div>
                <div class="header-actions">
                    <div class="systime-box">
                        <span class="icon">📅</span>
                        <span id="header-time">--:--:--</span>
                    </div>
                    <button id="apply-btn" class="btn btn-primary btn-apply hidden">
                        <span class="pulse-ring"></span>
                        Apply Pending Changes
                    </button>
                </div>
            </header>

            <div class="content-body">
                <!-- Tab: Dashboard -->
                <section id="tab-dashboard" class="tab-panel active">
                    <?php if (!$userManager->hasUsers()): ?>
                    <div class="alert-danger">
                        <div class="alert-danger-icon">⚠</div>
                        <div class="alert-danger-body">
                            <strong>No user accounts configured — authentication is disabled.</strong>
                            Anyone on the network can access and modify this system.
                            <a href="#users" class="alert-danger-link" data-tab="users">Create a user account</a> to enable login protection.
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon purple">🔌</div>
                            <div class="stat-details">
                                <span class="stat-val" id="stat-leases">0</span>
                                <span class="stat-label">Active DHCP Leases</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon blue">🌐</div>
                            <div class="stat-details">
                                <span class="stat-val" id="stat-dns-records">0</span>
                                <span class="stat-label">DNS Host Records</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green">📁</div>
                            <div class="stat-details">
                                <span class="stat-val" id="stat-shares">0</span>
                                <span class="stat-label">Active File Shares</span>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-grid">
                        <!-- Resource Metrics Card -->
                        <div class="card resource-card">
                            <h3>Resource Usage</h3>
                            <div class="metrics-container">
                                <div class="metric-circle">
                                    <svg class="progress-ring" width="120" height="120">
                                        <circle class="progress-ring__background" stroke="#222" stroke-width="8" fill="transparent" r="50" cx="60" cy="60"/>
                                        <circle class="progress-ring__circle" id="cpu-ring" stroke="url(#cpuGrad)" stroke-width="8" fill="transparent" r="50" cx="60" cy="60"/>
                                        <defs>
                                            <linearGradient id="cpuGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                                <stop offset="0%" stop-color="#a855f7" />
                                                <stop offset="100%" stop-color="#6366f1" />
                                            </linearGradient>
                                        </defs>
                                    </svg>
                                    <div class="metric-label">
                                        <span class="value" id="cpu-percent">0%</span>
                                        <span class="label">CPU</span>
                                    </div>
                                </div>
                                <div class="metric-circle">
                                    <svg class="progress-ring" width="120" height="120">
                                        <circle class="progress-ring__background" stroke="#222" stroke-width="8" fill="transparent" r="50" cx="60" cy="60"/>
                                        <circle class="progress-ring__circle" id="ram-ring" stroke="url(#ramGrad)" stroke-width="8" fill="transparent" r="50" cx="60" cy="60"/>
                                        <defs>
                                            <linearGradient id="ramGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                                <stop offset="0%" stop-color="#3b82f6" />
                                                <stop offset="100%" stop-color="#06b6d4" />
                                            </linearGradient>
                                        </defs>
                                    </svg>
                                    <div class="metric-label">
                                        <span class="value" id="ram-percent">0%</span>
                                        <span class="label">RAM</span>
                                    </div>
                                </div>
                                <div class="metric-circle">
                                    <svg class="progress-ring" width="120" height="120">
                                        <circle class="progress-ring__background" stroke="#222" stroke-width="8" fill="transparent" r="50" cx="60" cy="60"/>
                                        <circle class="progress-ring__circle" id="disk-ring" stroke="url(#diskGrad)" stroke-width="8" fill="transparent" r="50" cx="60" cy="60"/>
                                        <defs>
                                            <linearGradient id="diskGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                                <stop offset="0%" stop-color="#10b981" />
                                                <stop offset="100%" stop-color="#059669" />
                                            </linearGradient>
                                        </defs>
                                    </svg>
                                    <div class="metric-label">
                                        <span class="value" id="disk-percent">0%</span>
                                        <span class="label">Disk</span>
                                    </div>
                                </div>
                            </div>
                            <div class="resource-details">
                                <p>Memory: <span id="ram-details">0 GB / 0 GB</span></p>
                                <p>Disk Space: <span id="disk-details">0 GB / 0 GB</span></p>
                                <p>System Load: <span id="load-avg">0.00, 0.00, 0.00</span></p>
                            </div>
                        </div>

                        <!-- Services Status Card -->
                        <div class="card status-card">
                            <h3>Service Status</h3>
                            <ul class="service-list">
                                <li>
                                    <span class="service-name">DNS Server (Bind9)</span>
                                    <span class="badge" id="status-bind9">Checking...</span>
                                </li>
                                <li>
                                    <span class="service-name">DHCP Server (dnsmasq)</span>
                                    <span class="badge" id="status-dhcp">Checking...</span>
                                </li>
                                <li>
                                    <span class="service-name">NTP Service (Chrony)</span>
                                    <span class="badge" id="status-ntp">Checking...</span>
                                </li>
                                <li>
                                    <span class="service-name">File Sharing (smbd)</span>
                                    <span class="badge" id="status-samba">Checking...</span>
                                </li>
                                <li>
                                    <span class="service-name">ACN / Art-Poll Discovery (SLP)</span>
                                    <span class="badge" id="status-acn">Checking...</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- DHCP Leases Table -->
                    <div class="card table-card">
                        <h3>Active DHCP Leases</h3>
                        <div class="table-wrapper">
                            <table id="leases-table">
                                <thead>
                                    <tr>
                                        <th>IP Address</th>
                                        <th>MAC Address / DUID</th>
                                        <th>Hostname</th>
                                        <th>Expiry Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="4" class="text-center">Loading active leases...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Tab: DNS -->
                <section id="tab-dns" class="tab-panel">
                    <div class="settings-grid">
                        <div class="card form-card">
                            <h3>DNS Domain Configuration</h3>
                            <form id="dns-global-form">
                                <div class="form-group">
                                    <label for="system_name">System Hostname</label>
                                    <input type="text" id="system_name" name="system_name" required>
                                    <span class="help-text">Local hostname of the Lightbox Server.</span>
                                </div>
                                <div class="form-group">
                                    <label for="domain_name">Local Domain Name</label>
                                    <input type="text" id="domain_name" name="domain_name" required>
                                    <span class="help-text">Suffix for local devices (e.g. <code>lighting.local</code>).</span>
                                </div>
                                <div class="form-group">
                                    <label for="dns_interface">DNS Server Interface</label>
                                    <select id="dns_interface" name="dns_interface">
                                        <option value="">Auto-detect (first available host IP)</option>
                                    </select>
                                    <span class="help-text">Interface whose IP is used for SOA, NS, and DHCP DNS-server advertisement.</span>
                                </div>
                                <div class="form-group-row">
                                    <div class="form-group">
                                        <label for="primary_dns">Primary Upstream DNS</label>
                                        <input type="text" id="primary_dns" name="primary_dns" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="secondary_dns">Secondary Upstream DNS</label>
                                        <input type="text" id="secondary_dns" name="secondary_dns">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-secondary">Save DNS Settings</button>
                            </form>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 24px;">
                            <div class="card table-card">
                                <div class="card-header-btn">
                                    <h3>Custom Host Records</h3>
                                    <button id="add-dns-record-btn" class="btn btn-secondary btn-sm">+ Add Record</button>
                                </div>
                                <div class="table-wrapper">
                                    <table id="dns-records-table">
                                        <thead>
                                            <tr>
                                                <th>Hostname</th>
                                                <th>IP Address</th>
                                                <th>Type</th>
                                                <th>Description</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td colspan="5" class="text-center">Loading DNS records...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="card table-card">
                                <h3>DHCP Hostname DNS Entries</h3>
                                <p class="text-muted" style="margin: 4px 0 14px; font-size: 0.875rem;">DNS entries created automatically from DHCP. Static reservations are always present; dynamic entries update every minute from active leases.</p>
                                <div class="table-wrapper">
                                    <table id="dhcp-dns-table">
                                        <thead>
                                            <tr>
                                                <th>Hostname</th>
                                                <th>IP Address</th>
                                                <th>Type</th>
                                                <th>Source</th>
                                                <th>Lease</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td colspan="5" class="text-center">Loading...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Tab: DHCP -->
                <section id="tab-dhcp" class="tab-panel">
                    <div class="settings-grid">
                        <!-- DHCP Settings Form -->
                        <div class="card form-card">
                            <h3>DHCP Server Settings</h3>
                            <form id="dhcp-settings-form">
                                <div class="form-group">
                                    <label for="dhcp_interface">Network Interface</label>
                                    <select id="dhcp_interface" name="dhcp_interface">
                                        <option value="">Listen on all interfaces (Recommended)</option>
                                    </select>
                                    <span class="help-text">Physical network card to serve DHCP leases on.</span>
                                </div>

                                <div class="form-group">
                                    <label>DHCP Advertisements</label>
                                    <div class="checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="advertise_dns" name="advertise_dns" value="1">
                                            Offer Lightbox as DNS server
                                        </label>
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="advertise_ntp" name="advertise_ntp" value="1">
                                            Offer Lightbox as NTP server
                                        </label>
                                    </div>
                                    <span class="help-text">Advertise this server's IP to DHCP clients for DNS and/or NTP.</span>
                                </div>

                                <hr>
                                
                                <div class="config-section">
                                    <div class="section-title-toggle">
                                        <h4>IPv4 Configuration</h4>
                                        <label class="switch">
                                            <input type="checkbox" id="v4_enabled" name="v4_enabled" value="1">
                                            <span class="slider round"></span>
                                        </label>
                                    </div>
                                    
                                    <div id="v4-settings-fields">
                                        <div class="form-group-row">
                                            <div class="form-group">
                                                <label for="v4_subnet">Subnet Network</label>
                                                <input type="text" id="v4_subnet" name="v4_subnet" placeholder="192.168.1.0">
                                            </div>
                                            <div class="form-group">
                                                <label for="v4_netmask">Subnet Mask</label>
                                                <input type="text" id="v4_netmask" name="v4_netmask" placeholder="255.255.255.0">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="v4_gateway">Default Gateway (Router)</label>
                                            <input type="text" id="v4_gateway" name="v4_gateway" placeholder="192.168.1.1">
                                        </div>
                                        <div class="form-group-row">
                                            <div class="form-group">
                                                <label for="v4_range_start">IP Range Start</label>
                                                <input type="text" id="v4_range_start" name="v4_range_start" placeholder="192.168.1.100">
                                            </div>
                                            <div class="form-group">
                                                <label for="v4_range_end">IP Range End</label>
                                                <input type="text" id="v4_range_end" name="v4_range_end" placeholder="192.168.1.200">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="v4_lease_time">Default Lease Time</label>
                                            <input type="text" id="v4_lease_time" name="v4_lease_time" placeholder="12h">
                                            <span class="help-text">e.g. <code>12h</code>, <code>24h</code>, <code>45m</code>.</span>
                                        </div>
                                    </div>
                                </div>

                                <hr>

                                <div class="config-section">
                                    <div class="section-title-toggle">
                                        <h4>IPv6 Configuration (DHCPv6 & RA)</h4>
                                        <label class="switch">
                                            <input type="checkbox" id="v6_enabled" name="v6_enabled" value="1">
                                            <span class="slider round"></span>
                                        </label>
                                    </div>

                                    <div id="v6-settings-fields">
                                        <div class="form-group">
                                            <label for="v6_prefix">IPv6 Prefix</label>
                                            <input type="text" id="v6_prefix" name="v6_prefix" placeholder="fd00::/64">
                                        </div>
                                        <div class="form-group-row">
                                            <div class="form-group">
                                                <label for="v6_range_start">IPv6 Range Start</label>
                                                <input type="text" id="v6_range_start" name="v6_range_start" placeholder="fd00::100">
                                            </div>
                                            <div class="form-group">
                                                <label for="v6_range_end">IPv6 Range End</label>
                                                <input type="text" id="v6_range_end" name="v6_range_end" placeholder="fd00::200">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="v6_lease_time">Default Lease Time</label>
                                            <input type="text" id="v6_lease_time" name="v6_lease_time" placeholder="12h">
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-secondary">Save DHCP Settings</button>
                            </form>
                        </div>

                        <!-- DHCP Static Reservations Card -->
                        <div class="card table-card">
                            <div class="card-header-btn">
                                <h3>Static Address Reservations</h3>
                                <button id="add-reservation-btn" class="btn btn-secondary btn-sm">+ Add Reservation</button>
                            </div>
                            <div class="table-wrapper">
                                <table id="reservations-table">
                                    <thead>
                                        <tr>
                                            <th>Hostname</th>
                                            <th>MAC Address</th>
                                            <th>Reserved IP</th>
                                            <th>Type</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="5" class="text-center">Loading static reservations...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Tab: NTP -->
                <section id="tab-ntp" class="tab-panel">
                    <div class="card form-card max-w-600">
                        <h3>NTP Synchronization Settings</h3>
                        <p class="desc-text">Configures upstream Network Time Protocol servers. Chrony will synchronize system time to these targets and provide time services to local network clients.</p>
                        
                        <form id="ntp-settings-form">
                            <div class="form-group">
                                <label for="ntp_servers">Upstream NTP Pools / Servers</label>
                                <input type="text" id="ntp_servers" name="ntp_servers" required>
                                <span class="help-text">Comma-separated list of servers. e.g. <code>0.pool.ntp.org, 1.pool.ntp.org, time.google.com</code></span>
                            </div>
                            
                            <div class="info-alert">
                                <strong>Note:</strong> In isolated entertainment networks with no internet connection, this server automatically falls back to serving time from its internal system clock at <strong>Stratum 10</strong>, allowing your local devices to synchronize with each other.
                            </div>

                            <button type="submit" class="btn btn-secondary">Save NTP Settings</button>
                        </form>
                    </div>
                </section>

                <!-- Tab: Samba -->
                <section id="tab-samba" class="tab-panel">
                    <div class="card table-card">
                        <div class="card-header-btn">
                            <h3>File Shared Folders</h3>
                            <button id="add-share-btn" class="btn btn-secondary btn-sm">+ Add Share</button>
                        </div>
                        <p class="desc-text">Exposed network folders accessible via Windows File Sharing (SMB). Used for backups, show files, and software updates.</p>
                        <div class="table-wrapper">
                            <table id="samba-shares-table">
                                <thead>
                                    <tr>
                                        <th>Share Name</th>
                                        <th>Folder Path</th>
                                        <th>Writeable</th>
                                        <th>Public/Guest</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="6" class="text-center">Loading shared folders...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Tab: Network Interfaces -->
                <section id="tab-network" class="tab-panel">
                    <div class="info-alert" style="margin-bottom: 20px;">
                        <strong>Note:</strong> Changes are applied immediately to the host system via the DHCP container (host network namespace). Use <strong>Apply Pending Changes</strong> to re-apply these settings after a container restart.
                    </div>
                    <div id="interfaces-grid" class="interfaces-grid">
                        <p class="text-muted text-center">Loading network interfaces...</p>
                    </div>
                </section>

                <!-- Tab: Devices -->
                <section id="tab-devices" class="tab-panel">
                    <div class="card table-card">
                        <div class="card-header-btn">
                            <h3>Network Devices</h3>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span id="devices-summary" class="text-muted" style="font-size:0.875rem;"></span>
                                <button id="refresh-devices-btn" class="btn btn-secondary btn-sm">Re-ping All</button>
                            </div>
                        </div>
                        <p class="desc-text">All devices registered in local DNS. Source is shown for each entry; ping status is checked live.</p>
                        <div class="table-wrapper">
                            <table id="devices-table">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">Status</th>
                                        <th>Hostname</th>
                                        <th>IP Address</th>
                                        <th>FQDN</th>
                                        <th>Source</th>
                                        <th>Info</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6" class="text-center">Loading devices...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Tab: Logs -->
                <section id="tab-logs" class="tab-panel">
                    <div class="card logs-card">
                        <div class="logs-header">
                            <div class="form-group inline-group">
                                <label for="logs-service-select">Select Service:</label>
                                <select id="logs-service-select">
                                    <option value="web">Web Admin Dashboard (lightbox-web)</option>
                                    <option value="bind9" selected>DNS Service (lightbox-bind9)</option>
                                    <option value="dhcp">DHCP Service (lightbox-dhcp)</option>
                                    <option value="ntp">NTP Service (lightbox-ntp)</option>
                                    <option value="samba">File Service (lightbox-samba)</option>
                                </select>
                            </div>
                            <div class="logs-actions">
                                <button id="copy-logs-btn" class="btn btn-outline btn-sm">Copy Logs</button>
                                <button id="refresh-logs-btn" class="btn btn-secondary btn-sm">Refresh</button>
                            </div>
                        </div>
                        <div class="console-wrapper">
                            <pre id="console-output">Fetching logs...</pre>
                        </div>
                    </div>
                </section>

                <!-- Tab: User Accounts -->
                <section id="tab-users" class="tab-panel">
                    <div class="card table-card">
                        <div class="card-header-btn">
                            <h3>Local User Accounts</h3>
                            <button id="add-user-btn" class="btn btn-secondary btn-sm">+ Add User</button>
                        </div>
                        <p class="desc-text">Linux accounts on the Samba container. Users with Samba enabled can authenticate to SMB file shares with a password.</p>
                        <div class="table-wrapper">
                            <table id="users-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Display Name</th>
                                        <th>Samba Access</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5" class="text-center">Loading user accounts...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <!-- DNS Record Modal -->
    <div id="dns-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 id="dns-modal-title">Add DNS Record</h3>
            <form id="dns-record-form">
                <input type="hidden" id="dns_id" name="id">
                <div class="form-group">
                    <label for="dns_hostname">Hostname</label>
                    <input type="text" id="dns_hostname" name="hostname" required placeholder="console">
                    <span class="help-text">Will resolve as <code>hostname.[your-domain]</code>.</span>
                </div>
                <div class="form-group">
                    <label for="dns_ip">IP Address</label>
                    <input type="text" id="dns_ip" name="ip_address" required placeholder="192.168.1.30">
                </div>
                <div class="form-group">
                    <label for="dns_type">IP Version Type</label>
                    <select id="dns_type" name="ip_type" required>
                        <option value="A">A (IPv4)</option>
                        <option value="AAAA">AAAA (IPv6)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="dns_desc">Description</label>
                    <input type="text" id="dns_desc" name="description" placeholder="Eos Ti console primary">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline close-modal-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DHCP Reservation Modal -->
    <div id="dhcp-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 id="dhcp-modal-title">Add Static Lease Reservation</h3>
            <form id="dhcp-reservation-form">
                <input type="hidden" id="dhcp_res_id" name="id">
                <div class="form-group">
                    <label for="dhcp_hostname">Device Hostname</label>
                    <input type="text" id="dhcp_hostname" name="hostname" required placeholder="eos-console">
                </div>
                <div class="form-group">
                    <label for="dhcp_mac">MAC Address</label>
                    <input type="text" id="dhcp_mac" name="mac_address" required placeholder="00:11:22:33:44:55">
                </div>
                <div class="form-group">
                    <label for="dhcp_ip">IP Address</label>
                    <input type="text" id="dhcp_ip" name="ip_address" required placeholder="192.168.1.50">
                </div>
                <div class="form-group">
                    <label for="dhcp_ip_type">IP Type</label>
                    <select id="dhcp_ip_type" name="ip_type" required>
                        <option value="IPv4">IPv4</option>
                        <option value="IPv6">IPv6</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline close-modal-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Lease</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Samba Share Modal -->
    <div id="samba-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 id="samba-modal-title">Add File Shared Folder</h3>
            <form id="samba-share-form">
                <input type="hidden" id="samba_id" name="id">
                <div class="form-group">
                    <label for="samba_name">Share Name</label>
                    <input type="text" id="samba_name" name="share_name" required placeholder="ShowFiles">
                </div>
                <div class="form-group">
                    <label for="samba_path">Folder Path (Inside Container)</label>
                    <input type="text" id="samba_path" name="share_path" required placeholder="/shares/ShowFiles">
                    <span class="help-text">Folder must be inside <code>/shares/</code> to map correctly to host disk.</span>
                </div>
                <div class="form-group-row">
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="samba_writable" name="writable" value="1" checked>
                        <label for="samba_writable">Allow Writing / Uploads</label>
                    </div>
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="samba_guest" name="guest_ok" value="1" checked>
                        <label for="samba_guest">Allow Guest (Anonymous) Access</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="samba_desc">Description</label>
                    <input type="text" id="samba_desc" name="description" placeholder="Main backups foldler">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline close-modal-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Share</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Network Interface Configure Modal -->
    <div id="network-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 id="network-modal-title">Configure Interface</h3>
            <form id="network-interface-form">
                <input type="hidden" id="net_iface" name="interface_name">

                <div class="form-group">
                    <label>Interface</label>
                    <input type="text" id="net_iface_display" readonly class="input-readonly">
                </div>

                <div class="form-group">
                    <label>IP Assignment Mode</label>
                    <div class="mode-toggle-group">
                        <label class="mode-option">
                            <input type="radio" name="mode" value="dhcp" id="mode_dhcp" checked>
                            <span class="mode-label">DHCP</span>
                        </label>
                        <label class="mode-option">
                            <input type="radio" name="mode" value="static" id="mode_static">
                            <span class="mode-label">Static</span>
                        </label>
                    </div>
                </div>

                <div id="net-static-fields">
                    <hr>
                    <div class="config-section">
                        <h4>IPv4 Configuration</h4>
                        <div class="form-group">
                            <label for="net_v4_address">IPv4 Address (CIDR)</label>
                            <input type="text" id="net_v4_address" name="v4_address" placeholder="192.168.1.10/24">
                            <span class="help-text">e.g. <code>192.168.1.10/24</code></span>
                        </div>
                        <div class="form-group">
                            <label for="net_v4_gateway">Default Gateway</label>
                            <input type="text" id="net_v4_gateway" name="v4_gateway" placeholder="192.168.1.1">
                            <span class="help-text">Leave blank to keep existing routes unchanged.</span>
                        </div>
                    </div>
                    <hr>
                    <div class="config-section">
                        <h4>IPv6 Configuration <span class="optional-label">(optional)</span></h4>
                        <div class="form-group">
                            <label for="net_v6_address">IPv6 Address (CIDR)</label>
                            <input type="text" id="net_v6_address" name="v6_address" placeholder="fd00::1/64">
                            <span class="help-text">e.g. <code>fd00::1/64</code></span>
                        </div>
                        <div class="form-group">
                            <label for="net_v6_gateway">IPv6 Default Gateway</label>
                            <input type="text" id="net_v6_gateway" name="v6_gateway" placeholder="fd00::fffe">
                            <span class="help-text">Leave blank to keep existing routes unchanged.</span>
                        </div>
                    </div>
                    <div class="info-alert" style="margin-top: 16px;">
                        <strong>Warning:</strong> Changing the IP of the interface you use to reach this dashboard will disrupt your connection momentarily.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline close-modal-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Configuration</button>
                </div>
            </form>
        </div>
    </div>

    <!-- User Add/Edit Modal -->
    <div id="user-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 id="user-modal-title">Add User Account</h3>
            <form id="user-form">
                <input type="hidden" id="user_id" name="id">
                <div class="form-group-row">
                    <div class="form-group">
                        <label for="user_username">Username</label>
                        <input type="text" id="user_username" name="username" required placeholder="johndoe"
                               pattern="[a-z][a-z0-9_\-]{0,31}" autocomplete="off">
                        <span class="help-text">Lowercase letters, digits, hyphens, underscores. Max 32 chars.</span>
                    </div>
                    <div class="form-group">
                        <label for="user_display_name">Display Name</label>
                        <input type="text" id="user_display_name" name="display_name" placeholder="John Doe">
                    </div>
                </div>
                <div class="form-group">
                    <label for="user_password">Password <span id="user-pwd-hint" class="optional-label">(leave blank to keep existing)</span></label>
                    <input type="password" id="user_password" name="password" autocomplete="new-password" placeholder="Set a password">
                    <span class="help-text">Used for Samba SMB authentication.</span>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="user_samba_enabled" name="samba_enabled" value="1" checked>
                        Enable Samba (SMB) access for this user
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline close-modal-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Container for slide-in notifications -->
    <div id="toast-container" class="toast-container"></div>

    <script src="/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/app.js') ?>"></script>
</body>
</html>
