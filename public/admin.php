<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AdRegister\AdminAuth;
use AdRegister\Csrf;
use AdRegister\Database;
use AdRegister\InviteService;
use AdRegister\ProvisionerFactory;
use AdRegister\RegistrationRepository;

function admin_set_flash(string $type, string $message): void
{
    $_SESSION['_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_pull_flash(): ?array
{
    $flash = $_SESSION['_admin_flash'] ?? null;
    unset($_SESSION['_admin_flash']);

    return is_array($flash) ? $flash : null;
}

function admin_redirect(string $tab = 'invites'): void
{
    header('Location: /admin.php?tab=' . rawurlencode($tab));
    exit;
}

function admin_datetime_local(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function admin_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function admin_substr(string $value, int $start, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function admin_invite_status(array $invite): array
{
    if ((int)$invite['active'] !== 1 || !empty($invite['deleted_at'])) {
        return ['已删除', 'muted'];
    }

    if (!empty($invite['expires_at']) && strtotime((string)$invite['expires_at']) < time()) {
        return ['已过期', 'warning'];
    }

    if ($invite['max_uses'] !== null && $invite['max_uses'] !== '' && (int)$invite['used_count'] >= (int)$invite['max_uses']) {
        return ['已用完', 'warning'];
    }

    return ['可使用', 'ok'];
}

function admin_short_ua(string $ua): string
{
    $ua = trim($ua);
    if ($ua === '') {
        return '-';
    }

    return admin_strlen($ua) > 90 ? admin_substr($ua, 0, 90) . '…' : $ua;
}

$config = app_config();
$database = new Database($config);
$inviteService = new InviteService($database);
$registrationRepository = new RegistrationRepository($database);
$auth = new AdminAuth($config);

$tabs = ['invites', 'users', 'logs'];
$tab = (string)($_GET['tab'] ?? 'invites');
if (!in_array($tab, $tabs, true)) {
    $tab = 'invites';
}

$loginError = '';
$csrfError = '页面已过期，请刷新后重试。';
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$clientUa = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'login') {
        if (!Csrf::verify($_POST['_csrf_token'] ?? null)) {
            $loginError = $csrfError;
        } elseif (!$auth->login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            $loginError = '用户名或密码不正确。';
        } else {
            $registrationRepository->logEvent([
                'event_type' => 'admin_login',
                'username' => $auth->username(),
                'success' => true,
                'message' => '管理员登录后台。',
                'ip' => $clientIp,
                'user_agent' => $clientUa,
            ]);
            admin_set_flash('success', '已登录后台。');
            admin_redirect('invites');
        }
    } elseif ($action === 'logout') {
        if (Csrf::verify($_POST['_csrf_token'] ?? null)) {
            $registrationRepository->logEvent([
                'event_type' => 'admin_logout',
                'username' => $auth->username(),
                'success' => true,
                'message' => '管理员退出后台。',
                'ip' => $clientIp,
                'user_agent' => $clientUa,
            ]);
            $auth->logout();
        }
        admin_set_flash('success', '已退出后台。');
        admin_redirect('invites');
    } elseif (!$auth->isLoggedIn()) {
        admin_set_flash('error', '请先登录后台。');
        admin_redirect('invites');
    } elseif (!Csrf::verify($_POST['_csrf_token'] ?? null)) {
        admin_set_flash('error', $csrfError);
        admin_redirect($tab);
    } elseif ($action === 'create_invite') {
        $errors = [];
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $code = str_replace([" ", "\t", "\r", "\n"], '', $code);
        if ($code === '') {
            $code = InviteService::generateCode();
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{3,63}$/', $code)) {
            $errors[] = '邀请码只能包含大写字母、数字和短横线，长度 4-64 位，且不能以短横线开头。';
        }

        $note = trim((string)($_POST['note'] ?? ''));
        if (admin_strlen($note) > 120) {
            $errors[] = '备注不能超过 120 个字符。';
        }

        $maxUsesRaw = trim((string)($_POST['max_uses'] ?? ''));
        $maxUses = null;
        if ($maxUsesRaw !== '') {
            if (!preg_match('/^\d+$/', $maxUsesRaw) || (int)$maxUsesRaw < 1 || (int)$maxUsesRaw > 100000) {
                $errors[] = '使用次数必须为 1-100000 之间的整数；留空表示不限次数。';
            } else {
                $maxUses = (int)$maxUsesRaw;
            }
        }

        $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
        $expiresAt = null;
        if ($expiresRaw !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $expiresRaw);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $hasDateError = is_array($dateErrors) && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0);
            if ($dt === false || $hasDateError) {
                $errors[] = '过期时间格式不正确。';
            } elseif ($dt->getTimestamp() <= time()) {
                $errors[] = '过期时间必须晚于当前时间。';
            } else {
                $expiresAt = $dt->format('Y-m-d H:i:s');
            }
        }

        if ($errors !== []) {
            admin_set_flash('error', implode('；', $errors));
            admin_redirect('invites');
        }

        try {
            $inviteService->create($code, $note, $maxUses, $expiresAt, $auth->username());
            $registrationRepository->logEvent([
                'event_type' => 'invite_created',
                'username' => $auth->username(),
                'invite_code' => $code,
                'success' => true,
                'message' => '管理员创建邀请码。',
                'ip' => $clientIp,
                'user_agent' => $clientUa,
            ]);
            admin_set_flash('success', '邀请码已创建：' . $code);
        } catch (\PDOException $e) {
            admin_set_flash('error', '邀请码创建失败，可能已经存在。');
        }
        admin_redirect('invites');
    } elseif ($action === 'delete_invite') {
        $id = (int)($_POST['invite_id'] ?? 0);
        if ($id <= 0) {
            admin_set_flash('error', '邀请码编号无效。');
            admin_redirect('invites');
        }

        $inviteService->delete($id);
        $registrationRepository->logEvent([
            'event_type' => 'invite_deleted',
            'username' => $auth->username(),
            'success' => true,
            'message' => '管理员删除邀请码 #' . $id . '。',
            'ip' => $clientIp,
            'user_agent' => $clientUa,
        ]);
        admin_set_flash('success', '邀请码已删除。');
        admin_redirect('invites');
    } elseif ($action === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        $user = $id > 0 ? $registrationRepository->findUser($id) : null;
        if ($user === null) {
            admin_set_flash('error', '未找到要删除的系统注册用户。');
            admin_redirect('users');
        }

        if ((string)$user['status'] !== 'active') {
            admin_set_flash('error', '该用户已经不是活动状态。');
            admin_redirect('users');
        }

        try {
            ProvisionerFactory::make($config)->deleteUser((string)$user['username'], (string)$user['user_dn']);
            $registrationRepository->markUserDeleted($id, $auth->username());
            $registrationRepository->logEvent([
                'event_type' => 'user_deleted',
                'username' => (string)$user['username'],
                'display_name' => (string)$user['display_name'],
                'email' => (string)$user['email'],
                'invite_code' => (string)$user['invite_code'],
                'success' => true,
                'message' => '管理员删除系统注册用户。',
                'ip' => $clientIp,
                'user_agent' => $clientUa,
            ]);
            admin_set_flash('success', '已删除用户：' . (string)$user['username']);
        } catch (Throwable $e) {
            $registrationRepository->markUserDeleteFailed($id, $e->getMessage());
            $registrationRepository->logEvent([
                'event_type' => 'user_delete_failed',
                'username' => (string)$user['username'],
                'display_name' => (string)$user['display_name'],
                'email' => (string)$user['email'],
                'invite_code' => (string)$user['invite_code'],
                'success' => false,
                'message' => $e->getMessage(),
                'ip' => $clientIp,
                'user_agent' => $clientUa,
            ]);
            admin_set_flash('error', '删除失败：' . $e->getMessage());
        }
        admin_redirect('users');
    }
}

