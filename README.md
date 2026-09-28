# emailvalidator

[![CI](https://github.com/snipershady/emailvalidator/actions/workflows/ci.yml/badge.svg)](https://github.com/snipershady/emailvalidator/actions/workflows/ci.yml)

Simple, easy, clean, and useful email validator. Nothing you can't build yourself, but it's ready to use and always up to date.

Validates an email address through a multi-stage pipeline — syntax, RFC 5322/5321 format, and MX record verification (with Null MX detection, RFC 7505) — and returns the sanitized address when validation succeeds.

## Why use this library instead of `filter_var($x, FILTER_VALIDATE_EMAIL)`

`FILTER_VALIDATE_EMAIL` alone only answers the question "is this string written like an email?". It can't tell you whether that domain exists, whether it actually accepts mail, whether it contains a correctly normalized Unicode domain, or whether the input arrived "dirty" from a form and should be rejected instead of silently fixed up. This library adds all of these checks in a single call, and — the central point of its design — **it never tries to guess what the user meant**: if making it syntactically valid would require removing a character, it rejects the address instead of returning a "corrected" version the user never wrote.

| INPUT | `isValid()` | `getSanitizedEmail()` / `getError()->name` | Why |
| --- | --- | --- | --- |
| `"  Mario.Rossi@Gmail.com  "` | `true` | `Mario.Rossi@gmail.com` | spaces removed, domain normalized to lowercase |
| `"utente@müller.de"` | `true` | `utente@xn--mller-kva.de` | Unicode (IDN) domain converted to punycode |
| `"user+tag@gmail.com"` | `true` | `user+tag@gmail.com` | subaddressing (`+tag`) is preserved, not an error |
| `"sniper shady@gmail.com"` | `false` | `SANITIZE_ALTERED` | the space in the local part is not allowed: **rejected**, not silently corrected to `snipershady@gmail.com` |
| `""` / `"   "` | `false` | `EMPTY_ADDRESS` | empty even after trimming |
| `"aaaa…(250 characters)…@example.com"` | `false` | `TOO_LONG` | over the 254-character limit of RFC 5321 |
| `"not-an-email"` | `false` | `INVALID_SYNTAX` | missing the `local@domain` structure |
| `"user@example.c"` | `false` | `INVALID_FORMAT` | single-character TLD: `filter_var()` alone would accept it |
| `"user@ex--ample.com"` / `"user@xn--zz.com"` | `false` | `INVALID_FORMAT` | label with `--` in the reserved position 3-4 (RFC 5891) or undecodable punycode |
| `"user@[192.168.1.1]"` | `false` | `INVALID_FORMAT` | literal IP address: syntactically valid per RFC, but almost always a symptom of malformed input or a bypass attempt |
| `"user@example.com"` | `false` | `NULL_MX` | the domain exists but explicitly declares (RFC 7505) that it does not accept email — `filter_var()` has no way of knowing this |
| `"user@dominio-inesistente-xyz123.it"` | `false` | `NO_MX_RECORD` | the domain has no mail server configured — `filter_var()` has no way of knowing this |

The rows with a `true` outcome and the last two depend on the actual DNS state at call time (here verified with live resolution); the rest of the table is deterministic, because the address is rejected before any DNS query. In short: if you just need to know that a string "looks like" an email, `filter_var()` is enough; if you need to know that the address can receive mail *right now*, and want ambiguous input to be flagged rather than silently rewritten, that's what this library is for.

Changes for each version, including behavior changes and security fixes, are listed in the [CHANGELOG](CHANGELOG.md).

## Requirements

- PHP >= 8.3
- extensions: `intl`, `mbstring`, `filter` (declared in `composer.json`)

## Installation

```bash
composer require snipershady/emailvalidator
```

## Package structure

```
EmailValidator\Service\EmailValidator    main service, runs the validation pipeline
EmailValidator\Service\MxResolver        interface for MX record resolution
EmailValidator\Service\DnsMxResolver     real DNS implementation of MxResolver
EmailValidator\Dto\EmailValidationResult immutable object holding the validation outcome
EmailValidator\Enum\EmailError           enum of possible failure reasons
```

## Client documentation

### Basic usage

```php
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator();

$result = $validator->validate('  Mario.Rossi@Gmail.com ');

if ($result->isValid()) {
    echo $result->getSanitizedEmail(); // Mario.Rossi@gmail.com
} else {
    echo $result->getError()->value;   // e.g. "Invalid format"
}
```

`validate()` accepts `mixed` and **always** returns an `EmailValidationResult`, never throwing exceptions — useful when the value arrives raw from a form, query string, or decoded JSON:

- scalars (`int`, `float`, `bool`) are coerced to string;
- `null`, arrays (even deeply nested) and objects produce `EMPTY_ADDRESS` without being traversed;
- a raw string over 1024 bytes (including spaces) produces `TOO_LONG` before any processing.

### Configuration

`EmailValidator` requires no external dependency: `new EmailValidator()` is ready to use out of the box, with real DNS resolution and SSRF protection active. The constructor only accepts the MX resolver and two options, all optional:

```php
public function __construct(
    ?MxResolver $mxResolver = null,   // default: new DnsMxResolver()
    bool $rejectGmailAlias = false,   // rejects Gmail aliases, see "Gmail aliases"
    bool $safeLocalPart = false,      // local part restricted to [A-Za-z0-9._+-], see "Security"
)
```

DNS resolution options belong instead to `DnsMxResolver`:

```php
public function __construct(
    bool $allowImplicitMx = false,     // without MX, accepts an A/AAAA record (RFC 5321 §5.1)
    bool $rejectNonPublicHosts = true, // discards MX hosts that are not public, see "SSRF protection on MX hosts"
)
```

It's recommended to pass options as **named arguments**: they stay readable and don't depend on parameter order.

```php
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator(
    mxResolver: new DnsMxResolver(allowImplicitMx: true),
    rejectGmailAlias: true,
    safeLocalPart: true,
);
```

The string coercion of the input internally uses [`snipershady/typeidentifier`](https://packagist.org/packages/snipershady/typeidentifier), installed by Composer as a dependency: it's an implementation detail and should neither be instantiated nor passed by the client. The only replaceable collaborator is the MX resolver (see [MX resolution](#mx-resolution-injection-and-testing)).

`EmailValidator` is immutable (`final readonly`) and stateless between calls: instantiate it once, for example as a shared service in the dependency container, and reuse it for all validations.

### `EmailValidationResult`: reading the outcome

| Method | Returns | Description |
| --- | --- | --- |
| `isValid(): bool` | `bool` | overall validation outcome |
| `getEmail(): string` | `string` | address reached by the pipeline, valid or not — **if not valid, it's untrusted user input**, see [Security](#security) |
| `getSanitizedEmail(): ?string` | `string\|null` | sanitized address, only if `isValid()` is `true`; otherwise `null` |
| `getMxHosts(): array` | `list<string>` | domain's MX hosts, ordered by priority (empty if not valid); with the default resolver it contains only hosts that resolve to public addresses, see [SSRF protection](#ssrf-protection-on-mx-hosts-active-by-default) |
| `getError(): ?EmailError` | `EmailError\|null` | reason for failure; `null` if valid |

### `EmailError`: the possible failure reasons

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

`NON_PUBLIC_MX_HOST` means the domain has MX records, but none of its hosts resolve exclusively to public addresses (see [SSRF protection](#ssrf-protection-on-mx-hosts-active-by-default)).

`NO_MX_RECORD` and `NULL_MX` are definitive DNS outcomes; `DNS_FAILURE` instead indicates a transient error (SERVFAIL, timeout, unreachable resolver): in that case the address should not be considered non-existent, but should be retried later or accepted with reservation.

Each case is backed by an English string ready to be displayed (`$error->value`) or used as a key for your own translation:

```php
$messages = [
    EmailError::EMPTY_ADDRESS->name    => "The address cannot be empty",
    EmailError::INVALID_FORMAT->name   => "The address format is not valid",
    // ...
];

$error = $result->getError(); // null if the address is valid

if (null !== $error) {
    echo $messages[$error->name] ?? $error->value;
}
```

### Validation pipeline

1. **Sanitize** — string coercion, trim, domain normalization (lowercase + IDN/punycode via `ext-intl`), `FILTER_SANITIZE_EMAIL`.
2. **Syntax** — `FILTER_VALIDATE_EMAIL` + RFC 5321 length limits.
3. **Format** — strict regex on the local part (dot-atom, RFC 5322) and on the domain (DNS labels + TLD, valid IDNA A-labels).
4. **Safe local part** — optional, disabled by default (see [Security](#security)).
5. **Gmail alias** — optional, disabled by default (see below).
6. **MX records** — DNS resolution with Null MX detection (RFC 7505), distinguishing between "record absent" and "DNS error" and, by default, discarding MX hosts that don't resolve to public addresses (see [SSRF protection](#ssrf-protection-on-mx-hosts-active-by-default)).

### MX resolution: injection and testing

DNS resolution is isolated behind the `EmailValidator\Service\MxResolver` interface, so it can be replaced in tests without touching the network:

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

To also accept domains without an MX but with an A/AAAA record (implicit MX, RFC 5321 §5.1), use the real DNS implementation with the dedicated option:

```php
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator(new DnsMxResolver(allowImplicitMx: true));
```

Timeout and retry count for DNS queries depend on the system resolver (`options timeout:N attempts:N` in `/etc/resolv.conf`): PHP's native DNS functions don't allow setting them. If validation is exposed to public input, apply a rate limit upstream and, if needed, implement `MxResolver` with a DNS library that supports timeouts and caching.

### SSRF protection on MX hosts (active by default)

MX records are chosen by whoever controls the domain, meaning, for an address entered in a form, by anyone. Whoever registers `attacker.com` can point its MX at `127.0.0.1`, a host on your internal network, or the cloud metadata endpoint (`169.254.169.254`). If your application then connects to the hosts from `getMxHosts()`, for example for an SMTP check (`RCPT TO`), without filtering it would become a proxy into your internal network (SSRF).

For this reason, `DnsMxResolver`, and therefore `new EmailValidator()` which uses it by default, applies two layers of defense.

**1. Targets that are not domain names — always discarded, cannot be disabled.** RFC 5321 §5.1 requires the target of an MX to be a domain name, never an address. The following are ignored:

| MX target | Why it's discarded |
| --- | --- |
| `127.0.0.1`, `10.0.0.1`, `169.254.169.254` | IPv4 address literal |
| `127.1`, `0177.0.0.1`, `0x7f.0.0.1`, `2130706433` | non-canonical numeric forms: `FILTER_VALIDATE_IP` doesn't recognize them, but `getaddrinfo()` / `inet_aton()` still resolve them to `127.0.0.1` |
| `localhost`, `mailserver` | single-label name, resolved via `/etc/hosts` or local search domains |
| `mx.example.123` | non-alphabetic TLD |

The target must have at least two labels and an alphabetic TLD (or IDN in punycode, `xn--…`).

**2. Hosts that resolve to non-public addresses — discarded by default.** For each remaining MX host, A and AAAA records are resolved, and the host is kept only if **all** of its addresses are public (`FILTER_FLAG_GLOBAL_RANGE`). The following hosts are therefore discarded if they resolve, even partially, to:

- loopback (`127.0.0.0/8`, `::1`);
- private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`);
- link-local, including the cloud metadata endpoint (`169.254.0.0/16`, `fe80::/10`);
- other reserved or non-routable ranges.

A host with no A/AAAA record is discarded, because it's unreachable. The same filter applies to the implicit host when `allowImplicitMx` is enabled.

What you see as a client:

```php
$result = (new EmailValidator())->validate('utente@dominio.com');

$result->getMxHosts();  // only the safe hosts, in the original priority order
$result->getError();    // if no host is safe:
                        //   EmailError::NON_PUBLIC_MX_HOST — all discarded
                        //   EmailError::DNS_FAILURE        — none safe and at least one A/AAAA query failed (retry)
```

If the domain has both public and internal hosts, the address remains valid and `getMxHosts()` contains only the public ones.

**Cost.** In addition to the MX query, an A and an AAAA query are needed for each host: a domain with 5 MX records (like `gmail.com`) requires 11 queries instead of 1. If you never connect to MX hosts and latency matters, or if you're validating addresses on an internal network whose mail servers genuinely have private addresses, you can disable layer 2. Layer 1 remains active regardless:

```php
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Service\EmailValidator;

$validator = new EmailValidator(new DnsMxResolver(rejectNonPublicHosts: false));
```

**Limitation: DNS rebinding.** The filter checks the addresses at validation time. If you later connect again *to the name*, a malicious DNS could respond with a different address (TTL zero). To close this window too, resolve the host once, reapply the check `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)`, and connect to that IP.

> **Behavior change.** Up to 1.0.2 layer 2 was disabled by default (`rejectNonPublicHosts: false`); see the [CHANGELOG](CHANGELOG.md). Anyone validating addresses with mail servers on private IPs now receives `NON_PUBLIC_MX_HOST` and must explicitly pass `rejectNonPublicHosts: false`.

### Gmail aliases (optional, disabled by default)

Gmail delivers to the same mailbox addresses written in different forms: dots in the local part are ignored and everything after a `+` is a subaddressing tag, so `mario.rossi@gmail.com`, `mariorossi@gmail.com`, and `mariorossi+shop@gmail.com` are the same recipient; the `googlemail.com` domain is also just a synonym for `gmail.com`. If your application wants to prevent users from registering multiple times by exploiting these aliases, you can use `isGmailAlias()` on its own:

```php
$validator = new EmailValidator();

$validator->isGmailAlias('mario.rossi+shop@gmail.com'); // true
$validator->isGmailAlias('MarioRossi@gmail.com');        // true (Gmail ignores case)
$validator->isGmailAlias('mariorossi@gmail.com');        // false (canonical form)
$validator->isGmailAlias('mario.rossi@example.com');     // false (not Gmail)
```

To detect duplicates it's even more useful to store the canonical form alongside the address and make it unique:

```php
$validator->canonicalGmailAddress('Mario.Rossi+shop@GoogleMail.com'); // "mariorossi@gmail.com"
$validator->canonicalGmailAddress('mario@example.com');               // null (not Gmail)
$validator->canonicalGmailAddress('mario..rossi@gmail.com');          // null (invalid address)
```

Both methods apply the same sanitize, syntax, and format checks as `validate()` (excluding the MX check): an invalid address is never an alias and has no canonical form, so forms like `mario..rossi@gmail.com` or `x@gmail.com.` can never collide with the real mailbox `mariorossi@gmail.com`.

Alternatively, you can explicitly ask `validate()` to reject aliases by passing `rejectGmailAlias: true` to the constructor. **The default behavior remains unchanged** (aliases are accepted): it's an option the client must explicitly request, not a constraint imposed by the library.

```php
$validator = new EmailValidator(rejectGmailAlias: true);

$result = $validator->validate('mario.rossi+shop@gmail.com');

$result->isValid();          // false
$result->getError();         // EmailError::GMAIL_ALIAS
```

## Security

An address that passes `validate()` is **RFC-compliant**, not automatically safe in every context.

- **Local part and shell.** Per RFC 5322, the local part can start with `-` and contain characters like `` ' ` | & $ { } ``: `-oQx@example.com` and ``a'|`$x`&{}@example.com`` are valid. Never pass a user-supplied address to the fifth parameter of `mail()` (e.g. `-f$email`, see CVE-2016-10033: PHP's internal escaping does not prevent argument injection) nor to a shell without `escapeshellarg()`, and always use parameterized queries in SQL. If your application doesn't need these characters, enable the restrictive mode, which only allows `[A-Za-z0-9._+-]` and rejects a leading `-` (this excludes real but rare addresses like `o'brien@example.com`):

  ```php
  $validator = new EmailValidator(safeLocalPart: true);
  $validator->validate('-oQx@example.com')->getError(); // EmailError::UNSAFE_LOCAL_PART
  ```

- **`getEmail()` on failed outcomes** returns exactly what the user submitted, including `<`, `>`, `"`, and CR/LF. Always apply escaping appropriate to the destination context (`htmlspecialchars()` in HTML, stripping CR/LF before writing to logs). For an address ready to use, use `getSanitizedEmail()`, which is `null` if validation failed.

- **MX hosts and SSRF.** The hosts returned by `getMxHosts()` are chosen by whoever controls the domain. By default, both targets that are not domain names and hosts that resolve to non-public addresses are discarded; see [SSRF protection on MX hosts](#ssrf-protection-on-mx-hosts-active-by-default) for details, the cost, how to disable it, and the DNS rebinding limitation (connect to the already-verified IP, not to the name again).

- **Cost of DNS queries.** Every `validate()` call performs DNS queries against a domain chosen by whoever submits the input: one MX query, plus one A and one AAAA query per MX host with SSRF protection active. Apply a rate limit on public endpoints.

## Development

```bash
composer install

composer test          # PHPUnit (tests requiring network are in the "network" group, excluded by default)
composer test-coverage # PHPUnit with coverage report (requires Xdebug or PCOV)
composer stan          # PHPStan at max level
composer cs             # PHP-CS-Fixer, @Symfony rules (dry-run)
composer cs-fix         # applies style fixes
composer rector         # Rector (dry-run)
composer check          # cs + stan + test
```

The unit test suite covers 100% of classes, methods, and lines in `src/` (verified with Xdebug). DNS resolution is tested by replacing the global `dns_get_record()` function with a controllable double (see `tests/Support/`), so every outcome — valid MX, Null MX, no MX, implicit MX, DNS error, non-public MX hosts — is verified without depending on the network; the tests in `tests/Integration/` instead serve as a check against real DNS.

### CI

The GitHub Actions workflow (`.github/workflows/ci.yml`) runs on every push/PR to `main` with three jobs:

- **lint** — `composer validate`, PHPStan (max), PHP-CS-Fixer (dry-run), and Rector (dry-run), run once on the minimum supported version (PHP 8.3).
- **test** — the PHPUnit suite (with coverage) on a PHP 8.3 / 8.4 / 8.5 matrix, to ensure compatibility with what's declared in `composer.json`.
- **network-tests** — integration tests with real DNS (`tests/Integration/`), run but non-blocking (`continue-on-error`), because they depend on the state of third-party domains.

Third-party actions are pinned to commit SHAs, checkout does not persist credentials (`persist-credentials: false`), and `.github/dependabot.yml` proposes weekly updates for actions and Composer dependencies.

No `composer.lock` is committed: each run resolves dependencies against the current `composer.json` constraints, so CI is the first to flag any incompatibility with new dependency versions.

## License

GPL-2.0-only. See [LICENSE](LICENSE).
