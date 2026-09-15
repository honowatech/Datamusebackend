<?php

namespace Tests\Unit;

use App\Support\SqlIdentifier;
use PHPUnit\Framework\TestCase;

class SqlIdentifierTest extends TestCase
{
    public function test_normalize_transliterates_and_snake_cases(): void
    {
        $this->assertSame('prenom_de_l_enfant', SqlIdentifier::normalize('Prénom de l\'enfant'));
        $this->assertSame('age_ans', SqlIdentifier::normalize('  Âge (ans) '));
        $this->assertSame('q5__codes', SqlIdentifier::normalize('q5__codes'), 'les underscores doublés sont conservés');
        $this->assertSame('', SqlIdentifier::normalize('---'));
        $this->assertSame('2024_total', SqlIdentifier::normalize('2024 total'), 'normalize ne préfixe pas');
    }

    public function test_clean_prefixes_digits_and_empty_names(): void
    {
        $this->assertSame('c_2024_total', SqlIdentifier::clean('2024 total'));
        $this->assertSame('t_2024_total', SqlIdentifier::clean('2024 total', prefix: SqlIdentifier::TABLE_PREFIX));
        $this->assertSame('c_', SqlIdentifier::clean(''));
        $this->assertSame('c_', SqlIdentifier::clean('###'));
    }

    public function test_clean_suffixes_sqlite_reserved_words(): void
    {
        $this->assertSame('order_', SqlIdentifier::clean('Order'));
        $this->assertSame('group_', SqlIdentifier::clean('group'));
        $this->assertSame('select_', SqlIdentifier::clean('SELECT'));
        $this->assertSame('key_', SqlIdentifier::clean('key'));
        $this->assertSame('ordre', SqlIdentifier::clean('ordre'));
        $this->assertTrue(SqlIdentifier::isReserved('table'));
        $this->assertFalse(SqlIdentifier::isReserved('tableau'));
    }

    public function test_clean_truncates_to_max_length_without_trailing_underscore(): void
    {
        $long = str_repeat('abcde_', 20); // 120 caractères
        $cleaned = SqlIdentifier::clean($long);
        $this->assertSame(59, strlen($cleaned), 'tronqué à 60 puis « _ » final retiré');
        $this->assertStringStartsWith('abcde_abcde', $cleaned);

        $this->assertSame('abc', SqlIdentifier::clean('abcdef', 3));
    }

    public function test_unique_deduplicates_with_numeric_suffixes(): void
    {
        $taken = [];
        $this->assertSame('a', SqlIdentifier::unique('a', $taken));
        $this->assertSame('a_2', SqlIdentifier::unique('a', $taken));
        $this->assertSame('a_3', SqlIdentifier::unique('a', $taken));
        $this->assertSame('a_2_2', SqlIdentifier::unique('a_2', $taken), 'un nom déjà pris est suffixé à son tour');
        $this->assertSame('A_4', SqlIdentifier::unique('A', $taken), 'insensible à la casse');
        $this->assertSame(['a', 'a_2', 'a_3', 'a_2_2', 'a_4'], array_keys($taken));
    }

    public function test_unique_keeps_suffixed_names_within_max_length(): void
    {
        $taken = [];
        $base = str_repeat('x', 60);
        $this->assertSame($base, SqlIdentifier::unique($base, $taken, 60));
        $second = SqlIdentifier::unique($base, $taken, 60);
        $this->assertSame(60, strlen($second));
        $this->assertStringEndsWith('_2', $second);
        $this->assertSame(str_repeat('x', 58).'_2', $second);

        $third = SqlIdentifier::unique(str_repeat('y', 80), $taken, 60);
        $this->assertSame(60, strlen($third), 'un nom trop long est tronqué même sans collision');
    }
}
