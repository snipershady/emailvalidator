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

Le righe con esito `true` e le ultime due dipendono dallo stato DNS reale al momento della chiamata (qui verificato con risoluzione live); il resto della tabella è deterministico, perché l'indirizzo viene rifiutato prima di qualunque query DNS. In breve: se ti basta sapere che una stringa "assomiglia" a un'email, `filter_var()` basta; se devi sapere che quell'indirizzo può ricevere posta *adesso*, e vuoi che un input ambiguo venga segnalato invece che silenziosamente riscritto, è per questo che esiste questa libreria.

Le modifiche di ogni versione, inclusi i cambi di comportamento e le correzioni di sicurezza, sono elencate nel [CHANGELOG](CHANGELOG.md).

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

### Configurazione

`EmailValidator` non richiede alcuna dipendenza esterna: `new EmailValidator()` è già pronto all'uso, con risoluzione DNS reale e protezione SSRF attiva. Il costruttore accetta solo il resolver MX e due opzioni, tutte facoltative:

```php
public function __construct(
    ?MxResolver $mxResolver = null,   // default: new DnsMxResolver()
    bool $rejectGmailAlias = false,   // rifiuta gli alias Gmail, vedi "Alias Gmail"
    bool $safeLocalPart = false,      // local part limitato a [A-Za-z0-9._+-], vedi "Sicurezza"
)
```

Le opzioni di risoluzione DNS appartengono invece a `DnsMxResolver`:

```php
public function __construct(
    bool $allowImplicitMx = false,     // senza MX accetta un record A/AAAA (RFC 5321 §5.1)
    bool $rejectNonPublicHosts = true, // scarta gli host MX non pubblici, vedi "Protezione SSRF sugli host MX"
)
```

Si consiglia di passare le opzioni come **argomenti nominati**: restano leggibili e non dipendono dall'ordine dei parametri.

```php
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator(
    mxResolver: new DnsMxResolver(allowImplicitMx: true),
    rejectGmailAlias: true,
    safeLocalPart: true,
);
```

