<?php
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ConfigGenerator.php';
require_once __DIR__ . '/../src/SystemManager.php';

use App\Database;
use App\ConfigGenerator;
use App\SystemManager;

$db = Database::getInstance();
$system = new SystemManager();
$generator = new ConfigGenerator($db);

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
                    'bind9' => in_array('lightbox-bind9', $running, true),
                    'dhcp'  => in_array('lightbox-dhcp',  $running, true),
                    'ntp'   => in_array('lightbox-ntp',   $running, true),
                    'samba' => in_array('lightbox-samba', $running, true)
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
                    'bind9' => in_array('lightbox-bind9', $running, true),
                    'dhcp'  => in_array('lightbox-dhcp',  $running, true),
                    'ntp'   => in_array('lightbox-ntp',   $running, true),
                    'samba' => in_array('lightbox-samba', $running, true)
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
            echo json_encode([
                'status'     => 'success',
                'settings'   => $db->getSettings(),
                'records'    => $records,
                'interfaces' => $system->getHostNetworkInterfaces()
            ]);
            break;

        case 'dns_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            $db->updateSetting('system_name',   $_POST['system_name']   ?? 'Lightbox-Server');
            $db->updateSetting('domain_name',   $_POST['domain_name']   ?? 'lighting.local');
            $db->updateSetting('primary_dns',   $_POST['primary_dns']   ?? '8.8.8.8');
            $db->updateSetting('secondary_dns', $_POST['secondary_dns'] ?? '');
            $db->updateSetting('dns_interface', $_POST['dns_interface'] ?? '');
            $db->updateSetting('pending_changes', '1');
            
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
            $db->updateSetting('dhcp_interface', $_POST['dhcp_interface'] ?? '');
            $db->updateSetting('advertise_dns', isset($_POST['advertise_dns']) ? '1' : '0');
            $db->updateSetting('advertise_ntp', isset($_POST['advertise_ntp']) ? '1' : '0');
            
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
            $path = trim($_POST['share_path'] ?? '');
            $writable = isset($_POST['writable']) ? 1 : 0;
            $guest = isset($_POST['guest_ok']) ? 1 : 0;
            $desc = trim($_POST['description'] ?? '');

            if (empty($name) || empty($path)) {
                throw new Exception('Share name and folder path are required.');
            }

            // Ensure path starts with /shares/ for safety inside container
            if (strpos($path, '/shares/') !== 0) {
                throw new Exception('Share folder path must begin with /shares/ for Docker storage safety.');
            }

            $pdo = $db->getConnection();
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO samba_shares (share_name, share_path, writable, guest_ok, description) VALUES (:name, :path, :write, :guest, :desc)");
                $stmt->execute([':name' => $name, ':path' => $path, ':write' => $writable, ':guest' => $guest, ':desc' => $desc]);
            } else {
                $stmt = $pdo->prepare("UPDATE samba_shares SET share_name = :name, share_path = :path, writable = :write, guest_ok = :guest, description = :desc WHERE id = :id");
                $stmt->execute([':name' => $name, ':path' => $path, ':write' => $writable, ':guest' => $guest, ':desc' => $desc, ':id' => $id]);
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

        case 'apply_changes':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

            // 0. Re-apply stored static interface configs to the host
            $system->reapplyAllInterfaceConfigs($db->getAllInterfaceConfigs());

            // 0b. If DHCPv6 is enabled, ensure the server has the ::1 address of the prefix
            //     so clients can actually reach the advertised DNS/NTP IPv6 address.
            $dhcpCfg   = $db->getDhcpSettings();
            $appSettings = $db->getSettings();
            if (!empty($dhcpCfg['v6_enabled']) && !empty($appSettings['dhcp_interface'])) {
                $system->ensureServerIPv6(
                    $appSettings['dhcp_interface'],
                    $dhcpCfg['v6_prefix'] ?? 'fd00::/64'
                );
            }

            // 1. Generate all configuration files from DB state
            $generator->generateAll();
            
            // 2. Reload/Restart individual Docker containers
            $success = true;
            $errors = [];

            $services = [
                'lightbox-bind9' => 'bind9',
                'lightbox-dhcp'  => 'dhcp',
                'lightbox-ntp'   => 'ntp',
                'lightbox-samba' => 'samba',
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
                echo json_encode(['status' => 'warning', 'message' => 'Configs written, but some services failed: ' . implode('; ', $errors)]);
            }
            break;

        case 'logs_get':
            $service = $_GET['service'] ?? 'web';
            $logs = $system->getServiceLogs($service);
            echo json_encode([
                'status' => 'success',
                'logs' => $logs
            ]);
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
