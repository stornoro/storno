# SAF-T (D406) resources

Files used by `App\Service\Declaration\Populator\D406Populator` and
`App\Service\Declaration\XmlGenerator\D406XmlGenerator`.

| File | What it is | Source |
|---|---|---|
| `Ro_SAFT_Schema_v249_2025.xsd` | Romanian SAF-T Financial schema, version 2.4.9 (root `AuditFile`). The file's `targetNamespace` is the *types* namespace `mfp:anaf:dgti:d406t:declaratie:v1`; a declaration instance is written in `mfp:anaf:dgti:d406:declaratie:v1` (see `DeclarationNamespaceResolver`). | https://static.anaf.ro/static/10/Anaf/Informatii_R/Ro_SAFT_Schema_v249_2025.xsd |
| `tax_codes.json` | The VAT tax codes (`TaxTable` / `TaxInformation`, tax type `300`) Storno emits, with the rate and the Romanian description, plus the "no tax" pair `000` / `000000` ANAF prescribes for lines that carry no tax. A subset of the *Livrari* / *Achizitii ded 100%* / *Achizitii neded* sheets of the schema definition workbook. | https://static.anaf.ro/static/10/Anaf/Informatii_R/RO_SAFT_SchemaDefCod_16.02.2026.xlsx |
| `uom.json` | The units of measure (`UOMTable`) Storno can map an invoice line to, with the Romanian description; a subset of the *Unitati_masura* sheet (UN/ECE Recommendation 20 codes, the same ones used in e-Factura). | same workbook |

The validator itself (`D406Validator.jar`, `D406Pdf.jar`) is downloaded by
`tools/duk-integrator/update-jars.sh` from ANAF's DUKIntegrator manifest.

ANAF's SAF-T page (schema, nomenclatures, guide, FAQ):
https://www.anaf.ro/anaf/internet/ANAF/despre_anaf/strategii_anaf/proiecte_digitalizare/saf_t/
