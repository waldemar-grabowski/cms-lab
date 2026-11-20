# Auto-Serwis Waldi

Projekt strony warsztatu samochodowego na WordPressie. Oferta: naprawa i diagnostyka samochodów osobowych i dostawczych do 3.5t. Klienci: prywatni oraz małe firmy.

## Persony

### Klient prywatny
Chce umówić się na diagnostykę, bo zapaliła mu się kontrolka silnika. Nie ma czasu dzwonić w godzinach pracy warsztatu, więc wchodzi na stronę ze smartfona. CTA: „Umów wizytę online”.

### Klient flotowy
Szuka warsztatu, który zajmie się całą flotą, zamiast wozić auta po różnych miejscach i gubić się w organizacji. Wchodzi z komputera w biurze. CTA: „Zapytaj o ofertę dla firm”.

## Mapa strony
```mermaid
flowchart TD
  M(["Menu główne"])
  M --> H["Strona główna<br>/"]
  M --> U["Usługi<br>/uslugi/"]
  M --> O["Oferta<br>/oferta/"]
  M --> F["Dla firm<br>/dla-firm/"]
  M --> W["Umów wizytę<br>/umow-wizyte/"]
  M --> A["Aktualności<br>/aktualnosci/"]
  M --> K["Kontakt<br>/kontakt/"]
  M --> N["O nas<br>/o-nas/"]
  M --> P["Polityka prywatności<br>/polityka-prywatnosci/"]
  U --> U1["Mechanika i diagnostyka<br>/uslugi/mechanika-diagnostyka/"]
  U --> U2["Opony i klimatyzacja<br>/uslugi/opony-klimatyzacja/"]
  A --> A1["Porady<br>/category/porady/"]
  A --> A2["Z warsztatu<br>/category/z-warsztatu/"]
```

## Struktura treści
Bezpośrednie odnośniki: `/%postname%/` (sama nazwa wpisu). Strona główna jest statyczna (`/`), a wpisy wylądowały na stronie Aktualności (Ustawienia > Czytanie). Kategorie: Porady i Z warsztatu. Wpisy: „Kontrolka check engine – co oznacza i co zrobić?” oraz „Przygotuj auto do zimy” w Poradach, „Rezerwacja wizyt online – wkrótce” w Z warsztatu.

## Strona główna (według `wireframe.svg`)

| Sekcja | Bloki |
|---|---|
| Hero | Okładka → Tytuł (H1), Akapit, Przyciski (CTA „Umów wizytę online”) |
| Usługi i korzyści | Grupa → H2, Kolumny (3 × karta: H3, Lista, link) – wcześniej Tabela, zamieniona po audycie WAVE (*Layout table*) |
| Opinie klientów | Grupa → H2, Kolumny (3 × Grupa-karta z Cytatem) – przykładowe opinie, oznaczone na stronie jako wygenerowane przez AI |

Na stronie jest jeden H1, a kolejność nagłówków to H1 → H2 → H3. Użyłem szablonu „Strona bez tytułu”, żeby tytuł strony nie dublował H1.

## Nagłówek, stopka i wzorzec
W nagłówku logo z nazwą witryny, menu główne (Strona główna, Usługi z podstronami, Dla firm, Aktualności, Kontakt) i przycisk „Umów wizytę”. Stopka: nazwa i slogan, dane kontaktowe (adres, telefon, e-mail, godziny otwarcia) oraz „Menu stopki” z linkiem do Polityki prywatności.

Wzorzec „Hero – Auto-Serwis Waldi” (kategoria Auto-Serwis, niez synchronizowany) zapisałem z Okładki strony głównej i wstawiłem na stronie **Oferta** (`/oferta/`). Blok Tytuł w Okładce pokazuje tytuł danej strony jako H1, więc wzorzec pasuje też tam.

## Dlaczego WordPress
Super popularny, słyszałem i spotkałem się z WordPressem, jeszcze zanim rozpocząłem studia. Z doświadczenia z Open Source: za popularnością idzie bezpieczeństwo i ilość motywów/pluginów. No i łatwość uruchomienia i konfiguracji.

Według W3Techs (październik 2026) WordPress napędza 40,2% wszystkich stron i ma 58,7% rynku CMS – dla porównania Wix 6,1%, Shopify 7,8%, Drupal 0,6%. Wix i Shopify to SaaS: abonament, mniej kontroli i uzależnienie od dostawcy, a Drupal pasuje do dużych portali i sektora publicznego, nie do strony lokalnego warsztatu. Dlatego WordPress: darmowy, self-hosted, z ekosystemem, który już znam.

