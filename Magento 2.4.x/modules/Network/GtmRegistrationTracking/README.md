# Network_GtmRegistrationTracking

Moduł Magento śledzący 2-etapową rejestrację konta na `42bites.pl`
poprzez `window.dataLayer` (do przechwycenia przez GTM).

## Co robi

- **Krok 1 (Formularz)** – push do `dataLayer` przy każdym wejściu na
  `/customer/account/create/`. Realizowane przez layout XML podpięty
  pod natywny handle `customer_account_create` – nie wymaga żadnej
  modyfikacji core'owych plików ani theme'u.

- **Krok 2 (Założenie konta)** – push do `dataLayer` dopiero **po
  faktycznym utworzeniu konta**, a nie po samym kliknięciu przycisku.
  Magento po sukcesie rejestracji (kontroler `CreatePost`) przekierowuje
  na `/customer/account/` (panel klienta) – nie ma osobnego URL-a
  "dziękujemy". Dlatego:
  1. Observer podpięty pod natywny event `customer_register_success`
     ustawia flagę w sesji klienta w momencie sukcesu.
  2. Blok na stronie `/customer/account/` (handle `customer_account_index`)
     sprawdza tę flagę i **tylko jeśli jest ustawiona** renderuje
     `dataLayer.push(...)`, po czym natychmiast ją kasuje.
  3. Dzięki temu zwykłe logowanie i odświeżenie strony (F5) **nie**
     wywołują eventu drugi raz – tylko faktyczny moment po rejestracji.

## Instalacja

1. Skopiuj katalog `app/code/Network/GtmRegistrationTracking` do
   analogicznej lokalizacji w projekcie (`app/code/Network/...`).
2. Zmień namespace `Network` na docelowego vendora projektu we
   wszystkich plikach PHP/XML, jeśli chcesz zachować spójną konwencję.
3. Uruchom:
   ```
   bin/magento module:enable Network_GtmRegistrationTracking
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```
4. (Tryb produkcyjny) doślij static content jeśli wdrażasz przez
   pipeline wymagający `setup:static-content:deploy` – tu nie jest to
   wymagane, bo skrypty są inline w .phtml, ale nie zaszkodzi.

## Uwaga o cache (Varnish / Full Page Cache)

- `/customer/account/create/` to formularz identyczny dla każdego
  gościa – może być bezpiecznie cache'owany przez FPC, event i tak
  odpali się z JS przy każdym załadowaniu strony z cache.
- `/customer/account/` to strona prywatna zalogowanego klienta –
  Magento **nie** cache'uje jej w FPC dla zalogowanych użytkowników,
  więc odczyt świeżej flagi z sesji przy każdym request jest bezpieczny.

## Konfiguracja GTM (dopasowana do powyższych eventów)

1. Zmienne warstwy danych (Variable → Data Layer Variable):
   - `DLV - registration_step_number`
   - `DLV - registration_step_name`
2. Trigger: Custom Event → nazwa zdarzenia `registration_step`.
3. Tag (np. GA4 Event): event name `registration_step`, parametry z
   powyższych zmiennych. Możesz dodatkowo rozbić na dwa triggery z
   warunkiem `registration_step_number equals 1` / `equals 2`, jeśli
   chcesz mieć je jako osobne eventy w GA4.

## Cloudflare - na co uważać

Jeśli przed Magento stoi Cloudflare, sprawdź trzy rzeczy:

1. **Rocket Loader** - jeśli włączony, może przesuwać/async-ować inline
   `<script>`. Oba szablony (`registration_step1.phtml`,
   `registration_step2.phtml`) mają już `data-cfasync="false"`, co
   każe Cloudflare zostawić je w spokoju.

2. **Cache Rules / Page Rules "Cache Everything" na `/customer/*`**
   - to jest krytyczne, i to niezależnie od trackingu. Jeśli panel
   klienta (`/customer/account/`) jest cache'owany na brzegu
   Cloudflare, jeden użytkownik może dostać z cache stronę renderowaną
   dla innego użytkownika (wyciek danych osobowych/zamówień), a przy
   okazji Krok 2 może się nie odpalić wcale albo odpalić dla złej
   osoby. Sprawdź w Cloudflare: Caching → Cache Rules / Page Rules,
   czy `/customer/*` (i najlepiej też `/checkout/*`) mają ustawione
   "Bypass cache" / "No cache". Domyślne ustawienia Cloudflare pomijają
   cache przy obecności ciasteczek sesji, ale reguła "Cache Everything"
   to nadpisuje.

3. **Bot Fight Mode / Super Bot Fight Mode / WAF managed rules** -
   jeśli blokują lub wyświetlają Turnstile/challenge na POST do
   `/customer/account/createpost`, rejestracja w ogóle się nie uda,
   więc event `customer_register_success` nie ma prawa się odpalić.
   To nie jest coś, co naprawi ten moduł - sprawdź Security →
   Events w Cloudflare pod kątem zablokowanych requestów na ten URL.

## Testowanie

1. Włącz GTM Preview / Tag Assistant.
2. Wejdź na `https://42bites.pl/customer/account/create/` →
   powinieneś zobaczyć event `registration_step` (number: 1).
3. Wypełnij formularz i utwórz konto → po przekierowaniu na
   `/customer/account/` powinieneś zobaczyć event `registration_step`
   (number: 2).
4. Odśwież stronę `/customer/account/` (F5) → event **nie** powinien
   wystąpić drugi raz (flaga została skonsumowana).
5. Wyloguj się i zaloguj ponownie zwykłym loginem → event **nie**
   powinien wystąpić (bo `customer_register_success` odpala się tylko
   przy rejestracji, nie przy logowaniu).
