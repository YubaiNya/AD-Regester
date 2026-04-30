<?php

declare(strict_types=1);

namespace AdRegister;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        $config = function_exists('app_config') ? app_config() : Config::load();
        $file = $config['paths']['log_file'];
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

