<?php

namespace App\Services\Sync;

/**
 * Le sort d'UN élément d'un lot de synchronisation.
 *
 * L'uuid client est toujours rendu tel qu'il a été reçu, y compris pour un
 * élément rejeté : c'est la seule clé dont dispose le téléphone pour retrouver
 * la ligne concernée dans sa propre base et la marquer.
 */
class ResultatElement
{
    private function __construct(
        public readonly bool $accepte,
        public readonly ?string $uuidClient,
        public readonly string $type,
        public readonly int $rang,
        public readonly ?int $id = null,
        public readonly ?string $action = null,
        public readonly ?CodeRejet $code = null,
        public readonly ?string $motif = null,
        public readonly ?array $details = null,
    ) {}

    /**
     * @param  string  $action  « cree », « existant » ou « mis_a_jour » — le
     *                          téléphone y lit si son envoi précédent était déjà
     *                          passé, sans que ce soit une erreur.
     */
    public static function accepte(
        ?string $uuidClient,
        string $type,
        int $rang,
        ?int $id,
        string $action
    ): self {
        return new self(true, $uuidClient, $type, $rang, $id, $action);
    }

    public static function rejete(
        ?string $uuidClient,
        string $type,
        int $rang,
        CodeRejet $code,
        string $motif,
        ?array $details = null
    ): self {
        return new self(false, $uuidClient, $type, $rang, null, null, $code, $motif, $details);
    }

    public function enTableau(): array
    {
        $base = [
            'rang' => $this->rang,
            'uuid_client' => $this->uuidClient,
            'type' => $this->type,
        ];

        if ($this->accepte) {
            return $base + ['id' => $this->id, 'action' => $this->action];
        }

        return $base + array_filter([
            'code' => $this->code?->value,
            'motif' => $this->motif,
            'reessayer' => $this->code?->reessayer(),
            'details' => $this->details,
        ], fn ($valeur) => $valeur !== null);
    }
}
