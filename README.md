# Revizor Asszisztens 1.0

Bankegyeztető és bizonylat-ellenőrző rendszer adventista egyházi gyülekezetek számára. A banki kivonatok (CSV) és az OTS könyvelési adatok összevetése, automatikus párosítása, dokumentum-ellenőrzése.

## Funkciók

- **Bankegyeztetés** (reconciliation.php) – Automatikus progresszív párosítás 7 lépésben, kézi párosítás, státuszkezelés, CSV export
- **Bizonylat Ellenőrzés** (document_check.php) – Dokumentumok meglétének rögzítése, részletes OTS panel megtekintése
- **Tranzakció Kereső** (search.php) – Keresés banki és OTS tranzakciókban összeg, dátum, szöveg alapján
- **OTS Letöltő** (all_transactions/all_transactions_multi.php) – OTS tranzakciók böngészése, letöltése
- **CSV Feltöltés** (upload.php) – Egyedi és tömeges banki CSV import AJAX-szal
- **Gyülekezet választás** (select-church.php) – Billentyűzetes navigációval, szerepkör-alapú hozzáférés

## Rendszerkövetelmények

- **PHP** 7.4 – 8.2
- **MySQL** 5.7+ vagy MariaDB 10.3+
- **Webszerver**: Apache (vagy bármi, ami PHP-t futtat)
- **OTS adatbázis**: Az OTS rendszer adatbázisához olvasási hozzáférés
- **Böngésző**: modern böngésző (Chrome, Firefox, Edge)

## Telepítés

### Fájlok feltöltése

1. Töltsd le vagy klónozd a repót
2. Töltsd fel a fájlokat a webszerver `public_html` (vagy `www`, `htdocs`) könyvtárába
3. Győződj meg róla, hogy a `_storage/documents` könyvtár írható a web user számára

### Revizor adatbázis létrehozása

A Revizor Asszisztens saját adatbázisban tárolja a banki tételeket, egyeztetéseket, naplózást. Ezt az adatbázist a webszerveren kell létrehozni.

#### SQL-paranccsal (phpMyAdmin vagy parancssor):

```sql
CREATE DATABASE revizor_db CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
CREATE USER 'revizor_rw'@'localhost' IDENTIFIED BY 'erős_jelszó';
GRANT ALL PRIVILEGES ON revizor_db.* TO 'revizor_rw'@'localhost';
FLUSH PRIVILEGES;
```

#### cPanel-ben:

1. **MySQL Databases** menüpont
2. Görgess a **Create New Database** szakaszhoz, írd be a `revizor_db` nevet, majd kattints a **Create Database** gombra
3. Görgess a **Create New User** szakaszhoz
4. Add meg a felhasználónevet (pl. `revizor_rw`) és egy erős jelszót, majd kattints a **Create User** gombra
5. Görgess a **Add User To Database** szakaszhoz
6. Válaszd ki az imént létrehozott felhasználót és az adatbázist (pl. `tetkuhu1_revizor_db`), majd kattints az **Add** gombra
7. A megjelenő jogosultság listában **ALL PRIVILEGES**-t jelöld be (az összeset), majd kattints a **Make Changes** gombra

#### phpMyAdmin-ban (ha elérhető):

1. Nyisd meg a phpMyAdmin-t
2. Kattints a **New** (Új) gombra a bal oldali panelfülön
3. Add meg az adatbázis nevét: `revizor_db`, válaszd a `utf8mb4_hungarian_ci` karakterkódolást, majd kattints a **Create** gombra
4. Menj a **Felhasználók** fülre, kattints az **Add user** gombra
5. Add meg a felhasználónevet (pl. `revizor_rw`), jelszót
6. **Globális jogosultságoknál** ne pipálj be semmit, görgess le a **Database-specific privileges** részhez
7. Válaszd ki a `revizor_db`-t a listából, majd pipáld be az **ALL PRIVILEGES**-t (Check all)
8. Kattints az **Elküld** gombra

#### Táblák létrehozása:

Az adatbázis táblák **automatikusan létrejönnek** az első használatkor (a PHP fájlok `CREATE TABLE IF NOT EXISTS` utasításokkal hozzák létre őket). Alternatívaként futtathatod a `database/migration_002_audit_docs.sql` fájlt is.

### OTS adatbázis – csak olvasási user létrehozása (cPanel)

A Revizor Asszisztens az OTS adatbázisból olvassa a gyülekezeteket, tranzakciókat és személyeket. Biztonsági okból **csak olvasási (SELECT) jogosultsággal** rendelkező adatbázis-felhasználót kell létrehozni.

