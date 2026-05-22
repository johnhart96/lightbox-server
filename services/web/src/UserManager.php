<?php

namespace App;

class UserManager {
    private const SAMBA_CONTAINER = 'lightbox-samba';
    private const USERS_CONF = '/var/www/html/data/samba/linux_users.conf';

    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function listUsers(): array {
        $pdo = $this->db->getConnection();
        return $pdo->query("SELECT id, username, display_name, samba_enabled, created_at FROM users ORDER BY username ASC")->fetchAll();
    }

    public function saveUser(array $data): array {
        $id          = trim($data['id'] ?? '');
        $username    = trim($data['username'] ?? '');
        $displayName = trim($data['display_name'] ?? '');
        $sambaEnabled = isset($data['samba_enabled']) ? 1 : 0;
        $password    = $data['password'] ?? '';

        if (empty($username)) {
            throw new \Exception('Username is required.');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $username)) {
            throw new \Exception('Username must start with a lowercase letter and contain only lowercase letters, digits, hyphens, or underscores (max 32 chars).');
        }

        $pdo = $this->db->getConnection();
        $isNew = empty($id);

        if ($isNew) {
            if (empty($password)) {
                throw new \Exception('Password is required when creating a new user.');
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, display_name, samba_enabled, password_hash) VALUES (:u, :d, :s, :h)");
            $stmt->execute([':u' => $username, ':d' => $displayName, ':s' => $sambaEnabled, ':h' => $hash]);

            $this->createLinuxUser($username);
        } else {
            // Fetch current username in case it changed
            $current = $pdo->prepare("SELECT username FROM users WHERE id = :id");
            $current->execute([':id' => $id]);
            $row = $current->fetch();
            if (!$row) throw new \Exception('User not found.');
            $oldUsername = $row['username'];

            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET username = :u, display_name = :d, samba_enabled = :s, password_hash = :h WHERE id = :id");
                $stmt->execute([':u' => $username, ':d' => $displayName, ':s' => $sambaEnabled, ':h' => $hash, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = :u, display_name = :d, samba_enabled = :s WHERE id = :id");
                $stmt->execute([':u' => $username, ':d' => $displayName, ':s' => $sambaEnabled, ':id' => $id]);
            }

            if ($oldUsername !== $username) {
                $this->renameLinuxUser($oldUsername, $username);
            }
        }

        if (!empty($password)) {
            $this->setSambaPassword($username, $password);
        }

        // Keep samba enabled/disabled in sync
        if ($sambaEnabled) {
            $this->enableSambaUser($username);
        } else {
            $this->disableSambaUser($username);
        }

        $this->writeLinuxUsersConf();
        return ['ok' => true];
    }

    public function deleteUser(string $id): array {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('User not found.');

        $username = $row['username'];
        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);

        $this->removeSambaUser($username);
        $this->removeLinuxUser($username);
        $this->writeLinuxUsersConf();
        return ['ok' => true];
    }

    public function changePassword(string $id, string $password): array {
        if (empty($password)) throw new \Exception('Password cannot be empty.');
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('User not found.');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
            ->execute([':h' => $hash, ':id' => $id]);

        $this->setSambaPassword($row['username'], $password);
        return ['ok' => true];
    }

    public function verifyPassword(string $username, string $password): ?array {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT id, username, display_name, password_hash FROM users WHERE username = :u");
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();
        if (!$row || empty($row['password_hash'])) return null;
        if (!password_verify($password, $row['password_hash'])) return null;
        return ['id' => $row['id'], 'username' => $row['username'], 'display_name' => $row['display_name']];
    }

    public function hasUsers(): bool {
        return (int)$this->db->getConnection()->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
    }

    // --- Container operations ---

    private function exec(string $cmd): array {
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => implode("\n", $output)];
    }

    private function sambaExec(string $cmd): array {
        $container = escapeshellarg(self::SAMBA_CONTAINER);
        return $this->exec("docker exec $container sh -c " . escapeshellarg($cmd));
    }

    private function createLinuxUser(string $username): void {
        $u = escapeshellarg($username);
        // Alpine uses adduser; -D = no GECOS prompt, -H = no home dir
        $result = $this->sambaExec("adduser -D -H $u 2>&1 || true");
        // Ignore 'already exists' errors gracefully
    }

    private function renameLinuxUser(string $old, string $new): void {
        $o = escapeshellarg($old);
        $n = escapeshellarg($new);
        $this->sambaExec("adduser -D -H $n 2>&1 || true");
        // Remove old samba account and re-add under new name
        $this->sambaExec("smbpasswd -x $o 2>&1 || true");
    }

    private function removeLinuxUser(string $username): void {
        $u = escapeshellarg($username);
        $this->sambaExec("deluser $u 2>&1 || true");
    }

    private function setSambaPassword(string $username, string $password): void {
        // smbpasswd -a adds user if not present, -s reads from stdin
        $u = escapeshellarg($username);
        $p = escapeshellarg($password);
        $this->sambaExec("printf '%s\n%s\n' $p $p | smbpasswd -a -s $u 2>&1");
    }

    private function enableSambaUser(string $username): void {
        $u = escapeshellarg($username);
        $this->sambaExec("smbpasswd -e $u 2>&1 || true");
    }

    private function disableSambaUser(string $username): void {
        $u = escapeshellarg($username);
        $this->sambaExec("smbpasswd -d $u 2>&1 || true");
    }

    private function removeSambaUser(string $username): void {
        $u = escapeshellarg($username);
        $this->sambaExec("smbpasswd -x $u 2>&1 || true");
    }

    /**
     * Writes a conf file listing all users so the samba entrypoint can
     * recreate Linux accounts after a container restart.
     * Format: one username per line.
     */
    private function writeLinuxUsersConf(): void {
        $users = $this->listUsers();
        $lines = array_map(fn($u) => $u['username'], $users);
        $dir = dirname(self::USERS_CONF);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(self::USERS_CONF, implode("\n", $lines) . "\n");
    }
}
