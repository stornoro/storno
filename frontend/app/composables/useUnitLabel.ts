/**
 * The unit of measure of a document line in the interface language, whatever the line
 * carries: the Romanian labels the apps save ("buc", "ora", "luna"), their plurals, or the
 * UN/ECE codes e-Factura invoices come with ("H87", "C62", "HUR", "MON"). Mirrors
 * App\Util\UnitOfMeasure on the backend; unknown values are shown as they are.
 */
const UNIT_KEYS: Record<string, string> = {
  buc: 'buc', bucata: 'buc', bucati: 'buc', 'bucăți': 'buc', 'bucată': 'buc', h87: 'buc', c62: 'buc', ea: 'buc', pcs: 'buc', pc: 'buc', piece: 'buc', pieces: 'buc', stk: 'buc', unit: 'buc', units: 'buc', nil: 'buc',
  kg: 'kg', kilogram: 'kg', kgm: 'kg',
  g: 'g', gram: 'g', grame: 'g', grm: 'g',
  l: 'l', litru: 'l', litri: 'l', ltr: 'l',
  ml: 'ml', mlt: 'ml',
  m: 'm', metru: 'm', metri: 'm', mtr: 'm',
  cm: 'cm', cmt: 'cm',
  km: 'km', kmt: 'km',
  mp: 'mp', m2: 'mp', mtk: 'mp',
  mc: 'mc', m3: 'mc', mtq: 'mc',
  t: 't', tona: 't', tone: 't', tne: 't',
  ora: 'ora', ore: 'ora', 'oră': 'ora', h: 'ora', hur: 'ora', hour: 'ora', hours: 'ora',
  min: 'min', minut: 'min', minute: 'min',
  zi: 'zi', zile: 'zi', day: 'zi', days: 'zi',
  sapt: 'sapt', saptamana: 'sapt', saptamani: 'sapt', wee: 'sapt', week: 'sapt',
  luna: 'luna', luni: 'luna', 'lună': 'luna', mon: 'luna', month: 'luna', months: 'luna', 'luna - mon': 'luna',
  an: 'an', ani: 'an', ann: 'an', year: 'an', years: 'an',
  set: 'set',
  pereche: 'pereche', perechi: 'pereche', per: 'pereche', pr: 'pereche', pair: 'pereche',
  pachet: 'pachet', pachete: 'pachet', pac: 'pachet', xpk: 'pachet', pk: 'pachet', pack: 'pachet',
  cutie: 'cutie', cutii: 'cutie', xbx: 'cutie', box: 'cutie',
  kwh: 'kwh', c79: 'kwh', mwh: 'mwh',
  serv: 'serv', serviciu: 'serv', servicii: 'serv', e48: 'serv', service: 'serv',
  proc: 'proc', '%': 'proc', p1: 'proc', procent: 'proc',
  ftq: 'ftq',
}

export function useUnitLabel() {
  const { t, te } = useI18n()

  function unitLabel(raw: string | null | undefined): string {
    const value = (raw ?? '').trim()
    if (!value) return ''
    const key = UNIT_KEYS[value.toLowerCase()]
    if (!key) return value
    const i18nKey = `units.${key}`
    return te(i18nKey) ? t(i18nKey) : value
  }

  return { unitLabel }
}
