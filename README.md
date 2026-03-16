# QR Code Generator con Logo

Plugin WordPress per generare QR code personalizzati con logo centrale.

**Versione:** 1.1.0  
**Richiede:** WordPress 5.8+, PHP 7.4+

---

## Installazione

### Metodo 1 – ZIP (consigliato)
1. Carica il file `.zip` tramite **Plugin > Aggiungi nuovo > Carica plugin** in WordPress.
2. Dopo l'attivazione, nella cartella del plugin esegui:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Se non hai accesso SSH, carica manualmente la cartella `vendor/` (vedi metodo 2).

### Metodo 2 – Manuale con Composer
1. Clona o copia la cartella nella directory `/wp-content/plugins/`.
2. Nella cartella del plugin esegui:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Attiva il plugin dal pannello WordPress.

---

## Dipendenze Composer

| Pacchetto | Versione |
|---|---|
| `endroid/qr-code` | ^4.0 o ^5.0 |
| `claviska/simpleimage` | ^4.1 |

---

## Utilizzo

### Shortcode generatore interattivo
```
[qrcode_generator]
[qrcode_generator title="Crea il tuo QR Code" show_logo="yes"]
[qrcode_generator show_logo="no"]
```

### Shortcode QR code statico (con caching 24h)
```
[qrcode url="https://example.com"]
[qrcode url="https://example.com" size="400"]
```

### Pannello Admin
Vai su **QR Code** nel menu laterale di WordPress.

---

## Sicurezza

- Nonce dedicato per richieste frontend
- Rate limiting: max 10 richieste/IP per 60 secondi
- Validazione MIME reale del file logo (non solo estensione)
- Limite dimensione logo: 2 MB
- Directory listing disabilitata nella cartella QR

---

## Changelog

### 1.1.0
- Fix: bypass nonce AJAX lato frontend
- Fix: `logoPath()` ora usa file temporaneo anziché data URI
- Fix: validazione MIME reale del logo caricato
- Fix: autoload Composer caricato correttamente a livello di file
- Aggiunto: rate limiting per richieste pubbliche
- Aggiunto: caching QR code con transient (24 ore)
- Aggiunto: pulizia automatica file QR vecchi (> 30 giorni)
- Aggiunto: protezione directory listing (.htaccess)
- Aggiunto: check versione PHP all'attivazione
