# Changelog

## [2.1.1] - 2026-10-21

### Corretto
- ZIP da 13 MB a circa 1 MB: escluso il font di endroid/qr-code per le etichette sotto il QR (`noto_sans.otf`, 16 MB), che il plugin non usa. Con 13 MB il caricamento da WordPress falliva con "Il link che hai seguito è scaduto" sui server con limite di upload basso

## [2.1.0] - 2026-10-21

Versione che sostituisce la "2.0.0" installata sui siti (il codice originale, precedente al repository): il numero più alto evita che WordPress la tratti come un ritorno indietro.

### Corretto
- Dipendenze compatibili da PHP 7.4 a PHP 8.x: `composer.json` ammetteva endroid/qr-code 5, che ha un'interfaccia diversa (niente `ErrorCorrectionLevelHigh`); la release costruita con PHP 8.1 andava in errore fatale alla generazione. Ora endroid/qr-code `^4.6` e `composer.lock` versionato, risolto per PHP 7.4 (endroid 4.6.1, simpleimage 3.7.2, bacon 2.0.8)
- Lo ZIP della release contiene la cartella `qrcode-generator/`: prima i file erano nella radice e WordPress installava un secondo plugin `qrcode-generator-plugin` accanto a quello esistente, invece di sostituirlo
- La pulizia giornaliera dei PNG viene pianificata anche dopo un aggiornamento con "Sostituisci la versione attuale", che non esegue l'attivazione

### Aggiunto
- CI su GitHub Actions: sintassi da PHP 7.4 a 8.4 e generazione reale di un QR con logo su ogni versione