La coercizione a stringa dell'input usa internamente [`snipershady/typeidentifier`](https://packagist.org/packages/snipershady/typeidentifier), installata da Composer come dipendenza: è un dettaglio implementativo e non va né istanziata né passata dal client. L'unico collaboratore sostituibile è il resolver MX (vedi [Risoluzione MX](#risoluzione-mx-iniezione-e-test)).

`EmailValidator` è immutabile (`final readonly`) e senza stato tra una chiamata e l'altra: istanzialo una sola volta, per esempio come servizio condiviso nel container di dipendenze, e riusalo per tutte le validazioni.

### `EmailValidationResult`: leggere l'esito

| Metodo | Ritorna | Descrizione |
| --- | --- | --- |
| `isValid(): bool` | `bool` | esito complessivo della validazione |
| `getEmail(): string` | `string` | indirizzo raggiunto dalla pipeline, valido o meno — **se non valido è input utente non fidato**, vedi [Sicurezza](#sicurezza) |
| `getSanitizedEmail(): ?string` | `string\|null` | indirizzo sanitizzato, solo se `isValid()` è `true`; altrimenti `null` |
| `getMxHosts(): array` | `list<string>` | host MX del dominio, ordinati per priorità (vuoto se non valido); con il resolver di default contiene solo host che risolvono verso indirizzi pubblici, vedi [Protezione SSRF](#protezione-ssrf-sugli-host-mx-attiva-di-default) |
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

`NON_PUBLIC_MX_HOST` indica che il dominio ha dei record MX, ma nessuno dei suoi host risolve esclusivamente verso indirizzi pubblici (vedi [Protezione SSRF](#protezione-ssrf-sugli-host-mx-attiva-di-default)).

`NO_MX_RECORD` e `NULL_MX` sono esiti definitivi del DNS; `DNS_FAILURE` indica invece un errore transitorio (SERVFAIL, timeout, resolver irraggiungibile): in quel caso l'indirizzo non va considerato inesistente, ma conviene riprovare più tardi o accettarlo con riserva.

Ogni case è backed da una stringa in inglese pronta per essere mostrata (`$error->value`) oppure usata come chiave per una propria traduzione:

```php
$messages = [
    EmailError::EMPTY_ADDRESS->name    => "L'indirizzo non può essere vuoto",
    EmailError::INVALID_FORMAT->name   => "Il formato dell'indirizzo non è valido",
    // ...
];

$error = $result->getError(); // null se l'indirizzo è valido

if (null !== $error) {
    echo $messages[$error->name] ?? $error->value;
}
```

### Pipeline di validazione

1. **Sanitize** — coercizione a stringa, trim, normalizzazione del dominio (lowercase + IDN/punycode via `ext-intl`), `FILTER_SANITIZE_EMAIL`.
2. **Sintassi** — `FILTER_VALIDATE_EMAIL` + limiti di lunghezza RFC 5321.
3. **Formato** — regex stretta sul local part (dot-atom, RFC 5322) e sul dominio (label DNS + TLD, A-label IDNA valide).
4. **Local part sicura** — opzionale, disattivata di default (vedi [Sicurezza](#sicurezza)).
5. **Alias Gmail** — opzionale, disattivato di default (vedi sotto).
6. **Record MX** — risoluzione DNS con rilevamento del Null MX (RFC 7505), distinzione tra "record assente" ed "errore DNS" e, di default, scarto degli host MX che non risolvono verso indirizzi pubblici (vedi [Protezione SSRF](#protezione-ssrf-sugli-host-mx-attiva-di-default)).

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

### Protezione SSRF sugli host MX (attiva di default)

I record MX sono scelti da chi controlla il dominio, cioè, per un indirizzo inserito in un form, da chiunque. Chi registra `attaccante.com` può farne puntare l'MX a `127.0.0.1`, a un host della tua rete interna o all'endpoint dei metadati cloud (`169.254.169.254`). Se la tua applicazione si connette poi agli host di `getMxHosts()`, per esempio per una verifica SMTP (`RCPT TO`), senza filtro diventerebbe un proxy verso la tua rete interna (SSRF).

Per questo `DnsMxResolver`, e quindi `new EmailValidator()` che lo usa di default, applica due livelli di difesa.

**1. Target che non sono nomi di dominio — sempre scartati, non disattivabili.** RFC 5321 §5.1 richiede che il target di un MX sia un nome di dominio, mai un indirizzo. Vengono ignorati:

| Target MX | Perché è scartato |
| --- | --- |
| `127.0.0.1`, `10.0.0.1`, `169.254.169.254` | address literal IPv4 |
| `127.1`, `0177.0.0.1`, `0x7f.0.0.1`, `2130706433` | forme numeriche non canoniche: `FILTER_VALIDATE_IP` non le riconosce, ma `getaddrinfo()` / `inet_aton()` le risolvono comunque in `127.0.0.1` |
| `localhost`, `mailserver` | nome a una sola label, risolto da `/etc/hosts` o dai search domain locali |
| `mx.example.123` | TLD non alfabetico |

Il target deve avere almeno due label e un TLD alfabetico (o IDN in punycode, `xn--…`).

**2. Host che risolvono verso indirizzi non pubblici — scartati di default.** Per ogni host MX rimasto vengono risolti i record A e AAAA, e l'host viene tenuto solo se **tutti** i suoi indirizzi sono pubblici (`FILTER_FLAG_GLOBAL_RANGE`). Vengono quindi scartati gli host che risolvono, anche solo in parte, verso:

- loopback (`127.0.0.0/8`, `::1`);
- reti private (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`);
- link-local, incluso l'endpoint dei metadati cloud (`169.254.0.0/16`, `fe80::/10`);
- gli altri range riservati o non instradabili.

Un host senza alcun record A/AAAA viene scartato, perché non è raggiungibile. Lo stesso filtro vale per l'host implicito quando `allowImplicitMx` è attivo.

Cosa vedi come client:

```php
$result = (new EmailValidator())->validate('utente@dominio.com');

$result->getMxHosts();  // solo gli host sicuri, nell'ordine di priorità originale
$result->getError();    // se nessun host è sicuro:
                        //   EmailError::NON_PUBLIC_MX_HOST — tutti scartati
                        //   EmailError::DNS_FAILURE        — nessuno sicuro e almeno una query A/AAAA fallita (riprova)
```

Se il dominio ha sia host pubblici sia host interni, l'indirizzo resta valido e `getMxHosts()` contiene solo quelli pubblici.

**Costo.** Oltre alla query MX servono una query A e una AAAA per ogni host: un dominio con 5 MX (come `gmail.com`) richiede 11 query invece di 1. Se non ti connetti mai agli host MX e la latenza conta, o se valuti indirizzi di una rete interna i cui server di posta hanno davvero indirizzi privati, puoi disattivare il livello 2. Il livello 1 resta comunque attivo:

```php
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator(new DnsMxResolver(rejectNonPublicHosts: false));
```

**Limite: DNS rebinding.** Il filtro verifica gli indirizzi al momento della validazione. Se poi ti connetti di nuovo *al nome*, un DNS malevolo può rispondere con un altro indirizzo (TTL a zero). Per chiudere anche questa finestra risolvi l'host una sola volta, riapplica il controllo `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)` e connettiti a quell'IP.

> **Cambio di comportamento.** Fino alla 1.0.2 il livello 2 era disattivato di default (`rejectNonPublicHosts: false`); vedi il [CHANGELOG](CHANGELOG.md). Chi valida indirizzi con server di posta su IP privati ora riceve `NON_PUBLIC_MX_HOST` e deve passare esplicitamente `rejectNonPublicHosts: false`.

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

- **Host MX e SSRF.** Gli host restituiti da `getMxHosts()` sono scelti da chi controlla il dominio. Di default vengono scartati sia i target che non sono nomi di dominio sia gli host che risolvono verso indirizzi non pubblici; vedi [Protezione SSRF sugli host MX](#protezione-ssrf-sugli-host-mx-attiva-di-default) per i dettagli, il costo, come disattivarla e il limite del DNS rebinding (connettiti all'IP già verificato, non di nuovo al nome).

- **Costo delle query DNS.** Ogni `validate()` esegue query DNS verso un dominio scelto da chi invia l'input: una query MX, più una A e una AAAA per ogni host MX con la protezione SSRF attiva. Su endpoint pubblici applica un rate limit.

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
