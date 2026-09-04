<?php

namespace App\Enum;

/**
 * Coarse grouping of ANAF SPV message types (the free-text `tip` field of
 * listaMesaje). ANAF uses ~90 distinct labels; users think in these buckets.
 */
enum SpvDocumentCategory: string
{
    case SOMATIE = 'somatie';              // SOMATII — enforcement / payment demands
    case DECIZIE = 'decizie';              // DECIZIE, Decizie *, SME_Decizie
    case NOTIFICARE = 'notificare';        // NOTIFICARE, Notificare *, Instiintare *, Invitatie *, Informare *
    case ADRESA = 'adresa';                // ADRESE — official letters
    case ANALIZA_RISC = 'analiza_risc';    // RAPOARTE ANALIZA DE RISC
    case RECIPISA = 'recipisa';            // RECIPISA, RECIPISA TREZORERIE, SME_Recipisa
    case DECLARATIE = 'declaratie';        // DECLARATIE, D300 pilot SAFT, M1SS
    case CERTIFICAT = 'certificat';        // CERTIFICAT FISCAL, Certificat *, CAZIER FISCAL, ADEVERINTA VENIT
    case RASPUNS = 'raspuns';              // RASPUNS SOLICITARE, RASPUNS SESIZARE ...
    case PLATA = 'plata';                  // PLATA
    case EXTRAS_CONT = 'extras_cont';      // EXTRAS DE CONT
    case AJUTOR_STAT = 'ajutor_stat';      // AJUTOR DE STAT
    case FACTURI_ARHIVA = 'facturi_arhiva';// FACTURI ARHIVA
    case TEZAUR = 'tezaur';                // Rapoarte / Recipisa Program Tezaur
    case REGISTRU = 'registru';            // fiducie, registrul entitatilor
    case ALTELE = 'altele';

    public function label(): string
    {
        return match ($this) {
            self::SOMATIE => 'Somatii',
            self::DECIZIE => 'Decizii',
            self::NOTIFICARE => 'Notificari si instiintari',
            self::ADRESA => 'Adrese',
            self::ANALIZA_RISC => 'Rapoarte analiza de risc',
            self::RECIPISA => 'Recipise',
            self::DECLARATIE => 'Declaratii',
            self::CERTIFICAT => 'Certificate si adeverinte',
            self::RASPUNS => 'Raspunsuri la solicitari',
            self::PLATA => 'Plati',
            self::EXTRAS_CONT => 'Extrase de cont',
            self::AJUTOR_STAT => 'Ajutor de stat',
            self::FACTURI_ARHIVA => 'Facturi arhiva',
            self::TEZAUR => 'Program Tezaur',
            self::REGISTRU => 'Registre',
            self::ALTELE => 'Altele',
        };
    }
}
