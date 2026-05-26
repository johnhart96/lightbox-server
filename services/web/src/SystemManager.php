<?php

namespace App;

class SystemManager {

    /**
     * Get system metrics (CPU, RAM, Disk, Uptime, Load)
     */
    public function getSystemMetrics() {
        return [
            'uptime' => $this->getSystemUptime(),
            'cpu' => $this->getCpuUsage(),
            'ram' => $this->getRamUsage(),
            'disk' => $this->getDiskUsage(),
            'load' => sys_getloadavg() ?: [0, 0, 0],
            'time' => date('Y-m-d H:i:s T')
        ];
    }

    private function getSystemUptime() {
        if (!file_exists('/proc/uptime')) {
            return 'Unknown';
        }
        
        $str = file_get_contents('/proc/uptime');
        $uptime_sec = (int)floatval(explode(' ', $str)[0]);
        
        $days = floor($uptime_sec / 86400);
        $hours = floor(($uptime_sec % 86400) / 3600);
        $mins = floor(($uptime_sec % 3600) / 60);
        
        $out = [];
        if ($days > 0) $out[] = $days . 'd';
        if ($hours > 0) $out[] = $hours . 'h';
        if ($mins > 0 || empty($out)) $out[] = $mins . 'm';
        
        return implode(' ', $out);
    }

    private function getCpuUsage() {
        if (!file_exists('/proc/stat')) {
            return 0;
        }

        $getVal = function() {
            $data = file_get_contents('/proc/stat');
            $lines = explode("\n", $data);
            $cpuLine = explode(" ", preg_replace('/\s+/', ' ', trim($lines[0])));
            array_shift($cpuLine); // remove "cpu"
            $val = [
                'user' => (int)$cpuLine[0],
                'nice' => (int)$cpuLine[1],
                'system' => (int)$cpuLine[2],
                'idle' => (int)$cpuLine[3],
                'iowait' => (int)$cpuLine[4],
                'irq' => (int)$cpuLine[5],
                'softirq' => (int)$cpuLine[6],
                'steal' => (int)$cpuLine[7]
            ];
            $val['total'] = array_sum($val);
            return $val;
        };

        $t1 = $getVal();
        usleep(100000); // 100ms
        $t2 = $getVal();

        $diffTotal = $t2['total'] - $t1['total'];
        if ($diffTotal === 0) return 0;
        
        $diffIdle = ($t2['idle'] + $t2['iowait']) - ($t1['idle'] + $t1['iowait']);
        $diffUsed = $diffTotal - $diffIdle;

        return round(($diffUsed / $diffTotal) * 100, 1);
    }

