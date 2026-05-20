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
            'load' => sys_getloadavg(),
            'time' => date('Y-m-d H:i:s T')
        ];
    }

    private function getSystemUptime() {
        if (!file_exists('/proc/uptime')) {
            return 'Unknown';
        }
        
        $str = file_get_contents('/proc/uptime');
        $uptime_sec = floatval(explode(' ', $str)[0]);
        
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
        $paths = glob('/sys/class/net/*');
        
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
     * Check if a docker container is running
     */
    public function isContainerRunning($containerName) {
        $command = sprintf("docker ps --filter \"name=%s\" --format \"{{.Status}}\"", escapeshellarg($containerName));
        $output = shell_exec($command);
        if ($output && stripos(trim($output), 'Up') === 0) {
            // Further verify the container name matches exactly to avoid substring issues
            $nameCheck = shell_exec(sprintf("docker ps --filter \"name=^/%s$\" --format \"{{.Names}}\"", escapeshellarg($containerName)));
            return trim($nameCheck) === $containerName;
        }
        return false;
    }

    /**
     * Restart a docker container
     */
    public function restartContainer($containerName) {
        $command = sprintf("docker restart %s 2>&1", escapeshellarg($containerName));
        $output = shell_exec($command);
        return trim($output) === $containerName;
    }

    /**
     * Reload service config inside container if supported, else restart container
     */
    public function reloadService($serviceName) {
        switch ($serviceName) {
            case 'bind9':
                $output = shell_exec("docker exec lightbox-bind9 rndc reload 2>&1");
                return strpos($output, 'server reload successful') !== false || empty(trim($output));
            
            case 'samba':
                $output = shell_exec("docker exec lightbox-samba smbcontrol all reload-config 2>&1");
                return empty(trim($output));

            case 'dhcp':
                return $this->restartContainer('lightbox-dhcp');

            case 'ntp':
                return $this->restartContainer('lightbox-ntp');

            default:
                return false;
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
            default: return 'Invalid service';
        }

        $command = sprintf("docker logs --tail %d %s 2>&1", (int)$lines, escapeshellarg($containerName));
        $output = shell_exec($command);
        return $output ?: 'No log output found.';
    }
}
