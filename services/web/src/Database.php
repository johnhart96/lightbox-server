<?php

namespace App;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $pdo;
    private $dbPath = '/var/www/html/data/db/lightbox.db';

    private function __construct() {
        // Ensure the directory exists
        if (!file_exists('/var/www/html/data/db')) {
            mkdir('/var/www/html/data/db', 0777, true);
        }

        try {
            $this->pdo = new PDO("sqlite:" . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->initializeSchema();
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getDbPath(): string {
        return $this->dbPath;
    }

    public function close(): void {
        $this->pdo = null;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private function initializeSchema() {
        // 1. Settings table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        // 2. DNS records table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS dns_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hostname TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            ip_type TEXT CHECK(ip_type IN ('A', 'AAAA')) NOT NULL,
            description TEXT
        )");

        // 3. DHCP settings table (single row)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS dhcp_settings (
            id INTEGER PRIMARY KEY,
            v4_enabled INTEGER DEFAULT 1,
            v4_subnet TEXT DEFAULT '192.168.1.0',
            v4_netmask TEXT DEFAULT '255.255.255.0',
            v4_gateway TEXT DEFAULT '192.168.1.1',
            v4_range_start TEXT DEFAULT '192.168.1.100',
            v4_range_end TEXT DEFAULT '192.168.1.200',
            v4_lease_time TEXT DEFAULT '12h',
            v6_enabled INTEGER DEFAULT 0,
            v6_prefix TEXT DEFAULT 'fd00::/64',
            v6_range_start TEXT DEFAULT 'fd00::100',
            v6_range_end TEXT DEFAULT 'fd00::200',
            v6_lease_time TEXT DEFAULT '12h'
        )");

        // 4. DHCP reservations
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS dhcp_reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hostname TEXT NOT NULL,
            mac_address TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            ip_type TEXT CHECK(ip_type IN ('IPv4', 'IPv6')) NOT NULL
        )");

        // 5. Samba shares
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS samba_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_name TEXT NOT NULL UNIQUE,
            share_path TEXT NOT NULL,
            writable INTEGER DEFAULT 1,
            guest_ok INTEGER DEFAULT 1,
            description TEXT,
            is_tftp INTEGER DEFAULT 0
        )");
        // Migrate existing installs — add is_tftp if absent
        try {
            $this->pdo->exec("ALTER TABLE samba_shares ADD COLUMN is_tftp INTEGER DEFAULT 0");
        } catch (\PDOException $e) {
            // Column already exists
        }

        // 6. Network interface addresses (legacy — superseded by interface_config)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS interface_addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            interface_name TEXT NOT NULL,
            address TEXT NOT NULL,
            ip_type TEXT CHECK(ip_type IN ('IPv4', 'IPv6')) NOT NULL DEFAULT 'IPv4',
            UNIQUE(interface_name, address)
        )");

        // 7. Per-interface IP configuration (DHCP vs static)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS interface_config (
            interface_name TEXT PRIMARY KEY,
            mode           TEXT NOT NULL DEFAULT 'dhcp',
            v4_address     TEXT NOT NULL DEFAULT '',
            v4_gateway     TEXT NOT NULL DEFAULT '',
            v6_address     TEXT NOT NULL DEFAULT '',
            v6_gateway     TEXT NOT NULL DEFAULT ''
        )");

        // 8. Local user accounts (Linux + Samba)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT UNIQUE NOT NULL,
            display_name  TEXT NOT NULL DEFAULT '',
            samba_enabled INTEGER NOT NULL DEFAULT 1,
            password_hash TEXT,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Migrate existing users table — add password_hash if absent
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN password_hash TEXT");
        } catch (\PDOException $e) {
            // Column already exists
        }

        // 9. System alerts (warnings and errors)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            type            TEXT CHECK(type IN ('error', 'warning', 'info')) NOT NULL DEFAULT 'warning',
            source          TEXT NOT NULL DEFAULT 'system',
            message         TEXT NOT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            acknowledged_at DATETIME,
            acknowledged_by TEXT
        )");

        // Seed default settings if empty
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM settings");
        if ($stmt->fetchColumn() == 0) {
            $defaultSettings = [
                'system_name' => 'Lightbox-Server',
                'domain_name' => 'lighting.local',
                'primary_dns' => '8.8.8.8',
                'secondary_dns' => '1.1.1.1',
                'ntp_servers' => '0.pool.ntp.org, 1.pool.ntp.org',
                'dhcp_interface' => '', // Empty defaults to listening on all interfaces
                'dns_interface'  => '', // Empty = auto-detect first available host IP
                'advertise_dns'    => '1', // Offer Lightbox as DNS server via DHCP
                'advertise_ntp'    => '0', // Offer Lightbox as NTP server via DHCP
                'advertise_syslog' => '0'  // Offer Lightbox as syslog server via DHCP (option 7)
            ];
            $insert = $this->pdo->prepare("INSERT INTO settings (key, value) VALUES (:key, :value)");
            foreach ($defaultSettings as $key => $value) {
                $insert->execute([':key' => $key, ':value' => $value]);
            }
        }

        // Seed default DHCP settings if empty
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM dhcp_settings");
        if ($stmt->fetchColumn() == 0) {
            $this->pdo->exec("INSERT INTO dhcp_settings (id) VALUES (1)");
        }

        // Seed default Samba share if empty
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM samba_shares");
        if ($stmt->fetchColumn() == 0) {
            $this->pdo->exec("INSERT INTO samba_shares (share_name, share_path, writable, guest_ok, description) 
                VALUES ('ShowFiles', '/shares/ShowFiles', 1, 1, 'Lightbox Shared Show Files')");
        }
    }

    // Helper functions for common database tasks
    public function getSettings() {
        $stmt = $this->pdo->query("SELECT key, value FROM settings");
        $results = $stmt->fetchAll();
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    public function updateSetting($key, $value) {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");
        return $stmt->execute([':key' => $key, ':value' => $value]);
    }

    public function getDhcpSettings() {
        $stmt = $this->pdo->query("SELECT * FROM dhcp_settings WHERE id = 1");
        return $stmt->fetch();
    }

    public function getInterfaceConfig(string $iface): array {
        $stmt = $this->pdo->prepare("SELECT * FROM interface_config WHERE interface_name = :iface");
        $stmt->execute([':iface' => $iface]);
        return $stmt->fetch() ?: [
            'interface_name' => $iface,
            'mode'       => 'dhcp',
            'v4_address' => '',
            'v4_gateway' => '',
            'v6_address' => '',
            'v6_gateway' => '',
        ];
    }

    public function getAllInterfaceConfigs(): array {
        $stmt = $this->pdo->query("SELECT * FROM interface_config");
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['interface_name']] = $row;
        }
        return $out;
    }

    public function saveInterfaceConfig(string $iface, string $mode, string $v4addr, string $v4gw, string $v6addr, string $v6gw): void {
        $stmt = $this->pdo->prepare(
            "INSERT OR REPLACE INTO interface_config
             (interface_name, mode, v4_address, v4_gateway, v6_address, v6_gateway)
             VALUES (:iface, :mode, :v4a, :v4g, :v6a, :v6g)"
        );
        $stmt->execute([
            ':iface' => $iface, ':mode' => $mode,
            ':v4a'   => $v4addr, ':v4g'  => $v4gw,
            ':v6a'   => $v6addr, ':v6g'  => $v6gw,
        ]);
    }

    public function addAlert(string $type, string $source, string $message): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO alerts (type, source, message) VALUES (:type, :source, :message)"
        );
        $stmt->execute([':type' => $type, ':source' => $source, ':message' => $message]);
    }

    public function updateDhcpSettings($data) {
        $sql = "UPDATE dhcp_settings SET 
            v4_enabled = :v4_enabled,
            v4_subnet = :v4_subnet,
            v4_netmask = :v4_netmask,
            v4_gateway = :v4_gateway,
            v4_range_start = :v4_range_start,
            v4_range_end = :v4_range_end,
            v4_lease_time = :v4_lease_time,
            v6_enabled = :v6_enabled,
            v6_prefix = :v6_prefix,
            v6_range_start = :v6_range_start,
            v6_range_end = :v6_range_end,
            v6_lease_time = :v6_lease_time
            WHERE id = 1";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }
}
