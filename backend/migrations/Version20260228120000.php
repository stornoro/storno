<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260228120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize county full names to ISO 3166-2:RO codes in clients, suppliers, and companies tables';
    }

    public function up(Schema $schema): void
    {
        $nameToCode = [
            'Alba' => 'AB', 'Arges' => 'AG', 'Arad' => 'AR', 'Bucuresti' => 'B',
            'Bacau' => 'BC', 'Bihor' => 'BH', 'Bistrita-Nasaud' => 'BN',
            'Braila' => 'BR', 'Botosani' => 'BT', 'Brasov' => 'BV', 'Buzau' => 'BZ',
            'Cluj' => 'CJ', 'Calarasi' => 'CL', 'Caras-Severin' => 'CS',
            'Constanta' => 'CT', 'Covasna' => 'CV', 'Dambovita' => 'DB', 'Dolj' => 'DJ',
            'Gorj' => 'GJ', 'Galati' => 'GL', 'Giurgiu' => 'GR',
            'Hunedoara' => 'HD', 'Harghita' => 'HR', 'Ilfov' => 'IF',
            'Ialomita' => 'IL', 'Iasi' => 'IS', 'Mehedinti' => 'MH',
            'Maramures' => 'MM', 'Mures' => 'MS', 'Neamt' => 'NT', 'Olt' => 'OT',
            'Prahova' => 'PH', 'Sibiu' => 'SB', 'Salaj' => 'SJ', 'Satu Mare' => 'SM',
            'Suceava' => 'SV', 'Tulcea' => 'TL', 'Timis' => 'TM',
            'Teleorman' => 'TR', 'Valcea' => 'VL', 'Vrancea' => 'VN', 'Vaslui' => 'VS',
        ];

        foreach ($nameToCode as $name => $code) {
            // Fix clients
            $this->addSql(
                "UPDATE client SET county = ? WHERE LOWER(county) = LOWER(?)",
                [$code, $name]
            );
            // Fix suppliers
            $this->addSql(
                "UPDATE supplier SET county = ? WHERE LOWER(county) = LOWER(?)",
                [$code, $name]
            );
            // Fix companies (state column)
            $this->addSql(
                "UPDATE company SET state = ? WHERE LOWER(state) = LOWER(?)",
                [$code, $name]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Not reversible — full names are not needed
    }
}
