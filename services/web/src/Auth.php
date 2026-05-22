<?php

namespace App;

class Auth {
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => false, // set true if HTTPS is enforced
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public static function isLoggedIn(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function currentUser(): ?array {
        if (!self::isLoggedIn()) return null;
        return [
            'id'           => $_SESSION['user_id'],
            'username'     => $_SESSION['username'],
            'display_name' => $_SESSION['display_name'],
        ];
    }

    /**
     * Attempt login. Returns true on success.
     */
    public static function login(string $username, string $password, UserManager $userManager): bool {
        $user = $userManager->verifyPassword($username, $password);
        if ($user === null) return false;

        session_regenerate_id(true);
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        return true;
    }

    public static function logout(): void {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Redirect to login if not authenticated.
     * Skipped when no users exist yet (initial setup).
     */
    public static function requireLogin(UserManager $userManager): void {
        self::start();
        if ($userManager->hasUsers() && !self::isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * For API endpoints — return 401 JSON instead of redirecting.
     */
    public static function requireLoginApi(UserManager $userManager): void {
        self::start();
        if ($userManager->hasUsers() && !self::isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthenticated.', 'auth_required' => true]);
            exit;
        }
    }
}