$token = Csrf::token();
$flash = admin_pull_flash();
$adminConfigured = $auth->ensureConfigured();
$isLoggedIn = $auth->isLoggedIn();

$invites = $isLoggedIn ? $inviteService->list(200) : [];
$users = $isLoggedIn ? $registrationRepository->listUsers(200) : [];
$events = $isLoggedIn ? $registrationRepository->listEvents(300) : [];
$activeInviteCount = 0;
foreach ($invites as $invite) {
    if (admin_invite_status($invite)[0] === '可使用') {
        $activeInviteCount++;
    }
}
$activeUserCount = 0;
foreach ($users as $user) {
    if ((string)$user['status'] === 'active') {
        $activeUserCount++;
    }
}
$successEventCount = 0;
foreach ($events as $event) {
    if (!empty($event['success'])) {
        $successEventCount++;
    }
}

$cssFile = __DIR__ . '/assets/admin.css';
$cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : (string)time();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理后台 · 云桌面开通平台</title>
    <link rel="stylesheet" href="/assets/admin.css?v=<?= e($cssVersion) ?>">
</head>
<body>
<div class="admin-bg admin-bg-a"></div>
<div class="admin-bg admin-bg-b"></div>

<main class="admin-shell">
    <header class="topbar">
        <div>
            <div class="eyebrow">Admin Console</div>
            <h1>云桌面开通后台</h1>
            <p>管理邀请码、查看注册日志，并删除由本系统创建的 AD 用户。</p>
        </div>
        <div class="top-actions">
            <a class="ghost-link" href="/">返回开通页</a>
            <?php if ($isLoggedIn): ?>
                <form method="post">
                    <input type="hidden" name="_csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="ghost-link button-link" type="submit">退出登录</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$adminConfigured): ?>
        <section class="auth-card">
            <div class="status-dot warning"></div>
            <h2>后台尚未配置密码</h2>
            <p>请在服务器项目根目录的 <code>.env</code> 中配置 <code>ADMIN_USERNAME</code> 与 <code>ADMIN_PASSWORD_HASH</code>，然后刷新本页。</p>
            <pre>php -r "echo password_hash('你的后台密码', PASSWORD_DEFAULT), PHP_EOL;"</pre>
        </section>
    <?php elseif (!$isLoggedIn): ?>
        <section class="auth-card login-card">
            <div class="status-dot ok"></div>
            <h2>登录管理后台</h2>
            <p>登录后可以创建邀请码、查看注册记录和删除系统创建的域用户。</p>
            <?php if ($loginError !== ''): ?>
                <div class="notice error"><?= e($loginError) ?></div>
            <?php endif; ?>
            <?php if ($flash !== null): ?>
                <div class="notice <?= e((string)$flash['type']) ?>"><?= e((string)$flash['message']) ?></div>
            <?php endif; ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="_csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="login">
                <label>
                    <span>管理员账号</span>
                    <input type="text" name="username" value="<?= e($auth->username()) ?>" autocomplete="username" required>
                </label>
                <label>
                    <span>管理员密码</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="primary-button" type="submit">进入后台</button>
            </form>
        </section>
    <?php else: ?>
        <?php if ($flash !== null): ?>
            <div class="notice <?= e((string)$flash['type']) ?>"><?= e((string)$flash['message']) ?></div>
        <?php endif; ?>

        <section class="metric-grid">
            <article>
                <span>可用邀请码</span>
                <strong><?= e((string)$activeInviteCount) ?></strong>
                <small><?= InviteService::usesManagedInvites($config) ? '注册页已启用后台邀请码' : '注册页当前未强制后台邀请码' ?></small>
            </article>
            <article>
                <span>系统注册用户</span>
                <strong><?= e((string)$activeUserCount) ?></strong>
                <small>仅统计未删除状态</small>
            </article>
            <article>
                <span>日志事件</span>
                <strong><?= e((string)count($events)) ?></strong>
                <small><?= e((string)$successEventCount) ?> 条成功事件</small>
            </article>
        </section>

        <nav class="tabs" aria-label="后台功能">
            <a class="<?= $tab === 'invites' ? 'active' : '' ?>" href="/admin.php?tab=invites">邀请码</a>
            <a class="<?= $tab === 'users' ? 'active' : '' ?>" href="/admin.php?tab=users">注册用户</a>
            <a class="<?= $tab === 'logs' ? 'active' : '' ?>" href="/admin.php?tab=logs">注册日志</a>
        </nav>

        <?php if ($tab === 'invites'): ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>创建邀请码</h2>
                        <p>可设置过期时间和最大使用次数；邀请码留空时系统会自动生成。</p>
                    </div>
                </div>
                <form method="post" class="invite-form">
                    <input type="hidden" name="_csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="action" value="create_invite">
                    <label>
                        <span>邀请码（可留空）</span>
                        <input type="text" name="code" placeholder="例如 VDI-2026-A1">
                    </label>
                    <label>
                        <span>有效期</span>
                        <input type="datetime-local" name="expires_at" value="<?= e(admin_datetime_local('+7 days')) ?>">
                    </label>
                    <label>
                        <span>使用次数</span>
                        <input type="number" min="1" max="100000" name="max_uses" placeholder="留空表示不限">
                    </label>
                    <label class="wide">
                        <span>备注</span>
                        <input type="text" name="note" placeholder="例如：4 月新员工入职">
                    </label>
                    <button class="primary-button" type="submit">创建邀请码</button>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>邀请码列表</h2>
                        <p>删除后立即失效；已经消耗的使用次数会保留用于审计。</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>状态</th>
                            <th>邀请码</th>
                            <th>使用次数</th>
                            <th>有效期</th>
                            <th>备注</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($invites === []): ?>
                            <tr><td colspan="7" class="empty">还没有邀请码，请先创建一个。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($invites as $invite): ?>
                            <?php [$statusLabel, $statusClass] = admin_invite_status($invite); ?>
                            <tr>
                                <td><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                <td><code class="code-pill"><?= e($invite['code']) ?></code></td>
                                <td><?= e((string)$invite['used_count']) ?> / <?= $invite['max_uses'] === null || $invite['max_uses'] === '' ? '不限' : e((string)$invite['max_uses']) ?></td>
                                <td><?= $invite['expires_at'] ? e($invite['expires_at']) : '不限' ?></td>
                                <td><?= e($invite['note']) ?></td>
                                <td><?= e($invite['created_at']) ?></td>
                                <td>
                                    <?php if ((int)$invite['active'] === 1 && empty($invite['deleted_at'])): ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('确认删除这个邀请码？');">
                                            <input type="hidden" name="_csrf_token" value="<?= e($token) ?>">
                                            <input type="hidden" name="action" value="delete_invite">
                                            <input type="hidden" name="invite_id" value="<?= e((string)$invite['id']) ?>">
                                            <button class="danger-button" type="submit">删除</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">已删除</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($tab === 'users'): ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>系统注册用户</h2>
                        <p>这里只列出由本平台记录的用户。删除会同时调用当前 AD 后端删除域用户，并把本地记录标记为已删除。</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>状态</th>
                            <th>工号</th>
                            <th>姓名</th>
                            <th>域登录名</th>
                            <th>邮箱</th>
                            <th>邀请码</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($users === []): ?>
                            <tr><td colspan="8" class="empty">还没有系统注册用户。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <span class="badge <?= (string)$user['status'] === 'active' ? 'ok' : 'muted' ?>">
                                        <?= (string)$user['status'] === 'active' ? '活动' : '已删除' ?>
                                    </span>
                                    <?php if (!empty($user['delete_error'])): ?>
                                        <small class="row-error"><?= e($user['delete_error']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= e($user['username']) ?></strong></td>
                                <td><?= e($user['display_name']) ?></td>
                                <td><?= e($user['upn']) ?></td>
                                <td><?= e($user['email'] ?: '-') ?></td>
                                <td><?= $user['invite_code'] ? '<code class="code-pill">' . e($user['invite_code']) . '</code>' : '-' ?></td>
                                <td><?= e($user['created_at']) ?></td>
                                <td>
                                    <?php if ((string)$user['status'] === 'active'): ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('确认删除工号 <?= e($user['username']) ?>？该操作会删除 AD 域用户。');">
                                            <input type="hidden" name="_csrf_token" value="<?= e($token) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                            <button class="danger-button" type="submit">删除用户</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">已删除</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>注册日志</h2>
                        <p>记录注册成功、失败、邀请码拒绝、后台登录与删除操作。</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>时间</th>
                            <th>结果</th>
                            <th>事件</th>
                            <th>工号/管理员</th>
                            <th>邀请码</th>
                            <th>消息</th>
                            <th>来源</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($events === []): ?>
                            <tr><td colspan="7" class="empty">暂无日志。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= e($event['created_at']) ?></td>
                                <td><span class="badge <?= !empty($event['success']) ? 'ok' : 'warning' ?>"><?= !empty($event['success']) ? '成功' : '失败' ?></span></td>
                                <td><code><?= e($event['event_type']) ?></code></td>
                                <td><?= e($event['username'] ?: '-') ?></td>
                                <td><?= $event['invite_code'] ? '<code class="code-pill">' . e($event['invite_code']) . '</code>' : '-' ?></td>
                                <td>
                                    <?= e($event['message']) ?>
                                    <?php if (!empty($event['ref'])): ?>
                                        <small class="muted">编号：<?= e($event['ref']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($event['ip'] ?: '-') ?></strong>
                                    <small class="muted" title="<?= e($event['user_agent']) ?>"><?= e(admin_short_ua((string)$event['user_agent'])) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
