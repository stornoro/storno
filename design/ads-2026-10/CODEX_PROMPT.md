# Storno.ro — machete pentru 9 reclame Meta (Facebook + Instagram), octombrie 2026

Lucrezi în acest director. Citește mai întâi `context/01-product.md`, `context/02-brand-current.md`,
`context/tokens.json` și uită-te la `context/design-system.png` (sistemul vizual ales la redesign) și la
`context/current/*.png` (reclamele care rulează acum, stil „light, product-faithful”: fundal alb, o tentă
albastră, mockup de UI real). Noile machete trebuie să fie clar mai bune decât cele din `current/`:
mai mult contrast, un singur mesaj mare per imagine, un vizual concret al produsului, oferta vizibilă.

## Context de business (de ce facem asta)
- Ultimele 30 zile: ~1.000 RON, 1.340 click-uri, 0 înregistrări atribuite. Reclamele vindeau feature-uri.
- Competitorii (SmartBill, Oblio) rulează 23 și 42 de reclame active, toate cu oferta în titlu:
  „Începe Acum Gratuit” / „GRATUIT 1 AN”, 4-6 variante vizuale per mesaj.
- Oferta reală Storno, care bate ambele: **Freemium 0 lei pentru totdeauna** (1 firmă, 100 facturi/lună,
  e-Factura ANAF inclusă, fără card bancar), 14 zile Starter gratuit, Starter 19 lei/lună, self-hosting
  gratuit open-source. Nu promitem nimic altceva. Nu scriem „primii 100”, nu scriem „AI”.
- Fiecare machetă trebuie să conțină oferta sau un beneficiu concret și, discret, un chip de CTA
  „Începe gratuit” (nu este buton funcțional, Meta pune butonul real sub imagine).

## Reguli de brand (obligatorii)
- Logo: `context/logo.png` (buclă multicoloră + wordmark) sau `context/logo-mark.png` (doar marca,
  PNG transparent) — se păstrează exact, nu se recolorează. Apare mic, jos sau sus, pe fiecare machetă.
- Paletă: albastru primar `#2563eb` / `#1e40af`, slate pentru text (`#0f172a`, `#475569`), fundal alb
  sau `#f8fafc`, tentă `#dbeafe` / `#eff6ff`. Verde `#16a34a` doar pentru stări „ok”, amber `#d97706`
  pentru atenție, roșu `#dc2626` pentru somații. Fără gradiente violet, fără stock photos, fără emoji.
- Font: Public Sans (Google Fonts, `<link>` în `<head>`), fallback Inter / system-ui. Diacritice corecte.
- Storno.ro este furnizor privat, NU afiliat ANAF: nicio stemă, niciun albastru guvernamental, fără
  „ANAF” ca logo. Persoanele fizice (CNP) nu emit facturi, doar primesc; nu arăta un CNP care emite.
- Textul din imagine: maximum ~20% din suprafață ocupat de text mare; titlul lizibil pe telefon
  (minimum ~64px la 1080 lățime). Un titlu, un subtitlu scurt, un vizual de produs, logo, chip CTA.
- Mockup-urile de UI trebuie să arate ca aplicația reală (carduri albe, ring `#e2e8f0`, pastile de
  status colorate, tabele simple), nu ilustrații abstracte. Date fictive: firme „Exemplu SRL”,
  „Popescu Ana PFA”, CUI-uri inventate de forma RO12345678, sume rotunde în lei.

## Formate
Pentru fiecare reclamă livrezi 2 fișiere HTML self-contained (CSS inline, fără JS, fără imagini
externe în afară de Google Fonts și de `../context/logo*.png` referit relativ):
- `mockups/<slug>.1080x1080.html` — pătrat, feed Facebook + Instagram (fallback pe toate plasamentele)
- `mockups/<slug>.1080x1920.html` — vertical, Instagram Reels + Stories; zona de sus 250px și de jos
  350px rămân libere de text important (acolo stau UI-ul Instagram și butonul CTA)
