# QR Code Generator con Logo

> Plugin WordPress per generare QR code personalizzati con logo centrale, interfaccia admin e shortcode frontend.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b?logo=wordpress&logoColor=white)
![License](https://img.shields.io/badge/License-GPL2-green)
![Version](https://img.shields.io/badge/Version-1.2.0-blue)
[![Release](https://img.shields.io/github/v/release/dadebertolino/qrcode-generator-plugin)](https://github.com/dadebertolino/qrcode-generator-plugin/releases/latest)

---

## Funzionalità

- Generazione QR code PNG con correzione errori alta (H)
- Logo centrale opzionale con sfondo bianco arrotondato
- Interfaccia admin dedicata
- Shortcode frontend con form AJAX interattivo
- Shortcode statico con caching automatico (24h)
- Rate limiting per richieste pubbliche (10 req/IP ogni 60s)
- Pulizia automatica dei file generati (> 30 giorni)
- Validazione MIME reale dei file caricati
- Accessibilità WCAG 2.1 AA (ARIA, focus management, touch target)

---

## Requisiti

| Requisito | Versione minima |
|---|---|
| PHP | 7.4 |
| WordPress | 5.8 |
| Composer | 2.x |

---

## Installazione

### Metodo consigliato — ZIP da GitHub Releases

Scarica lo ZIP già compilato (con `vendor/` incluso) dalla pagina [Releases](https://github.com/dadebertolino/qrcode-generator-plugin/releases/latest) e installalo direttamente da WordPress: **Plugin → Aggiungi nuovo → Carica plugin**.

### Da sorgente

```bash
git clone https://github.com/dadebertolino/qrcode-generator-plugin.git wp-content/plugins/qrcode-generator-plugin
cd wp-content/plugins/qrcode-generator-plugin
composer install --no-dev --optimize-autoloader
```

Dal pannello WordPress: **Plugin → Plugin installati → QR Code Generator con Logo → Attiva**.

> ⚠️ Il plugin non si attiva se `vendor/autoload.php` è assente o se PHP < 7.4.

---

## Dipendenze

| Pacchetto | Versione | Utilizzo |
|---|---|---|
| [`endroid/qr-code`](https://github.com/endroid/qr-code) | ^4.0 \|\| ^5.0 | Generazione QR code |
| [`claviska/simpleimage`](https://github.com/claviska/SimpleImage) | ^4.1 | Elaborazione logo |

---

## Utilizzo

### Shortcode generatore interattivo

```
[dbqr_generator]
```

| Attributo | Default | Descrizione |
|---|---|---|
| `title` | `Genera il tuo QR Code` | Titolo sopra il form |
| `show_logo` | `yes` | Mostra/nasconde il campo logo (`yes` / `no`) |

**Esempi:**

```
[dbqr_generator title="Crea il tuo QR" show_logo="no"]
[dbqr_generator title="QR Code Personalizzato"]
```

> **Retrocompatibilità:** lo shortcode `[qrcode_generator]` continua a funzionare.

### Shortcode QR code statico

```
[dbqr_code url="https://example.com"]
[dbqr_code url="https://example.com" size="400"]
```

| Attributo | Default | Note |
|---|---|---|
| `url` | _(obbligatorio)_ | URL o testo da codificare |
| `size` | `300` | Dimensione in pixel (100–1000) |

> **Retrocompatibilità:** lo shortcode `[qrcode]` continua a funzionare.

Il QR code viene generato una sola volta e poi servito dalla cache per 24 ore.

### Pannello Admin

Vai su **QR Code** nel menu laterale di WordPress per generare QR code manualmente con anteprima e link al download.

---

## Struttura del progetto

```
qrcode-generator-plugin/
├── .github/
│   └── workflows/
│       └── release.yml        # GitHub Action per build automatica
├── assets/
│   ├── css/
│   │   └── frontend.css       # Stili frontend
│   └── js/
│       ├── admin.js           # Script pannello admin
│       └── frontend.js        # Script shortcode frontend
├── qrcode-generator.php       # File principale del plugin
├── composer.json              # Dipendenze Composer
├── LICENSE                    # Licenza GPL-2.0
├── vendor/                    # Librerie (generata da Composer, non in VCS)
├── .gitignore
└── README.md
```

---

## Sicurezza

- **Nonce WordPress** — ogni richiesta AJAX (admin e frontend) è verificata con un nonce dedicato (`dbqr_admin`, `dbqr_frontend`).
- **Validazione MIME reale** — il tipo del file logo viene verificato con `finfo::file()` sul contenuto effettivo, non sull'estensione dichiarata dal client.
- **Whitelist MIME** — sono accettati solo `image/png`, `image/jpeg`, `image/gif`, `image/webp`.
- **Limite dimensione** — il logo non può superare 2 MB.
- **Rate limiting** — max 10 richieste per IP ogni 60 secondi sulle chiamate pubbliche.
- **Directory listing** — la cartella `/uploads/qrcodes/` è protetta da un `.htaccess` generato automaticamente.
- **Check attivazione** — il plugin verifica PHP e Composer prima di attivarsi, con messaggi di errore espliciti.

---

## Changelog

### 1.2.0
- **Refactoring completo** — applicato prefisso `dbqr_` a costanti, classi, hook, AJAX, transient, CSS, JS
- **Fix header plugin** — Author, Author URI, Plugin URI, Text Domain corretti
- **Estratti CSS/JS** — stili e script spostati in `assets/css/` e `assets/js/`, caricati solo dove servono
- **Accessibilità WCAG 2.1 AA** — `aria-required`, `aria-describedby`, `role="alert"`, `aria-live`, `focus-visible`, touch target 44px, `prefers-reduced-motion`
- **Localizzazione** — tutte le stringhe utente wrappate con `__()` / `_e()` e text domain `qrcode-generator`
- **Retrocompatibilità** — shortcode `[qrcode]` e `[qrcode_generator]` mantenuti come alias
- **Aggiunto** file `LICENSE` GPL-2.0

### 1.1.0
- **Fix critico** — rimosso bypass nonce AJAX lato frontend; aggiunto nonce dedicato
- **Fix critico** — `logoPath()` ora riceve un percorso file temporaneo anziché un data URI
- **Fix critico** — validazione MIME reale del logo con `finfo`
- **Fix** — autoload Composer spostato a livello di file
- **Aggiunto** — rate limiting, caching, pulizia cron, protezione directory, verifica attivazione

### 1.0.0
- Release iniziale

---

## Autore

**Davide Bertolino** — [davidebertolino.it](https://www.davidebertolino.it) — info@davidebertolino.it

---

## Licenza

Distribuito sotto licenza [GPL-2.0](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html), in conformità con l'ecosistema WordPress.
