<?php

declare(strict_types=1);

namespace App\Util;

use Doctrine\ORM\QueryBuilder;

/**
 * What a list search box does with the text the user typed.
 *
 * The text is cut into words and a row matches when every word appears in at least one of the
 * searched columns, so "popescu ion" finds "ION POPESCU SRL" and "dedeman 4521785" finds the
 * invoice by name and tax id at once. A tax id typed as "RO12345678" or "RO 12345678" is
 * searched as "12345678", because clients and invoices store it without the country prefix
 * while the VAT code keeps it: the digits match both.
 */
final class SearchTerms
{
    /** More words than this only narrow a result that is already down to a few rows. */
    private const MAX_WORDS = 6;

    /**
     * LIKE patterns, one per word, with % and _ escaped so they match as typed.
     *
     * @return list<string>
     */
    public static function patterns(?string $search): array
    {
        $text = trim((string) $search);
        if ($text === '') {
            return [];
        }

        $text = (string) preg_replace('/(?<![\p{L}\p{N}])RO\s*(?=\d)/iu', '', $text);
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            static fn (string $word): string => '%' . addcslashes($word, '\\%_') . '%',
            array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS),
        );
    }

    /**
     * Narrows a DQL query to the rows that match the search text.
     *
     * @param list<string> $columns DQL paths, for example ['i.number', 'c.name']
     */
    public static function applyTo(QueryBuilder $qb, ?string $search, array $columns): void
    {
        foreach (self::patterns($search) as $i => $pattern) {
            $name = 'search' . $i;
            $qb->andWhere('(' . implode(' OR ', array_map(static fn (string $column): string => "$column LIKE :$name", $columns)) . ')')
                ->setParameter($name, $pattern);
        }
    }

    /**
     * The same condition for a hand-written SQL query. Returns null when there is nothing to
     * search for and adds the bound values to $params otherwise.
     *
     * @param list<string>         $columns SQL column references, for example ['c.name', 'c.cui']
     * @param array<string, mixed> $params
     */
    public static function sqlClause(?string $search, array $columns, array &$params): ?string
    {
        $groups = [];
        foreach (self::patterns($search) as $i => $pattern) {
            $name = 'search' . $i;
            $groups[] = '(' . implode(' OR ', array_map(static fn (string $column): string => "$column LIKE :$name", $columns)) . ')';
            $params[$name] = $pattern;
        }

        return $groups === [] ? null : '(' . implode(' AND ', $groups) . ')';
    }
}
