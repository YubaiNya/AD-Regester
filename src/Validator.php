<?php

declare(strict_types=1);

namespace AdRegister;

final class Validator
{
    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function registration(array $input, array $config): array
    {
        $errors = [];
        $clean = [];
        $rules = $config['registration'];

        $employeeId = trim((string)($input['employee_id'] ?? $input['username'] ?? ''));
        $min = (int)$rules['username_min'];
        $max = min((int)$rules['username_max'], 20);
        if ($employeeId === '') {
            $errors['employee_id'] = '请输入工号。';
        } elseif (strlen($employeeId) < $min || strlen($employeeId) > $max) {
            $errors['employee_id'] = sprintf('工号长度需为 %d-%d 位。', $min, $max);
        } elseif (!preg_match('/^\d+$/', $employeeId)) {
            $errors['employee_id'] = '工号只能填写数字。';
        } else {
            $clean['username'] = $employeeId;
            $clean['employee_id'] = $employeeId;
        }

        $displayName = trim((string)($input['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $employeeId;
        }
        if (strlen($displayName) > 80) {
            $errors['display_name'] = '显示名称不能超过 80 个字符。';
        } else {
            $clean['display_name'] = $displayName;
        }

        $email = trim((string)($input['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = '邮箱格式不正确。';
        } else {
            $clean['email'] = $email;
        }

        $password = (string)($input['password'] ?? '');
        $passwordConfirm = (string)($input['password_confirm'] ?? '');
        if ($password === '') {
            $errors['password'] = '请输入密码。';
        } elseif (strlen($password) < (int)$rules['password_min']) {
            $errors['password'] = sprintf('密码至少需要 %d 个字符。', (int)$rules['password_min']);
        } elseif ($password !== $passwordConfirm) {
            $errors['password_confirm'] = '两次输入的密码不一致。';
        } elseif ((bool)$rules['password_complexity'] && !self::passwordLooksComplex($password)) {
            $errors['password'] = '密码必须同时包含大写字母、小写字母、数字和特殊字符。';
        } elseif ($employeeId !== '' && stripos($password, $employeeId) !== false) {
            $errors['password'] = '密码不能包含工号。';
        } else {
            $clean['password'] = $password;
        }

        // 邀请码由入口页根据配置区分“后台邀请码”和旧版单一邀请码后统一处理。
        return [$errors, $clean];
    }

    private static function passwordLooksComplex(string $password): bool
    {
        return preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^a-zA-Z\d]/', $password) === 1;
    }
}
