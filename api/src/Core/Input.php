<?php
declare(strict_types=1);

namespace School\Core;

/** Reads and checks request fields; every error names the field so the form can show it. */
final class Input
{
    public function __construct(private readonly array $data) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null && $this->data[$key] !== '';
    }

    public function str(string $key, int $max = 255, bool $required = true): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) throw HttpError::badRequest("Please fill in $key.", ['field' => $key]);
            return null;
        }
        if (!is_scalar($v)) throw HttpError::badRequest("$key is not valid.", ['field' => $key]);
        $v = trim((string)$v);
        if (mb_strlen($v) > $max) throw HttpError::badRequest("$key is too long (max $max).", ['field' => $key]);
        return $v;
    }

    public function int(string $key, bool $required = true, int $min = 0): ?int
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) throw HttpError::badRequest("Please fill in $key.", ['field' => $key]);
            return null;
        }
        if (!is_numeric($v) || (int)$v != $v || (int)$v < $min) throw HttpError::badRequest("$key must be a whole number.", ['field' => $key]);
        return (int)$v;
    }

    public function date(string $key, bool $required = true): ?string
    {
        $v = $this->str($key, 10, $required);
        if ($v === null) return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw HttpError::badRequest("$key must be a date like 2026-06-01.", ['field' => $key]);
        return $v;
    }

    public function email(string $key, bool $required = true): ?string
    {
        $v = $this->str($key, 190, $required);
        if ($v === null) return null;
        $v = strtolower($v);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw HttpError::badRequest('Please enter a valid email.', ['field' => $key]);
        return $v;
    }

    public function phone(string $key, bool $required = true): ?string
    {
        $v = $this->str($key, 20, $required);
        if ($v === null) return null;
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) throw HttpError::badRequest('Please enter a 10-digit mobile number.', ['field' => $key]);
        return $digits;
    }

    /** @param list<string> $allowed */
    public function oneOf(string $key, array $allowed, bool $required = true): ?string
    {
        $v = $this->str($key, 50, $required);
        if ($v === null) return null;
        if (!in_array($v, $allowed, true)) throw HttpError::badRequest("$key must be one of: " . implode(', ', $allowed) . '.', ['field' => $key]);
        return $v;
    }

    public function list(string $key): array
    {
        $v = $this->data[$key] ?? [];
        if (!is_array($v) || !array_is_list($v)) throw HttpError::badRequest("$key must be a list.", ['field' => $key]);
        return $v;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->data[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
