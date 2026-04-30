<?php

declare(strict_types=1);

namespace AdRegister;

use PDO;

final class RegistrationRepository
{
    private PDO $pdo;

    public function __construct(Database $database)
    {
        $this->pdo = $database->pdo();
    }

    public function recordUser(array $result, array $clean, string $ip, string $inviteCode): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registered_users
             (username, upn, display_name, email, backend, group_name, group_dn, user_dn, invite_code, created_ip, created_at, status)
             VALUES
             (:username, :upn, :display_name, :email, :backend, :group_name, :group_dn, :user_dn, :invite_code, :created_ip, :created_at, :status)
             ON CONFLICT(username) DO UPDATE SET
                upn = excluded.upn,
                display_name = excluded.display_name,
                email = excluded.email,
                backend = excluded.backend,
                group_name = excluded.group_name,
                group_dn = excluded.group_dn,
                user_dn = excluded.user_dn,
                invite_code = excluded.invite_code,
                created_ip = excluded.created_ip,
                created_at = excluded.created_at,
                status = excluded.status,
                deleted_at = NULL,
                deleted_by = "",
                delete_error = ""'
        );
        $stmt->execute([
            ':username' => (string)$result['username'],
            ':upn' => (string)($result['upn'] ?? ''),
            ':display_name' => (string)($clean['display_name'] ?? ''),
            ':email' => (string)($clean['email'] ?? ''),
            ':backend' => (string)($result['backend'] ?? ''),
            ':group_name' => (string)($result['group_name'] ?? 'VDI_Users'),
            ':group_dn' => (string)($result['group_dn'] ?? ''),
            ':user_dn' => (string)($result['user_dn'] ?? ''),
            ':invite_code' => $inviteCode,
            ':created_ip' => $ip,
            ':created_at' => date('Y-m-d H:i:s'),
            ':status' => 'active',
        ]);
    }

    public function listUsers(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registered_users ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findUser(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registered_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markUserDeleted(int $id, string $admin): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE registered_users SET status = "deleted", deleted_at = :deleted_at, deleted_by = :deleted_by, delete_error = "" WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':deleted_at' => date('Y-m-d H:i:s'),
            ':deleted_by' => $admin,
        ]);
    }

    public function markUserDeleteFailed(int $id, string $message): void
    {
        $stmt = $this->pdo->prepare('UPDATE registered_users SET delete_error = :delete_error WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':delete_error' => $message,
        ]);
    }

    public function logEvent(array $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_events
             (event_type, username, display_name, email, invite_code, success, message, ref, ip, user_agent, created_at)
             VALUES
             (:event_type, :username, :display_name, :email, :invite_code, :success, :message, :ref, :ip, :user_agent, :created_at)'
        );
        $stmt->execute([
            ':event_type' => (string)($event['event_type'] ?? ''),
            ':username' => (string)($event['username'] ?? ''),
            ':display_name' => (string)($event['display_name'] ?? ''),
            ':email' => (string)($event['email'] ?? ''),
            ':invite_code' => (string)($event['invite_code'] ?? ''),
            ':success' => !empty($event['success']) ? 1 : 0,
            ':message' => (string)($event['message'] ?? ''),
            ':ref' => (string)($event['ref'] ?? ''),
            ':ip' => (string)($event['ip'] ?? ''),
            ':user_agent' => (string)($event['user_agent'] ?? ''),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function listEvents(int $limit = 300): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_events ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