`<html>` și `<body>` au exact dimensiunea respectivă (`width`, `height`, `margin:0`, `overflow:hidden`),
fără scroll. Un script de randare va face screenshot al viewport-ului la dimensiunea din numele fișierului.

## Cele 9 reclame (slug · campanie · titlu · subtitlu · vizual)
Titlurile și subtitlurile de mai jos sunt copy-ul aprobat; poți ajusta tăierile de rând și poți
propune o variantă alternativă de titlu în README, dar nu schimba promisiunea.

Campania e-Factura ANAF (public: firme mici și PFA, 25-60):
1. `ef-gratuit` · „Facturare cu e-Factura, gratuit pentru totdeauna” · „1 firmă, 100 facturi/lună, 0 lei. Fără card bancar.” · card de factură cu status „Trimisă în SPV ✓” și un badge „0 lei / lună”.
2. `ef-furnizori` · „Facturile de la furnizori intră singure în cont” · „Sincronizare SPV în ambele direcții. Token reînnoit automat.” · listă inbox cu 3-4 facturi primite (furnizor, sumă, „descărcată automat”).
3. `ef-somatii` · „Somațiile ANAF ajung pe telefon, nu în spam” · „Mesajele din SPV, traduse pe românește, cu notificare push.” · telefon cu o notificare push „Somație de plată · termen 15 zile” și cardul mesajului explicat.

Campania Freelanceri PFA (public: PFA, IT/design/consultanță, 22-50):
4. `fr-gratuit` · „PFA? Facturezi gratuit, pentru totdeauna” · „100 facturi/lună, e-Factura inclusă, fără card bancar.” · factură PFA simplă emisă către un client SRL, badge „0 lei”.
5. `fr-30s` · „Factura PFA, în 30 de secunde, de pe telefon” · „Client din CUI, produs salvat, trimisă pe email cu link de plată.” · flux pe telefon în 3 pași (CUI → produs → trimis).
6. `fr-conform` · „PFA-ul tău e în regulă cu ANAF?” · „e-Factura, SPV, declarația unică: toate într-un singur loc.” · checklist cu 4 puncte bifate verde și unul amber „Declarația unică · termen 25 mai”.

Campania nouă „Gratuit pentru totdeauna” (public: firme mici + contabili, 25-60):
7. `gr-vs` · „Nu 1 an gratuit. Pentru totdeauna.” · „Freemium 0 lei. Starter 19 lei/lună. La jumătate față de piață.” · card de plan Freemium (0 lei, 1 firmă, 100 facturi, e-Factura inclusă) lângă un card Starter 19 lei; fără nume de competitori.
8. `gr-contabili` · „Contabil cu 20 de firme? Un singur SPV, un singur export” · „Toate firmele clienților într-un cont. Export SAGA lunar, într-un click.” · listă de firme cu status SPV sincronizat și un buton „Export SAGA · octombrie”.
9. `gr-selfhost` · „Datele tale, pe serverul tău. Gratuit.” · „Open source. Docker. O singură comandă.” · terminal mic cu `curl -fsSL https://get.storno.ro | bash` și cardul aplicației rulând.

## Livrabile
1. Cele 18 fișiere HTML în `mockups/`.
2. `mockups/README.md` cu, pentru fiecare slug: textul principal pentru Meta (primary text, 2-3 propoziții,
   fără emoji, diacritice), headline (max 40 caractere), description (max 30), o variantă alternativă
   de headline, și ce arată vizualul. Copy-ul trebuie să fie onest față de `context/01-product.md`.
3. `mockups/index.html` cu toate machetele într-un grid (iframe-uri scalate), ca să le vedem dintr-o privire.
Nu rula browsere și nu instala pachete; doar scrie fișierele. Verifică la final că fiecare HTML are
dimensiunile corecte în CSS și că referințele la logo sunt relative (`../context/logo.png`).