#### cPanel-ben:

1. **MySQL Databases** menüpont
2. Görgess a **Create New User** szakaszhoz
3. Add meg a felhasználónevet (pl. `ots_ro`) és egy erős jelszót, majd kattints a **Create User** gombra
4. Görgess a **Add User To Database** szakaszhoz
5. Válaszd ki az imént létrehozott felhasználót és az OTS adatbázist (pl. `tetkuhu1_mant687`), majd kattints az **Add** gombra
6. A megjelenő jogosultság listában **csak a SELECT** jelölőnégyzetet pipáld be, a többit hagyd üresen
7. Kattints a **Make Changes** gombra

#### phpMyAdmin-ban (ha elérhető):

1. Nyisd meg a phpMyAdmin-t
2. Menj a **Felhasználók** (Users) fülre
3. Kattints az **Új felhasználó hozzáadása** (Add user) gombra
4. Add meg a felhasználónevet és jelszót
5. A **Globális jogosultságok** (Global privileges) résznél **ne pipálj be semmit**
6. Kattints az **Elküld** gombra
7. Menj vissza a **Felhasználók** fülre, kattints a **Jogosultságok szerkesztése** (Edit privileges) ikonra az új user mellett
8. Válaszd ki az **Adatbázis-specifikus jogosultságok** (Database-specific privileges) fület
9. Válaszd ki az OTS adatbázist a legördülőből
10. A **SELECT** előtti jelölőnégyzetet pipáld be, majd **Elküld**

### Konfiguráció

1. Másold a `config/app.php` fájlt `config/app.local.php` néven (a `.gitignore` már tartalmazza)

2. Állítsd be az adatbázis kapcsolatokat a `config/app.local.php` fájlban:

```php
<?php
return [
    'demo_mode' => false,
    'superadmin_user_id' => null,
    'db' => [
        'revizor' => [
            'host' => 'localhost',
            'user' => 'revizor_rw',
            'pass' => 'revizor_jelszó',
            'name' => 'revizor_db',
        ],
        'ots' => [
            'host' => 'localhost',
            'user' => 'ots_ro',
            'pass' => 'ots_olvasasi_jelszó',
            'name' => 'ots',
        ],
    ],
    'storage' => [
        'documents_path' => __DIR__ . '/../_storage/documents',
    ],
];
```

Alternatív megoldásként környezeti változókat is használhatsz:

| Változó | Leírás |
|---------|--------|
| `REVIZOR_DB_HOST` | Revizor adatbázis hoszt |
| `REVIZOR_DB_USER` | Revizor adatbázis user |
| `REVIZOR_DB_PASS` | Revizor adatbázis jelszó |
| `REVIZOR_DB_NAME` | Revizor adatbázis neve |
| `OTS_DB_HOST` | OTS adatbázis hoszt |
| `OTS_DB_USER` | OTS olvasási user |
| `OTS_DB_PASS` | OTS olvasási jelszó |
| `OTS_DB_NAME` | OTS adatbázis neve |

### OTS konfiguráció

A Revizor az OTS rendszer `constant.php` fájlját használja a session-kezeléshez. Győződj meg róla, hogy az `ots/` könyvtár a Revizor könyvtárával egy szinten van (pl. `/revizor/` és `/ots/`).

Az `ots/constant.php`-ban definiált konstansok:
- `GC_LOGIN_COOKIE` – A bejelentkezett session azonosító
- `GN_LAST_ACTIVE` – Az utolsó aktivitás időbélyege
- `GC_USER_FULL_NAME` – A felhasználó teljes neve

## Biztonság

- SQL injection ellen: prepared statement-ek
- XSS ellen: htmlspecialchars() ENT_QUOTES-szal
- CSRF védelem: random token minden űrlaphoz
- Session timeout: 20 perc inaktivitás után
- Szerepkör-alapú hozzáférés: admin/nem-admin
- Egyház-szintű adatelérés: nem-admin csak a saját gyülekezetét látja
- Fájlfeltöltés memóriában feldolgozva (nem kerül lemezre)

## Verziótörténet

### 1.0 – 2025
- Kezdeti verzió: bankegyeztetés, feltöltés, alap admin felület
- Bizonylat-ellenőrzés modul (document_check.php)
- Tranzakció kereső (search.php)
- Church scope – nem-adminok automatikus gyülekezet-szűrése
- Több tételes párosítás támogatása
- Billentyűzetes navigáció a gyülekezet-választóban
- AJAX tömeges feltöltés és feltöltési naplózás
- OTS összeg egyeztetés ellenőrzés
- Session-kezelés javítás (OTS popup redirect)