    private function getRamUsage() {
        if (!file_exists('/proc/meminfo')) {
            return ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0];
        }

        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);
        
        $totalKb = (int)($total[1] ?? 0);
        $availKb = (int)($avail[1] ?? 0);
        $usedKb = $totalKb - $availKb;

        $percent = ($totalKb > 0) ? round(($usedKb / $totalKb) * 100, 1) : 0;

        return [
            'total' => round($totalKb / 1024 / 1024, 2), // GB
            'used' => round($usedKb / 1024 / 1024, 2),  // GB
            'percent' => $percent
        ];
    }

    private function getDiskUsage() {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;
        
        $percent = ($total > 0) ? round(($used / $total) * 100, 1) : 0;

        return [
            'total' => round($total / 1024 / 1024 / 1024, 1), // GB
            'used' => round($used / 1024 / 1024 / 1024, 1),  // GB
            'percent' => $percent
        ];
    }

    /**
     * Get active network interfaces on the host
     */
    public function getNetworkInterfaces() {
        $interfaces = [];
        $paths = glob('/sys/class/net/*') ?: [];

        foreach ($paths as $path) {
            $name = basename($path);

            // Skip loopback interface
            if ($name === 'lo' || strpos($name, 'docker') === 0 || strpos($name, 'veth') === 0 || strpos($name, 'br-') === 0) {
                continue;
            }

            // Read operational status (up/down)
            $operstate = 'unknown';
            $stateFile = $path . '/operstate';
            if (file_exists($stateFile)) {
                $operstate = trim(file_get_contents($stateFile));
            }

            // Read IP address if available
            $ip = '';
            $output = shell_exec("ip -4 addr show dev " . escapeshellarg($name));
            if ($output) {
                preg_match('/inet\s+([0-9\.]+)/', $output, $matches);
                $ip = $matches[1] ?? '';
            }

            $interfaces[] = [
                'name' => $name,
                'status' => $operstate,
                'ip' => $ip
            ];
        }
        return $interfaces;
    }

    /**
     * Get host network interfaces with full address info via the dhcp container.
     * The dhcp container runs with network_mode: host and NET_ADMIN, so it sees
     * the real host interfaces. iproute2 must be installed in that container.
     */
    public function getHostNetworkInterfaces(): array {
        $output = shell_exec("docker exec lightbox-dhcp ip addr 2>/dev/null");
        if (empty(trim($output ?? ''))) return [];
        return $this->parseIpAddrOutput($output);
    }

    private function parseIpAddrOutput(string $output): array {
        $interfaces  = [];
        $current     = null;
        $skip        = ['lo'];
        $skipPrefixes = ['docker', 'veth', 'br-'];

        foreach (explode("\n", $output) as $line) {
            // Interface header: "2: eth0: <flags> state UP ..."
            if (preg_match('/^\d+:\s+([^:@\s]+)/', $line, $m)) {
                if ($current !== null) {
                    $interfaces[] = $current;
                }
                $name = $m[1];

                if (in_array($name, $skip, true)) { $current = null; continue; }
                foreach ($skipPrefixes as $prefix) {
                    if (strpos($name, $prefix) === 0) { $current = null; continue 2; }
                }

                $status = 'unknown';
                if (strpos($line, 'state UP') !== false)   $status = 'up';
                elseif (strpos($line, 'state DOWN') !== false) $status = 'down';

                $current = ['name' => $name, 'status' => $status, 'mac' => '', 'v4_addresses' => [], 'v6_addresses' => []];
            } elseif ($current !== null) {
                if (preg_match('/^\s+link\/ether\s+([\da-f:]+)/i', $line, $m)) {
                    $current['mac'] = $m[1];
                } elseif (preg_match('/^\s+inet\s+([\d.]+\/\d+)/', $line, $m)) {
                    $current['v4_addresses'][] = $m[1];
                } elseif (preg_match('/^\s+inet6\s+([0-9a-f:]+\/\d+)/i', $line, $m)) {
                    if (strpos(strtolower($m[1]), 'fe80') !== 0) {
                        $current['v6_addresses'][] = $m[1];
                    }
                }
            }
        }

        if ($current !== null) {
            $interfaces[] = $current;
        }

        return $interfaces;
    }

    /**
     * Apply a stored interface config (static or dhcp) to the host via the dhcp container.
     * For static: flushes the interface then sets addresses and routes.
     * For dhcp:   removes any static addresses we previously set (host DHCP client handles the rest).
     */
    public function applyInterfaceConfig(string $iface, array $cfg): array {
        $errors = [];
        $mode   = $cfg['mode'] ?? 'dhcp';
        $dev    = escapeshellarg($iface);

        if ($mode === 'static') {
            // Remove all current addresses so we start clean
            shell_exec("docker exec lightbox-dhcp ip addr flush dev $dev 2>/dev/null");

            $v4 = trim($cfg['v4_address'] ?? '');
            if (!empty($v4)) {
                $out = trim(shell_exec(sprintf(
                    "docker exec lightbox-dhcp ip addr add %s dev $dev 2>&1",
                    escapeshellarg($v4)
                )) ?? '');
                if (!empty($out)) $errors[] = "IPv4 addr: $out";
            }

            $gw4 = trim($cfg['v4_gateway'] ?? '');
            if (!empty($gw4)) {
                // replace is atomic: creates if missing, updates if present
                shell_exec("docker exec lightbox-dhcp ip route del default 2>/dev/null");
                $out = trim(shell_exec(sprintf(
                    "docker exec lightbox-dhcp ip route add default via %s dev $dev 2>&1",
                    escapeshellarg($gw4)
                )) ?? '');
                if (!empty($out) && strpos($out, 'File exists') === false) $errors[] = "IPv4 gw: $out";
            }

            $v6 = trim($cfg['v6_address'] ?? '');
            if (!empty($v6)) {
                $out = trim(shell_exec(sprintf(
                    "docker exec lightbox-dhcp ip -6 addr add %s dev $dev 2>&1",
                    escapeshellarg($v6)
                )) ?? '');
                if (!empty($out)) $errors[] = "IPv6 addr: $out";
            }

            $gw6 = trim($cfg['v6_gateway'] ?? '');
            if (!empty($gw6)) {
                shell_exec("docker exec lightbox-dhcp ip -6 route del default 2>/dev/null");
                $out = trim(shell_exec(sprintf(
                    "docker exec lightbox-dhcp ip -6 route add default via %s dev $dev 2>&1",
                    escapeshellarg($gw6)
                )) ?? '');
                if (!empty($out) && strpos($out, 'File exists') === false) $errors[] = "IPv6 gw: $out";
            }
        }
        // DHCP mode: we do not touch the interface; the host DHCP client manages it.

        return empty($errors)
            ? ['ok' => true]
            : ['ok' => false, 'error' => implode('; ', $errors)];
    }

    /**
     * Check internet connectivity per interface by looking for a default route
     * assigned specifically to that interface. Returns a map of name => bool.
     */
    public function checkInterfacesConnectivity(array $ifaceNames): array {
        $results = [];
        foreach ($ifaceNames as $name) {
            // "ip route show dev <iface>" lists routes assigned to that interface.
            // If a "default" route appears, it has a gateway to the wider internet.
            $cmd    = sprintf("docker exec lightbox-dhcp ip route show dev %s 2>/dev/null", escapeshellarg($name));
            $output = shell_exec($cmd) ?? '';
            $results[$name] = strpos($output, 'default') !== false;
        }
        return $results;
    }

    /**
     * Re-apply all stored static interface configs (called from apply_changes).
     * DHCP interfaces are skipped — the host DHCP client handles them.
     */
    public function reapplyAllInterfaceConfigs(array $configs): void {
        foreach ($configs as $cfg) {
            if (($cfg['mode'] ?? 'dhcp') === 'static') {
                $this->applyInterfaceConfig($cfg['interface_name'], $cfg);
            }
        }
    }

    /**
     * Get all running container names in a single docker call.
     * Falls back to empty array if docker is unavailable or times out.
     */
    public function getRunningContainerNames(): array {
        $output = shell_exec('timeout 2 docker ps --format ' . escapeshellarg('{{.Names}}'));
        if (!$output) return [];
        return array_values(array_filter(array_map('trim', explode("\n", trim($output)))));
    }

    /**
     * Check if a docker container is running
     */
    public function isContainerRunning($containerName) {
        return in_array($containerName, $this->getRunningContainerNames(), true);
    }

    /**
     * Restart a docker container
     */
    public function restartContainer($containerName) {
        $command = sprintf("docker restart %s 2>&1", escapeshellarg($containerName));
        $output = shell_exec($command) ?? '';
        return strpos($output, $containerName) !== false;
    }

    public function startContainer($containerName) {
        $command = sprintf("docker start %s 2>&1", escapeshellarg($containerName));
        $output = shell_exec($command) ?? '';
        return strpos($output, $containerName) !== false;
    }

    public function stopContainer($containerName) {
        $command = sprintf("docker stop %s 2>&1", escapeshellarg($containerName));
        $output = shell_exec($command) ?? '';
        return strpos($output, $containerName) !== false;
    }

    /**
     * Reload service config inside container if supported, else restart container
     */
    /**
     * Reload/restart a service. Returns ['ok' => bool, 'output' => string].
     */
    public function reloadService($serviceName) {
        switch ($serviceName) {
            case 'bind9':
                exec("docker exec lightbox-bind9 rndc reload 2>&1", $out, $exitCode);
                return ['ok' => $exitCode === 0, 'output' => implode("\n", $out)];

            case 'samba':
                $ok = $this->restartContainer('lightbox-samba');
                return ['ok' => $ok, 'output' => $ok ? '' : 'docker restart returned unexpected output'];

            case 'dhcp':
                $ok = $this->restartContainer('lightbox-dhcp');
                return ['ok' => $ok, 'output' => $ok ? '' : 'docker restart returned unexpected output'];

            case 'ntp':
                $ok = $this->restartContainer('lightbox-ntp');
                return ['ok' => $ok, 'output' => $ok ? '' : 'docker restart returned unexpected output'];

            case 'syslog':
                $ok = $this->restartContainer('lightbox-syslog');
                return ['ok' => $ok, 'output' => $ok ? '' : 'docker restart returned unexpected output'];

            default:
                return ['ok' => false, 'output' => 'unknown service'];
        }
    }

    /**
     * Get docker logs for a specific service container
     */
    public function getServiceLogs($serviceName, $lines = 100) {
        $containerName = '';
        switch ($serviceName) {
            case 'web': $containerName = 'lightbox-web'; break;
            case 'bind9': $containerName = 'lightbox-bind9'; break;
            case 'dhcp': $containerName = 'lightbox-dhcp'; break;
            case 'ntp': $containerName = 'lightbox-ntp'; break;
            case 'samba': $containerName = 'lightbox-samba'; break;
            case 'syslog': $containerName = 'lightbox-syslog'; break;
            default: return 'Invalid service';
        }

        $command = sprintf("docker logs --tail %d %s 2>&1", (int)$lines, escapeshellarg($containerName));
        $output = shell_exec($command);
        return $output ?: 'No log output found.';
    }
}
