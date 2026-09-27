<?php

declare(strict_types=1);

namespace EmailValidator\Service;

use EmailValidator\Dto\EmailValidationResult;
use EmailValidator\Enum\EmailError;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * Validazione email per PHP >= 8.3.
 *
 * Pipeline:
 *   1. Sanitize      → coercizione a stringa, trim, normalizzazione dominio (lowercase + IDN/punycode), FILTER_SANITIZE_EMAIL
 *   2. Sintassi      → FILTER_VALIDATE_EMAIL + limiti di lunghezza RFC 5321
 *   3. Formato       → regex stretta su local part (dot-atom) e dominio (label + TLD)
 *   4. Record MX     → risoluzione via {@see MxResolver}, con rilevamento Null MX (RFC 7505)
 */
final readonly class EmailValidator
{
    // RFC 5321 (path 256 - <>)
    private const int MAX_LENGTH = 254;

    private const int MAX_LOCAL_LENGTH = 64;

    private const int MAX_LABEL_LENGTH = 63;

    // Local part "dot-atom" (RFC 5322) senza quoted-string: niente punti iniziali/finali/consecutivi
    private const string LOCAL_REGEX =
        "/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+)*$/D";

    // Singola label DNS: alfanumerici e trattini, non inizia né finisce con '-'
    private const string LABEL_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)$/D';

    // TLD: solo lettere oppure IDN in punycode
    private const string TLD_REGEX = '/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D';

    private MxResolver $mxResolver;

    private EffectivePrimitiveTypeIdentifierServiceInterface $effectivePrimitiveTypeIdentifierService;

    public function __construct(
        ?MxResolver $mxResolver = null,
        ?EffectivePrimitiveTypeIdentifierServiceInterface $effectivePrimitiveTypeIdentifierService = null,
    ) {
        $this->mxResolver = $mxResolver ?? new DnsMxResolver();
        $this->effectivePrimitiveTypeIdentifierService = $effectivePrimitiveTypeIdentifierService ?? new EffectivePrimitiveTypeIdentifierService();
    }

    public function validate(mixed $input): EmailValidationResult
    {
        // ── 1. SANITIZE ────────────────────────────────────────────────
        // Coercizione a stringa + trim: rende la pipeline sicura anche quando
        // $input arriva grezzo da fonti non fidate (form, querystring, JSON
        // decodificato: null, array, scalari) invece che già come string
        // tipizzata. Non si usa sanitizeHtml: FILTER_FLAG_STRIP_HIGH
        // rimuoverebbe i domini Unicode (IDN) e '+' è un carattere legittimo
        // del local part (subaddressing "user+tag@dominio").
        $raw = $this->effectivePrimitiveTypeIdentifierService->getStringValue($input, trim: true);

        if ('' === $raw) {
            return EmailValidationResult::fail($raw, EmailError::EMPTY_ADDRESS);
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            return EmailValidationResult::fail($raw, EmailError::INVALID_ENCODING);
        }

        // normalizeDomain() non può mai restituire una stringa vuota partendo
        // da $raw non vuoto: nel caso senza '@' restituisce $raw invariato,
        // altrimenti ricostruisce almeno "@dominio" (o "local@").
        $normalized = $this->normalizeDomain($raw);

        $sanitized = (string) filter_var($normalized, FILTER_SANITIZE_EMAIL);

        // Se il sanitize ha rimosso qualcosa, l'input conteneva caratteri illeciti:
        // si rifiuta invece di "correggere" in silenzio l'indirizzo dell'utente.
        if ($sanitized !== $normalized) {
            return EmailValidationResult::fail($raw, EmailError::SANITIZE_ALTERED);
        }

        $email = $sanitized;

        // ── 2. VALIDAZIONE SINTASSI ────────────────────────────────────
        if (strlen($email) > self::MAX_LENGTH) {
            return EmailValidationResult::fail($email, EmailError::TOO_LONG);
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return EmailValidationResult::fail($email, EmailError::INVALID_SYNTAX);
        }

        // ── 3. VALIDAZIONE FORMATO ─────────────────────────────────────
        [$local, $domain] = $this->split($email);

        if (!$this->isValidLocalPart($local) || !$this->isValidDomain($domain)) {
            return EmailValidationResult::fail($email, EmailError::INVALID_FORMAT);
        }

        // ── 4. RECORD MX ───────────────────────────────────────────────
        $mx = $this->mxResolver->resolve($domain);

        return $mx instanceof EmailError
            ? EmailValidationResult::fail($email, $mx)
            : EmailValidationResult::ok($email, $mx);
    }

    /**
     * Il dominio è case-insensitive: lo porta in minuscolo e, se contiene
     * caratteri Unicode, lo converte in punycode (richiede ext-intl).
     * La local part NON viene toccata: per RFC è potenzialmente case-sensitive.
     */
    private function normalizeDomain(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = mb_strtolower(substr($email, $at + 1), 'UTF-8');

        if (1 === preg_match('/[^\x00-\x7F]/', $domain) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii(
                $domain,
                IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
                INTL_IDNA_VARIANT_UTS46,
            );
            // Se la conversione fallisce si lascia l'originale: verrà scartato dal sanitize
            $domain = false !== $ascii ? $ascii : $domain;
        }

        return $local . '@' . $domain;
    }

    /**
     * @return array{string, string}
     */
    private function split(string $email): array
    {
        $at = strrpos($email, '@');

        if (false === $at) {
            // split() è privato e viene chiamato solo dopo che
            // FILTER_VALIDATE_EMAIL ha già confermato la presenza di '@': il
            // ramo sotto resta solo per soddisfare il tipo int|false di
            // strrpos() agli occhi di PHPStan.
            // @codeCoverageIgnoreStart
            return [$email, ''];
            // @codeCoverageIgnoreEnd
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }

    /**
     * A questo punto della pipeline FILTER_VALIDATE_EMAIL ha già garantito un
     * local part non vuoto, lungo al più 64 caratteri (RFC 5321) e privo di
     * punti iniziali/finali/consecutivi: con il comportamento attuale di
     * ext/filter nessuno dei controlli qui sotto può quindi fallire. Restano
     * come garanzia esplicita e documentata (RFC 5322 dot-atom), perché quel
     * comportamento non è specificato da alcuna RFC e potrebbe cambiare tra
     * le versioni di PHP: senza questo metodo la libreria dipenderebbe
     * ciecamente da un dettaglio implementativo di ext/filter.
     *
     * @codeCoverageIgnore
     */
    private function isValidLocalPart(string $local): bool
    {
        return '' !== $local
            && strlen($local) <= self::MAX_LOCAL_LENGTH
            && 1 === preg_match(self::LOCAL_REGEX, $local);
    }

    private function isValidDomain(string $domain): bool
    {
        $labels = explode('.', $domain);

        if (count($labels) < 2) {
            // Come per isValidLocalPart(): FILTER_VALIDATE_EMAIL richiede già
            // un dominio con almeno un punto. Garanzia esplicita RFC 1035,
            // non raggiungibile con l'attuale comportamento di ext/filter.
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                // Limite di 63 caratteri per label: già imposto da
                // FILTER_VALIDATE_EMAIL. Garanzia esplicita RFC 1035.
                // @codeCoverageIgnoreStart
                return false;
                // @codeCoverageIgnoreEnd
            }

            if (1 !== preg_match(self::LABEL_REGEX, $label)) {
                return false;
            }
        }

        $tld = end($labels);

        if (false === $tld) {
            // Impossibile per costruzione: $labels ha sempre almeno 2
            // elementi (verificato sopra) ed explode() non restituisce mai un
            // array vuoto, quindi end() qui non può restituire false. Resta
            // solo per soddisfare il tipo string|false richiesto da PHPStan.
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        return 1 === preg_match(self::TLD_REGEX, $tld);
    }
}
