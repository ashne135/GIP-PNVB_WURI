<?php

namespace App\Services\Sync;

/**
 * POURQUOI un élément a été refusé — et surtout, CE QUE LE TÉLÉPHONE DOIT EN FAIRE.
 *
 * C'est la distinction la plus importante de tout le mécanisme hors ligne.
 * Sans elle, le mobile n'a que deux comportements possibles, tous deux mauvais :
 * réessayer sans fin un élément définitivement invalide et bloquer sa file, ou
 * abandonner un élément qui serait passé à la tentative suivante — et perdre
 * une journée de travail d'un agent.
 *
 * D'où un code stable par motif, et une réponse claire à la seule question qui
 * compte pour le client : faut-il réessayer ?
 */
enum CodeRejet: string
{
    /** Type absent du registre : le mobile est plus récent que le serveur. */
    case TypeInconnu = 'type_inconnu';

    /** Données malformées : réessayer à l'identique ne changera rien. */
    case DonneesInvalides = 'donnees_invalides';

    /** Droit ou périmètre refusé : ce n'est pas un incident réseau. */
    case DroitRefuse = 'droit_refuse';

    /** Règle métier non satisfaite — message en français, lisible par l'agent. */
    case RegleMetier = 'regle_metier';

    /** Objet visé introuvable côté serveur : il a pu être supprimé depuis. */
    case IntrouvableServeur = 'introuvable_serveur';

    /** Panne serveur : la seule situation où réessayer a un sens. */
    case ErreurServeur = 'erreur_serveur';

    /**
     * Accès fermé, et l'action est POSTÉRIEURE à la fermeture — ou son heure
     * manque, ce qui empêche de prouver le contraire. Le temps n'y changera rien.
     */
    case AccesFerme = 'acces_ferme';

    /**
     * LE TÉLÉPHONE DOIT-IL REMETTRE CET ÉLÉMENT DANS SA FILE ?
     *
     * Deux codes disent oui, et pour des raisons différentes :
     *   - une panne serveur se résout d'elle-même ;
     *   - une cible introuvable arrive souvent d'un AUTRE appareil. Un visa
     *     remonté avant le rapport qu'il vise redeviendra traitable dès que
     *     l'opérateur aura synchronisé le sien.
     *
     * Tous les autres décrivent un problème que le temps ne résout pas : les
     * garder en file ferait grossir indéfiniment le tampon d'un téléphone qui a
     * déjà peu de place.
     */
    public function reessayer(): bool
    {
        return in_array($this, [self::ErreurServeur, self::IntrouvableServeur], true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::TypeInconnu => 'Type de donnée inconnu du serveur',
            self::DonneesInvalides => 'Données incomplètes ou invalides',
            self::DroitRefuse => 'Action non autorisée pour ce compte',
            self::RegleMetier => 'Règle métier non satisfaite',
            self::IntrouvableServeur => 'Élément introuvable sur le serveur',
            self::ErreurServeur => 'Incident technique du serveur',
            self::AccesFerme => 'Action postérieure à la fermeture de l\'accès',
        };
    }
}
