# NetMafia 2.0 – Technikai Specifikáció

## I. Projekt Vízió és Filozófia
A projekt célja egy modern, böngésző alapú maffia játék létrehozása, elkerülve a monolitikus keretrendszerek (pl. Laravel, Symfony Full Stack) lassúságát és felesleges bonyolultságát ("bloat"). Ehelyett a "Best-of-Breed" (Legjobbat a kategóriából) megközelítést alkalmazzuk, szabványos PHP komponensekből építkezve.

### Alapelvek
*   **Moduláris Monolit (Modular Monolith):** A rendszer egyetlen alkalmazásként fut, de a belső struktúra szigorúan elszeparált üzleti modulokra (Kocsma, Bank, Banda) bomlik.
*   **ADR (Action-Domain-Responder):** Az elavult MVC helyett minden HTTP végpont egyetlen, dedikált osztály (Action), amely az üzleti logikát (Domain) hívja, majd átadja az eredményt a megjelenítésnek (Responder).
*   **Framework-Agnosztikus:** A kód 90%-a (az üzleti logika) tiszta PHP, nem függ a keretrendszertől.
*   **Szigorú Szabványkövetés:** PSR-4, PSR-7, PSR-11, PSR-15.

## II. Technológiai Stack (A Vas)
A projekt a Composer csomagkezelőre épül. Az alábbi könyvtárak alkotják a gerincet:

| Réteg | Választott Technológia | Indoklás |
| :--- | :--- | :--- |
| **HTTP Keret** | Slim 4 (vagy Mezzio) | Minimális overhead, csak a Request/Response folyamatot kezeli. |
| **Router** | FastRoute | A leggyorsabb PHP router a piacon. |
| **Container (DI)** | PHP-DI | Erős Autowiring képesség (nem kell kézzel konfigurálni mindent). |
| **Adatbázis** | Doctrine DBAL | Biztonságos SQL Query Builder, tranzakciókezelés (ORM nélkül). |
| **Template** | Twig | Biztonságos (Auto-escape), öröklődés támogatása, tiszta szintaxis. |
| **Frontend** | HTMX | AJAX kérések, HTML cserebere és SPA-élmény JavaScript írása nélkül. |
| **Realtime** | Ratchet (PHP) + Redis | WebSocket szerver és üzenetközvetítő csatorna. |
| **Biztonság** | Slim-CSRF | Cross-Site Request Forgery elleni védelem. |
| **Config** | vlucas/phpdotenv | Környezeti változók (.env) kezelése. |
| **Minőség** | PHPStan | Statikus kódanalízis a típusbiztonságért. |

## III. Architektúra és Mappaszerkezet
A rendszer fizikai felépítése tükrözi a moduláris logikát.

```plaintext
/netmafia
  /bin                  <-- Háttér folyamatok (WebSocket server, Cron)
  /config               <-- DI konfig, Route definíciók, Middleware setup
  /public               <-- Web root (index.php, css, js/modules)
  /src
     /Infrastructure    <-- Technikai megvalósítások (Redis, SessionService, Logger)
     /Shared            <-- KÖZÖS elemek (Kernel)
        /Domain
           /ValueObjects (Money.php, UserId.php)
           /Entities     (User.php - csak alap adatok)
     /Web               <-- Webes keretrendszer elemek (BaseAction, Middleware)
     /Modules           <-- A JÁTÉK ÜZLETI LOGIKÁJA
        /Kocsma
           /Actions     <-- BuyDrinkAction.php (HTTP végpont)
           /Domain      <-- AlcoholService.php (Üzleti logika), Events
           /WebSocket   <-- KocsmaTopicHandler.php (Realtime logika)
           /Templates   <-- kocsma.twig (HTML)
        /Banda          <-- Banda Modul (Saját al-mappákkal)
  /templates            <-- Globális layoutok (base.twig, 404.twig, flash.twig)
  composer.json         <-- Autoloading beállítások
```

## IV. Backend Fejlesztési Szabálykönyv

