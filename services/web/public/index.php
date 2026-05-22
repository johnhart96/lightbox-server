<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SystemManager.php';

use App\Database;
use App\SystemManager;

// Initialize Database to ensure SQLite is created and seeded
$db = Database::getInstance();
$settings = $db->getSettings();
$systemName = htmlspecialchars($settings['system_name'] ?? 'Lightbox-Server');
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
                    <span>Entertainment Network Services</span>
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
                        <a href="#logs" class="nav-link" data-tab="logs">
                            <span class="icon">📝</span> Service Logs
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

    <!-- Toast Container for slide-in notifications -->
    <div id="toast-container" class="toast-container"></div>

    <script src="/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/app.js') ?>"></script>
</body>
</html>
