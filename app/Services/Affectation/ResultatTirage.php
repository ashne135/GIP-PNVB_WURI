<?php

namespace App\Services\Affectation;

/**
 * La PROPOSITION issue d'un tirage, telle qu'elle est affichée AVANT validation
 * (cadrage, section 7).
 *
 * « Le résultat est une PROPOSITION affichée avant validation : liste des
 * affectations, ALERTES SUR LES CONTRAINTES NON SATISFAITES, effectifs
 * restants. »
 *
 * Les anomalies ne bloquent pas le tirage : elles l'accompagnent. Un centre
 * sans superviseur disponible, deux centres trop éloignés, un kit manquant —
 * l'administrateur doit les voir pour décider, pas les découvrir après coup.
 */
class ResultatTirage
{
    public array $superviseurs = [];

    public array $operateurs = [];

    public array $assistants = [];

    /** @var array<int, array{type: string, message: string}> */
    public array $anomalies = [];

    public array $effectifsRestants = [];

    public function __construct(
        public readonly int $graine,
        public readonly array $contraintes,
    ) {
    }

    public function nombreAffectations(): int
    {
        return count($this->superviseurs) + count($this->operateurs) + count($this->assistants);
    }

    public function contraintesNonSatisfaites(): int
    {
        return count(array_filter(
            $this->superviseurs,
            fn (array $s) => $s['contrainte_respectee'] === false
        ));
    }

    public function enTableau(): array
    {
        return [
            'graine' => $this->graine,
            'contraintes' => $this->contraintes,
            'effectifs' => [
                'superviseurs' => count($this->superviseurs),
                'operateurs' => count($this->operateurs),
                'assistants' => count($this->assistants),
                'total' => $this->nombreAffectations(),
            ],
            'superviseurs' => $this->superviseurs,
            'operateurs' => $this->operateurs,
            'assistants' => $this->assistants,
            'anomalies' => $this->anomalies,
            'contraintes_non_satisfaites' => $this->contraintesNonSatisfaites(),
            'effectifs_restants' => $this->effectifsRestants,
        ];
    }
}
