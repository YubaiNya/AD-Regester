<?php

declare(strict_types=1);

namespace AdRegister;

final class SambaAdService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function createUser(array $data): array
    {
        $username = strtolower(trim((string)$data['username']));
        $displayName = trim((string)($data['display_name'] ?? $username));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)$data['password'];
        $upn = $username . '@' . $this->config['ad']['upn_suffix'];

        if ($this->userExists($username)) {
            throw new DuplicateUserException('AD user already exists: ' . $username);
        }

        $created = false;
        try {
            $this->runNet(['rpc', 'user', 'add', $username, $password]);
            $created = true;
            $this->runNet(['rpc', 'group', 'addmem', (string)$this->config['ad']['group_cn'], $username]);
            $this->postUpdateLdap($username, $upn, $displayName, $email);
        } catch (\Throwable $e) {
            if ($created) {
                try {
                    $this->runNet(['rpc', 'user', 'delete', $username]);
                } catch (\Throwable $rollbackError) {
                    Logger::error('Failed to rollback Samba-created AD user', [
                        'username' => $username,
                        'rollback_error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            throw $e;
        }

        Logger::info('AD user registered via Samba RPC', [
            'username' => $username,
            'upn' => $upn,
            'group' => $this->config['ad']['group_cn'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);

        return [
            'username' => $username,
            'upn' => $upn,
            'user_dn' => '',
            'group_dn' => (string)$this->config['ad']['group_cn'],
            'group_name' => (string)$this->config['ad']['group_cn'],
            'backend' => 'samba',
        ];
    }

    public function deleteUser(string $username, string $userDn = ''): void
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            throw new AdException('删除用户失败：用户名为空。');
        }

        $result = $this->runNet(['rpc', 'user', 'delete', $username], false);
        if ($result['code'] !== 0) {
            $text = strtolower($result['stdout'] . "\n" . $result['stderr']);
            if (strpos($text, 'no such user') !== false
                || strpos($text, 'not found') !== false
                || strpos($text, 'nt_status_no_such_user') !== false
                || strpos($text, 'werr_user_not_found') !== false) {
                Logger::warning('Samba RPC delete user treated as already absent', ['username' => $username]);
                return;
            }
            throw new AdException('Samba RPC 删除用户失败：' . trim($result['stderr'] . "\n" . $result['stdout']));
        }

        Logger::info('AD user deleted via Samba RPC', [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    }

    public function diagnose(): array
    {
        $info = $this->runNet(['rpc', 'info']);
        $members = $this->runNet(['rpc', 'group', 'members', (string)$this->config['ad']['group_cn']]);

        return [
            'backend' => 'samba',
            'host' => $this->config['ad']['host'],
            'net_path' => $this->config['ad']['samba']['net_path'],
            'samba_domain' => $this->config['ad']['samba']['domain'],
            'rpc_info' => trim($info['stdout']),
            'group' => $this->config['ad']['group_cn'],
            'group_members_query' => trim($members['stdout']) === '' ? 'OK (empty group or no stdout)' : 'OK',
        ];
    }

    private function userExists(string $username): bool
    {
        $result = $this->runNet(['rpc', 'user', 'info', $username], false);
        if ($result['code'] === 0) {
            return true;
        }

        $text = strtolower($result['stdout'] . "\n" . $result['stderr']);
        if (strpos($text, 'no such user') !== false
            || strpos($text, 'not found') !== false
            || strpos($text, 'not exist') !== false
            || strpos($text, 'nt_status_no_such_user') !== false
            || strpos($text, 'werr_user_not_found') !== false) {
            return false;
        }

        return false;
    }

    private function runNet(array $args, bool $throw = true): array
    {
        $samba = $this->config['ad']['samba'];
        $netPath = $this->resolveNetPath((string)$samba['net_path']);

        if ((string)$samba['user'] === '' || (string)$samba['password'] === '') {
            throw new AdException('AD_SAMBA_USER 或 AD_SAMBA_PASSWORD 未配置。');
        }

        $tmpDir = (string)$this->config['paths']['tmp_dir'];
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0770, true);
        }

        $authFile = tempnam($tmpDir, 'smb_auth_');
        if ($authFile === false) {
            throw new AdException('无法创建 Samba 临时认证文件。');
        }

        $auth = sprintf(
            "username = %s\npassword = %s\ndomain = %s\n",
            (string)$samba['user'],
            (string)$samba['password'],
            (string)$samba['domain']
        );
        file_put_contents($authFile, $auth, LOCK_EX);
        @chmod($authFile, 0600);

        $cmd = array_merge(
            [$netPath],
            $args,
            ['-S', (string)$this->config['ad']['host'], '-A', $authFile]
        );

        try {
            $result = $this->runProcess($cmd, (int)$samba['timeout']);
        } finally {
            @unlink($authFile);
        }

        if ($throw && $result['code'] !== 0) {
            throw new AdException('Samba RPC 命令失败：' . trim($result['stderr'] . "\n" . $result['stdout']));
        }

        return $result;
    }

    private function runProcess(array $cmd, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, APP_ROOT, [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => '/tmp',
        ]);
        if (!is_resource($process)) {
            throw new AdException('无法启动 Samba net 命令：' . (string)($cmd[0] ?? 'net'));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = time();
        $code = 1;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);
            if (!$status['running']) {
                $code = (int)$status['exitcode'];
                break;
            }

            if ($timeout > 0 && (time() - $started) > $timeout) {
                proc_terminate($process, 9);
                $code = 124;
                $stderr .= "\nCommand timed out.";
                break;
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'code' => $code,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    private function resolveNetPath(string $configuredPath): string
    {
        $candidates = [];

        $configuredPath = trim($configuredPath);
        if ($configuredPath !== '') {
            $candidates[] = $configuredPath;
        }

        foreach ([
            '/usr/bin/net',
            '/bin/net',
            '/usr/local/bin/net',
            '/usr/sbin/net',
            '/sbin/net',
            'net',
        ] as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === 'net') {
                return $candidate;
            }

            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'net';
    }

    private function postUpdateLdap(string $username, string $upn, string $displayName, string $email): void
    {
        if (!$this->config['ad']['samba']['ldap_post_update'] || !extension_loaded('ldap')) {
            return;
        }

        try {
            $conn = @ldap_connect('ldap://' . $this->config['ad']['host'] . ':389');
            if ($conn === false) {
                throw new AdException('无法初始化 LDAP post-update 连接。');
            }

            @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            if (!@ldap_bind($conn, (string)$this->config['ad']['bind_user'], (string)$this->config['ad']['bind_password'])) {
                throw new AdException('LDAP post-update bind failed: ' . @ldap_error($conn));
            }

            $filter = sprintf('(sAMAccountName=%s)', $this->escapeFilter($username));
            $search = @ldap_search($conn, $this->config['ad']['base_dn'], $filter, ['dn']);
            if ($search === false) {
                throw new AdException('LDAP post-update search failed: ' . @ldap_error($conn));
            }

            $entries = @ldap_get_entries($conn, $search);
            if (!is_array($entries) || (int)($entries['count'] ?? 0) < 1) {
                throw new AdException('LDAP post-update cannot find created user.');
            }

            $dn = (string)$entries[0]['dn'];
            $attrs = [
                'userPrincipalName' => $upn,
                'displayName' => $displayName,
                'description' => 'Created by AD registration portal at ' . date('Y-m-d H:i:s'),
            ];
            if ($email !== '') {
                $attrs['mail'] = $email;
            }

            if (!@ldap_mod_replace($conn, $dn, $attrs)) {
                throw new AdException('LDAP post-update modify failed: ' . @ldap_error($conn));
            }
        } catch (\Throwable $e) {
            Logger::warning('Samba RPC user created but LDAP post-update failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function escapeFilter(string $value): string
    {
        if (function_exists('ldap_escape') && defined('LDAP_ESCAPE_FILTER')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return strtr($value, [
            '\\' => '\\5c',
            '*' => '\\2a',
            '(' => '\\28',
            ')' => '\\29',
            "\x00" => '\\00',
        ]);
    }
}
