<?php

namespace App\Enums;

/**
 * Cycle de vie d'un rapport journalier (cadrage v2, section 9).
 *
 *   brouillon → soumis → visé → clos
 *                  ↘ rejeté → (retour en brouillon pour correction)
 *
 * « Un rapport visé n'est plus modifiable par son auteur. Un rejet exige un
 * motif. » Ces deux règles sont portées par les méthodes ci-dessous et
 * appliquées par la Policy, jamais par l'interface seule.
 */
enum StatutRapport: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case Vise = 'vise';
    case Rejete = 'rejete';
    case Clos = 'clos';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis, en attente de visa',
            self::Vise => 'Visé',
            self::Rejete => 'Rejeté pour correction',
            self::Clos => 'Clos',
        };
    }

    /** L'auteur peut-il encore modifier son rapport ? */
    public function modifiableParAuteur(): bool
    {
        return in_array($this, [self::Brouillon, self::Rejete], true);
    }

    /** Le supérieur peut-il viser ou rejeter à ce stade ? */
    public function attendUnVisa(): bool
    {
        return $this === self::Soumis;
    }

    /**
     * Ce rapport peut-il alimenter le pré-remplissage du niveau supérieur ?
     * Seuls les chiffres DÉJÀ VISÉS remontent (cadrage, règles transverses).
     */
    public function alimenteLeNiveauSuperieur(): bool
    {
        return in_array($this, [self::Vise, self::Clos], true);
    }

    /** @return self[] Les transitions permises depuis cet état. */
    public function transitionsPossibles(): array
    {
        return match ($this) {
            self::Brouillon => [self::Soumis],
            self::Soumis => [self::Vise, self::Rejete],
            self::Rejete => [self::Soumis],
            self::Vise => [self::Clos],
            self::Clos => [],
        };
    }

    public function peutAllerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsPossibles(), true);
    }
}
