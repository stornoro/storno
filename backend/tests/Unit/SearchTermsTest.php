<?php

namespace App\Tests\Unit;

use App\Util\SearchTerms;
use PHPUnit\Framework\TestCase;

class SearchTermsTest extends TestCase
{
    /** @dataProvider cases */
    public function testPatterns(?string $search, array $expected): void
    {
        $this->assertSame($expected, SearchTerms::patterns($search));
    }

    public static function cases(): array
    {
        return [
            'nothing typed' => [null, []],
            'only spaces' => ['   ', []],
            'one word' => ['Exemplu', ['%Exemplu%']],
            'words are matched one by one' => ['  exemplu   srl ', ['%exemplu%', '%srl%']],
            'a repeated word counts once' => ['srl srl', ['%srl%']],
            'tax id with prefix' => ['RO12345678', ['%12345678%']],
            'prefix, a space, then the digits' => ['ro 12345678', ['%12345678%']],
            'prefix after a name' => ['exemplu RO12345678', ['%exemplu%', '%12345678%']],
            'RO inside a word stays' => ['metro 2000', ['%metro%', '%2000%']],
            'RO without digits stays' => ['RO Exemplu', ['%RO%', '%Exemplu%']],
            'wildcards match as typed' => ['50%_a\\b', ['%50\\%\\_a\\\\b%']],
            'at most six words' => ['a b c d e f g h', ['%a%', '%b%', '%c%', '%d%', '%e%', '%f%']],
        ];
    }

    public function testSqlClauseBindsOnePatternPerWord(): void
    {
        $params = ['companyId' => 'x'];
        $clause = SearchTerms::sqlClause('exemplu RO12345678', ['c.name', 'c.cui'], $params);

        $this->assertSame('((c.name LIKE :search0 OR c.cui LIKE :search0) AND (c.name LIKE :search1 OR c.cui LIKE :search1))', $clause);
        $this->assertSame(['companyId' => 'x', 'search0' => '%exemplu%', 'search1' => '%12345678%'], $params);
        $this->assertNull(SearchTerms::sqlClause(' ', ['c.name'], $params));
    }
}
