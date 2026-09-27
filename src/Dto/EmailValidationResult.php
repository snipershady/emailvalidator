<?php

declare(strict_types=1);

namespace EmailValidator\Dto;

use EmailValidator\Enum\EmailError;

/**
 * Esito immutabile della validazione di un indirizzo email.
 */
final readonly class EmailValidationResult
{
    /** @param list<string> $mxHosts */
    private function __construct(
        private bool $valid,
        private string $email,
        private ?EmailError $emailError = null,
        private array $mxHosts = [],
    ) {
    }

    /** @param list<string> $mxHosts */
    public static function ok(string $email, array $mxHosts): self
    {
        return new self(valid: true, email: $email, mxHosts: $mxHosts);
    }

    public static function fail(string $email, EmailError $emailError): self
    {
        return new self(valid: false, email: $email, emailError: $emailError);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Indirizzo raggiunto dalla pipeline, valido o meno.
     *
     * ATTENZIONE: se isValid() è false il valore è input utente NON fidato,
     * anche con caratteri come < > " o CR/LF: va sempre sottoposto a escaping
     * per il contesto di destinazione (htmlspecialchars() in HTML, rimozione
     * di CR/LF nei log, ...). È vuoto quando l'input non era una stringa,
     * superava la dimensione massima o non era UTF-8 valido.
     * Per un indirizzo pronto all'uso usare {@see getSanitizedEmail()}.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    public function getError(): ?EmailError
    {
        return $this->emailError;
    }

    /** @return list<string> */
    public function getMxHosts(): array
    {
        return $this->mxHosts;
    }

    /**
     * Restituisce l'indirizzo sanitizzato solo se la validazione ha avuto esito
     * positivo, altrimenti null: evita di riutilizzare per errore un indirizzo
     * non valido come se fosse pronto all'uso.
     */
    public function getSanitizedEmail(): ?string
    {
        return $this->valid ? $this->email : null;
    }
}
