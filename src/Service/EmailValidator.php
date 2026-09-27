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
 *   1. Sanitize      → limite sulla dimensione grezza, coercizione a stringa, trim, normalizzazione dominio (lowercase + IDN/punycode), FILTER_SANITIZE_EMAIL
 *   2. Sintassi      → FILTER_VALIDATE_EMAIL + limiti di lunghezza RFC 5321
 *   3. Formato       → regex stretta su local part (dot-atom) e dominio (label + TLD, A-label IDNA valide)
 *   4. Local part sicura → opzionale, disattivata di default, vedi $safeLocalPart
 *   5. Alias Gmail   → opzionale, disattivato di default, vedi {@see isGmailAlias()}
 *   6. Record MX     → risoluzione via {@see MxResolver}, con rilevamento Null MX (RFC 7505)
 *
 * Un indirizzo valido è conforme alle RFC, NON è sicuro in ogni contesto:
 * senza $safeLocalPart il local part può iniziare con '-' o contenere
 * caratteri come ' ` | & $ { }, pericolosi se passati a una shell o al
 * quinto parametro di mail() (vedi CVE-2016-10033).
 */
final readonly class EmailValidator
{
    // RFC 5321 (path 256 - <>)
    private const int MAX_LENGTH = 254;

    private const int MAX_LOCAL_LENGTH = 64;

    private const int MAX_LABEL_LENGTH = 63;

    // Oltre questa dimensione (in byte, spazi inclusi) l'input grezzo viene
    // rifiutato prima di qualunque elaborazione: nessun indirizzo reale ci si
    // avvicina e si evita di processare payload arbitrariamente grandi.
    private const int MAX_INPUT_LENGTH = 1024;

    // Local part "dot-atom" (RFC 5322) senza quoted-string: niente punti iniziali/finali/consecutivi
    private const string LOCAL_REGEX =
        "/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+)*$/D";

    // Singola label DNS: alfanumerici e trattini, non inizia né finisce con '-'
    private const string LABEL_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)$/D';

    // TLD: solo lettere oppure IDN in punycode
    private const string TLD_REGEX = '/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D';

    // Local part "sicura": solo alfanumerici, '.', '_', '+', '-' e mai '-' iniziale
    private const string SAFE_LOCAL_REGEX = '/^(?!-)[A-Za-z0-9._+-]+$/D';

    private const int IDNA_TO_ASCII_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    private const int IDNA_TO_UNICODE_OPTIONS = IDNA_NONTRANSITIONAL_TO_UNICODE | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    private MxResolver $mxResolver;

    private EffectivePrimitiveTypeIdentifierServiceInterface $effectivePrimitiveTypeIdentifierService;

    public function __construct(
        ?MxResolver $mxResolver = null,
        ?EffectivePrimitiveTypeIdentifierServiceInterface $effectivePrimitiveTypeIdentifierService = null,
        /**
         * Se true, validate() rifiuta (EmailError::GMAIL_ALIAS) un indirizzo
         * che {@see isGmailAlias()} riconosce come alias di Gmail. Disattivato
         * di default: è un'opzione esplicita del client, non un comportamento
         * imposto dalla libreria.
         */
        private bool $rejectGmailAlias = false,
        /**
         * Se true, validate() rifiuta (EmailError::UNSAFE_LOCAL_PART) un local
         * part con caratteri diversi da [A-Za-z0-9._+-] o che inizia con '-'.
         * Esclude indirizzi RFC-validi ma rari (es. "o'brien@..."), in cambio
         * di un indirizzo innocuo anche per shell, header e argomenti di
         * comando. Disattivato di default.
         */
        private bool $safeLocalPart = false,
    ) {
        $this->mxResolver = $mxResolver ?? new DnsMxResolver();
        $this->effectivePrimitiveTypeIdentifierService = $effectivePrimitiveTypeIdentifierService ?? new EffectivePrimitiveTypeIdentifierService();
    }

    public function validate(mixed $input): EmailValidationResult
    {
        $email = $this->checkSyntax($input);
        if ($email instanceof EmailValidationResult) {
            return $email;
        }

        [$local, $domain] = $this->split($email);

        // ── 4. LOCAL PART SICURA (opzionale) ────────────────────────────
        if ($this->safeLocalPart && 1 !== preg_match(self::SAFE_LOCAL_REGEX, $local)) {
            return EmailValidationResult::fail($email, EmailError::UNSAFE_LOCAL_PART);
        }

        // ── 5. ALIAS GMAIL (opzionale) ──────────────────────────────────
        if ($this->rejectGmailAlias && $this->isValidatedGmailAlias($email)) {
            return EmailValidationResult::fail($email, EmailError::GMAIL_ALIAS);
        }

        // ── 6. RECORD MX ───────────────────────────────────────────────
        $mx = $this->mxResolver->resolve($domain);

        return $mx instanceof EmailError
            ? EmailValidationResult::fail($email, $mx)
            : EmailValidationResult::ok($email, $mx);
    }

    /**
     * Passi 1-3 della pipeline (sanitize, sintassi, formato), senza rete.
     *
     * @return string|EmailValidationResult l'indirizzo sanitizzato e
     *                                      sintatticamente valido, oppure l'esito fallito
     */
    private function checkSyntax(mixed $input): string|EmailValidationResult
    {
        // ── 1. SANITIZE ────────────────────────────────────────────────
        // Coercizione a stringa + trim: rende la pipeline sicura anche quando
        // $input arriva grezzo da fonti non fidate (form, querystring, JSON
        // decodificato: null, array, scalari) invece che già come string
        // tipizzata. Non si usa sanitizeHtml: FILTER_FLAG_STRIP_HIGH
        // rimuoverebbe i domini Unicode (IDN) e '+' è un carattere legittimo
        // del local part (subaddressing "user+tag@dominio").
        //
        // Array e oggetti non possono essere un indirizzo: vengono rifiutati
        // senza attraversarli (un array molto annidato farebbe lanciare
        // un'eccezione alla libreria di coercizione, uno enorme costerebbe CPU).
        if (null !== $input && !is_scalar($input)) {
            return EmailValidationResult::fail('', EmailError::EMPTY_ADDRESS);
        }

        // Limite sulla dimensione grezza prima di trim, mb_*, IDN e filter:
        // l'input scartato non viene conservato nel risultato.
        if (is_string($input) && strlen($input) > self::MAX_INPUT_LENGTH) {
            return EmailValidationResult::fail('', EmailError::TOO_LONG);
        }

        $raw = $this->effectivePrimitiveTypeIdentifierService->getStringValue($input, trim: true);

        if ('' === $raw) {
            return EmailValidationResult::fail($raw, EmailError::EMPTY_ADDRESS);
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            // I byte non UTF-8 non vengono restituiti: non sono rappresentabili
            // in modo sicuro né in HTML né nei log.
            return EmailValidationResult::fail('', EmailError::INVALID_ENCODING);
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

        return $email;
    }

    /**
     * Funzionalità di validazione opzionale, richiamabile a prescindere da
     * {@see validate()}: riconosce se $email è un "alias" di una casella
     * Gmail, cioè un indirizzo diverso dalla forma canonica che Gmail
     * recapita comunque nella stessa casella:
     *
     *  - punti nel local part ("mario.rossi@gmail.com" == "mariorossi@gmail.com");
     *  - subaddressing con '+' ("mariorossi+shop@gmail.com");
     *  - maiuscole nel local part ("MarioRossi@gmail.com"): Gmail le ignora;
     *  - il dominio alternativo "googlemail.com", sinonimo di "gmail.com".
     *
     * $email passa per gli stessi controlli di sanitize, sintassi e formato
     * di validate() (senza la verifica MX): un indirizzo non valido, come
     * "mario..rossi@gmail.com" o "x@gmail.com.", non è mai un alias e
     * restituisce false. Il dominio viene normalizzato come in validate()
     * (minuscole, IDN), quindi anche "GMAIL.COM" o "ｇｍａｉｌ.com" sono
     * riconosciuti.
     */
    public function isGmailAlias(string $email): bool
    {
        $email = $this->checkSyntax($email);

        return is_string($email) && $this->isValidatedGmailAlias($email);
    }

    /**
     * Forma canonica di un indirizzo Gmail ("mariorossi@gmail.com"), utile per
     * riconoscere registrazioni duplicate fatte tramite alias: senza punti,
     * senza tag '+', in minuscolo, sul dominio "gmail.com".
     *
     * Come {@see isGmailAlias()}, applica i controlli sintattici di
     * validate(): restituisce null se $email non è un indirizzo valido (così
     * "mario..rossi@gmail.com" non può collidere con la casella reale
     * "mariorossi@gmail.com"), se non è Gmail o se il local part canonico
     * risulterebbe vuoto (es. "+tag@gmail.com").
     */
    public function canonicalGmailAddress(string $email): ?string
    {
        $email = $this->checkSyntax($email);
        if (!is_string($email)) {
            return null;
        }

        $parts = $this->splitGmail($email);
        if (null === $parts) {
            return null;
        }

        $local = $this->canonicalGmailLocal($parts[0]);

        return '' === $local ? null : $local . '@gmail.com';
    }

    /**
     * @param string $email indirizzo già passato da {@see checkSyntax()}
     */
    private function isValidatedGmailAlias(string $email): bool
    {
        $parts = $this->splitGmail($email);
        if (null === $parts) {
            return false;
        }

        [$local, $domain] = $parts;

        return 'googlemail.com' === $domain || $local !== $this->canonicalGmailLocal($local);
    }

    /**
     * @param string $email indirizzo già passato da {@see checkSyntax()}: il
     *                      dominio è normalizzato e privo di punti finali
     *
     * @return array{string, string}|null local part e dominio, se Gmail
     */
    private function splitGmail(string $email): ?array
    {
        [$local, $domain] = $this->split($email);

        if ('gmail.com' !== $domain && 'googlemail.com' !== $domain) {
            return null;
        }

        return [$local, $domain];
    }

    private function canonicalGmailLocal(string $local): string
    {
        $plus = strpos($local, '+');
        if (false !== $plus) {
            $local = substr($local, 0, $plus);
        }

        return strtolower(str_replace('.', '', $local));
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

        return substr($email, 0, $at) . '@' . $this->normalizeDomainPart(substr($email, $at + 1));
    }

    private function normalizeDomainPart(string $domain): string
    {
        $domain = mb_strtolower($domain, 'UTF-8');

        if (1 === preg_match('/[^\x00-\x7F]/', $domain)) {
            $ascii = idn_to_ascii($domain, self::IDNA_TO_ASCII_OPTIONS, INTL_IDNA_VARIANT_UTS46);
            // Se la conversione fallisce si lascia l'originale: verrà scartato dal sanitize
            $domain = false !== $ascii ? $ascii : $domain;
        }

        return $domain;
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

            if (1 !== preg_match(self::LABEL_REGEX, $label) || !$this->isValidHyphenation($label)) {
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

    /**
     * Le label con '--' in terza e quarta posizione sono riservate (RFC 5891
     * §4.2.3.1): è ammesso solo il prefisso IDNA "xn--", e in quel caso la
     * label deve essere una A-label valida, cioè decodificabile e identica a
     * se stessa dopo il round trip punycode → Unicode → punycode.
     */
    private function isValidHyphenation(string $label): bool
    {
        if ('--' !== substr($label, 2, 2)) {
            return true;
        }

        if (!str_starts_with($label, 'xn--')) {
            return false;
        }

        $unicode = idn_to_utf8($label, self::IDNA_TO_UNICODE_OPTIONS, INTL_IDNA_VARIANT_UTS46);
        if (false === $unicode || $unicode === $label) {
            return false;
        }

        return idn_to_ascii($unicode, self::IDNA_TO_ASCII_OPTIONS, INTL_IDNA_VARIANT_UTS46) === $label;
    }
}
