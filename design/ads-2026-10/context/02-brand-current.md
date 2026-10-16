# Brand și sistem vizual ACTUAL (starea de la care plecăm)

Logo: `context/logo.png` — buclă infinit multicoloră (stele, picături, cercuri în galben/roșu/verde/albastru), cu „Storno.ro” ca text bold lângă ea. **Logo-ul se păstrează exact așa, nu se redesenează, nu se monocromizează.** Paleta nouă trebuie să funcționeze lângă el.
Font actual: **Public Sans** (landing + dashboard); mobil: fontul de sistem (SF / Roboto).

## Landing + Dashboard (Nuxt UI 4 + Tailwind 4)
Temele sunt definite prin `app.config.ts`:
- landing: `primary: 'blue'`, `neutral: 'slate'`
- dashboard: `primary: 'blue'`, `neutral: 'zinc'`
Tokenii semantici Nuxt UI (`--ui-primary`, `--ui-bg`, `--ui-bg-elevated`, `--ui-border`, `--ui-text-muted`, radius `--ui-radius`) sunt singurul loc unde se schimbă culorile global. Componentele folosite: `UHeader`, `UPageHero`, `UPageSection`, `UPageCard`, `UPageCTA`, `UAccordion`, `UDashboardGroup/Sidebar/Panel/Navbar/Toolbar`, `UTable`, `UCard`, `UBadge`, `UButton`, `UModal`, `USlideover`, `UForm*`, `UNavigationMenu`, `UCommandPalette` (⌘K).

## Mobil (Expo 54 / React Native 0.81, StyleSheet, fără NativeWind)
`mobile/src/theme/colors.ts` (copiat aici):
```
light: primary #1e40af, primaryLight #3b82f6, primaryDark #1e3a8a
       success #16a34a, warning #d97706, error #dc2626, info #0284c7
       background #f8fafc, surface #ffffff, surfaceVariant #f1f5f9
       text #0f172a, textSecondary #64748b, textTertiary #94a3b8
       border #e2e8f0, incoming #3b82f6 (facturi primite), outgoing #22c55e (emise)
dark:  primary #3b82f6, background #0f172a, surface #1e293b, surfaceVariant #283548,
       text #f1f5f9, textSecondary #94a3b8, border #334155
```
Spacing/borderRadius/typography sunt în `mobile/src/theme/index.ts`. Icoane: Ionicons.

## Diagnostic sincer al designului actual (de ce refacem)
- Landing: arată ca template-ul default Nuxt UI SaaS (hero split + grid de carduri identice + FAQ). Nicio identitate proprie, hero-ul e un SVG static de dashboard, „trust logos” Stripe/Google/Microsoft/Slack sunt generice și nu au legătură cu clienții reali.
- Prea multe secțiuni pe home (10) cu aceeași greutate vizuală; catalogul de servicii e un zid de text.
- Dashboard: funcțional și dens, dar generic (sidebar zinc, carduri albe). Onboarding-ul ocupă tot ecranul la început. Nu există ierarhie clară „ce am de făcut azi” (facturi neîncasate, mesaje ANAF, declarații cu termen).
- Mobil: stil „admin generic” (liste + carduri), tab bar cu 6 taburi, culori Tailwind default. Nu se simte ca o aplicație de produs, ci ca o versiune mică a web-ului.
- Nicio ilustrație/iconografie proprie; nicio voce vizuală care să transmită „ANAF fără stres”.
