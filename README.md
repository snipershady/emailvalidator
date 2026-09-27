# emailvalidator

[![CI](https://github.com/snipershady/emailvalidator/actions/workflows/ci.yml/badge.svg)](https://github.com/snipershady/emailvalidator/actions/workflows/ci.yml)

Simple, easy, clean, and useful email validator. Nothing you can't build yourself, but it's ready to use and always up to date.

Valida un indirizzo email attraverso una pipeline a più livelli — sintassi, formato RFC 5322/5321 e verifica dei record MX (con rilevamento del Null MX, RFC 7505) — e restituisce l'indirizzo sanitizzato quando la validazione ha esito positivo.

## Perché usare questa libreria invece di `filter_var($x, FILTER_VALIDATE_EMAIL)`

`FILTER_VALIDATE_EMAIL` da solo risponde solo alla domanda "la stringa è scritta come un'email?". Non sa dirti se quel dominio esiste, se accetta davvero posta, se contiene un dominio Unicode normalizzato correttamente, né se l'input arriva "sporco" da un form e andrebbe rifiutato invece di corretto a caso. Questa libreria aggiunge tutti questi controlli in un'unica chiamata, e — punto centrale del suo design — **non prova mai a indovinare cosa intendesse l'utente**: se per renderlo sintatticamente valido dovrebbe rimuovere un carattere, rifiuta l'indirizzo invece di restituire una versione "corretta" che l'utente non ha scritto.

| INPUT | `isValid()` | `getSanitizedEmail()` / `getError()->name` | Perché |
| --- | --- | --- | --- |
| `"  Mario.Rossi@Gmail.com  "` | `true` | `Mario.Rossi@gmail.com` | spazi rimossi, dominio normalizzato in minuscolo |
| `"utente@müller.de"` | `true` | `utente@xn--mller-kva.de` | dominio Unicode (IDN) convertito in punycode |
| `"user+tag@gmail.com"` | `true` | `user+tag@gmail.com` | il subaddressing (`+tag`) è preservato, non è un errore |
| `"sniper shady@gmail.com"` | `false` | `SANITIZE_ALTERED` | lo spazio nel local part non è ammesso: **rifiutato**, non corretto in `snipershady@gmail.com` a insaputa dell'utente |
| `""` / `"   "` | `false` | `EMPTY_ADDRESS` | vuoto anche dopo il trim |
| `"aaaa…(250 caratteri)…@example.com"` | `false` | `TOO_LONG` | oltre i 254 caratteri di RFC 5321 |
| `"not-an-email"` | `false` | `INVALID_SYNTAX` | manca la struttura `local@dominio` |
| `"user@example.c"` | `false` | `INVALID_FORMAT` | TLD di un solo carattere: `filter_var()` da solo lo accetterebbe |
| `"user@ex--ample.com"` / `"user@xn--zz.com"` | `false` | `INVALID_FORMAT` | label con `--` in posizione 3-4 riservata (RFC 5891) o punycode non decodificabile |
| `"user@[192.168.1.1]"` | `false` | `INVALID_FORMAT` | indirizzo IP letterale: sintatticamente valido per RFC, ma quasi sempre sintomo di un input malformato o di un tentativo di bypass |
| `"user@example.com"` | `false` | `NULL_MX` | il dominio esiste ma dichiara esplicitamente (RFC 7505) di non accettare email — `filter_var()` non ha modo di saperlo |
| `"user@dominio-inesistente-xyz123.it"` | `false` | `NO_MX_RECORD` | il dominio non ha alcun server di posta configurato — `filter_var()` non ha modo di saperlo |

Le ultime due righe dipendono dallo stato DNS reale al momento della chiamata (qui verificato con risoluzione live); il resto della tabella è deterministico. In breve: se ti basta sapere che una stringa "assomiglia" a un'email, `filter_var()` basta; se devi sapere che quell'indirizzo può ricevere posta *adesso*, e vuoi che un input ambiguo venga segnalato invece che silenziosamente riscritto, è per questo che esiste questa libreria.

## Requisiti

- PHP >= 8.3
- estensioni: `intl`, `mbstring`, `filter` (dichiarate in `composer.json`)

## Installazione

```bash
composer require snipershady/emailvalidator
```

## Struttura del pacchetto

```
EmailValidator\Service\EmailValidator    servizio principale, esegue la pipeline di validazione
EmailValidator\Service\MxResolver        interfaccia per la risoluzione dei record MX
EmailValidator\Service\DnsMxResolver     implementazione DNS reale di MxResolver
EmailValidator\Dto\EmailValidationResult oggetto immutabile con l'esito della validazione
EmailValidator\Enum\EmailError           enum dei possibili motivi di fallimento
```

## Documentazione per il client

### Uso base

```php
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator();

$result = $validator->validate('  Mario.Rossi@Gmail.com ');

if ($result->isValid()) {
    echo $result->getSanitizedEmail(); // Mario.Rossi@gmail.com
} else {
    echo $result->getError()->value;   // es. "Invalid format"
}
```

`validate()` accetta `mixed` e restituisce **sempre** un `EmailValidationResult`, senza mai lanciare eccezioni — utile quando il valore arriva grezzo da form, querystring o JSON decodificato:

- gli scalari (`int`, `float`, `bool`) vengono coerciati a stringa;
- `null`, array (anche annidati in profondità) e oggetti producono `EMPTY_ADDRESS` senza essere attraversati;
- una stringa grezza oltre 1024 byte (spazi inclusi) produce `TOO_LONG` prima di qualunque elaborazione.

### `EmailValidationResult`: leggere l'esito

| Metodo | Ritorna | Descrizione |
| --- | --- | --- |
| `isValid(): bool` | `bool` | esito complessivo della validazione |
| `getEmail(): string` | `string` | indirizzo raggiunto dalla pipeline, valido o meno — **se non valido è input utente non fidato**, vedi [Sicurezza](#sicurezza) |
| `getSanitizedEmail(): ?string` | `string\|null` | indirizzo sanitizzato, solo se `isValid()` è `true`; altrimenti `null` |
| `getMxHosts(): array` | `list<string>` | host MX del dominio, ordinati per priorità (vuoto se non valido) |
| `getError(): ?EmailError` | `EmailError\|null` | motivo del fallimento; `null` se valido |

### `EmailError`: i possibili motivi di fallimento

```php
use EmailValidator\Enum\EmailError;

EmailError::EMPTY_ADDRESS;    // Address is empty
EmailError::INVALID_ENCODING; // Invalid UTF-8 encoding
EmailError::SANITIZE_ALTERED; // Address contains characters that are not allowed
EmailError::TOO_LONG;         // Address is too long
EmailError::INVALID_SYNTAX;   // Invalid syntax
EmailError::INVALID_FORMAT;   // Invalid format
EmailError::NULL_MX;          // Domain declares it does not accept email (Null MX)
EmailError::NO_MX_RECORD;     // No MX record for domain
EmailError::DNS_FAILURE;      // DNS resolution error
EmailError::GMAIL_ALIAS;      // Address is a Gmail alias
EmailError::UNSAFE_LOCAL_PART;  // Local part contains characters outside the safe set
EmailError::NON_PUBLIC_MX_HOST; // No MX host resolves only to public addresses
```

`NO_MX_RECORD` e `NULL_MX` sono esiti definitivi del DNS; `DNS_FAILURE` indica invece un errore transitorio (SERVFAIL, timeout, resolver irraggiungibile): in quel caso l'indirizzo non va considerato inesistente, ma conviene riprovare più tardi o accettarlo con riserva.

Ogni case è backed da una stringa in inglese pronta per essere mostrata (`$error->value`) oppure usata come chiave per una propria traduzione:

```php
$messages = [
    EmailError::EMPTY_ADDRESS->name    => "L'indirizzo non può essere vuoto",
    EmailError::INVALID_FORMAT->name   => "Il formato dell'indirizzo non è valido",
    // ...
];

echo $messages[$result->getError()->name] ?? $result->getError()->value;
```

### Pipeline di validazione

1. **Sanitize** — coercizione a stringa, trim, normalizzazione del dominio (lowercase + IDN/punycode via `ext-intl`), `FILTER_SANITIZE_EMAIL`.
2. **Sintassi** — `FILTER_VALIDATE_EMAIL` + limiti di lunghezza RFC 5321.
3. **Formato** — regex stretta sul local part (dot-atom, RFC 5322) e sul dominio (label DNS + TLD, A-label IDNA valide).
4. **Local part sicura** — opzionale, disattivata di default (vedi [Sicurezza](#sicurezza)).
5. **Alias Gmail** — opzionale, disattivato di default (vedi sotto).
6. **Record MX** — risoluzione DNS con rilevamento del Null MX (RFC 7505) e distinzione tra "record assente" ed "errore DNS".

### Risoluzione MX: iniezione e test

La risoluzione DNS è isolata dietro l'interfaccia `EmailValidator\Service\MxResolver`, cosicché nei test si possa sostituirla senza toccare la rete:

```php
use EmailValidator\Enum\EmailError;
use EmailValidator\Service\EmailValidator;
use EmailValidator\Service\MxResolver;

$fakeResolver = new class implements MxResolver {
    public function resolve(string $domain): array|EmailError
    {
        return ['mx.example.com'];
    }
};

$validator = new EmailValidator($fakeResolver);
```

Per accettare anche domini privi di MX ma con un record A/AAAA (implicit MX, RFC 5321 §5.1), usa l'implementazione DNS reale con l'opzione dedicata:

```php
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator(new DnsMxResolver(allowImplicitMx: true));
```

Timeout e numero di tentativi delle query DNS dipendono dal resolver di sistema (`options timeout:N attempts:N` in `/etc/resolv.conf`): le funzioni DNS native di PHP non permettono di impostarli. Se la validazione è esposta a input pubblico, applica un rate limit a monte ed eventualmente implementa `MxResolver` con una libreria DNS che supporti timeout e cache.

### Alias Gmail (opzionale, disattivato di default)

Gmail recapita nella stessa casella indirizzi scritti in forme diverse: i punti nel local part sono ignorati e tutto ciò che segue un `+` è un tag di subaddressing, quindi `mario.rossi@gmail.com`, `mariorossi@gmail.com` e `mariorossi+shop@gmail.com` sono lo stesso destinatario; anche il dominio `googlemail.com` è solo un sinonimo di `gmail.com`. Se la tua applicazione vuole impedire agli utenti di registrarsi più volte sfruttando questi alias, puoi usare `isGmailAlias()` in autonomia:

```php
$validator = new EmailValidator();

$validator->isGmailAlias('mario.rossi+shop@gmail.com'); // true
$validator->isGmailAlias('MarioRossi@gmail.com');        // true (Gmail ignora le maiuscole)
$validator->isGmailAlias('mariorossi@gmail.com');        // false (forma canonica)
$validator->isGmailAlias('mario.rossi@example.com');     // false (non è Gmail)
```

Per riconoscere i duplicati è ancora più utile salvare la forma canonica accanto all'indirizzo e renderla univoca:

```php
$validator->canonicalGmailAddress('Mario.Rossi+shop@GoogleMail.com'); // "mariorossi@gmail.com"
$validator->canonicalGmailAddress('mario@example.com');               // null (non è Gmail)
$validator->canonicalGmailAddress('mario..rossi@gmail.com');          // null (indirizzo non valido)
```

Entrambi i metodi applicano gli stessi controlli di sanitize, sintassi e formato di `validate()` (esclusa la verifica MX): un indirizzo non valido non è mai un alias e non ha forma canonica, quindi forme come `mario..rossi@gmail.com` o `x@gmail.com.` non possono collidere con la casella reale `mariorossi@gmail.com`.

Oppure chiedere esplicitamente a `validate()` di rifiutare gli alias, passando `rejectGmailAlias: true` al costruttore. **Il comportamento di default resta invariato** (gli alias sono accettati): è un'opzione che il client deve richiedere esplicitamente, non un vincolo imposto dalla libreria.

```php
$validator = new EmailValidator(rejectGmailAlias: true);

$result = $validator->validate('mario.rossi+shop@gmail.com');

$result->isValid();          // false
$result->getError();         // EmailError::GMAIL_ALIAS
```

## Sicurezza

Un indirizzo che supera `validate()` è **conforme alle RFC**, non automaticamente sicuro in ogni contesto.

- **Local part e shell.** Per RFC 5322 il local part può iniziare con `-` e contenere caratteri come `` ' ` | & $ { } ``: `-oQx@example.com` e ``a'|`$x`&{}@example.com`` sono validi. Non passare un indirizzo fornito dall'utente al quinto parametro di `mail()` (es. `-f$email`, vedi CVE-2016-10033: l'escaping interno di PHP non impedisce l'iniezione di argomenti) né a una shell senza `escapeshellarg()`, e usa sempre query parametrizzate in SQL. Se la tua applicazione non ha bisogno di questi caratteri, attiva la modalità restrittiva, che ammette solo `[A-Za-z0-9._+-]` e rifiuta il `-` iniziale (esclude indirizzi reali ma rari come `o'brien@example.com`):

  ```php
  $validator = new EmailValidator(safeLocalPart: true);
  $validator->validate('-oQx@example.com')->getError(); // EmailError::UNSAFE_LOCAL_PART
  ```

- **`getEmail()` sugli esiti falliti** restituisce ciò che l'utente ha inviato, inclusi `<`, `>`, `"` e CR/LF. Applica sempre l'escaping del contesto di destinazione (`htmlspecialchars()` in HTML, rimozione di CR/LF prima di scrivere nei log). Per un indirizzo pronto all'uso usa `getSanitizedEmail()`, che è `null` se la validazione è fallita.

- **Host MX e SSRF.** Gli host restituiti da `getMxHosts()` sono scelti da chi controlla il dominio. I target che non sono un nome di dominio (address literal come `127.0.0.1`, forme numeriche come `127.1` o `0177.0.0.1`, nomi a una sola label come `localhost`) vengono sempre scartati, come richiede RFC 5321 §5.1; un nome regolare può però risolvere comunque verso `127.0.0.1`, la rete interna o un endpoint di metadati cloud. Se ti connetti a quegli host (es. verifica SMTP), abilita il filtro, che scarta gli host che non risolvono esclusivamente verso indirizzi pubblici:

  ```php
  $validator = new EmailValidator(new DnsMxResolver(rejectNonPublicHosts: true));
  ```

  Il filtro costa una query A e una AAAA per host e non protegge dal DNS rebinding: connettiti all'IP già verificato, non di nuovo al nome.

- **Costo delle query DNS.** Ogni `validate()` esegue almeno una query DNS verso un dominio scelto da chi invia l'input: su endpoint pubblici applica un rate limit.

## Sviluppo

```bash
composer install

composer test          # PHPUnit (i test che richiedono rete sono nel gruppo "network", escluso di default)
composer test-coverage # PHPUnit con report di copertura (richiede Xdebug o PCOV)
composer stan          # PHPStan a livello max
composer cs             # PHP-CS-Fixer, regole @Symfony (dry-run)
composer cs-fix         # applica le correzioni di stile
composer rector         # Rector (dry-run)
composer check          # cs + stan + test
```

La suite unitaria copre il 100% di classi, metodi e linee di `src/` (verificato con Xdebug). La risoluzione DNS è testata sostituendo la funzione globale `dns_get_record()` con un doppio controllabile (vedi `tests/Support/`), così ogni esito — MX valido, Null MX, nessun MX, implicit MX, errore DNS, host MX non pubblici — è verificato senza dipendere dalla rete; i test in `tests/Integration/` restano invece a fare da riscontro con DNS reale.

### CI

Il workflow GitHub Actions (`.github/workflows/ci.yml`) gira su ogni push/PR su `main` con tre job:

- **lint** — `composer validate`, PHPStan (max), PHP-CS-Fixer (dry-run) e Rector (dry-run), eseguiti una sola volta sulla versione minima supportata (PHP 8.3).
- **test** — la suite PHPUnit (con coverage) su una matrice PHP 8.3 / 8.4 / 8.5, per garantire la compatibilità dichiarata in `composer.json`.
- **network-tests** — i test di integrazione con DNS reale (`tests/Integration/`), eseguiti ma non bloccanti (`continue-on-error`), perché dipendono dallo stato di domini di terze parti.

Le action di terze parti sono pinnate a SHA di commit, il checkout non conserva le credenziali (`persist-credentials: false`) e `.github/dependabot.yml` propone gli aggiornamenti settimanali di action e dipendenze Composer.

Nessun `composer.lock` è versionato: ogni run risolve le dipendenze contro i vincoli correnti di `composer.json`, così la CI segnala per prima eventuali incompatibilità con nuove versioni delle dipendenze.

## Licenza

GPL-2.0-only. Vedi [LICENSE](LICENSE).
