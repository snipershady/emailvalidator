# emailvalidator

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
| `"user@[192.168.1.1]"` | `false` | `INVALID_FORMAT` | indirizzo IP letterale: sintatticamente valido per RFC, ma quasi sempre sintomo di un input malformato o di un tentativo di bypass |
| `"user@example.com"` | `false` | `NULL_MX` | il dominio esiste ma dichiara esplicitamente (RFC 7505) di non accettare email — `filter_var()` non ha modo di saperlo |
| `"user@dominio-inesistente-xyz123.it"` | `false` | `NO_MX_RECORD` | il dominio non ha alcun server di posta configurato — `filter_var()` non ha modo di saperlo |

Le ultime due righe dipendono dallo stato DNS reale al momento della chiamata (qui verificato con risoluzione live); il resto della tabella è deterministico. In breve: se ti basta sapere che una stringa "assomiglia" a un'email, `filter_var()` basta; se devi sapere che quell'indirizzo può ricevere posta *adesso*, e vuoi che un input ambiguo venga segnalato invece che silenziosamente riscritto, è per questo che esiste questa libreria.

## Requisiti

- PHP >= 8.3
- estensioni: `intl`, `mbstring`, `filter`

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

`validate()` accetta `mixed`: un input non stringa (`null`, un array, uno scalare) viene coerciato a stringa e trattato come indirizzo vuoto se non producibile, invece di generare un errore a runtime — utile quando il valore arriva grezzo da form, querystring o JSON decodificato.

### `EmailValidationResult`: leggere l'esito

| Metodo | Ritorna | Descrizione |
| --- | --- | --- |
| `isValid(): bool` | `bool` | esito complessivo della validazione |
| `getEmail(): string` | `string` | indirizzo raggiunto dalla pipeline, valido o meno (utile per logging) |
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
```

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
3. **Formato** — regex stretta sul local part (dot-atom, RFC 5322) e sul dominio (label DNS + TLD).
4. **Alias Gmail** — opzionale, disattivato di default (vedi sotto).
5. **Record MX** — risoluzione DNS con rilevamento del Null MX (RFC 7505).

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

### Alias Gmail (opzionale, disattivato di default)

Gmail recapita nella stessa casella indirizzi scritti in forme diverse: i punti nel local part sono ignorati e tutto ciò che segue un `+` è un tag di subaddressing, quindi `mario.rossi@gmail.com`, `mariorossi@gmail.com` e `mariorossi+shop@gmail.com` sono lo stesso destinatario; anche il dominio `googlemail.com` è solo un sinonimo di `gmail.com`. Se la tua applicazione vuole impedire agli utenti di registrarsi più volte sfruttando questi alias, puoi usare `isGmailAlias()` in autonomia:

```php
$validator = new EmailValidator();

$validator->isGmailAlias('mario.rossi+shop@gmail.com'); // true
$validator->isGmailAlias('mariorossi@gmail.com');        // false (forma canonica)
$validator->isGmailAlias('mario.rossi@example.com');     // false (non è Gmail)
```

Oppure chiedere esplicitamente a `validate()` di rifiutare gli alias, passando `rejectGmailAlias: true` al costruttore. **Il comportamento di default resta invariato** (gli alias sono accettati): è un'opzione che il client deve richiedere esplicitamente, non un vincolo imposto dalla libreria.

```php
$validator = new EmailValidator(rejectGmailAlias: true);

$result = $validator->validate('mario.rossi+shop@gmail.com');

$result->isValid();          // false
$result->getError();         // EmailError::GMAIL_ALIAS
```

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

La suite unitaria copre il 100% di classi, metodi e linee di `src/` (verificato con Xdebug). La risoluzione DNS è testata sostituendo le funzioni globali `checkdnsrr()`/`dns_get_record()` con un doppio controllabile (vedi `tests/Support/`), così ogni esito — MX valido, Null MX, nessun MX, implicit MX, errore DNS — è verificato senza dipendere dalla rete; i test in `tests/Integration/` restano invece a fare da riscontro con DNS reale.

## Licenza

GPL-2.0-only. Vedi [LICENSE](LICENSE).
