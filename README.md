# Berufsmesse

Eine PHP/MySQL-Webanwendung zur Organisation und Durchführung von Berufsmessen – inklusive Aussteller-Verwaltung, Schüler-Einschreibung, Raumzuteilung, QR-Code-Check-in und Admin-Dashboard.

---

## Inhaltsverzeichnis

- [Features](#features)
- [Schnellstart mit Docker](#schnellstart-mit-docker)
- [Umgebungsvariablen](#umgebungsvariablen)
- [Erster Start – Admin-Konto & Setup](#erster-start--admin-konto--setup)
- [Datenbank-Migrationen](#datenbank-migrationen)
- [phpMyAdmin (optional)](#phpmyadmin-optional)
- [Produktionsbetrieb & HTTPS](#produktionsbetrieb--https)
- [Anwendung aktualisieren](#anwendung-aktualisieren)
- [Backup & Wiederherstellung](#backup--wiederherstellung)
- [Lokale Entwicklung (ohne Docker)](#lokale-entwicklung-ohne-docker)
- [Verzeichnisstruktur](#verzeichnisstruktur)

---

## Features

| Bereich | Funktion |
|---|---|
| **Aussteller** | Anlegen, Bearbeiten, Logo-Upload, Dokumente, Angebotstypen (Ausbildung, Studium …), dynamische Branchen |
| **Schüler-Frontend** | Aussteller-Übersicht mit Branchenfilter & Suche, Angebots-Badges, Detailansicht |
| **Einschreibung** | Schüler wählen bis zu 3 Aussteller, automatische Slot-Zuteilung |
| **Admin-Dashboard** | Statistiken, Raumplan, manuelle Zuteilung, Zuteilung zurücksetzen |
| **Räume** | Verwaltung, slot-spezifische Kapazitäten |
| **Benutzer** | Rollen (admin / teacher / orga / student), granulares Berechtigungssystem, Gruppen |
| **QR-Code** | Check-in-Seite, Token-Verwaltung |
| **Berichte** | PDF-Export (Raumpläne, Klassenlisten, persönliche Pläne) |
| **Einstellungen** | Einschreibezeitraum, Veranstaltungsdatum, Branchen-CRUD, QR-Code-URL |
| **Audit-Log** | Protokollierung aller Admin-Aktionen |

---

## Schnellstart mit Docker

### Option A: Vorgefertigtes Docker Image verwenden

Das fertige Docker Image wird automatisch bei jedem Push auf den `main` Branch erstellt und ist verfügbar über GitHub Container Registry.

#### 1. Docker Compose mit vorgefertigtem Image

Erstelle eine `docker-compose.yml`:

```yaml
version: '3.8'

services:
  db:
    image: mariadb:10.9
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASS}
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASS}
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5

  app:
    image: ghcr.io/schlaumischlumpf/berufsmesse:latest
    ports:
      - "${APP_PORT:-9000}:80"
    environment:
      DB_HOST: db
      DB_USER: ${DB_USER}
      DB_PASS: ${DB_PASS}
      DB_NAME: ${DB_NAME}
      BASE_URL: ${BASE_URL:-/}
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

volumes:
  db_data:
```

#### 2. Environment-Variablen setzen

Erstelle eine `.env` Datei:

```bash
DB_ROOT_PASS=mein_sicheres_root_passwort
DB_USER=berufsmesse
DB_PASS=mein_sicheres_app_passwort
DB_NAME=berufsmesse
APP_PORT=9000
BASE_URL=/
```

#### 3. Container starten

```bash
docker compose up -d
```

Die Anwendung ist dann unter `http://localhost:9000` erreichbar.

---

### Option B: Selbst bauen

#### Voraussetzungen

- [Docker Engine](https://docs.docker.com/engine/install/) ≥ 24
- [Docker Compose](https://docs.docker.com/compose/install/) ≥ 2 (in neueren Docker-Versionen als `docker compose` enthalten)

### 1. Repository klonen

```bash
git clone https://github.com/schlaumischlumpf/Berufsmesse.git
cd Berufsmesse
```

### 2. Umgebungsvariablen einrichten

```bash
cp .env.example .env
```

Öffne `.env` in einem Editor und **setze sichere Passwörter**:

```dotenv
DB_ROOT_PASS=mein_sicheres_root_passwort
DB_USER=berufsmesse
DB_PASS=mein_sicheres_app_passwort
DB_NAME=berufsmesse
APP_PORT=9000
BASE_URL=/
```

> ⚠️ Committe die `.env`-Datei **niemals** in ein Git-Repository.

### 3. Container bauen und starten

```bash
docker compose up -d --build
```

Beim ersten Start:
1. Der `db`-Container (MariaDB) startet und initialisiert die Datenbank.  
2. Der `app`-Container wartet auf die Datenbank und führt danach automatisch `migrations.sql` aus.  
3. Apache startet und die Anwendung ist erreichbar.

### 4. Anwendung aufrufen

```
http://<server-ip>:9000
```

> Beim ersten Aufruf wird automatisch ein Admin-Konto über die Einrichtungsseite angelegt – siehe [Erster Start](#erster-start--admin-konto--setup).

---

## Umgebungsvariablen

| Variable | Standard | Beschreibung |
|---|---|---|
| `DB_HOST` | `db` | Hostname der Datenbank (in Docker immer `db`) |
| `DB_USER` | `berufsmesse` | Datenbankbenutzer |
| `DB_PASS` | *(leer)* | **Pflichtfeld.** Datenbankpasswort |
| `DB_ROOT_PASS` | *(leer)* | **Pflichtfeld.** MariaDB-Root-Passwort |
| `DB_NAME` | `berufsmesse` | Datenbankname |
| `APP_PORT` | `9000` | Host-Port für die Webanwendung |
| `BASE_URL` | `/` | Basis-URL, z. B. `/berufsmesse/` bei Unterverzeichnis |
| `APP_ENV` | `production` | `development` aktiviert PHP-Fehleranzeige |
| `PMA_PORT` | `8080` | Host-Port für phpMyAdmin (nur mit `--profile tools`) |

---

## Erster Start – Admin-Konto & Setup

Nach dem ersten `docker compose up` musst du ein Admin-Konto anlegen:

1. Öffne `http://<server-ip>:9000/register.php`
2. Registriere dich mit einem Benutzernamen und Passwort
3. Setze die Rolle manuell auf `admin` – entweder über phpMyAdmin oder direkt per SQL:

```bash
docker compose exec db mysql -u"${DB_USER}" -p"${DB_PASS}" berufsmesse \
  -e "UPDATE users SET role='admin' WHERE username='dein_benutzername';"
```

4. Logge dich auf `http://<server-ip>:9000` ein.
5. Navigiere zu **Einstellungen → System-Einstellungen** und konfiguriere den Einschreibezeitraum und das Veranstaltungsdatum.

---

## Datenbank-Migrationen

Migrationen werden beim Container-Start **automatisch** über `docker-entrypoint.sh` ausgeführt. Alle Statements in `migrations.sql` verwenden `IF NOT EXISTS` / `IF EXISTS`, sodass ein erneutes Ausführen sicher (idempotent) ist.

### Manuelle Ausführung

Falls du Migrationen manuell anwenden möchtest:

```bash
docker compose exec app mysql -h db \
  -u"${DB_USER}" -p"${DB_PASS}" berufsmesse < migrations.sql
```

Oder über phpMyAdmin (siehe unten).

---

## phpMyAdmin (optional)

phpMyAdmin ist als optionaler Service mit dem `tools`-Profil enthalten und wird standardmäßig **nicht** gestartet.

```bash
# phpMyAdmin zusätzlich starten
docker compose --profile tools up -d

# Erreichbar unter:
# http://<server-ip>:8080
```

> ⚠️ Starte phpMyAdmin nicht dauerhaft in Produktion. Nutze es nur für Wartungsarbeiten und stoppe es danach wieder.

---

## Produktionsbetrieb & HTTPS

### Mit einem Reverse Proxy (empfohlen)

Für HTTPS-Betrieb empfiehlt sich ein Reverse Proxy wie **Nginx Proxy Manager**, **Caddy** oder **Traefik** vor dem App-Container.

Beispiel mit **Caddy** (`Caddyfile` auf dem Host-System):

```caddy
berufsmesse.example.com {
    reverse_proxy localhost:9000
}
```

Caddy übernimmt automatisch die TLS-Zertifikate via Let's Encrypt.

### HTTPS-Konfiguration in der App

Sobald HTTPS aktiv ist, aktiviere das Secure-Cookie-Flag in `config.php`:

```php
ini_set('session.cookie_secure', 1);
```

Oder setze die Umgebungsvariable `BASE_URL` auf die vollständige HTTPS-URL:

```dotenv
BASE_URL=/
```

### Firewall

In Produktion sollte nur Port 443 (HTTPS) und ggf. 80 (HTTP → Redirect) öffentlich erreichbar sein. Port 9000 und 8080 sollten **nicht** direkt im Internet exponiert werden.

---

## Anwendung aktualisieren

```bash
# 1. Neuen Code holen
git pull origin main

# 2. Image neu bauen und Container neu starten
docker compose up -d --build

# Die migrations.sql wird beim Neustart automatisch angewendet.
```

> Uploads (Logos, Dokumente) und Datenbankdaten bleiben durch Docker-Volumes erhalten.

---

## Backup & Wiederherstellung

### Datenbank-Backup

```bash
# Backup erstellen (Passwort wird sicher aus der Umgebungsvariable gelesen)
docker compose exec db sh -c \
  'MYSQL_PWD="${MYSQL_PASSWORD}" mysqldump -u"${MYSQL_USER}" "${MYSQL_DATABASE}"' \
  > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Datenbank-Wiederherstellung

```bash
docker compose exec -T db sh -c \
  'MYSQL_PWD="${MYSQL_PASSWORD}" mysql -u"${MYSQL_USER}" "${MYSQL_DATABASE}"' \
  < backup_2025-01-01.sql
```

### Uploads-Backup (Logos & Dokumente)

```bash
# Volume-Pfad ermitteln
docker volume inspect berufsmesse_uploads

# Dateien kopieren
docker run --rm \
  -v berufsmesse_uploads:/data \
  -v $(pwd):/backup \
  alpine tar czf /backup/uploads_backup.tar.gz -C /data .
```

### Uploads-Wiederherstellung

```bash
docker run --rm \
  -v berufsmesse_uploads:/data \
  -v $(pwd):/backup \
  alpine sh -c "cd /data && tar xzf /backup/uploads_backup.tar.gz"
```

---

## Lokale Entwicklung (ohne Docker)

### Voraussetzungen

- PHP ≥ 8.1 mit den Extensions `pdo_mysql`, `mbstring`, `gd`, `zip`
- MariaDB ≥ 10.6 oder MySQL ≥ 8.0
- Apache mit `mod_rewrite` (oder ein alternativer Webserver)

### Setup

```bash
# 1. Repository klonen
git clone https://github.com/schlaumischlumpf/Berufsmesse.git
cd Berufsmesse

# 2. Datenbank anlegen
mysql -u root -p -e "CREATE DATABASE berufsmesse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Migrationen anwenden
mysql -u root -p berufsmesse < migrations.sql

# 4. Umgebungsvariablen setzen (oder config.php direkt anpassen)
export DB_HOST=127.0.0.1
export DB_USER=root
export DB_PASS=dein_passwort
export DB_NAME=berufsmesse
export APP_ENV=development

# 5. Eingebetteten PHP-Server starten (nur für Entwicklung)
php -S localhost:8000
```

Öffne `http://localhost:8000` im Browser.

---

## Verzeichnisstruktur

```
Berufsmesse/
├── api/                    # JSON-APIs (AJAX-Endpunkte)
├── assets/                 # CSS, JS, Bilder
├── fpdf/                   # FPDF-Bibliothek für PDF-Export
├── pages/                  # Seitenmodule (per ?page= geladen)
│   ├── admin-exhibitors.php
│   ├── admin-settings.php  # inkl. Branchen-CRUD
│   └── exhibitors.php
├── uploads/                # Hochgeladene Dateien (per Docker Volume persistiert)
├── .env.example            # Vorlage für Umgebungsvariablen
├── compose.yaml            # Docker Compose Konfiguration
├── config.php              # App-Konfiguration (liest Env-Variablen)
├── docker-entrypoint.sh    # Wartet auf DB, führt Migrationen aus
├── Dockerfile              # Multi-stage-ready PHP/Apache Image
├── functions.php           # Hilfsfunktionen (DB, Berechtigungen, Audit-Log …)
├── index.php               # Einstiegspunkt / Router
├── migrations.sql          # Alle Datenbankmigrationen (idempotent)
└── setup.php               # Webbasiertes Setup-Tool (nur für Admins)
```