### 1. Kódolási Szabványok
*   **Szigorú Típusok:** Minden PHP fájl első sora kötelezően: `declare(strict_types=1);`.
*   **Dependency Injection:** Tilos a `new` kulcsszó használata szolgáltatásokra (Service, Repository). Mindent a konstruktorban kérünk be.
*   **Value Objects:** Primitív típusok (`int $money`) helyett Értékobjektumok (`Money $amount`) használata a validáció garantálására.
*   **No Magic:** Kerüljük a `__get`, `__set` metódusokat és a `global` változókat.
*   **Early Return:** A mélyen beágyazott if-else helyett a feltételek korai ellenőrzése és visszatérés/hiba dobása.

### 2. Adatbázis és Konzisztencia (Race Condition Védelem)
Mivel ez egy gazdasági játék, a pénz és tárgyak kezelése kritikus.
*   **Atomicitás:** Minden módosító műveletet (INSERT, UPDATE, DELETE) tranzakcióba kell zárni (`$conn->beginTransaction() ... $conn->commit()`).
*   **Pesszimista Lock:** Erőforrás lekérdezésekor, ha azt módosítani fogjuk, kötelező a `SELECT ... FOR UPDATE` használata.
*   **Constraints:** Az adatbázisban a `money`, `bullets` oszlopok legyenek `UNSIGNED` típusúak, hogy a DB szinten is védve legyünk a negatív egyenlegtől.
*   **N+1 Query Elkerülése:** Ciklusban tilos SQL lekérdezést futtatni. Használj JOIN-t vagy gyűjtsd ki az ID-kat előre.

### 3. Middleware Pipeline (A Kapuőrök)
A nem-üzleti logikát kiszervezzük a csővezetékbe:
1.  **ErrorMiddleware** (Hibák elkapása)
2.  **SessionMiddleware** (Session indítása)
3.  **CsrfMiddleware** (Űrlap védelem)
4.  **AuthMiddleware** (Be van lépve?)
5.  **RoutingMiddleware** (Hova megy?)
6.  **Action** (A te kódod)

## V. Frontend Stratégia (HTMX & JS)

### 1. HTMX Alapelvek
*   A Frontend "buta". Nem tud üzleti logikát.
*   Minden interakció (gombnyomás, űrlap, navigáció) HTMX attribútumokkal történik pl: `(hx-post="/kocsma/buy", hx-target="#game-content")`.
*   A szerver HTML-t (Partial) küld vissza, nem JSON-t (kivéve speciális esetek).

### 2. JavaScript Modularitás és Életciklus
Ha egyedi JS logika kell (pl. animáció), azt modulárisan kezeljük a memóriaszivárgás és ütközések elkerülése érdekében.
*   **Fájlok:** `public/js/modules/kocsma.js`
*   **Lifecycle API:** Minden modulnak kötelező implementálnia:
    *   `init()`: Eseményfigyelők felrakása, Timer indítása.
    *   `destroy()`: Takarítás (Timer leállítása, Eventek levétele) navigáció előtt.

## VI. Realtime Stratégia (WebSocket)
A rendszer "Hibrid" modellt használ a skálázhatóság érdekében.
1.  **Webes Szál (Szinkron):** A felhasználó cselekszik (HTTP POST). Az Action meghívja a Service-t. A Service elvégzi a DB módosítást.
2.  **Esemény Busz:** A Service eldob egy eseményt (pl. `UserBecameDrunk`).
3.  **Redis Pub/Sub:** A rendszer bedobja az esemény adatait a Redisbe.
4.  **WebSocket Szerver (Aszinkron):** A háttérben futó PHP processz (`bin/socket_server.php`) figyeli a Redist.
5.  **Broadcast:** A WebSocket szerver kiküldi az üzenetet a releváns klienseknek.

## VII. Biztonság
*   **XSS:** A Twig template motor automatikusan escape-el mindent. Tilos a `| raw` filter használata user inputon.
*   **CSRF:** A Middleware automatikusan ellenőrzi a tokeneket minden POST kérésnél. A tokent a `<head>`-be injektáljuk, ahonnan a HTMX kiolvassa.
*   **Session:** A Session kezelés szerver oldalon, `HttpOnly` és `Secure` cookie-kkal történik. Az Actionökben a `SessionService` wrapper használata kötelező a `$_SESSION` közvetlen piszkálása helyett.
*   **Jelszavak:** Soha nem tárolunk jelszót nyílt szövegként (BCrypt/Argon2 használata kötelező). DB jelszavak `.env` fájlban, ami nincs verziókövetés alatt.
