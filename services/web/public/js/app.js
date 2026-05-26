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
        pki: { title: 'Local PKI', desc: 'Integrated Certificate Authority — generate and distribute trusted local TLS certificates' },
        ntp: { title: 'NTP Synchronization', desc: 'Configure system time and network synchronization pools' },
        syslog: { title: 'Syslog Receiver', desc: 'Centralised syslog collection from network devices via UDP/TCP 514' },
        samba: { title: 'File Sharing', desc: 'Expose storage directories to network clients via SMB' },
        network: { title: 'Network Interfaces', desc: 'Assign IPv4 and IPv6 addresses to host network interfaces' },
        devices: { title: 'Network Devices', desc: 'All DNS-registered devices with live reachability status' },
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
        if (tabId === 'pki') loadPKIData();
        if (tabId === 'ntp') loadNTPData();
        if (tabId === 'syslog') loadSyslogData();
        if (tabId === 'samba') loadSambaData();
        if (tabId === 'logs') loadLogsData();
        if (tabId === 'network') loadNetworkData();
        if (tabId === 'devices') loadDevices();
        if (tabId === 'dashboard') loadLeases();
        if (tabId === 'users') loadUsersData();
    }

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const tabId = link.dataset.tab;
            window.location.hash = tabId;
            switchTab(tabId);
        });
    });

    // Allow any element with data-tab to act as a tab link (e.g. dashboard alerts)
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-tab]:not(.nav-link)');
        if (!el) return;
        e.preventDefault();
        const tabId = el.dataset.tab;
        if (tabMeta[tabId]) {
            window.location.hash = tabId;
            switchTab(tabId);
        }
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

    // Logout
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', () => {
            fetch('/api.php?action=logout', { method: 'POST' })
                .finally(() => { window.location.href = '/login.php'; });
        });
    }

    // Global 401 handler — redirect to login if session expires
    const _origFetch = window.fetch;
    window.fetch = function(...args) {
        return _origFetch(...args).then(res => {
            if (res.status === 401) {
                window.location.href = '/login.php';
            }
            return res;
        });
    };

    // Apply Changes Button
    const applyBtn = document.getElementById('apply-btn');
    applyBtn.addEventListener('click', () => {
        applyBtn.disabled = true;
        applyBtn.textContent = 'Applying Configurations...';
        
        fetch('/api.php?action=apply_changes', { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                applyBtn.classList.add('hidden');
                updateStatus();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                } else if (data.status === 'warning') {
                    showToast(data.message, 'error');
                } else {
                    showToast(data.message || 'Apply failed.', 'error');
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
        // Fire metrics and service status fetches in parallel so slow docker calls
        // don't block uptime/CPU/RAM/disk from rendering.
        fetch('/api.php?action=metrics')
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') return;

                document.getElementById('sidebar-uptime').textContent = data.metrics.uptime;
                const timePart = data.metrics.time ? data.metrics.time.split(' ')[1] : new Date().toLocaleTimeString();
                document.getElementById('header-time').textContent = timePart;

                document.getElementById('cpu-percent').textContent = `${data.metrics.cpu}%`;
                setProgress('cpu-ring', data.metrics.cpu);

                document.getElementById('ram-percent').textContent = `${data.metrics.ram.percent}%`;
                setProgress('ram-ring', data.metrics.ram.percent);
                document.getElementById('ram-details').textContent = `${data.metrics.ram.used} GB / ${data.metrics.ram.total} GB`;

                document.getElementById('disk-percent').textContent = `${data.metrics.disk.percent}%`;
                setProgress('disk-ring', data.metrics.disk.percent);
                document.getElementById('disk-details').textContent = `${data.metrics.disk.used} GB / ${data.metrics.disk.total} GB`;

                document.getElementById('load-avg').textContent = data.metrics.load.join(', ');

                document.getElementById('stat-leases').textContent = data.stats.lease_count;
                document.getElementById('stat-dns-records').textContent = data.stats.dns_count;
                document.getElementById('stat-shares').textContent = data.stats.share_count;

                if (data.pending_changes === 1) {
                    applyBtn.classList.remove('hidden');
                } else {
                    applyBtn.classList.add('hidden');
                }

                const intSelect = document.getElementById('dhcp_interface');
                if (intSelect && intSelect.options.length === 1 && data.interfaces) {
                    data.interfaces.forEach(i => {
                        const opt = document.createElement('option');
                        opt.value = i.name;
                        opt.textContent = `${i.name} (${i.ip || 'No IP'} - ${i.status.toUpperCase()})`;
                        intSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => console.error('Error fetching metrics:', err));

        fetch('/api.php?action=services')
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') return;
                const map = { bind9: data.services.bind9, dhcp: data.services.dhcp, ntp: data.services.ntp, samba: data.services.samba, acn: data.services.acn, syslog: data.services.syslog };
                for (const [svc, running] of Object.entries(map)) {
                    updateServiceBadge('status-' + svc, running);
                    syncServiceToggle(svc, running);
                }
            })
            .catch(err => console.error('Error fetching service status:', err));
    }

    function updateServiceBadge(elementId, isRunning) {
        const badge = document.getElementById(elementId);
        if (!badge) return;
        badge.className = 'badge';
        badge.classList.add(isRunning ? 'active' : 'inactive');
        badge.textContent = isRunning ? 'Active' : 'Inactive';
    }

    function syncServiceToggle(svc, isRunning) {
        const toggle = document.getElementById('toggle-' + svc);
        if (!toggle || toggle._busy) return;
        toggle.checked = isRunning;
        toggle.disabled = false;
    }

    function toggleService(svc, enable) {
        const toggle = document.getElementById('toggle-' + svc);
        if (!toggle) return;

        const warn = toggle.dataset.warn;
        if (!enable && warn && !confirm(warn + '\n\nContinue?')) {
            toggle.checked = true;
            return;
        }

        toggle._busy = true;
        toggle.disabled = true;

        const body = new URLSearchParams({ service: svc, state: enable ? 'start' : 'stop' });
        fetch('/api.php?action=service_toggle', { method: 'POST', body })
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') {
                    toggle.checked = !enable;
                    alert(data.message || 'Failed to toggle service.');
                }
            })
            .catch(() => { toggle.checked = !enable; })
            .finally(() => {
                toggle._busy = false;
                toggle.disabled = false;
                updateStatus();
            });
    }

    document.querySelectorAll('[id^="toggle-"]').forEach(input => {
        input.addEventListener('change', () => {
            const svc = input.dataset.service;
            if (svc) toggleService(svc, input.checked);
        });
    });

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

    // --- ALERTS (Dashboard) ---
    const alertsCard   = document.getElementById('alerts-card');
    const alertsList   = document.getElementById('alerts-list');
    const alertsBadge  = document.getElementById('alerts-count-badge');
    const alertsIcon   = document.getElementById('alerts-icon');

    function formatAlertDate(dtStr) {
        if (!dtStr) return '';
        const d = new Date(dtStr.replace(' ', 'T') + 'Z');
        return isNaN(d) ? dtStr : d.toLocaleString();
    }

    function loadAlertsData() {
        fetch('/api.php?action=alerts_get')
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success' || !alertsList) return;
                const alerts = data.alerts || [];

                // Update badge and card visibility
                const unacked = alerts.filter(a => !a.acknowledged_at);
                const hasErrors = unacked.some(a => a.type === 'error');

                if (alerts.length === 0) {
                    if (alertsCard) alertsCard.style.display = 'none';
                    return;
                }

                if (alertsCard) alertsCard.style.display = '';
                if (alertsBadge) {
                    alertsBadge.textContent = unacked.length > 0 ? `${unacked.length} active` : 'all acknowledged';
                    alertsBadge.classList.toggle('has-errors', hasErrors);
                }
                if (alertsIcon) alertsIcon.textContent = hasErrors ? '🔴' : '⚠';

                alertsList.innerHTML = '';
                alerts.forEach(a => {
                    const isAcked = !!a.acknowledged_at;
                    const li = document.createElement('li');
                    li.className = `alert-item ${a.type}${isAcked ? ' acknowledged' : ''}`;
                    li.dataset.id = a.id;

                    const ackHtml = isAcked
                        ? `<div class="alert-ack-info">✔ Acknowledged by <strong>${escHtml(a.acknowledged_by || 'unknown')}</strong> at ${formatAlertDate(a.acknowledged_at)}</div>`
                        : '';

                    const actionsHtml = isAcked
                        ? `<button class="btn-danger-sm alert-clear-btn" data-id="${a.id}">Clear</button>`
                        : `<button class="btn-edit-sm alert-ack-btn" data-id="${a.id}">Acknowledge</button>
                           <button class="btn-danger-sm alert-clear-btn" data-id="${a.id}">Clear</button>`;

                    li.innerHTML = `
                        <div class="alert-item-body">
                            <div class="alert-item-top">
                                <span class="alert-type-badge ${a.type}">${a.type}</span>
                                <span class="alert-source">${escHtml(a.source)}</span>
                                <span class="alert-time">${formatAlertDate(a.created_at)}</span>
                            </div>
                            <div class="alert-message">${escHtml(a.message)}</div>
                            ${ackHtml}
                        </div>
                        <div class="alert-actions">${actionsHtml}</div>
                    `;
                    alertsList.appendChild(li);
                });

                alertsList.querySelectorAll('.alert-ack-btn').forEach(btn => {
                    btn.addEventListener('click', () => acknowledgeAlert(btn.dataset.id));
                });
                alertsList.querySelectorAll('.alert-clear-btn').forEach(btn => {
                    btn.addEventListener('click', () => clearAlert(btn.dataset.id));
                });
            })
            .catch(err => console.error('Error fetching alerts:', err));
    }

    function acknowledgeAlert(id) {
        const fd = new FormData();
        fd.append('id', id);
        fetch('/api.php?action=alert_acknowledge', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') loadAlertsData();
                else showToast(data.message, 'error');
            });
    }

    function clearAlert(id) {
        const fd = new FormData();
        fd.append('id', id);
        fetch('/api.php?action=alert_clear', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') loadAlertsData();
                else showToast(data.message, 'error');
            });
    }

    const clearAckedBtn = document.getElementById('clear-acked-alerts-btn');
    if (clearAckedBtn) {
        clearAckedBtn.addEventListener('click', () => {
            fetch('/api.php?action=alerts_clear_acknowledged', { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') { showToast(data.message); loadAlertsData(); }
                    else showToast(data.message, 'error');
                });
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    loadAlertsData();
    setInterval(loadAlertsData, 15000);

    // --- TAB: DNS DATA & ACTIONS ---
    const dnsForm = document.getElementById('dns-global-form');
    const dnsRecordForm = document.getElementById('dns-record-form');

    function expandIPv6(ip) {
        const halves = ip.split('::');
        const left  = halves[0] ? halves[0].split(':') : [];
        const right = halves.length > 1 && halves[1] ? halves[1].split(':') : [];
        const full  = [...left, ...Array(8 - left.length - right.length).fill('0'), ...right];
        return full.map(g => g.padStart(4, '0')).join('');
    }

    function reverseInfo(ip, type) {
        if (type === 'A') {
            const p = ip.split('.');
            return { zone: `${p[2]}.${p[1]}.${p[0]}.in-addr.arpa`, ptr: p[3] };
        }
        const hex  = expandIPv6(ip);
        const zone = hex.slice(0, 16).split('').reverse().join('.') + '.ip6.arpa';
        const ptr  = hex.slice(16).split('').reverse().join('.');
        return { zone, ptr };
    }

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

                    // Populate DNS interface dropdown with live host interfaces
                    const dnsIfaceSelect = document.getElementById('dns_interface');
                    dnsIfaceSelect.innerHTML = '<option value="">Auto-detect (first available host IP)</option>';
                    (data.interfaces || []).forEach(iface => {
                        const addrs = [...iface.v4_addresses, ...iface.v6_addresses];
                        const ipLabel = addrs.length ? addrs.join(', ') : 'No IP assigned';
                        const opt = document.createElement('option');
                        opt.value = iface.name;
                        opt.textContent = `${iface.name} — ${ipLabel}`;
                        if (data.settings.dns_interface === iface.name) opt.selected = true;
                        dnsIfaceSelect.appendChild(opt);
                    });

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
                    // Populate DNS Zone Entries table (forward + reverse combined)
                    const allEntries = [
                        ...(data.records || []).map(r => ({
                            hostname: r.hostname, ip: r.ip_address, type: r.ip_type, source: 'custom'
                        })),
                        ...(data.dhcp_dns_entries || []).map(e => ({
                            hostname: e.hostname, ip: e.ip, type: e.type, source: e.source
                        }))
                    ];
                    const zoneTbody = document.querySelector('#dns-zone-table tbody');
                    if (allEntries.length > 0) {
                        const sourceBadge = {
                            custom:      '<span class="badge active">Custom</span>',
                            reservation: '<span class="badge active">Reservation</span>',
                            dynamic:     '<span class="badge pending">Dynamic</span>',
                        };
                        zoneTbody.innerHTML = allEntries.map(e => {
                            const rev = reverseInfo(e.ip, e.type);
                            const badge = sourceBadge[e.source] || `<span class="badge">${e.source}</span>`;
                            return `<tr>
                                <td><strong>${e.hostname}</strong>.${data.settings.domain_name}</td>
                                <td><code>${e.ip}</code></td>
                                <td><span class="badge ${e.type === 'A' ? 'active' : 'pending'}">${e.type}</span></td>
                                <td>${badge}</td>
                                <td class="text-muted"><code>${rev.zone}</code></td>
                                <td><code>${rev.ptr}</code></td>
                            </tr>`;
                        }).join('');
                    } else {
                        zoneTbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No DNS entries found.</td></tr>';
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

    // --- TAB: PKI ---
    function loadPKIData() {
        fetch('/api.php?action=pki_get')
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') return;
                renderCACard(data.pki.ca);
                renderWildcardCard(data.pki.wildcard);
            });
    }

    function renderCACard(ca) {
        const body = document.getElementById('pki-ca-body');
        const newBtn = document.getElementById('pki-new-ca-btn');
        if (!ca) {
            newBtn.style.display = 'none';
            body.innerHTML = `
                <p class="text-muted" style="margin-bottom:20px">No Certificate Authority has been generated yet.</p>
                <button id="pki-generate-btn" class="btn btn-primary" style="width:100%">Generate CA &amp; Wildcard Certificate</button>`;
            document.getElementById('pki-generate-btn').addEventListener('click', () => pkiRegenerate('all'));
            return;
        }
        newBtn.style.display = '';
        const statusBadge = ca.expired
            ? '<span class="badge inactive">Expired</span>'
            : '<span class="badge active">Active</span>';
        body.innerHTML = `
            <div class="pki-info">
                <span class="pki-label">Status</span>      <span>${statusBadge}</span>
                <span class="pki-label">Subject</span>     <span>${ca.subject}</span>
                <span class="pki-label">Valid From</span>  <span>${ca.valid_from}</span>
                <span class="pki-label">Valid Until</span> <span>${ca.valid_to}</span>
            </div>
            <p class="desc-text">Import this CA into your OS or browser once to trust all local certificates.</p>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px">
                <a class="btn btn-secondary" href="/api.php?action=pki_download&file=ca_cert">&#8595; Download CA Certificate (.crt)</a>
            </div>`;
    }

    function renderWildcardCard(wc) {
        const body  = document.getElementById('pki-wildcard-body');
        const regen = document.getElementById('pki-regen-btn');
        if (!wc) {
            regen.style.display = 'none';
            body.innerHTML = `<p class="text-muted text-center" style="padding:20px 0">Generate a CA first to create a wildcard certificate.</p>`;
            return;
        }
        regen.style.display = '';
        const expired  = wc.expired;
        const statusBadge = expired
            ? '<span class="badge inactive">Expired</span>'
            : '<span class="badge active">Valid</span>';
        const sans = (wc.sans || []).map(s => `<code>${s}</code>`).join(' ');
        body.innerHTML = `
            <div class="pki-info">
                <span class="pki-label">Status</span>      <span>${statusBadge}</span>
                <span class="pki-label">Domain</span>      <span><code>*.${wc.domain || wc.subject}</code></span>
                <span class="pki-label">SANs</span>        <span>${sans}</span>
                <span class="pki-label">Valid From</span>  <span>${wc.valid_from}</span>
                <span class="pki-label">Valid Until</span> <span>${wc.valid_to}</span>
                <span class="pki-label">Issued By</span>   <span>${wc.issuer}</span>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px">
                <a class="btn btn-secondary" href="/api.php?action=pki_download&file=wildcard_cert">&#8595; Certificate (.crt)</a>
                <a class="btn btn-secondary" href="/api.php?action=pki_download&file=wildcard_key">&#8595; Private Key (.key)</a>
            </div>`;
    }

    function pkiRegenerate(scope) {
        const regenBtn   = document.getElementById('pki-regen-btn');
        const generateBtn = document.getElementById('pki-generate-btn');
        const btn = regenBtn || generateBtn;
        if (btn) { btn.disabled = true; btn.textContent = 'Generating…'; }

        const fd = new FormData();
        fd.append('scope', scope);
        fetch('/api.php?action=pki_regenerate', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    renderCACard(data.pki.ca);
                    renderWildcardCard(data.pki.wildcard);
                } else {
                    showToast(data.message, 'error');
                    if (btn) { btn.disabled = false; btn.textContent = scope === 'all' ? 'Generate CA & Wildcard Certificate' : '↻ Regenerate'; }
                }
            });
    }

    document.getElementById('pki-regen-btn').addEventListener('click', () => pkiRegenerate('wildcard'));
    document.getElementById('pki-new-ca-btn').addEventListener('click', () => {
        if (confirm('Generate a new CA? Any devices that imported the old CA will need to re-import it.')) {
            pkiRegenerate('all');
        }
    });

    // --- TAB: DHCP DATA & ACTIONS ---
    const dhcpForm = document.getElementById('dhcp-settings-form');
    const dhcpResForm = document.getElementById('dhcp-reservation-form');

    function loadDHCPData() {
        fetch('/api.php?action=dhcp_get')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Populate DHCP interface dropdown with live host interfaces
                    const dhcpIfaceSelect = document.getElementById('dhcp_interface');
                    dhcpIfaceSelect.innerHTML = '<option value="">Listen on all interfaces (Recommended)</option>';
                    (data.interfaces || []).forEach(iface => {
                        const addrs = [...iface.v4_addresses, ...iface.v6_addresses];
                        const ipLabel = addrs.length ? addrs.join(', ') : 'No IP assigned';
                        const opt = document.createElement('option');
                        opt.value = iface.name;
                        opt.textContent = `${iface.name} — ${ipLabel}`;
                        if (data.settings.dhcp_interface === iface.name) opt.selected = true;
                        dhcpIfaceSelect.appendChild(opt);
                    });
                    
                    // Advertisement checkboxes (from global settings, not dhcp_settings)
                    document.getElementById('advertise_dns').checked    = (data.settings.advertise_dns    ?? '1') !== '0';
                    document.getElementById('advertise_ntp').checked    = data.settings.advertise_ntp    === '1';
                    document.getElementById('advertise_syslog').checked = data.settings.advertise_syslog === '1';

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
                                <td><span class="badge ${s.is_tftp == 1 ? 'active' : 'inactive'}">${s.is_tftp == 1 ? 'Yes' : 'No'}</span></td>
                                <td class="text-muted">${s.description || ''}</td>
                                <td>
                                    <button class="btn-edit-sm" data-id="${s.id}" data-name="${s.share_name}" data-write="${s.writable}" data-guest="${s.guest_ok}" data-tftp="${s.is_tftp}" data-desc="${s.description || ''}">Edit</button>
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
                                document.getElementById('samba_writable').checked = btn.dataset.write === '1';
                                document.getElementById('samba_guest').checked = btn.dataset.guest === '1';
                                document.getElementById('samba_tftp').checked = btn.dataset.tftp === '1';
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
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No folders shared via Samba.</td></tr>';
                    }
                }
            });
    }

    document.getElementById('add-share-btn').addEventListener('click', () => {
        document.getElementById('samba-modal-title').textContent = 'Add File Share';
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

    // --- TAB: NETWORK INTERFACES ---
    const networkForm = document.getElementById('network-interface-form');
    const staticFields = document.getElementById('net-static-fields');

    function toggleNetworkStaticFields() {
        const isStatic = document.getElementById('mode_static').checked;
        staticFields.style.display = isStatic ? 'block' : 'none';
    }

    document.getElementById('mode_dhcp').addEventListener('change', toggleNetworkStaticFields);
    document.getElementById('mode_static').addEventListener('change', toggleNetworkStaticFields);
    toggleNetworkStaticFields();

    function loadNetworkData() {
        fetch('/api.php?action=network_get')
            .then(res => res.json())
            .then(data => {
                const grid = document.getElementById('interfaces-grid');
                if (data.status !== 'success') {
                    grid.innerHTML = '<p class="text-muted text-center">Failed to load network interfaces.</p>';
                    return;
                }
                if (!data.interfaces || data.interfaces.length === 0) {
                    grid.innerHTML = '<p class="text-muted text-center">No network interfaces detected. Ensure the DHCP container is running.</p>';
                    return;
                }

                grid.innerHTML = '';
                const configs = data.configs || {};

                data.interfaces.forEach(iface => {
                    const cfg         = configs[iface.name] || { mode: 'dhcp' };
                    const isStatic    = cfg.mode === 'static';
                    const statusClass = iface.status === 'up' ? 'active' : 'inactive';
                    const modeClass   = isStatic ? 'pending' : 'active';
                    const modeLabel   = isStatic ? 'Static' : 'DHCP';

                    // Address rows: show configured static addresses, then any live addresses
                    let addrHtml = '';
                    if (isStatic) {
                        if (cfg.v4_address) {
                            addrHtml += `<div class="addr-row">
                                <code class="addr-cidr">${cfg.v4_address}</code>
                                <span class="badge active">IPv4</span>
                                ${cfg.v4_gateway ? `<span class="addr-gw-label">gw ${cfg.v4_gateway}</span>` : ''}
                            </div>`;
                        }
                        if (cfg.v6_address) {
                            addrHtml += `<div class="addr-row">
                                <code class="addr-cidr">${cfg.v6_address}</code>
                                <span class="badge pending">IPv6</span>
                                ${cfg.v6_gateway ? `<span class="addr-gw-label">gw ${cfg.v6_gateway}</span>` : ''}
                            </div>`;
                        }
                        if (!cfg.v4_address && !cfg.v6_address) {
                            addrHtml = '<p class="text-muted no-addr-msg">Static mode — no address configured yet.</p>';
                        }
                    } else {
                        // DHCP: show whatever the system currently has
                        [...iface.v4_addresses, ...iface.v6_addresses].forEach(addr => {
                            const isV6 = addr.includes(':');
                            addrHtml += `<div class="addr-row addr-unmanaged">
                                <code class="addr-cidr">${addr}</code>
                                <span class="badge ${isV6 ? 'pending' : 'active'}">${isV6 ? 'IPv6' : 'IPv4'}</span>
                                <span class="addr-unmanaged-label">DHCP assigned</span>
                            </div>`;
                        });
                        if (!iface.v4_addresses.length && !iface.v6_addresses.length) {
                            addrHtml = '<p class="text-muted no-addr-msg">No address assigned by DHCP yet.</p>';
                        }
                    }

                    const card = document.createElement('div');
                    card.className = 'card interface-card';
                    card.innerHTML = `
                        <div class="interface-header">
                            <span class="status-pulse grey net-conn-dot" data-iface="${iface.name}" title="Checking internet connectivity..."></span>
                            <span class="interface-name">${iface.name}</span>
                            <span class="badge ${statusClass}">${iface.status.toUpperCase()}</span>
                            <span class="badge ${modeClass} mode-badge">${modeLabel}</span>
                            ${iface.mac ? `<code class="mac-addr">${iface.mac}</code>` : ''}
                            <button class="btn btn-secondary btn-sm configure-iface-btn"
                                    data-iface="${iface.name}"
                                    data-mode="${cfg.mode || 'dhcp'}"
                                    data-v4="${cfg.v4_address || ''}"
                                    data-v4gw="${cfg.v4_gateway || ''}"
                                    data-v6="${cfg.v6_address || ''}"
                                    data-v6gw="${cfg.v6_gateway || ''}">Configure</button>
                        </div>
                        <div class="addresses-list">${addrHtml}</div>`;

                    grid.appendChild(card);
                });

                grid.querySelectorAll('.configure-iface-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.getElementById('network-modal-title').textContent = `Configure ${btn.dataset.iface}`;
                        document.getElementById('net_iface').value         = btn.dataset.iface;
                        document.getElementById('net_iface_display').value = btn.dataset.iface;
                        document.getElementById('net_v4_address').value    = btn.dataset.v4;
                        document.getElementById('net_v4_gateway').value    = btn.dataset.v4gw;
                        document.getElementById('net_v6_address').value    = btn.dataset.v6;
                        document.getElementById('net_v6_gateway').value    = btn.dataset.v6gw;

                        const isStatic = btn.dataset.mode === 'static';
                        document.getElementById('mode_static').checked = isStatic;
                        document.getElementById('mode_dhcp').checked   = !isStatic;
                        toggleNetworkStaticFields();

                        openModal('network-modal');
                    });
                });

                // Fetch connectivity asynchronously — pings take a moment
                fetch('/api.php?action=network_connectivity')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status !== 'success') return;
                        grid.querySelectorAll('.net-conn-dot').forEach(dot => {
                            const connected = data.connectivity[dot.dataset.iface];
                            dot.classList.remove('grey');
                            if (connected) {
                                dot.classList.add('green');
                                dot.title = 'Internet: Connected';
                            } else {
                                dot.classList.add('grey');
                                dot.title = 'Internet: No connection';
                            }
                        });
                    })
                    .catch(() => {
                        grid.querySelectorAll('.net-conn-dot').forEach(dot => {
                            dot.title = 'Connectivity check failed';
                        });
                    });
            })
            .catch(err => {
                document.getElementById('interfaces-grid').innerHTML =
                    '<p class="text-muted text-center">Error connecting to API.</p>';
                console.error(err);
            });
    }

    // --- TAB: DEVICES ---
    function loadDevices() {
        const tbody   = document.querySelector('#devices-table tbody');
        const summary = document.getElementById('devices-summary');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading devices...</td></tr>';
        summary.textContent = '';

        fetch('/api.php?action=devices_get')
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Failed to load devices.</td></tr>';
                    return;
                }
                const devices = data.devices || [];
                tbody.innerHTML = '';
                if (devices.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No devices found in DNS.</td></tr>';
                    return;
                }

                const count = devices.length;
                summary.textContent = `${count} device${count !== 1 ? 's' : ''} — pinging…`;

                const sourceBadge = {
                    custom:      '<span class="badge active">Custom</span>',
                    reservation: '<span class="badge active">Reservation</span>',
                    dynamic:     '<span class="badge pending">Dynamic</span>',
                    acn:         '<span class="badge acn">ACN</span>',
                };

                devices.forEach((device, idx) => {
                    const primary = sourceBadge[device.source] || `<span class="badge">${device.source}</span>`;
                    const acnTag  = (device.acn && device.source !== 'acn') ? ' <span class="badge acn">ACN</span>' : '';
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <div class="ping-status">
                                <span class="status-pulse ping-checking" id="ping-dot-${idx}"></span>
                                <span class="ping-text" id="ping-text-${idx}">Checking</span>
                            </div>
                        </td>
                        <td><strong>${device.hostname}</strong></td>
                        <td><code>${device.ip}</code></td>
                        <td class="text-muted fqdn-cell">${device.hostname}.${data.domain}</td>
                        <td>${primary}${acnTag}</td>
                        <td class="text-muted">${device.info || ''}</td>
                    `;
                    tbody.appendChild(tr);
                });

                let online = 0, done = 0;
                devices.forEach((device, idx) => {
                    fetch(`/api.php?action=ping&ip=${encodeURIComponent(device.ip)}`)
                        .then(res => res.json())
                        .then(result => {
                            const dot  = document.getElementById(`ping-dot-${idx}`);
                            const text = document.getElementById(`ping-text-${idx}`);
                            if (!dot) return;
                            dot.classList.remove('ping-checking');
                            if (result.online) {
                                dot.classList.add('green');
                                text.textContent = 'Online';
                                text.style.color = 'var(--color-green)';
                                online++;
                            } else {
                                dot.classList.add('ping-offline');
                                text.textContent = 'Offline';
                                text.style.color = 'var(--color-red)';
                            }
                        })
                        .catch(() => {
                            const dot  = document.getElementById(`ping-dot-${idx}`);
                            const text = document.getElementById(`ping-text-${idx}`);
                            if (!dot) return;
                            dot.classList.remove('ping-checking');
                            dot.classList.add('ping-offline');
                            text.textContent = 'Error';
                        })
                        .finally(() => {
                            done++;
                            if (done === count) {
                                summary.textContent = `${count} device${count !== 1 ? 's' : ''} — ${online} online, ${count - online} offline`;
                            }
                        });
                });
            })
            .catch(() => {
                document.querySelector('#devices-table tbody').innerHTML =
                    '<tr><td colspan="6" class="text-center text-muted">Error connecting to API.</td></tr>';
            });
    }

    document.getElementById('refresh-devices-btn').addEventListener('click', loadDevices);

    networkForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(networkForm);
        fetch('/api.php?action=network_interface_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    closeModal();
                    loadNetworkData();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => showToast('Failed to apply configuration: server error.', 'error'));
    });

    // --- TAB: SYSLOG ---
    const syslogOutput = document.getElementById('syslog-output');
    const syslogFilter = document.getElementById('syslog-filter-input');
    const syslogLines  = document.getElementById('syslog-lines-select');
    const syslogTotal  = document.getElementById('syslog-total-label');

    let _syslogEntries = [];

    function renderSyslogEntries() {
        if (!syslogOutput) return;
        const filter = syslogFilter ? syslogFilter.value.trim().toLowerCase() : '';
        const visible = filter
            ? _syslogEntries.filter(l => l.toLowerCase().includes(filter))
            : _syslogEntries;
        syslogOutput.textContent = visible.length > 0
            ? visible.join('\n')
            : '(no messages match filter)';
        const wrapper = syslogOutput.closest('.console-wrapper');
        if (wrapper) wrapper.scrollTop = wrapper.scrollHeight;
    }

    function loadSyslogData() {
        if (!syslogOutput) return;
        const lines = syslogLines ? syslogLines.value : 200;
        syslogOutput.textContent = 'Fetching syslog messages...';
        fetch(`/api.php?action=syslog_get&lines=${lines}`)
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') {
                    syslogOutput.textContent = `Error: ${data.message}`;
                    return;
                }
                _syslogEntries = data.entries || [];
                if (syslogTotal) {
                    syslogTotal.textContent = `(${data.total.toLocaleString()} total lines on disk)`;
                }
                if (_syslogEntries.length === 0) {
                    syslogOutput.textContent = '(no syslog messages received yet)';
                    return;
                }
                renderSyslogEntries();
            })
            .catch(() => { syslogOutput.textContent = 'Error: Failed to fetch syslog data.'; });
    }

    if (syslogFilter) syslogFilter.addEventListener('input', renderSyslogEntries);
    if (syslogLines)  syslogLines.addEventListener('change', loadSyslogData);

    const refreshSyslogBtn = document.getElementById('refresh-syslog-btn');
    if (refreshSyslogBtn) refreshSyslogBtn.addEventListener('click', loadSyslogData);

    const copySyslogBtn = document.getElementById('copy-syslog-btn');
    if (copySyslogBtn) {
        copySyslogBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(syslogOutput.textContent)
                .then(() => showToast('Syslog copied to clipboard.'))
                .catch(() => showToast('Failed to copy.', 'error'));
        });
    }

    const clearSyslogBtn = document.getElementById('clear-syslog-btn');
    if (clearSyslogBtn) {
        clearSyslogBtn.addEventListener('click', () => {
            if (!confirm('Clear all syslog messages? This cannot be undone.')) return;
            fetch('/api.php?action=syslog_clear', { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        _syslogEntries = [];
                        syslogOutput.textContent = '(log cleared)';
                        if (syslogTotal) syslogTotal.textContent = '(0 total lines on disk)';
                        showToast(data.message);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => showToast('Failed to clear syslog.', 'error'));
        });
    }

    // --- TAB: USER ACCOUNTS ---
    const userForm = document.getElementById('user-form');

    // Wire tab meta
    tabMeta['users'] = { title: 'User Accounts', desc: 'Manage Linux and Samba user accounts for file share access' };

    function loadUsersData() {
        fetch('/api.php?action=users_get')
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') return;
                const tbody = document.querySelector('#users-table tbody');
                if (!data.users.length) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No user accounts configured.</td></tr>';
                    return;
                }
                tbody.innerHTML = data.users.map(u => `
                    <tr>
                        <td><code>${u.username}</code></td>
                        <td>${u.display_name || '<span class="text-muted">—</span>'}</td>
                        <td><span class="badge ${u.samba_enabled == 1 ? 'active' : 'inactive'}">${u.samba_enabled == 1 ? 'Enabled' : 'Disabled'}</span></td>
                        <td>${u.created_at ? u.created_at.split(' ')[0] : '—'}</td>
                        <td>
                            <button class="btn-edit-sm user-edit-btn"
                                data-id="${u.id}"
                                data-username="${u.username}"
                                data-display="${u.display_name || ''}"
                                data-samba="${u.samba_enabled}">Edit</button>
                            <button class="btn-danger-sm user-delete-btn" data-id="${u.id}" data-username="${u.username}">Delete</button>
                        </td>
                    </tr>
                `).join('');

                document.querySelectorAll('.user-edit-btn').forEach(btn => {
                    btn.addEventListener('click', () => openUserModal(btn.dataset));
                });
                document.querySelectorAll('.user-delete-btn').forEach(btn => {
                    btn.addEventListener('click', () => deleteUser(btn.dataset.id, btn.dataset.username));
                });
            })
            .catch(() => {
                document.querySelector('#users-table tbody').innerHTML =
                    '<tr><td colspan="5" class="text-center text-muted">Error loading user accounts.</td></tr>';
            });
    }

    function openUserModal(data = {}) {
        document.getElementById('user-modal-title').textContent = data.id ? 'Edit User Account' : 'Add User Account';
        document.getElementById('user_id').value          = data.id       || '';
        document.getElementById('user_username').value    = data.username || '';
        document.getElementById('user_display_name').value = data.display || '';
        document.getElementById('user_password').value    = '';
        document.getElementById('user_samba_enabled').checked = data.samba !== '0';
        document.getElementById('user-pwd-hint').style.display = data.id ? '' : 'none';
        document.getElementById('user_username').readOnly = !!data.id;
        openModal('user-modal');
    }

    document.getElementById('add-user-btn').addEventListener('click', () => openUserModal());

    userForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = userForm.querySelector('[type="submit"]');
        btn.disabled = true;
        const formData = new FormData(userForm);
        fetch('/api.php?action=user_save', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    closeModal();
                    loadUsersData();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => showToast('Server error saving user.', 'error'))
            .finally(() => { btn.disabled = false; });
    });

    function deleteUser(id, username) {
        if (!confirm(`Delete user "${username}"? This will remove their Linux and Samba accounts.`)) return;
        const formData = new FormData();
        formData.append('id', id);
        fetch('/api.php?action=user_delete', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    loadUsersData();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => showToast('Server error deleting user.', 'error'));
    }

});
