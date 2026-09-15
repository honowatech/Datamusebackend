<?php

namespace App\Services\Dfs;

/**
 * Résultat de `DfsValidator::validate()` : erreurs (bloquantes) et avertissements, chacun sous la forme
 * `{path, code, message, severity}` (`path` = JSON Pointer RFC 6901 dans la définition,
 * `severity` = `error` | `warning`).
 */
final class ValidationResult
{
    /**
     * @param  array<int, array{path: string, code: string, message: string, severity: string}>  $errors
     * @param  array<int, array{path: string, code: string, message: string, severity: string}>  $warnings
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * Erreurs puis avertissements.
     *
     * @return array<int, array{path: string, code: string, message: string, severity: string}>
     */
    public function all(): array
    {
        return array_merge($this->errors, $this->warnings);
    }

    /**
     * @return string[]
     */
    public function errorCodes(): array
    {
        return array_values(array_unique(array_map(static fn (array $e): string => $e['code'], $this->errors)));
    }

    /**
     * @return string[]
     */
    public function warningCodes(): array
    {
        return array_values(array_unique(array_map(static fn (array $e): string => $e['code'], $this->warnings)));
    }

    public function hasError(string $code): bool
    {
        return in_array($code, $this->errorCodes(), true);
    }

    public function hasWarning(string $code): bool
    {
        return in_array($code, $this->warningCodes(), true);
    }
}
