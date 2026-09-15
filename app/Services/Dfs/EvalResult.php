<?php

namespace App\Services\Dfs;

/**
 * Résultat d'une évaluation Logic : la valeur et les diagnostics accumulés.
 *
 * Chaque diagnostic est un tableau `{path, code, message}` (`path` = JSON Pointer
 * RFC 6901 du nœud fautif, `/` pour la racine). Codes émis par l'évaluateur :
 * `max_depth`, `bad_expr`, `type_error`, `unknown_op`, `bad_arity`, `unknown_var`,
 * `division_by_zero`, `invalid_regex`, `invalid_date`.
 */
final class EvalResult
{
    /**
     * @param  array<int, array{path: string, code: string, message: string}>  $diagnostics
     */
    public function __construct(
        public readonly mixed $value,
        public readonly array $diagnostics = [],
    ) {}

    public function hasDiagnostics(): bool
    {
        return $this->diagnostics !== [];
    }

    /**
     * @return string[]
     */
    public function codes(): array
    {
        return array_map(static fn (array $d): string => $d['code'], $this->diagnostics);
    }
}
