<?php
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ConfigGenerator.php';
require_once __DIR__ . '/../src/SystemManager.php';
require_once __DIR__ . '/../src/UserManager.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/PKIManager.php';

use App\Database;
use App\ConfigGenerator;
use App\SystemManager;
use App\UserManager;
use App\Auth;

$db = Database::getInstance();
$system = new SystemManager();
$generator = new ConfigGenerator($db);
$users = new UserManager($db);
$pki = new \App\PKIManager();

Auth::requireLoginApi($users);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'metrics':
            $settings = $db->getSettings();
            $metrics = $system->getSystemMetrics();

            $pdo = $db->getConnection();
            $dnsCount = $pdo->query("SELECT COUNT(*) FROM dns_records")->fetchColumn();
            $shareCount = $pdo->query("SELECT COUNT(*) FROM samba_shares")->fetchColumn();
            $leaseCount = count(getActiveLeases());

            echo json_encode([
                'status' => 'success',
                'metrics' => $metrics,
                'stats' => [
                    'dns_count' => $dnsCount,
                    'share_count' => $shareCount,
                    'lease_count' => $leaseCount
                ],
                'pending_changes' => (int)($settings['pending_changes'] ?? 0),
                'interfaces' => $system->getNetworkInterfaces()
            ]);
            break;

        case 'services':
            $running = $system->getRunningContainerNames();
            echo json_encode([
                'status' => 'success',
                'services' => [
                    'bind9'  => in_array('lightbox-bind9',  $running, true),
                    'dhcp'   => in_array('lightbox-dhcp',   $running, true),
                    'ntp'    => in_array('lightbox-ntp',    $running, true),
                    'samba'  => in_array('lightbox-samba',  $running, true),
                    'syslog' => in_array('lightbox-syslog', $running, true),
                ]
            ]);
            break;

        case 'status':
            $settings = $db->getSettings();
            $metrics = $system->getSystemMetrics();
            $running = $system->getRunningContainerNames();

            $pdo = $db->getConnection();
            $dnsCount = $pdo->query("SELECT COUNT(*) FROM dns_records")->fetchColumn();
            $shareCount = $pdo->query("SELECT COUNT(*) FROM samba_shares")->fetchColumn();
            $leaseCount = count(getActiveLeases());

            echo json_encode([
                'status' => 'success',
                'metrics' => $metrics,
                'services' => [
                    'bind9'  => in_array('lightbox-bind9',  $running, true),
                    'dhcp'   => in_array('lightbox-dhcp',   $running, true),
                    'ntp'    => in_array('lightbox-ntp',    $running, true),
                    'samba'  => in_array('lightbox-samba',  $running, true),
                    'syslog' => in_array('lightbox-syslog', $running, true),
                ],
                'stats' => [
                    'dns_count' => $dnsCount,
                    'share_count' => $shareCount,
                    'lease_count' => $leaseCount
                ],
                'pending_changes' => (int)($settings['pending_changes'] ?? 0),
                'interfaces' => $system->getNetworkInterfaces()
            ]);
            break;

        case 'leases':
            echo json_encode([
                'status' => 'success',
                'leases' => getActiveLeases()
            ]);
            break;

        case 'dns_get':
            $pdo = $db->getConnection();
            $records = $pdo->query("SELECT * FROM dns_records ORDER BY hostname ASC")->fetchAll();

            // Static reservations always have explicit hostnames and are always in DNS
            $reservations = $pdo->query("SELECT hostname, ip_address, ip_type FROM dhcp_reservations ORDER BY hostname ASC")->fetchAll();
            $dhcpDnsEntries = array_map(fn($r) => [
                'hostname' => $r['hostname'],
                'ip'       => $r['ip_address'],
                'type'     => $r['ip_type'] === 'IPv6' ? 'AAAA' : 'A',
                'expiry'   => 'Static reservation',
                'source'   => 'reservation',
            ], $reservations);

            // Dynamic leases from the leases file that include a hostname
            $seenHostnames = array_map(fn($e) => strtolower($e['hostname']), $dhcpDnsEntries);
            foreach (getActiveLeases() as $l) {
                if ($l['hostname'] === 'Unknown') continue;
                if (in_array(strtolower($l['hostname']), $seenHostnames, true)) continue;
                $seenHostnames[] = strtolower($l['hostname']);
                $dhcpDnsEntries[] = [
                    'hostname' => $l['hostname'],
                    'ip'       => $l['ip'],
                    'type'     => filter_var($l['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'A' : 'AAAA',
                    'expiry'   => $l['expiry'],
                    'source'   => 'dynamic',
                ];
            }

            echo json_encode([
                'status'           => 'success',
                'settings'         => $db->getSettings(),
                'records'          => $records,
                'interfaces'       => $system->getHostNetworkInterfaces(),
                'dhcp_dns_entries' => $dhcpDnsEntries,
            ]);
            break;

        case 'dns_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $oldDomain = $db->getSettings()['domain_name'] ?? '';
            $newDomain = trim($_POST['domain_name'] ?? 'lighting.local');
            $db->updateSetting('system_name',   $_POST['system_name']   ?? 'Lightbox-Server');
            $db->updateSetting('domain_name',   $newDomain);
            $db->updateSetting('primary_dns',   $_POST['primary_dns']   ?? '8.8.8.8');
            $db->updateSetting('secondary_dns', $_POST['secondary_dns'] ?? '');
            $db->updateSetting('dns_interface', $_POST['dns_interface'] ?? '');
            $db->updateSetting('pending_changes', '1');

            if ($newDomain !== $oldDomain && $pki->caExists()) {
                try {
                    $pki->generateWildcard($newDomain);
                } catch (Exception $e) {
                    $db->addAlert('warning', 'pki', 'Wildcard cert regen failed after domain change: ' . $e->getMessage());
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'DNS settings saved. Apply changes to activate.']);
            break;

        case 'dns_record_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = $_POST['id'] ?? '';
            $hostname = trim($_POST['hostname'] ?? '');
            $ip = trim($_POST['ip_address'] ?? '');
            $type = $_POST['ip_type'] ?? 'A';
            $desc = trim($_POST['description'] ?? '');

            if (empty($hostname) || empty($ip)) {
                throw new Exception('Hostname and IP address are required.');
            }

            $pdo = $db->getConnection();
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO dns_records (hostname, ip_address, ip_type, description) VALUES (:host, :ip, :type, :desc)");
                $stmt->execute([':host' => $hostname, ':ip' => $ip, ':type' => $type, ':desc' => $desc]);
            } else {
                $stmt = $pdo->prepare("UPDATE dns_records SET hostname = :host, ip_address = :ip, ip_type = :type, description = :desc WHERE id = :id");
                $stmt->execute([':host' => $hostname, ':ip' => $ip, ':type' => $type, ':desc' => $desc, ':id' => $id]);
            }
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'DNS record saved.']);
            break;

        case 'dns_record_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = $_POST['id'] ?? '';
            if (empty($id)) throw new Exception('ID required');
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'DNS record deleted.']);
            break;

        case 'dhcp_get':
            $pdo = $db->getConnection();
            $reservations = $pdo->query("SELECT * FROM dhcp_reservations ORDER BY hostname ASC")->fetchAll();
            echo json_encode([
                'status' => 'success',
                'settings' => $db->getSettings(),
                'dhcp_settings' => $db->getDhcpSettings(),
                'reservations' => $reservations,
                'interfaces' => $system->getHostNetworkInterfaces()
            ]);
            break;

        case 'dhcp_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $db->updateSetting('dhcp_interface',   $_POST['dhcp_interface'] ?? '');
            $db->updateSetting('advertise_dns',    isset($_POST['advertise_dns'])    ? '1' : '0');
            $db->updateSetting('advertise_ntp',    isset($_POST['advertise_ntp'])    ? '1' : '0');
            $db->updateSetting('advertise_syslog', isset($_POST['advertise_syslog']) ? '1' : '0');
            
            $data = [
                ':v4_enabled' => isset($_POST['v4_enabled']) ? 1 : 0,
                ':v4_subnet' => $_POST['v4_subnet'] ?? '192.168.1.0',
                ':v4_netmask' => $_POST['v4_netmask'] ?? '255.255.255.0',
                ':v4_gateway' => $_POST['v4_gateway'] ?? '192.168.1.1',
                ':v4_range_start' => $_POST['v4_range_start'] ?? '192.168.1.100',
                ':v4_range_end' => $_POST['v4_range_end'] ?? '192.168.1.200',
                ':v4_lease_time' => $_POST['v4_lease_time'] ?? '12h',
                ':v6_enabled' => isset($_POST['v6_enabled']) ? 1 : 0,
                ':v6_prefix' => $_POST['v6_prefix'] ?? 'fd00::/64',
                ':v6_range_start' => $_POST['v6_range_start'] ?? 'fd00::100',
                ':v6_range_end' => $_POST['v6_range_end'] ?? 'fd00::200',
                ':v6_lease_time' => $_POST['v6_lease_time'] ?? '12h'
            ];
            
            $db->updateDhcpSettings($data);
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'DHCP settings saved.']);
            break;

        case 'dhcp_reservation_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = $_POST['id'] ?? '';
            $hostname = trim($_POST['hostname'] ?? '');
            $mac = trim($_POST['mac_address'] ?? '');
            $ip = trim($_POST['ip_address'] ?? '');
            $type = $_POST['ip_type'] ?? 'IPv4';

            if (empty($hostname) || empty($mac) || empty($ip)) {
                throw new Exception('Hostname, MAC, and IP are required.');
            }

            // Normalise MAC address
            $mac = strtolower(str_replace('-', ':', $mac));

            $pdo = $db->getConnection();
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO dhcp_reservations (hostname, mac_address, ip_address, ip_type) VALUES (:host, :mac, :ip, :type)");
                $stmt->execute([':host' => $hostname, ':mac' => $mac, ':ip' => $ip, ':type' => $type]);
            } else {
                $stmt = $pdo->prepare("UPDATE dhcp_reservations SET hostname = :host, mac_address = :mac, ip_address = :ip, ip_type = :type WHERE id = :id");
                $stmt->execute([':host' => $hostname, ':mac' => $mac, ':ip' => $ip, ':type' => $type, ':id' => $id]);
            }
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'DHCP reservation saved.']);
            break;

        case 'dhcp_reservation_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = $_POST['id'] ?? '';
            if (empty($id)) throw new Exception('ID required');
            
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM dhcp_reservations WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'Reservation deleted.']);
            break;

        case 'ntp_get':
            echo json_encode([
                'status' => 'success',
                'settings' => $db->getSettings()
            ]);
            break;

        case 'ntp_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $servers = trim($_POST['ntp_servers'] ?? '');
            
            $db->updateSetting('ntp_servers', $servers);
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'NTP settings saved.']);
            break;

        case 'samba_get':
            $pdo = $db->getConnection();
            $shares = $pdo->query("SELECT * FROM samba_shares ORDER BY share_name ASC")->fetchAll();
            echo json_encode([
                'status' => 'success',
                'shares' => $shares
            ]);
            break;

        case 'samba_share_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['share_name'] ?? '');
            $writable = isset($_POST['writable']) ? 1 : 0;
            $guest = isset($_POST['guest_ok']) ? 1 : 0;
            $desc = trim($_POST['description'] ?? '');
            $isTftp = isset($_POST['is_tftp']) ? 1 : 0;

            if (empty($name)) {
                throw new Exception('Share name is required.');
            }

            $path = '/shares/' . $name;

            $pdo = $db->getConnection();
            // Only one share may be the TFTP share — clear the flag on all others first
            if ($isTftp) {
                $pdo->exec("UPDATE samba_shares SET is_tftp = 0");
            }
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO samba_shares (share_name, share_path, writable, guest_ok, description, is_tftp) VALUES (:name, :path, :write, :guest, :desc, :tftp)");
                $stmt->execute([':name' => $name, ':path' => $path, ':write' => $writable, ':guest' => $guest, ':desc' => $desc, ':tftp' => $isTftp]);
            } else {
                $stmt = $pdo->prepare("UPDATE samba_shares SET share_name = :name, share_path = :path, writable = :write, guest_ok = :guest, description = :desc, is_tftp = :tftp WHERE id = :id");
                $stmt->execute([':name' => $name, ':path' => $path, ':write' => $writable, ':guest' => $guest, ':desc' => $desc, ':tftp' => $isTftp, ':id' => $id]);
            }
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'Samba share saved.']);
            break;

        case 'samba_share_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = $_POST['id'] ?? '';
            if (empty($id)) throw new Exception('ID required');
            
            $pdo = $db->getConnection();
            // Do not allow deleting ShowFiles default share for safety
            $check = $pdo->prepare("SELECT share_name FROM samba_shares WHERE id = :id");
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() === 'ShowFiles') {
                throw new Exception('The primary ShowFiles share cannot be deleted.');
            }

            $stmt = $pdo->prepare("DELETE FROM samba_shares WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            $db->updateSetting('pending_changes', '1');
            echo json_encode(['status' => 'success', 'message' => 'Samba share deleted.']);
            break;

        case 'network_get':
            echo json_encode([
                'status'     => 'success',
                'interfaces' => $system->getHostNetworkInterfaces(),
                'configs'    => $db->getAllInterfaceConfigs(),
            ]);
            break;

        case 'network_interface_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $iface  = trim($_POST['interface_name'] ?? '');
            $mode   = $_POST['mode'] ?? 'dhcp';
            $v4addr = trim($_POST['v4_address'] ?? '');
            $v4gw   = trim($_POST['v4_gateway']  ?? '');
            $v6addr = trim($_POST['v6_address'] ?? '');
            $v6gw   = trim($_POST['v6_gateway']  ?? '');

            if (empty($iface)) throw new Exception('Interface name is required.');
            if (!in_array($mode, ['dhcp', 'static'], true)) throw new Exception('Invalid mode.');

            if ($mode === 'static') {
                // Validate any provided addresses are CIDR format
                foreach (['v4_address' => $v4addr, 'v6_address' => $v6addr] as $field => $val) {
                    if (!empty($val) && !preg_match('/^[\da-fA-F:.]+\/\d{1,3}$/', $val)) {
                        throw new Exception("$field must be in CIDR notation (e.g. 192.168.1.10/24).");
                    }
                }
            }

            $cfg = ['mode' => $mode, 'v4_address' => $v4addr, 'v4_gateway' => $v4gw, 'v6_address' => $v6addr, 'v6_gateway' => $v6gw];
            $result = $system->applyInterfaceConfig($iface, $cfg);
            if (!$result['ok']) {
                throw new Exception('Configuration applied with errors: ' . ($result['error'] ?? ''));
            }

            $db->saveInterfaceConfig($iface, $mode, $v4addr, $v4gw, $v6addr, $v6gw);

            $modeLabel = $mode === 'static' ? 'static' : 'DHCP';
            echo json_encode(['status' => 'success', 'message' => "{$iface} set to {$modeLabel}."]);
            break;

        case 'network_connectivity':
            $interfaces   = $system->getHostNetworkInterfaces();
            $ifaceNames   = array_column($interfaces, 'name');
            $connectivity = $system->checkInterfacesConnectivity($ifaceNames);
            echo json_encode(['status' => 'success', 'connectivity' => $connectivity]);
            break;

        case 'sync_leases':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

            $leaseFile    = '/data/dnsmasq/leases';
            $currentMtime = file_exists($leaseFile) ? (string)filemtime($leaseFile) : '0';
            $lastMtime    = $db->getSettings()['last_lease_sync_mtime'] ?? '0';

            if ($currentMtime !== $lastMtime) {
                $generator->syncDynamicLeases();
                $system->reloadService('bind9');
                $db->updateSetting('last_lease_sync_mtime', $currentMtime);
            }

            echo json_encode(['status' => 'success', 'message' => 'DNS synced with DHCP leases.']);
            break;

        case 'devices_get':
            $pdo      = $db->getConnection();
            $settings = $db->getSettings();
            $domain   = $settings['domain_name'] ?? 'lighting.local';
            $byIp     = [];  // ip => device entry (dedup by IP, not hostname)

            // Custom DNS A records
            $rows = $pdo->query("SELECT hostname, ip_address, description FROM dns_records WHERE ip_type = 'A' ORDER BY hostname")->fetchAll();
            foreach ($rows as $r) {
                $ip = $r['ip_address'];
                if (isset($byIp[$ip])) continue;
                $byIp[$ip] = ['hostname' => $r['hostname'], 'ip' => $ip, 'source' => 'custom', 'info' => $r['description'] ?: ''];
            }

            // DHCP static reservations (IPv4 only)
            $rows = $pdo->query("SELECT hostname, ip_address, mac_address FROM dhcp_reservations WHERE ip_type = 'IPv4' ORDER BY hostname")->fetchAll();
            foreach ($rows as $r) {
                $ip = $r['ip_address'];
                if (isset($byIp[$ip])) continue;
                $byIp[$ip] = ['hostname' => $r['hostname'], 'ip' => $ip, 'source' => 'reservation', 'info' => $r['mac_address']];
            }

            // Dynamic DHCP leases with hostnames
            foreach (getActiveLeases() as $l) {
                if ($l['hostname'] === 'Unknown') continue;
                if (!filter_var($l['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
                $ip = $l['ip'];
                if (isset($byIp[$ip])) continue;
                $byIp[$ip] = ['hostname' => $l['hostname'], 'ip' => $ip, 'source' => 'dynamic', 'info' => $l['expiry']];
            }

            $devices = array_values($byIp);
            usort($devices, fn($a, $b) => strcmp($a['hostname'], $b['hostname']));
            echo json_encode(['status' => 'success', 'devices' => $devices, 'domain' => $domain]);
            break;

        case 'ping':
            $ip = $_GET['ip'] ?? '';
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                echo json_encode(['online' => false]);
                break;
            }
            exec('ping -c 1 -W 1 -q ' . escapeshellarg($ip) . ' 2>/dev/null', $out, $ret);
            echo json_encode(['online' => $ret === 0]);
            break;

        case 'db_backup':
            $path = $db->getDbPath();
            if (!file_exists($path)) throw new Exception('Database file not found.');
            $filename = 'lightbox-backup-' . date('Y-m-d') . '.db';
            header('Content-Type: application/octet-stream', true);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        case 'db_restore':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            if (empty($_FILES['db_file']) || $_FILES['db_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error.');
            }
            $tmp = $_FILES['db_file']['tmp_name'];

            // Validate SQLite magic header
            $fh    = fopen($tmp, 'rb');
            $magic = fread($fh, 16);
            fclose($fh);
            if ($magic !== "SQLite format 3\x00") {
                throw new Exception('Uploaded file is not a valid SQLite database.');
            }

            $dest = $db->getDbPath();
            $db->close(); // release the PDO handle before replacing the file
            if (!copy($tmp, $dest)) {
                throw new Exception('Failed to write the database file. Check permissions.');
            }
            echo json_encode(['status' => 'success', 'message' => 'Database restored. Reload the page to apply.']);
            exit;

        case 'pki_get':
            echo json_encode(['status' => 'success', 'pki' => $pki->getStatus()]);
            break;

        case 'pki_regenerate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $scope  = $_POST['scope'] ?? 'wildcard';
            $domain = $db->getSettings()['domain_name'] ?? 'lighting.local';
            if ($scope === 'all' || !$pki->caExists()) {
                $pki->generateCA();
            }
            $pki->generateWildcard($domain);
            echo json_encode(['status' => 'success', 'message' => 'Certificates generated successfully.', 'pki' => $pki->getStatus()]);
            break;

        case 'pki_download':
            $file = $_GET['file'] ?? '';
            $map  = [
                'ca_cert'       => ['/data/pki/ca.crt',      'lightbox-ca.crt', 'application/x-x509-ca-cert'],
                'wildcard_cert' => ['/data/pki/wildcard.crt', 'wildcard.crt',    'application/x-pem-file'],
                'wildcard_key'  => ['/data/pki/wildcard.key', 'wildcard.key',    'application/x-pem-file'],
            ];
            if (!isset($map[$file])) throw new Exception('Invalid file requested.');
            [$path, $name, $type] = $map[$file];
            if (!file_exists($path)) throw new Exception('Certificate not found. Generate certificates first.');
            header('Content-Type: ' . $type, true);
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            echo file_get_contents($path);
            exit;

        case 'apply_changes':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

            // 0. Re-apply stored static interface configs to the host
            $system->reapplyAllInterfaceConfigs($db->getAllInterfaceConfigs());

            // 1. Generate all configuration files from DB state
            $generator->generateAll();
            
            // 2. Reload/Restart individual Docker containers
            $success = true;
            $errors = [];

            $services = [
                'lightbox-bind9'   => 'bind9',
                'lightbox-dhcp'    => 'dhcp',
                'lightbox-ntp'     => 'ntp',
                'lightbox-samba'   => 'samba',
                'lightbox-syslog'  => 'syslog',
            ];

            foreach ($services as $container => $svc) {
                if ($system->isContainerRunning($container)) {
                    $result = $system->reloadService($svc);
                    if (!$result['ok']) {
                        $errors[] = $container . ': ' . ($result['output'] ?: 'reload failed');
                        $success = false;
                    }
                } else {
                    $system->restartContainer($container);
                }
            }

            // Configs are always written to disk above — clear pending flag regardless of service reload outcome
            $db->updateSetting('pending_changes', '0');

            if ($success) {
                echo json_encode(['status' => 'success', 'message' => 'Configurations written and all services reloaded.']);
            } else {
                $errMsg = 'Service reload failed after applying changes: ' . implode('; ', $errors);
                $db->addAlert('error', 'apply_changes', $errMsg);
                echo json_encode(['status' => 'warning', 'message' => 'Configs written, but some services failed: ' . implode('; ', $errors)]);
            }
            break;

        case 'service_toggle':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $allowed = ['bind9' => 'lightbox-bind9', 'dhcp' => 'lightbox-dhcp', 'ntp' => 'lightbox-ntp', 'samba' => 'lightbox-samba', 'syslog' => 'lightbox-syslog'];
            $service = $_POST['service'] ?? '';
            $state   = $_POST['state']   ?? '';

            if (!isset($allowed[$service])) throw new Exception('Unknown service.');
            if (!in_array($state, ['start', 'stop'], true)) throw new Exception('Invalid state.');

            $container = $allowed[$service];
            if ($state === 'start') {
                $ok = $system->startContainer($container);
            } else {
                $ok = $system->stopContainer($container);
            }

            if (!$ok) {
                $db->addAlert('error', 'service_toggle', "Failed to $state service '$service'.");
                throw new Exception("Failed to $state $service.");
            }
            echo json_encode(['status' => 'success', 'message' => ucfirst($state) . 'ed ' . $service . '.']);
            break;

        case 'logs_get':
            $service = $_GET['service'] ?? 'web';
            $logs = $system->getServiceLogs($service);
            echo json_encode([
                'status' => 'success',
                'logs' => $logs
            ]);
            break;

        case 'syslog_get':
            $logFile = '/data/syslog/messages.log';
            $lines   = max(1, min(2000, (int)($_GET['lines'] ?? 200)));
            $entries = [];
            if (file_exists($logFile)) {
                $all = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $entries = array_slice($all, -$lines);
            }
            echo json_encode([
                'status'  => 'success',
                'entries' => $entries,
                'total'   => file_exists($logFile) ? count(file($logFile) ?: []) : 0,
            ]);
            break;

        case 'syslog_clear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $logFile = '/data/syslog/messages.log';
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
            }
            echo json_encode(['status' => 'success', 'message' => 'Syslog cleared.']);
            break;

        case 'alerts_get':
            $pdo = $db->getConnection();
            $rows = $pdo->query(
                "SELECT * FROM alerts ORDER BY acknowledged_at IS NOT NULL ASC, created_at DESC"
            )->fetchAll();
            echo json_encode(['status' => 'success', 'alerts' => $rows]);
            break;

        case 'alert_acknowledge':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id   = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Alert ID required.');
            $user = Auth::currentUser();
            $by   = $user ? ($user['display_name'] ?: $user['username']) : 'System';
            $pdo  = $db->getConnection();
            $stmt = $pdo->prepare(
                "UPDATE alerts SET acknowledged_at = CURRENT_TIMESTAMP, acknowledged_by = :by WHERE id = :id"
            );
            $stmt->execute([':by' => $by, ':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'Alert acknowledged.']);
            break;

        case 'alert_clear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id  = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Alert ID required.');
            $pdo = $db->getConnection();
            $pdo->prepare("DELETE FROM alerts WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'Alert cleared.']);
            break;

        case 'alerts_clear_acknowledged':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $db->getConnection()->exec("DELETE FROM alerts WHERE acknowledged_at IS NOT NULL");
            echo json_encode(['status' => 'success', 'message' => 'Acknowledged alerts cleared.']);
            break;

        case 'logout':
            Auth::logout();
            echo json_encode(['status' => 'success']);
            break;

        case 'users_get':
            echo json_encode([
                'status' => 'success',
                'users'  => $users->listUsers()
            ]);
            break;

        case 'user_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $users->saveUser($_POST);
            echo json_encode(['status' => 'success', 'message' => 'User saved.']);
            break;

        case 'user_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id = trim($_POST['id'] ?? '');
            if (empty($id)) throw new Exception('User ID required.');
            $users->deleteUser($id);
            echo json_encode(['status' => 'success', 'message' => 'User deleted.']);
            break;

        case 'user_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $id  = trim($_POST['id'] ?? '');
            $pwd = $_POST['password'] ?? '';
            if (empty($id)) throw new Exception('User ID required.');
            $users->changePassword($id, $pwd);
            echo json_encode(['status' => 'success', 'message' => 'Password updated.']);
            break;

        default:
            throw new Exception('Unknown API action requested.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Helper to parse dnsmasq leases file
 */
function getActiveLeases() {
    $leaseFile = '/data/dnsmasq/leases';
    $leases = [];
    if (file_exists($leaseFile)) {
        $content = file_get_contents($leaseFile);
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            if (empty($line) || strpos($line, '#') === 0) continue;
            $parts = explode(' ', $line);
            if (count($parts) >= 3) {
                $expiryUnix = (int)$parts[0];
                $mac = $parts[1];
                $ip = $parts[2];
                $hostname = $parts[3] ?? '*';
                
                $timeRemaining = 'Expired';
                if ($expiryUnix > time()) {
                    $diff = $expiryUnix - time();
                    $hours = floor($diff / 3600);
                    $mins = floor(($diff % 3600) / 60);
                    $timeRemaining = ($hours > 0 ? "{$hours}h " : "") . "{$mins}m remaining";
                }
                
                $leases[] = [
                    'ip' => $ip,
                    'mac' => $mac,
                    'hostname' => $hostname === '*' ? 'Unknown' : $hostname,
                    'expiry' => $timeRemaining
                ];
            }
        }
    }
    return $leases;
}
