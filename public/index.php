<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AdRegister\AdException;
use AdRegister\Csrf;
use AdRegister\Database;
use AdRegister\DuplicateUserException;
use AdRegister\InviteService;
use AdRegister\Logger;
use AdRegister\ProvisionerFactory;
use AdRegister\RateLimiter;
use AdRegister\RegistrationRepository;
use AdRegister\Validator;

$config = app_config();
$pageTitle = (string)($config['app']['name'] ?? 'Cloud Desktop Registration Portal');
$database = new Database($config);
$inviteService = new InviteService($database);
$registrationRepository = new RegistrationRepository($database);
$errors = [];
$success = null;
$consumedInvite = null;
$old = [
    'employee_id' => '',
    'display_name' => '',
    'email' => '',
    'invite_code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'employee_id' => trim((string)($_POST['employee_id'] ?? $_POST['username'] ?? '')),
        'display_name' => trim((string)($_POST['display_name'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
    ];

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $limiter = new RateLimiter($config['paths']['ratelimit_dir']);
    if (!$limiter->attempt('register:' . $ip, (int)$config['registration']['rate_limit_attempts'], (int)$config['registration']['rate_limit_window'])) {
        $errors['_global'] = '尝试次数过多，请稍后再试。';
    } elseif (!Csrf::verify($_POST['_csrf_token'] ?? null)) {
        $errors['_global'] = '页面已过期，请刷新后重试。';
    } else {
        [$errors, $clean] = Validator::registration($_POST, $config);
        if ($errors === []) {
            $inviteCode = trim((string)($_POST['invite_code'] ?? ''));
            if (InviteService::usesManagedInvites($config)) {
                $inviteError = null;
                $consumedInvite = $inviteService->consume($inviteCode, $inviteError);
                if ($consumedInvite === null) {
                    $errors['invite_code'] = $inviteError ?: '邀请码无效。';
                    $registrationRepository->logEvent([
                        'event_type' => 'invite_rejected',
                        'username' => $clean['username'] ?? $old['employee_id'],
                        'display_name' => $clean['display_name'] ?? $old['display_name'],
                        'email' => $clean['email'] ?? $old['email'],
                        'invite_code' => $inviteCode,
                        'success' => false,
                        'message' => $errors['invite_code'],
                        'ip' => $ip,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ]);
                }
            } elseif ((string)$config['registration']['invite_code'] !== '') {
                // 兼容旧版单一邀请码配置。
                $expected = (string)$config['registration']['invite_code'];
                if ($inviteCode === '' || !hash_equals($expected, $inviteCode)) {
                    $errors['invite_code'] = '邀请码不正确。';
                }
            }
        }

        if ($errors !== []) {
            $registrationRepository->logEvent([
                'event_type' => 'register_rejected',
                'username' => $old['employee_id'],
                'display_name' => $old['display_name'],
                'email' => $old['email'],
                'invite_code' => $old['invite_code'],
                'success' => false,
                'message' => implode('；', array_values($errors)),
                'ip' => $ip,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
        }

        if ($errors === []) {
            try {
                $result = ProvisionerFactory::make($config)->createUser($clean);
                $registrationRepository->recordUser($result, $clean, $ip, trim((string)($_POST['invite_code'] ?? '')));
                $registrationRepository->logEvent([
                    'event_type' => 'register_success',
                    'username' => $result['username'] ?? $clean['username'],
                    'display_name' => $clean['display_name'] ?? '',
                    'email' => $clean['email'] ?? '',
                    'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
                    'success' => true,
                    'message' => '云桌面开通成功，已加入 VDI_Users。',
                    'ip' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
                $success = $result;
                $old = [
                    'employee_id' => '',
                    'display_name' => '',
                    'email' => '',
                    'invite_code' => '',
                ];
                $_SESSION['_csrf_token'] = null;
            } catch (DuplicateUserException $e) {
                if (is_array($consumedInvite) && isset($consumedInvite['id'])) {
                    $inviteService->releaseUsage((int)$consumedInvite['id']);
                }
                $errors['employee_id'] = '该工号已存在，请检查后重试。';
                $registrationRepository->logEvent([
                    'event_type' => 'register_duplicate',
                    'username' => $clean['username'] ?? $old['employee_id'],
                    'display_name' => $clean['display_name'] ?? '',
                    'email' => $clean['email'] ?? '',
                    'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
                    'success' => false,
                    'message' => '该工号已存在。',
                    'ip' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
                Logger::warning('Duplicate AD registration attempt', [
                    'username' => $clean['username'] ?? $old['employee_id'],
                    'ip' => $ip,
                ]);
            } catch (AdException $e) {
                if (is_array($consumedInvite) && isset($consumedInvite['id'])) {
                    $inviteService->releaseUsage((int)$consumedInvite['id']);
                }
                $ref = strtoupper(substr(sha1(uniqid('', true)), 0, 8));
                $errors['_global'] = '注册失败，请联系管理员处理。错误编号：' . $ref;
                $registrationRepository->logEvent([
                    'event_type' => 'register_failed',
                    'username' => $clean['username'] ?? $old['employee_id'],
                    'display_name' => $clean['display_name'] ?? '',
                    'email' => $clean['email'] ?? '',
                    'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
                    'success' => false,
                    'message' => $e->getMessage(),
                    'ref' => $ref,
                    'ip' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
                Logger::error('AD registration failed', [
                    'ref' => $ref,
                    'username' => $clean['username'] ?? $old['employee_id'],
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                if (is_array($consumedInvite) && isset($consumedInvite['id'])) {
                    $inviteService->releaseUsage((int)$consumedInvite['id']);
                }
                $ref = strtoupper(substr(sha1(uniqid('', true)), 0, 8));
                $errors['_global'] = '系统异常，请联系管理员处理。错误编号：' . $ref;
                $registrationRepository->logEvent([
                    'event_type' => 'register_exception',
                    'username' => $clean['username'] ?? $old['employee_id'],
                    'display_name' => $clean['display_name'] ?? '',
                    'email' => $clean['email'] ?? '',
                    'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
                    'success' => false,
                    'message' => $e->getMessage(),
                    'ref' => $ref,
                    'ip' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
                Logger::error('Unexpected registration failure', [
                    'ref' => $ref,
                    'username' => $clean['username'] ?? $old['employee_id'],
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

$token = Csrf::token();
$needInvite = InviteService::isRequired($config);
$cssFile = __DIR__ . '/assets/style.css';
$cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : (string)time();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= e($cssVersion) ?>">
</head>
<body>
<a class="admin-shortcut" href="/admin.php">管理后台</a>
<div class="ambient ambient-a"></div>
<div class="ambient ambient-b"></div>

<main class="shell">
    <section class="hero-panel">
        <div class="hero-copy">
            <div class="eyebrow">Cloud Desktop Access</div>
            <h1>工号一键开通<span>云桌面</span></h1>
            <p class="lead">
                填写工号、姓名与登录密码即可提交云桌面开通。平台会自动创建基础域账号、加入默认 VDI 权限组，并完成云桌面使用前的账号准备。
            </p>

            <div class="hero-points">
                <div>
                    <strong>工号即开通标识</strong>
                    <p>以工号作为账号主键，便于人员、终端与云桌面权限统一对应。</p>
                </div>
                <div>
                    <strong>自动纳入云桌面权限组</strong>
                    <p>开通完成后自动加入 <code>VDI_Users</code>，减少人工配置步骤。</p>
                </div>
                <div>
                    <strong>开通前安全校验</strong>
                    <p>密码必须包含大写字母、小写字母、数字与特殊字符。</p>
                </div>
            </div>
        </div>

        <div class="hero-strip">
            <div class="stat">
                <span>开通标识</span>
                <strong>工号</strong>
            </div>
            <div class="stat">
                <span>权限组</span>
                <strong>VDI_Users</strong>
            </div>
            <div class="stat">
                <span>平台能力</span>
                <strong>Cloud Desktop</strong>
            </div>
        </div>
    </section>

    <section class="register-panel">
        <?php if ($success !== null): ?>
            <div class="success-panel">
                <div class="success-badge">✓ 开通完成</div>
                <h2>云桌面已开通</h2>
                <p>你的基础域账号与云桌面权限已经准备完成，已自动加入默认 VDI 用户组。</p>

                <dl>
                    <dt>工号</dt>
                    <dd><?= e($success['username']) ?></dd>
                    <dt>域登录名</dt>
                    <dd><?= e($success['upn']) ?></dd>
                    <dt>开通组</dt>
                    <dd>VDI_Users</dd>
                </dl>

                <a class="ghost-button" href="/">继续开通其他账号</a>
            </div>
        <?php else: ?>
            <div class="panel-head">
                <div>
                    <div class="panel-kicker">Cloud Desktop Provisioning</div>
                    <h2>开通你的云桌面</h2>
                </div>
                <p>请使用工号提交开通信息，系统会自动完成账号创建与权限归组。</p>
            </div>

            <?php if (isset($errors['_global'])): ?>
                <div class="notice error"><?= e($errors['_global']) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off" novalidate>
                <input type="hidden" name="_csrf_token" value="<?= e($token) ?>">

                <?php if ($needInvite): ?>
                    <label class="field">
                        <span>邀请码</span>
                        <input type="text" name="invite_code" value="<?= e($old['invite_code']) ?>" placeholder="请输入管理员发放的邀请码" required>
                        <?php if (isset($errors['invite_code'])): ?><em><?= e($errors['invite_code']) ?></em><?php endif; ?>
                    </label>
                <?php endif; ?>

                <label class="field">
                    <span>工号</span>
                    <input
                        type="text"
                        name="employee_id"
                        value="<?= e($old['employee_id']) ?>"
                        placeholder="例如 100086"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="20"
                        required
                    >
                    <small>工号将作为基础 AD 账号名，用于云桌面开通，仅支持数字。</small>
                    <?php if (isset($errors['employee_id'])): ?><em><?= e($errors['employee_id']) ?></em><?php endif; ?>
                </label>

                <label class="field">
                    <span>姓名 / 显示名称</span>
                    <input type="text" name="display_name" value="<?= e($old['display_name']) ?>" placeholder="例如 张三" required>
                    <?php if (isset($errors['display_name'])): ?><em><?= e($errors['display_name']) ?></em><?php endif; ?>
                </label>

                <label class="field">
                    <span>邮箱（可选）</span>
                    <input type="email" name="email" value="<?= e($old['email']) ?>" placeholder="name@example.local">
                    <?php if (isset($errors['email'])): ?><em><?= e($errors['email']) ?></em><?php endif; ?>
                </label>

                <div class="form-grid">
                    <label class="field">
                    <span>云桌面登录密码</span>
                    <input type="password" name="password" placeholder="请输入密码" required>
                    <?php if (isset($errors['password'])): ?><em><?= e($errors['password']) ?></em><?php endif; ?>
                </label>

                    <label class="field">
                        <span>确认密码</span>
                        <input type="password" name="password_confirm" placeholder="再次输入密码" required>
                        <?php if (isset($errors['password_confirm'])): ?><em><?= e($errors['password_confirm']) ?></em><?php endif; ?>
                    </label>
                </div>

                <div class="rule-block">
                    <div class="rule-title">密码规则</div>
                    <div class="rule-list">
                        <span>大写字母</span>
                        <span>小写字母</span>
                        <span>数字</span>
                        <span>特殊字符</span>
                        <span>不少于 <?= e((string)$config['registration']['password_min']) ?> 位</span>
                    </div>
                    <p>为保证云桌面账号安全性，密码中不能包含工号。</p>
                </div>

                <button class="submit-button" type="submit">立即开通云桌面</button>
            </form>
        <?php endif; ?>
    </section>
</main>

<section class="detail-section">
    <article>
        <span>01</span>
        <h3>开通标识统一</h3>
        <p>工号作为统一身份标识，便于员工、终端与云桌面账号一一对应。</p>
    </article>
    <article>
        <span>02</span>
        <h3>自动完成权限归组</h3>
        <p>平台会自动加入 <code>VDI_Users</code>，减少云桌面开通的人工维护成本。</p>
    </article>
    <article>
        <span>03</span>
        <h3>安全策略前置</h3>
        <p>提交前先校验密码复杂度，降低因 AD 安全策略不匹配带来的开通失败。</p>
    </article>
</section>
</body>
</html>
