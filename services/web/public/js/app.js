document.addEventListener('DOMContentLoaded', () => {
    // Tab Navigation
    const navLinks = document.querySelectorAll('.nav-link');
    const tabPanels = document.querySelectorAll('.tab-panel');
    const tabTitle = document.getElementById('current-tab-title');
    const tabDesc = document.getElementById('current-tab-desc');

    const tabMeta = {
        dashboard: { title: 'Dashboard', desc: 'System health overview and active leases' },
        dns: { title: 'DNS Configuration', desc: 'Manage local name resolution and upstream DNS servers' },
        dhcp: { title: 'DHCP Server', desc: 'Manage IP address assignments and static network reservations' },
        ntp: { title: 'NTP Synchronization', desc: 'Configure system time and network synchronization pools' },
        samba: { title: 'File Sharing', desc: 'Expose storage directories to network clients via SMB' },
        logs: { title: 'Service Logs', desc: 'Inspect live diagnostic logs for system containers' }
    };

    function switchTab(tabId) {
        navLinks.forEach(link => {
            if (link.dataset.tab === tabId) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });

        tabPanels.forEach(panel => {
            if (panel.id === `tab-${tabId}`) {
                panel.classList.add('active');
            } else {
                panel.classList.remove('active');
            }
        });

        tabTitle.textContent = tabMeta[tabId].title;
        tabDesc.textContent = tabMeta[tabId].desc;

        // Load tab-specific data
        if (tabId === 'dns') loadDNSData();
        if (tabId === 'dhcp') loadDHCPData();
        if (tabId === 'ntp') loadNTPData();
        if (tabId === 'samba') loadSambaData();
        if (tabId === 'logs') loadLogsData();
        if (tabId === 'dashboard') loadLeases();
    }

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const tabId = link.dataset.tab;
            window.location.hash = tabId;
            switchTab(tabId);
        });
    });

    // Check hash on load
    const initialTab = window.location.hash.substring(1);
    if (tabMeta[initialTab]) {
        switchTab(initialTab);
    } else {
        switchTab('dashboard');
    }

    // Modal Control
    const modals = document.querySelectorAll('.modal');
    const closeButtons = document.querySelectorAll('.close-modal, .close-modal-btn');

    function openModal(modalId) {
        document.getElementById(modalId).classList.add('show');
    }

    function closeModal() {
        modals.forEach(m => m.classList.remove('show'));
    }

    closeButtons.forEach(btn => {
        btn.addEventListener('click', closeModal);
    });

    window.addEventListener('click', (e) => {
        modals.forEach(modal => {
            if (e.target === modal) closeModal();
        });
    });

    // Toast Notifications
    const toastContainer = document.getElementById('toast-container');
    function showToast(message, type = 'success', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let icon = '✔';
        if (type === 'error') icon = '❌';
        if (type === 'warning') icon = '⚠';

        toast.innerHTML = `<span class="toast-icon">${icon}</span> <span class="toast-msg">${message}</span>`;
        toastContainer.appendChild(toast);

        // Slide in and fade out
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => {
                toast.remove();
            }, 350);
        }, duration);
    }

    // Apply Changes Button
    const applyBtn = document.getElementById('apply-btn');
    applyBtn.addEventListener('click', () => {
        applyBtn.disabled = true;
        applyBtn.textContent = 'Applying Configurations...';
        
        fetch('/api.php?action=apply_changes', { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    applyBtn.classList.add('hidden');
                    updateStatus(); // Refresh status card
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(err => {
                showToast('Failed to apply changes: Server error', 'error');
                console.error(err);
            })
            .finally(() => {
                applyBtn.disabled = false;
                applyBtn.innerHTML = '<span class="pulse-ring"></span>Apply Pending Changes';
            });
    });

    // Progress Ring helper
    function setProgress(circleId, percent) {
        const circle = document.getElementById(circleId);
        if (!circle) return;
        const radius = circle.r.baseVal.value;
        const circumference = radius * 2 * Math.PI;
        circle.style.strokeDasharray = `${circumference} ${circumference}`;
        const offset = circumference - (percent / 100) * circumference;
        circle.style.strokeDashoffset = offset;
    }

    // --- POLLING LOGIC FOR STATUS & HEALTH METRICS ---
    function updateStatus() {
        fetch('/api.php?action=status')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Update System Info
                    document.getElementById('sidebar-uptime').textContent = data.metrics.uptime;
                    const timePart = data.metrics.time ? data.metrics.time.split(' ')[1] : new Date().toLocaleTimeString();
                    document.getElementById('header-time').textContent = timePart; // Time part or local fallback
                    
                    // Update Resource rings
                    document.getElementById('cpu-percent').textContent = `${data.metrics.cpu}%`;
                    setProgress('cpu-ring', data.metrics.cpu);

                    document.getElementById('ram-percent').textContent = `${data.metrics.ram.percent}%`;
                    setProgress('ram-ring', data.metrics.ram.percent);
                    document.getElementById('ram-details').textContent = `${data.metrics.ram.used} GB / ${data.metrics.ram.total} GB`;

                    document.getElementById('disk-percent').textContent = `${data.metrics.disk.percent}%`;
                    setProgress('disk-ring', data.metrics.disk.percent);
                    document.getElementById('disk-details').textContent = `${data.metrics.disk.used} GB / ${data.metrics.disk.total} GB`;

                    document.getElementById('load-avg').textContent = data.metrics.load.join(', ');

                    // Update Services Badges
                    updateServiceBadge('status-bind9', data.services.bind9);
                    updateServiceBadge('status-dhcp', data.services.dhcp);
                    updateServiceBadge('status-ntp', data.services.ntp);
                    updateServiceBadge('status-samba', data.services.samba);

                    // Update counters
                    document.getElementById('stat-leases').textContent = data.stats.lease_count;
                    document.getElementById('stat-dns-records').textContent = data.stats.dns_count;
                    document.getElementById('stat-shares').textContent = data.stats.share_count;

                    // Update Apply Changes Button
                    if (data.pending_changes === 1) {
                        applyBtn.classList.remove('hidden');
                    } else {
                        applyBtn.classList.add('hidden');
                    }

                    // Populate network interface select if it's empty (just once)
                    const intSelect = document.getElementById('dhcp_interface');
                    if (intSelect && intSelect.options.length === 1 && data.interfaces) {
                        data.interfaces.forEach(i => {
                            const opt = document.createElement('option');
                            opt.value = i.name;
                            opt.textContent = `${i.name} (${i.ip || 'No IP'} - ${i.status.toUpperCase()})`;
                            intSelect.appendChild(opt);
                        });
                    }
                }
            })
            .catch(err => console.error('Error fetching system status:', err));
    }

    function updateServiceBadge(elementId, isRunning) {
        const badge = document.getElementById(elementId);
        if (!badge) return;
        
        badge.className = 'badge';
        if (isRunning) {
            badge.classList.add('active');
            badge.textContent = 'Active';
        } else {
            badge.classList.add('inactive');
            badge.textContent = 'Inactive';
        }
    }

    // Start Polling System Status
    updateStatus();
    setInterval(updateStatus, 5000);

    // Active Leases Loader (Dashboard)
    function loadLeases() {
        fetch('/api.php?action=leases')
            .then(res => res.json())
            .then(data => {
                const tbody = document.querySelector('#leases-table tbody');
                tbody.innerHTML = '';
                if (data.leases && data.leases.length > 0) {
                    data.leases.forEach(lease => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><code>${lease.ip}</code></td>
                            <td><code>${lease.mac}</code></td>
                            <td>${lease.hostname}</td>
                            <td>${lease.expiry}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No active DHCP leases found.</td></tr>';
                }
            });
    }
    loadLeases();

    // --- TAB: DNS DATA & ACTIONS ---
    const dnsForm = document.getElementById('dns-global-form');
    const dnsRecordForm = document.getElementById('dns-record-form');

    function loadDNSData() {
        fetch('/api.php?action=dns_get')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Populate Global Settings
                    document.getElementById('system_name').value = data.settings.system_name || '';
                    document.getElementById('domain_name').value = data.settings.domain_name || '';
                    document.getElementById('primary_dns').value = data.settings.primary_dns || '';
                    document.getElementById('secondary_dns').value = data.settings.secondary_dns || '';

                    // Populate Records Table
                    const tbody = document.querySelector('#dns-records-table tbody');
                    tbody.innerHTML = '';
                    if (data.records && data.records.length > 0) {
                        data.records.forEach(r => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><strong>${r.hostname}</strong>.${data.settings.domain_name}</td>
                                <td><code>${r.ip_address}</code></td>
                                <td><span class="badge ${r.ip_type === 'A' ? 'active' : 'pending'}">${r.ip_type}</span></td>
                                <td class="text-muted">${r.description || ''}</td>
                                <td>
                                    <button class="btn-edit-sm" data-id="${r.id}" data-host="${r.hostname}" data-ip="${r.ip_address}" data-type="${r.ip_type}" data-desc="${r.description || ''}">Edit</button>
                                    <button class="btn-danger-sm delete-dns-btn" data-id="${r.id}">Delete</button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });

                        // Attach record actions
                        tbody.querySelectorAll('.btn-edit-sm').forEach(btn => {
                            btn.addEventListener('click', () => {
                                document.getElementById('dns-modal-title').textContent = 'Edit DNS Record';
                                document.getElementById('dns_id').value = btn.dataset.id;
                                document.getElementById('dns_hostname').value = btn.dataset.host;
                                document.getElementById('dns_ip').value = btn.dataset.ip;
                                document.getElementById('dns_type').value = btn.dataset.type;
                                document.getElementById('dns_desc').value = btn.dataset.desc;
                                openModal('dns-modal');
                            });
                        });

                        tbody.querySelectorAll('.delete-dns-btn').forEach(btn => {
                            btn.addEventListener('click', () => {
                                if (confirm('Are you sure you want to delete this DNS record?')) {
                                    deleteDNSRecord(btn.dataset.id);
                                }
                            });
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No custom host records defined.</td></tr>';
                    }
                }
            });
    }

    dnsForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(dnsForm);
        fetch('/api.php?action=dns_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    });

    document.getElementById('add-dns-record-btn').addEventListener('click', () => {
        document.getElementById('dns-modal-title').textContent = 'Add DNS Record';
        dnsRecordForm.reset();
        document.getElementById('dns_id').value = '';
        openModal('dns-modal');
    });

    dnsRecordForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(dnsRecordForm);
        fetch('/api.php?action=dns_record_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    closeModal();
                    loadDNSData();
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    });

    function deleteDNSRecord(id) {
        const formData = new FormData();
        formData.append('id', id);
        fetch('/api.php?action=dns_record_delete', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    loadDNSData();
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    }

    // --- TAB: DHCP DATA & ACTIONS ---
    const dhcpForm = document.getElementById('dhcp-settings-form');
    const dhcpResForm = document.getElementById('dhcp-reservation-form');

    function loadDHCPData() {
        fetch('/api.php?action=dhcp_get')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Populate Global DHCP Settings
                    document.getElementById('dhcp_interface').value = data.settings.dhcp_interface || '';
                    
                    const settings = data.dhcp_settings;
                    
                    // IPv4 fields
                    const v4check = document.getElementById('v4_enabled');
                    v4check.checked = settings.v4_enabled === 1;
                    document.getElementById('v4_subnet').value = settings.v4_subnet;
                    document.getElementById('v4_netmask').value = settings.v4_netmask;
                    document.getElementById('v4_gateway').value = settings.v4_gateway;
                    document.getElementById('v4_range_start').value = settings.v4_range_start;
                    document.getElementById('v4_range_end').value = settings.v4_range_end;
                    document.getElementById('v4_lease_time').value = settings.v4_lease_time;

                    // IPv6 fields
                    const v6check = document.getElementById('v6_enabled');
                    v6check.checked = settings.v6_enabled === 1;
                    document.getElementById('v6_prefix').value = settings.v6_prefix;
                    document.getElementById('v6_range_start').value = settings.v6_range_start;
                    document.getElementById('v6_range_end').value = settings.v6_range_end;
                    document.getElementById('v6_lease_time').value = settings.v6_lease_time;

                    toggleDHCPFields();

                    // Static Reservations Table
                    const tbody = document.querySelector('#reservations-table tbody');
                    tbody.innerHTML = '';
                    if (data.reservations && data.reservations.length > 0) {
                        data.reservations.forEach(r => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><strong>${r.hostname}</strong></td>
                                <td><code>${r.mac_address}</code></td>
                                <td><code>${r.ip_address}</code></td>
                                <td><span class="badge ${r.ip_type === 'IPv4' ? 'active' : 'pending'}">${r.ip_type}</span></td>
                                <td>
                                    <button class="btn-edit-sm" data-id="${r.id}" data-host="${r.hostname}" data-mac="${r.mac_address}" data-ip="${r.ip_address}" data-type="${r.ip_type}">Edit</button>
                                    <button class="btn-danger-sm delete-dhcp-btn" data-id="${r.id}">Delete</button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });

                        tbody.querySelectorAll('.btn-edit-sm').forEach(btn => {
                            btn.addEventListener('click', () => {
                                document.getElementById('dhcp-modal-title').textContent = 'Edit Static Lease';
                                document.getElementById('dhcp_res_id').value = btn.dataset.id;
                                document.getElementById('dhcp_hostname').value = btn.dataset.host;
                                document.getElementById('dhcp_mac').value = btn.dataset.mac;
                                document.getElementById('dhcp_ip').value = btn.dataset.ip;
                                document.getElementById('dhcp_ip_type').value = btn.dataset.type;
                                openModal('dhcp-modal');
                            });
                        });

                        tbody.querySelectorAll('.delete-dhcp-btn').forEach(btn => {
                            btn.addEventListener('click', () => {
                                if (confirm('Are you sure you want to delete this static reservation?')) {
                                    deleteDHCPReservation(btn.dataset.id);
                                }
                            });
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No static address reservations mapped.</td></tr>';
                    }
                }
            });
    }

    function toggleDHCPFields() {
        const v4enabled = document.getElementById('v4_enabled').checked;
        const v4fields = document.getElementById('v4-settings-fields');
        v4fields.style.opacity = v4enabled ? '1' : '0.4';
        v4fields.querySelectorAll('input').forEach(i => i.disabled = !v4enabled);

        const v6enabled = document.getElementById('v6_enabled').checked;
        const v6fields = document.getElementById('v6-settings-fields');
        v6fields.style.opacity = v6enabled ? '1' : '0.4';
        v6fields.querySelectorAll('input').forEach(i => i.disabled = !v6enabled);
    }

    document.getElementById('v4_enabled').addEventListener('change', toggleDHCPFields);
    document.getElementById('v6_enabled').addEventListener('change', toggleDHCPFields);

    dhcpForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(dhcpForm);
        fetch('/api.php?action=dhcp_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    });

    document.getElementById('add-reservation-btn').addEventListener('click', () => {
        document.getElementById('dhcp-modal-title').textContent = 'Add Static Lease';
        dhcpResForm.reset();
        document.getElementById('dhcp_res_id').value = '';
        openModal('dhcp-modal');
    });

    dhcpResForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(dhcpResForm);
        fetch('/api.php?action=dhcp_reservation_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    closeModal();
                    loadDHCPData();
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    });

    function deleteDHCPReservation(id) {
        const formData = new FormData();
        formData.append('id', id);
        fetch('/api.php?action=dhcp_reservation_delete', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    loadDHCPData();
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    }

    // --- TAB: NTP DATA & ACTIONS ---
    const ntpForm = document.getElementById('ntp-settings-form');

    function loadNTPData() {
        fetch('/api.php?action=ntp_get')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('ntp_servers').value = data.settings.ntp_servers || '';
                }
            });
    }

    ntpForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(ntpForm);
        fetch('/api.php?action=ntp_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    });

    // --- TAB: SAMBA DATA & ACTIONS ---
    const sambaForm = document.getElementById('samba-share-form');

    function loadSambaData() {
        fetch('/api.php?action=samba_get')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const tbody = document.querySelector('#samba-shares-table tbody');
                    tbody.innerHTML = '';
                    if (data.shares && data.shares.length > 0) {
                        data.shares.forEach(s => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><strong>${s.share_name}</strong></td>
                                <td><code>${s.share_path}</code></td>
                                <td><span class="badge ${s.writable ? 'active' : 'inactive'}">${s.writable ? 'Yes' : 'No'}</span></td>
                                <td><span class="badge ${s.guest_ok ? 'active' : 'inactive'}">${s.guest_ok ? 'Public' : 'Private'}</span></td>
                                <td class="text-muted">${s.description || ''}</td>
                                <td>
                                    <button class="btn-edit-sm" data-id="${s.id}" data-name="${s.share_name}" data-path="${s.share_path}" data-write="${s.writable}" data-guest="${s.guest_ok}" data-desc="${s.description || ''}">Edit</button>
                                    <button class="btn-danger-sm delete-samba-btn" data-id="${s.id}" ${s.share_name === 'ShowFiles' ? 'disabled' : ''}>Delete</button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });

                        tbody.querySelectorAll('.btn-edit-sm').forEach(btn => {
                            btn.addEventListener('click', () => {
                                document.getElementById('samba-modal-title').textContent = 'Edit Samba Share';
                                document.getElementById('samba_id').value = btn.dataset.id;
                                document.getElementById('samba_name').value = btn.dataset.name;
                                document.getElementById('samba_path').value = btn.dataset.path;
                                document.getElementById('samba_writable').checked = btn.dataset.write === '1';
                                document.getElementById('samba_guest').checked = btn.dataset.guest === '1';
                                document.getElementById('samba_desc').value = btn.dataset.desc;
                                openModal('samba-modal');
                            });
                        });

                        tbody.querySelectorAll('.delete-samba-btn').forEach(btn => {
                            btn.addEventListener('click', () => {
                                if (confirm('Are you sure you want to delete this Samba share?')) {
                                    deleteSambaShare(btn.dataset.id);
                                }
                            });
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No folders shared via Samba.</td></tr>';
                    }
                }
            });
    }

    document.getElementById('add-share-btn').addEventListener('click', () => {
        document.getElementById('samba-modal-title').textContent = 'Add Samba Share';
        sambaForm.reset();
        document.getElementById('samba_id').value = '';
        openModal('samba-modal');
    });

    sambaForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(sambaForm);
        fetch('/api.php?action=samba_share_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    closeModal();
                    loadSambaData();
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    });

    function deleteSambaShare(id) {
        const formData = new FormData();
        formData.append('id', id);
        fetch('/api.php?action=samba_share_delete', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    loadSambaData();
                    applyBtn.classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            });
    }

    // --- TAB: DIAGNOSTICS & LOGS ---
    const logsSelect = document.getElementById('logs-service-select');
    const logsOutput = document.getElementById('console-output');

    function loadLogsData() {
        const service = logsSelect.value;
        logsOutput.textContent = `Fetching logs for ${service}...`;
        
        fetch(`/api.php?action=logs_get&service=${service}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    logsOutput.textContent = data.logs;
                    // Auto scroll to bottom
                    const consoleWrap = document.querySelector('.console-wrapper');
                    consoleWrap.scrollTop = consoleWrap.scrollHeight;
                } else {
                    logsOutput.textContent = `Error fetching logs: ${data.message}`;
                }
            })
            .catch(err => {
                logsOutput.textContent = 'Error: Failed to connect to backend server logs API.';
                console.error(err);
            });
    }

    logsSelect.addEventListener('change', loadLogsData);
    document.getElementById('refresh-logs-btn').addEventListener('click', loadLogsData);

    document.getElementById('copy-logs-btn').addEventListener('click', () => {
        navigator.clipboard.writeText(logsOutput.textContent)
            .then(() => showToast('Logs copied to clipboard.'))
            .catch(() => showToast('Failed to copy logs.', 'error'));
    });
});
