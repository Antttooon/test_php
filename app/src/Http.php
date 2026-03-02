<?php

declare(strict_types=1);

final class Http
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function badRequest(string $message): void
    {
        self::json([
            'ok' => false,
            'error' => $message,
        ], 400);
    }

    public static function serverError(string $message = 'Internal server error'): void
    {
        self::json([
            'ok' => false,
            'error' => $message,
        ], 500);
    }
}