## Użycie AI w projekcie
Narzędziem był Claude Code (Anthropic) – asystent w terminalu z dostępem do repozytorium i lokalnego WordPressa.

Osobiście zrobiłem: persony, mapę strony, układ strony głównej (Okładka ze zdjęciem, usługi, opinie), wybór palety (mój motyw Spectagon) i zdjęć z Pexels, ocenę kolejnych wersji i poprawki wyglądu (m.in. odrzuciłem pierwszą wersję strony głównej; wyśrodkowanie i rozmiar tekstu na stronie Usługi), audyt WAVE, przegląd i akceptację zmian.

AI użyłem do:
- wygenerowania pliku `wireframe.svg` i ogólnej pomocy,
- tekstów pomocniczych: 3 wpisów w Aktualnościach, opisów na stronach Usługi, Oferta i Kontakt, szkicu polityki prywatności (do weryfikacji – firma i dane są fikcyjne),
- przykładowych opinii klientów (oznaczonych na stronie),
- kodu: pliku wzorca `patterns/hero-cta.php`, `theme.json`, Dodatkowego CSS, formularza CF7 z komunikatami,
- obliczeń kontrastu, uruchomienia Lighthouse (pliki w [`raport/`](raport/), podsumowanie [`lighthouse-podsumowanie.md`](raport/lighthouse-podsumowanie.md)) i poprawek zgłoszonych przez Lighthouse/WAVE,
- pomocy w redakcji tego README (tabele, lista źódeł, opis B1).

Później, przy kończeniu projektu, użyłem agenta w terminalu (Hermes): poprawił separator dziesiętny (3.5 t → 3,5 t) w bazie eksportu ZIP, przepakował eksport, zredagował formatowanie tego README i raportu dostępności (treść liczbowa bez zmian), wygenerował ponownie PDF raportu, odświeżył zrzuty strony głównej i Oferty oraz przeprowadził automatyczny test struktury drzewa dostępności (B2). Słuchowy test VoiceOver zrobiłem sam.

Każdą zmianę sprawdzałem w przeglądarce i na zrzutach ekranu (komputer i telefon). Wyniki audytów są w [`raport/`](raport/) (opis zawartości: [`raport/README.md`](raport/README.md)). Plik `raport/lighthouse/pierwszy-testowy-pomiar.html` to mój pierwszy, testowy pomiar stanu po zmianach.

## Style globalne i kontrast (L3 G1)
Paleta bierze się z mojego motywu terminala/edytora **Monokai Pro Spectagon** (ciemne tło Spectrum i akcenty Octagon, plik `nixconfig-global`). Pomarańcz marki pasuje do zdjęcia warsztatu. Ten sam zapis jest w [`theme.json`](theme.json) (przyda się do motywu w L4).

| Kolor | Slug | Wartość |
|---|---|---|
| Tło | `base` | `#222222` |
| Tekst | `contrast` | `#EAF2F1` |
| Marka (pomarańcz) | `accent-1` | `#FF9B5E` |
| Tekst drugorzędny | `accent-4` | `#B2B9BD` |
| Akcent – tło kart | `accent-5` | `#363537` |
| Obramowania (techniczny) | `accent-6` | `#696D77` |

Kontrast liczyłem dla każdej pary tekst/tło:

| Para tekst / tło | Kolory | Kontrast | WCAG AA |
|---|---|---|---|
| Nagłówki / tło | `#EAF2F1` / `#222222` | 13,99:1 | spełnia |
| Tekst akapitu / tło | `#B2B9BD` / `#222222` | 8,01:1 | spełnia |
| Tekst w kartach opinii / tło kart | `#B2B9BD` / `#363537` | 6,14:1 | spełnia |
| Przycisk: tekst / kolor marki | `#222222` / `#FF9B5E` | 7,65:1 | spełnia |
| Stopka: tekst / tło | `#EAF2F1` / `#131313` | 16,34:1 | spełnia |
| Obramowania / tło (element UI, wymóg 3:1) | `#696D77` / `#222222` | 3,07:1 | spełnia |
| Okładka: biały tekst / nakładka | `#FFFFFF` / `#393232` (70%) | 12,53:1* | spełnia |

