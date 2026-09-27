# Changelog

Tutte le modifiche rilevanti di questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it-IT/1.1.0/) e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

## [Non rilasciato]

### ⚠ Cambi di comportamento

- **Protezione SSRF sugli host MX attiva di default.** `DnsMxResolver::$rejectNonPublicHosts` passa da `false` a `true`: `new EmailValidator()` e `new DnsMxResolver()` scartano ora gli host MX che non risolvono esclusivamente verso indirizzi pubblici (loopback, reti private, link-local incluso l'endpoint dei metadati cloud `169.254.169.254`, range riservati) e quelli senza alcun record A/AAAA. Se nessun host resta, `validate()` restituisce `EmailError::NON_PUBLIC_MX_HOST` (o `DNS_FAILURE` se una delle query A/AAAA è fallita).
  **Chi è impattato:** chi valida indirizzi i cui server di posta hanno davvero indirizzi privati (es. rete aziendale interna). **Come ripristinare il comportamento precedente:**
  ```php
  new EmailValidator(new DnsMxResolver(rejectNonPublicHosts: false));
  ```
  **Costo:** una query A e una AAAA in più per ogni host MX (es. 11 query invece di 1 per un dominio con 5 MX). Vedi [Protezione SSRF sugli host MX](README.md#protezione-ssrf-sugli-host-mx-attiva-di-default).
- **`isGmailAlias()` e `canonicalGmailAddress()` validano l'input.** Applicano gli stessi controlli di sanitize, sintassi e formato di `validate()` (senza verifica MX): per un indirizzo non valido restituiscono rispettivamente `false` e `null`. In particolare `mario.rossi@gmail.com.` (punto finale sul dominio) non è più riconosciuto come alias.

### Rimosso

- **Parametro `$effectivePrimitiveTypeIdentifierService` del costruttore di `EmailValidator`.** La coercizione a stringa dell'input è un dettaglio implementativo della libreria, non un punto di estensione: il servizio di `snipershady/typeidentifier` viene ora istanziato internamente. Chi usa gli argomenti nominati (`new EmailValidator(rejectGmailAlias: true)`) o passa solo il resolver non deve cambiare nulla; chi passava gli argomenti **per posizione** deve aggiornare la chiamata, perché `rejectGmailAlias` e `safeLocalPart` scalano di una posizione:
  ```php
  // prima
  new EmailValidator($resolver, null, true);
  // ora
  new EmailValidator($resolver, rejectGmailAlias: true);
  ```
  Il README documenta ora le firme complete dei costruttori nella nuova sezione [Configurazione](README.md#configurazione).

### Documentazione

- README: nuova sezione [Configurazione](README.md#configurazione) con le firme complete dei costruttori di `EmailValidator` e `DnsMxResolver`, i loro default, un esempio con argomenti nominati e l'indicazione di istanziare il validatore una sola volta come servizio condiviso.
- README: nuova sezione [Protezione SSRF sugli host MX](README.md#protezione-ssrf-sugli-host-mx-attiva-di-default).
- README: l'esempio di traduzione dei messaggi d'errore accedeva a `getError()->name` senza controllare che la validazione fosse fallita, quindi su un indirizzo valido (`getError()` restituisce `null`) generava un errore. Ora verifica prima il `null`.
- README: precisato che anche le righe con esito positivo della tabella introduttiva dipendono dal DNS.
- Tutti gli esempi PHP del README sono stati eseguiti contro il codice di questa versione.

### Corretto

- CI: il test di integrazione `testReturnsDnsFailureOnServfail` falliva sui runner GitHub Actions, il cui resolver non valida DNSSEC. Il test deduceva la validazione dall'esito della query MX su `dnssec-failed.org`, ma quel dominio non ha record MX: con un resolver non validante la risposta è NODATA e la libreria restituisce correttamente `NO_MX_RECORD`. Ora il test verifica prima se il record A del dominio risolve, e in quel caso viene saltato.

### Sicurezza

- **Target MX che non sono nomi di dominio sempre scartati** (anche con `rejectNonPublicHosts: false`). `FILTER_VALIDATE_DOMAIN` con `FILTER_FLAG_HOSTNAME` accettava come target MX address literal (`127.0.0.1`, `10.0.0.1`, `169.254.169.254`), nomi a una sola label (`localhost`) e forme numeriche non canoniche (`127.1`, `0177.0.0.1`, `0x7f.0.0.1`, `2130706433`) che `FILTER_VALIDATE_IP` non riconosce ma che `getaddrinfo()`/`inet_aton()` risolvono verso un IP: `getMxHosts()` poteva così restituire host di loopback o della rete interna, con SSRF diretto per chi fa probing SMTP. Ora il target deve avere almeno due label e un TLD alfabetico o punycode, come richiede RFC 5321 §5.1.
- **Collisioni della forma canonica Gmail con indirizzi non validi.** `canonicalGmailAddress()` non validava l'input: `mario..rossi@gmail.com`, `.mario.rossi@gmail.com`, `x@gmail.com..` (il dominio veniva ripulito con `rtrim()`) e perfino `mario rossi@gmail.com` producevano una forma canonica. Un'applicazione che usa la forma canonica come chiave univoca poteva far collidere un indirizzo invalido con la casella reale (`mariorossi@gmail.com`), bloccandone la registrazione.

## [1.0.2] - 2026-09-27

### Aggiunto

- Opzione `safeLocalPart` di `EmailValidator` (disattivata di default) ed errore `EmailError::UNSAFE_LOCAL_PART`: ammette nel local part solo `[A-Za-z0-9._+-]` e rifiuta il `-` iniziale, per indirizzi innocui anche se passati a shell o al quinto parametro di `mail()` (CVE-2016-10033).
- Opzione `rejectNonPublicHosts` di `DnsMxResolver` (disattivata in questa versione) ed errore `EmailError::NON_PUBLIC_MX_HOST`, contro SSRF verso la rete interna per chi si connette agli host MX.
- `EmailValidator::canonicalGmailAddress()`: forma canonica di un indirizzo Gmail, per riconoscere registrazioni duplicate tramite alias.
- Estensioni `ext-filter`, `ext-intl` ed `ext-mbstring` dichiarate in `composer.json`.
- Sezione "Sicurezza" nel README.

### Modificato

- `isGmailAlias()` normalizza il dominio come `validate()` (minuscole, IDN, punto finale) e riconosce come alias anche le maiuscole nel local part (`MarioRossi@gmail.com`).
- `validate()` rifiuta array e oggetti con `EMPTY_ADDRESS` senza attraversarli, e un input grezzo oltre 1024 byte con `TOO_LONG` prima di qualunque elaborazione.
- Con `INVALID_ENCODING` e con gli input rifiutati per dimensione o tipo, `getEmail()` restituisce una stringa vuota invece dei byte ricevuti.
- La normalizzazione IDN richiede `ext-intl` invece di essere saltata in silenzio in sua assenza.
- CI: `actions/checkout` aggiornata alla v7 e `actions/cache` alla v6.

### Corretto

- Un errore DNS transitorio (SERVFAIL, timeout) nella query MX veniva riportato come `NO_MX_RECORD`: ora produce `DNS_FAILURE`, mentre un dominio senza MX resta `NO_MX_RECORD`.
- Un Null MX mescolato ad altri record MX (violazione di RFC 7505 §3) rendeva invalido l'indirizzo: ora il record vuoto viene ignorato e si usano gli host reali.
- I target MX non conformi alla sintassi hostname (es. con CR/LF) vengono scartati invece di essere restituiti da `getMxHosts()`.
- Le label di dominio con `--` in terza e quarta posizione sono rifiutate (RFC 5891 §4.2.3.1), salvo A-label `xn--` valide e decodificabili.

## [1.0.1] - 2026-09-27

### Corretto

- CI: il nome del job di analisi statica usava il contesto `env`, non supportato nel campo `name` di GitHub Actions.

## [1.0.0] - 2026-09-27

Primo rilascio.

### Aggiunto

- `EmailValidator::validate()`: pipeline di sanitize, sintassi (`FILTER_VALIDATE_EMAIL` + limiti RFC 5321), formato (dot-atom RFC 5322, label DNS e TLD) e verifica dei record MX, con rilevamento del Null MX (RFC 7505). Restituisce sempre un `EmailValidationResult`, senza eccezioni.
- Normalizzazione del dominio (minuscole, IDN in punycode) e rifiuto, invece della correzione silenziosa, degli input alterati dal sanitize (`SANITIZE_ALTERED`).
- Interfaccia `MxResolver` e implementazione `DnsMxResolver`, con opzione `allowImplicitMx` (RFC 5321 §5.1).
- Riconoscimento degli alias Gmail: `isGmailAlias()` e opzione `rejectGmailAlias` di `EmailValidator` (disattivata di default) con errore `EmailError::GMAIL_ALIAS`.
- CI su GitHub Actions: lint (PHPStan, PHP-CS-Fixer, Rector), test su PHP 8.3 / 8.4 / 8.5, test di integrazione DNS non bloccanti.

[Non rilasciato]: https://github.com/snipershady/emailvalidator/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/snipershady/emailvalidator/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/snipershady/emailvalidator/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/snipershady/emailvalidator/releases/tag/v1.0.0
