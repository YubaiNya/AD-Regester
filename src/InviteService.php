<?php

declare(strict_types=1);

namespace AdRegister;

use PDO;

final class InviteService
{
    private PDO $pdo;

    public function __construct(Database $database)
    {
        $this->pdo = $database->pdo();
    }

    public static function usesManagedInvites(array $config): bool
    {
        return (bool)($config['registration']['invite_required'] ?? true);
    }

    public static function isRequired(array $config): bool
    {
        return self::usesManagedInvites($config)
            || (string)($config['registration']['invite_code'] ?? '') !== '';
    }

    public function create(string $code, string $note, ?int $maxUses, ?string $expiresAt, string $createdBy): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invites (code, note, max_uses, expires_at, active, created_by, created_at)
             VALUES (:code, :note, :max_uses, :expires_at, 1, :created_by, :created_at)'
        );
        $stmt->execute([
            ':code' => $code,
            ':note' => $note,
            ':max_uses' => $maxUses,
            ':expires_at' => $expiresAt,
            ':created_by' => $createdBy,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE invites SET active = 0, deleted_at = :deleted_at WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':deleted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function list(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invites ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function consume(string $code, ?string &$error = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            $error = '请输入邀请码。';
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM invites WHERE code = :code AND active = 1 AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':code' => $code]);
            $invite = $stmt->fetch();
            if (!$invite) {
                $this->pdo->rollBack();
                $error = '邀请码不存在或已失效。';
                return null;
            }

            if (!empty($invite['expires_at']) && strtotime((string)$invite['expires_at']) < time()) {
                $this->pdo->rollBack();
                $error = '邀请码已过期。';
                return null;
            }

            if ($invite['max_uses'] !== null && $invite['max_uses'] !== '' && (int)$invite['used_count'] >= (int)$invite['max_uses']) {
                $this->pdo->rollBack();
                $error = '邀请码使用次数已用完。';
                return null;
            }

            $update = $this->pdo->prepare('UPDATE invites SET used_count = used_count + 1 WHERE id = :id');
            $update->execute([':id' => (int)$invite['id']]);
            $this->pdo->commit();

            $invite['used_count'] = (int)$invite['used_count'] + 1;
            return $invite;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function releaseUsage(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE invites SET used_count = CASE WHEN used_count > 0 THEN used_count - 1 ELSE 0 END WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bytes = random_bytes(10);
        $out = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        }

        return substr($out, 0, 5) . '-' . substr($out, 5, 5);
    }
}