Przycisk wymagał jedynego kompromisu: jasny tekst na pomarańczu nie osiągnąłby 4,5:1, więc tekst przycisku zrobiłem ciemny.

\* dla samej nakładki; na jaśniejszych fragmentach zdjęcia kontrast jest niższy – sprawdzić w Lighthouse (G3).

## Źródła i licencje materiałów (L3 G4)

| Materiał | Gdzie użyty | Autor | Licencja | Link |
|---|---|---|---|---|
| Zdjęcie „Mechanicy przy samochodach w warsztacie” | Okładka strony głównej, strona Oferta, wzorzec `hero-cta` | Quentin Martinez | [Licencja Pexels](https://www.pexels.com/license/) – darmowe użycie, bez obowiązku podpisu | [pexels.com/photo/33169327](https://www.pexels.com/photo/33169327/) · [autor](https://www.pexels.com/@quentin-martinez-2147503099/) |
| Font Manrope | Cały tekst strony | The Manrope Project Authors | SIL Open Font License 1.1 | [github.com/sharanda/manrope](https://github.com/sharanda/manrope) |
| Font Fira Code | Bloki kodu | The Fira Code Project Authors | SIL Open Font License 1.1 | [github.com/tonsky/FiraCode](https://github.com/tonsky/FiraCode) |
| WordPress | CMS | WordPress.org | GPLv2 lub nowsza | [wordpress.org](https://wordpress.org/) |
| Motyw Twenty Twenty-Five 1.5 | Motyw witryny | the WordPress team | GPLv2 lub nowsza | [wordpress.org/themes/twentytwentyfive](https://wordpress.org/themes/twentytwentyfive/) |
| Wtyczka Contact Form 7 6.2.5 | Formularze | Rock Lobster Inc. | GPLv2 lub nowsza | [contactform7.com](https://contactform7.com/) |
| Wtyczka Bookly 28.8 | **Wyłączona** – niezgodna z bazą SQLite (Playground): instalator tworzy tylko 19 z ok. 34 tabel, kreator konfiguracji kończy się błędem; rezerwacja online „wkrótce” | Nota-Info | GPLv3 | [wordpress.org/plugins/bookly-responsive-appointment-booking-tool](https://wordpress.org/plugins/bookly-responsive-appointment-booking-tool/) |
| Wtyczka SQLite Database Integration 3.1.3 | Baza danych (WordPress Playground) | The WordPress Team | GPLv2 lub nowsza | [wordpress.org/plugins/sqlite-database-integration](https://wordpress.org/plugins/sqlite-database-integration/) |
| Paleta kolorów | Style globalne, `theme.json` | na bazie motywu Monokai Pro (filtr „Spectagon” – własna mieszanka Spectrum + Octagon) | kolory, nie podlegają licencji | – |

Fonty są hostowane lokalnie: Manrope i Fira Code dostarcza motyw (`themes/twentytwentyfive/assets/fonts/`) i ładują się z serwera strony, więc nie ma połączenia z Google Fonts (RODO).

## Dostępny formularz kontaktowy (L3 B1)
Formularz Contact Form 7 na stronie [Kontakt](/kontakt/), zbudowany według startera.

Każde pole ma widoczną etykietę w `<label>`, a wymagane pola są oznaczone tekstem „(wymagane)”, nie tylko gwiazdką czy kolorem; dodatkowo `aria-required="true"`. Pola mają atrybuty `autocomplete` („name”, „email”, „tel”), żeby przeglądarka i czytnik ekranu wiedziały, czego oczekiwać (WCAG 1.3.5 Określenie pożądanego wkładu).

Pole zgody RODO jest domyślnie niezaznaczone i zawiera link do polityki prywatności. Włączyłem `acceptance_as_validation: on`: przycisk „Wyślij” jest zawsze aktywny, a brak zgody daje komunikat błędu. Domyślnie CF7 po prostu wyłącza przycisk, a wyłączony przycisk jest pomijany przez Tab i nie mówi, czego brakuje.

Komunikaty błędów są po polsku i podpowiadają, co poprawić (WCAG 3.3.1, 3.3.3), np. „Wpisz poprawny adres e-mail, np. jan@przyklad.pl.”, „Aby wysłać wiadomość, zaznacz zgodę na przetwarzanie danych.” Kolor błędów zmieniłem z domyślnego `#DC3232` (3,44:1 na tle – poniżej wymaganego 4,5:1) na `#FF657A` (5,6:1), a pole z błędem dostaje grubszą ramkę, żeby informacja nie zależała tylko od koloru.

Walidację sprawdziłem wysłaniem pustego formularza, błędnego e-maila, braku zgody i poprawnych danych. Lighthouse (axe-core) dla strony Kontakt: 100/100. Lokalny kontener nie ma serwera poczty, więc formularz ma `demo_mode: on` (pomija wysyłkę e-maila); na produkcji trzeba to usunąć i skonfigurować SMTP.

Formularz nie wymaga żadnego testu poznawczego (WCAG 3.3.8 Accessible Authentication): nie ma CAPTCHA z przepisywaniem znaków, łamigłówki, zadania matematycznego ani pytania-quizu CF7. Pola da się wypełniać autouzupełnianiem i wklejać do nich tekst. Ochrona przed spamem to na razie lista zabronionych słów z WordPressa (Ustawienia > Dyskusja), którą sprawdza CF7; na produkcji planuję pole-pułapkę (np. wtyczkę „Honeypot for Contact Form 7”) albo Akismet / Cloudflare Turnstile w trybie niewidocznym – żadne z nich nie wymaga od użytkownika testu poznawczego.

Rozmiary celów (WCAG 2.5.8 Target Size): pola tekstowe i przycisk mają min. 44 px wysokości, pole zgody 24×24 px, a link do polityki prywatności jest w tekście zdania (wyjątek „inline” w kryterium). Wszystkie cele mają co najmniej 24×24 px.

## Preferencje użytkownika i skip link (L3 B2)
Dodatkowy CSS ze startera, uzupełniony.

Przy `prefers-reduced-motion: reduce` animacje i przejścia są skrócone do 0,01 ms i nie ma płynnego przewijania (sprawdzone emulacją w DevTools: `scroll-behavior: auto`). W trybie wysokiego kontrastu Windows (`forced-colors: active`) przyciski, pola formularza i karty dostają obramowanie w kolorach systemowych (`ButtonText`, `CanvasText`), a fokus w kolorze `Highlight`. Zrzut z emulacji: [`raport/b2/forced-colors.png`](raport/b2/forced-colors.png).

Skip link „Przejdź do treści” jest pierwszym elementem po naciśnięciu Tab; po fokusie robi się widoczny, a Enter przenosi do `#wp--skip-link--target` (link dodaje sam WordPress, wygląd po fokusie to domyślny styl WordPressa). Zrzut: [`raport/b2/skip-link-po-tab.png`](raport/b2/skip-link-po-tab.png).

- Czytnik ekranu – zamiast testu słuchowego VoiceOver sprawdziłem automatycznie strukturę, którą czytnik odczyta (drzewo dostępności i kolejność w DOM, przez Chrome DevTools Protocol). Strona główna: jeden H1 w Okładce, dalej H2 i H3 bez przeskakiwania poziomów; lądmarki w kolejności nagłówek → nawigacja → treść główna → stopka; skip link jest pierwszym elementem strony i prowadzi do istniejącego celu; jedyny obraz ma pełny alt. Formularz na Kontakt czyta się pole po polu: każde pole z widoczną etykietą i „(wymagane)" w tekście, autocomplete (imię, e-mail, telefon), zgoda RODO domyślnie niezaznaczona. Poza tym zrobiłem test słuchowy VoiceOverem (Cmd+F5, Safari) na stronie głównej i Kontakt: skip link był pierwszym elementem po Tabie, nagłówki czytały się po kolei, pola formularza podawały etykietę i stan wymagane. Wszystko działało, bez większych problemów.

## Blueprint (L1 B1)
[`blueprint-schema.json`](blueprint-schema.json) odtwarza strukturę projektu w Playground: język pl_PL, motyw Twenty Twenty-Five, Contact Form 7, wszystkie 11 stron z mapy strony, 2 kategorie Aktualności, usunięcie domyślnych treści (w tym wpisu „Witaj świecie!”), statyczna strona główna i strona wpisów. Treści i wygląd są w eksporcie ZIP. Bookly usunąłem z blueprintu, bo okazał się niezgodny z SQLite. [`blueprint-ai.json`](blueprint-ai.json) to wersja wygenerowana przez AI do porównania.
