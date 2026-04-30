<?php

declare(strict_types=1);

namespace AdRegister;

final class AdService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function createUser(array $data): array
    {
        $conn = $this->connect();

        $username = strtolower(trim((string)$data['username']));
        $displayName = trim((string)($data['display_name'] ?? $username));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)$data['password'];
        $upn = $username . '@' . $this->config['ad']['upn_suffix'];
        $userDn = 'CN=' . $this->escapeDn($username) . ',' . $this->config['ad']['user_base_dn'];

        if ($this->userExists($conn, $username, $upn)) {
            throw new DuplicateUserException('AD user already exists: ' . $username);
        }

        $groupDn = $this->resolveGroupDn($conn);

        $entry = [
            'objectClass' => ['top', 'person', 'organizationalPerson', 'user'],
            'cn' => $username,
            'sAMAccountName' => $username,
            'userPrincipalName' => $upn,
            'displayName' => $displayName,
            'description' => 'Created by AD registration portal at ' . date('Y-m-d H:i:s'),
            // 514 = NORMAL_ACCOUNT + ACCOUNTDISABLE；先创建禁用账号，设置密码/组后再启用
            'userAccountControl' => '514',
        ];

        if ($email !== '') {
            $entry['mail'] = $email;
        }

        if (!@ldap_add($conn, $userDn, $entry)) {
            $this->throwLastError($conn, '创建 AD 用户失败');
        }

        try {
            $this->setPassword($conn, $userDn, $password);
            $this->addUserToGroup($conn, $userDn, $groupDn);
            $this->finalizeAccount($conn, $userDn);
        } catch (\Throwable $e) {
            Logger::error('AD user post-create step failed; account remains disabled unless step had already enabled it.', [
                'username' => $username,
                'user_dn' => $userDn,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        Logger::info('AD user registered', [
            'username' => $username,
            'upn' => $upn,
            'user_dn' => $userDn,
            'group_dn' => $groupDn,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);

        return [
            'username' => $username,
            'upn' => $upn,
            'user_dn' => $userDn,
            'group_dn' => $groupDn,
            'group_name' => (string)$this->config['ad']['group_cn'],
            'backend' => 'ldap',
        ];
    }

    public function deleteUser(string $username, string $userDn = ''): void
    {
        $conn = $this->connect();
        $username = strtolower(trim($username));
        if ($username === '') {
            throw new AdException('删除用户失败：用户名为空。');
        }

        $dn = trim($userDn);
        if ($dn === '') {
            $upn = $username . '@' . $this->config['ad']['upn_suffix'];
            $filter = sprintf(
                '(|(sAMAccountName=%s)(userPrincipalName=%s))',
                $this->escapeFilter($username),
                $this->escapeFilter($upn)
            );
            $search = @ldap_search($conn, $this->config['ad']['base_dn'], $filter, ['dn']);
            if ($search === false) {
                $this->throwLastError($conn, '查询待删除 AD 用户失败');
            }
            $entries = @ldap_get_entries($conn, $search);
            if (!is_array($entries) || (int)($entries['count'] ?? 0) < 1) {
                Logger::warning('LDAP delete treated as already absent', ['username' => $username]);
                return;
            }
            $dn = (string)$entries[0]['dn'];
        }

        if (!@ldap_delete($conn, $dn)) {
            $errno = function_exists('ldap_errno') ? (int)@ldap_errno($conn) : 0;
            if ($errno === 32) {
                Logger::warning('LDAP delete DN treated as already absent', ['username' => $username, 'user_dn' => $dn]);
                return;
            }
            $this->throwLastError($conn, '删除 AD 用户失败');
        }

        Logger::info('AD user deleted via LDAP', [
            'username' => $username,
            'user_dn' => $dn,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    }

    public function diagnose(): array
    {
        $conn = $this->connect();
        $groupDn = $this->resolveGroupDn($conn);
        $baseExists = $this->dnExists($conn, $this->config['ad']['base_dn']);
        $userBaseExists = $this->dnExists($conn, $this->config['ad']['user_base_dn']);

        return [
            'host' => $this->config['ad']['host'],
            'port' => $this->config['ad']['port'],
            'ssl' => $this->config['ad']['use_ssl'] ? 'yes' : 'no',
            'base_dn' => $this->config['ad']['base_dn'],
            'base_dn_exists' => $baseExists ? 'yes' : 'no',
            'user_base_dn' => $this->config['ad']['user_base_dn'],
            'user_base_dn_exists' => $userBaseExists ? 'yes' : 'no',
            'group_dn' => $groupDn,
        ];
    }

    /**
     * @return resource|\LDAP\Connection
     */
    private function connect()
    {
        if (!extension_loaded('ldap')) {
            throw new AdException('当前 PHP 未安装/启用 ldap 扩展。');
        }

        $ad = $this->config['ad'];
        if ((string)$ad['bind_user'] === '' || (string)$ad['bind_password'] === '') {
            throw new AdException('AD_BIND_USER 或 AD_BIND_PASSWORD 未配置。');
        }

        if (!$ad['tls_verify']) {
            if (\function_exists('putenv')) {
                \putenv('LDAPTLS_REQCERT=never');
            }
            if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
            }
        }

        $scheme = $ad['use_ssl'] ? 'ldaps' : 'ldap';
        $uri = sprintf('%s://%s:%d', $scheme, $ad['host'], (int)$ad['port']);
        $conn = @ldap_connect($uri);
        if ($conn === false) {
            throw new AdException('无法初始化 LDAP 连接：' . $uri);
        }

        @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$ad['timeout']);
        }

        if (!$ad['use_ssl'] && $ad['start_tls']) {
            if (!@ldap_start_tls($conn)) {
                $this->throwLastError($conn, 'StartTLS 失败');
            }
        }

        if (!@ldap_bind($conn, (string)$ad['bind_user'], (string)$ad['bind_password'])) {
            $this->throwLastError($conn, 'LDAP 绑定失败');
        }

        return $conn;
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function userExists($conn, string $username, string $upn): bool
    {
        $filter = sprintf(
            '(|(sAMAccountName=%s)(userPrincipalName=%s))',
            $this->escapeFilter($username),
            $this->escapeFilter($upn)
        );
        $search = @ldap_search($conn, $this->config['ad']['base_dn'], $filter, ['dn']);
        if ($search === false) {
            $this->throwLastError($conn, '查询 AD 用户失败');
        }

        return @ldap_count_entries($conn, $search) > 0;
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function resolveGroupDn($conn): string
    {
        $configuredDn = trim((string)$this->config['ad']['group_dn']);
        if ($configuredDn !== '' && $this->dnExists($conn, $configuredDn)) {
            return $configuredDn;
        }

        $groupCn = (string)$this->config['ad']['group_cn'];
        $filter = sprintf('(&(objectClass=group)(cn=%s))', $this->escapeFilter($groupCn));
        $search = @ldap_search($conn, $this->config['ad']['base_dn'], $filter, ['dn']);
        if ($search === false) {
            $this->throwLastError($conn, '查询默认用户组失败');
        }

        $entries = @ldap_get_entries($conn, $search);
        if (!is_array($entries) || (int)($entries['count'] ?? 0) < 1) {
            throw new AdException('找不到默认用户组：' . $groupCn);
        }

        return (string)$entries[0]['dn'];
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function dnExists($conn, string $dn): bool
    {
        $read = @ldap_read($conn, $dn, '(objectClass=*)', ['dn']);
        if ($read === false) {
            return false;
        }

        return @ldap_count_entries($conn, $read) > 0;
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function setPassword($conn, string $userDn, string $password): void
    {
        $encoded = $this->encodeAdPassword($password);
        if (!@ldap_mod_replace($conn, $userDn, ['unicodePwd' => $encoded])) {
            $this->throwLastError($conn, '设置用户密码失败；请确认 LDAPS/StartTLS 可用且密码符合域策略');
        }
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function addUserToGroup($conn, string $userDn, string $groupDn): void
    {
        if (!@ldap_mod_add($conn, $groupDn, ['member' => $userDn])) {
            $errno = @ldap_errno($conn);
            if ($errno === 20) {
                return; // typeOrValueExists
            }
            $this->throwLastError($conn, '加入默认组失败');
        }
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function finalizeAccount($conn, string $userDn): void
    {
        $uac = 512; // NORMAL_ACCOUNT
        if (!$this->config['ad']['enable_account']) {
            $uac |= 2; // ACCOUNTDISABLE
        }
        if ($this->config['ad']['password_never_expires']) {
            $uac |= 65536; // DONT_EXPIRE_PASSWORD
        }

        if (!@ldap_mod_replace($conn, $userDn, ['userAccountControl' => (string)$uac])) {
            $this->throwLastError($conn, '启用/更新账号状态失败');
        }

        if ($this->config['ad']['change_password_at_next_logon']) {
            if (!@ldap_mod_replace($conn, $userDn, ['pwdLastSet' => '0'])) {
                $this->throwLastError($conn, '设置下次登录修改密码失败');
            }
        }
    }

    private function encodeAdPassword(string $password): string
    {
        $quoted = '"' . $password . '"';
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($quoted, 'UTF-16LE', 'UTF-8');
        }

        $encoded = @iconv('UTF-8', 'UTF-16LE', $quoted);
        if ($encoded === false) {
            throw new AdException('无法编码 AD 密码，请启用 mbstring 或 iconv 扩展。');
        }

        return $encoded;
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

    private function escapeDn(string $value): string
    {
        if (function_exists('ldap_escape') && defined('LDAP_ESCAPE_DN')) {
            return ldap_escape($value, '', LDAP_ESCAPE_DN);
        }

        $escaped = strtr($value, [
            '\\' => '\\5c',
            ',' => '\\2c',
            '+' => '\\2b',
            '"' => '\\22',
            '<' => '\\3c',
            '>' => '\\3e',
            ';' => '\\3b',
            '=' => '\\3d',
        ]);

        if ($escaped !== '' && ($escaped[0] === ' ' || $escaped[0] === '#')) {
            $escaped = '\\' . $escaped;
        }
        if ($escaped !== '' && substr($escaped, -1) === ' ') {
            $escaped = substr($escaped, 0, -1) . '\\ ';
        }

        return $escaped;
    }

    /**
     * @param resource|\LDAP\Connection $conn
     */
    private function throwLastError($conn, string $prefix): void
    {
        $errno = @ldap_errno($conn);
        $error = @ldap_error($conn);
        $diagnostic = '';
        if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
            @ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
        }

        $message = trim($prefix . sprintf(' [LDAP %s] %s %s', (string)$errno, (string)$error, (string)$diagnostic));
        throw new AdException($message);
    }
}
