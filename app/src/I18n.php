<?php

declare(strict_types=1);

final class I18n
{
    private const SUPPORTED = ['en', 'sr'];
    private const DEFAULT_LOCALE = 'sr';

    /** @var array<string, string> */
    private array $messages = [];

    public static function fromRequest(): self
    {
        $raw = $_GET['lang'] ?? $_GET['locale'] ?? '';
        $locale = is_string($raw) ? trim($raw) : '';
        if ($locale === '' || !in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT_LOCALE;
        }
        return new self($locale);
    }

    public function __construct(private readonly string $locale)
    {
        $path = __DIR__ . '/../locales/' . $this->locale . '.php';
        if (is_file($path)) {
            $this->messages = (array) require $path;
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function t(string $key): string
    {
        return $this->messages[$key] ?? $key;
    }
}
