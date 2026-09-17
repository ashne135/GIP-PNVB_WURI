<?php

namespace App\Services\Exports;

use App\Models\Incident;

/**
 * Les incidents déclarés pendant la période.
 *
 * LE DÉLAI DE PRISE EN CHARGE EST LA COLONNE QUI COMPTE : c'est son absence qui
 * déclenche l'escalade, et c'est elle qu'un comité de suivi regarde. Le niveau
 * de gravité reste CELUI DU TÉMOIN — l'escalade élargit la liste des personnes
 * prévenues, elle ne réécrit jamais ce qui a été déclaré.
 *
 * Le récit n'est pas exporté : il contient souvent des noms et des
 * circonstances, et un fichier qui circule n'est pas le bon endroit pour cela.
 * La fiche complète reste lisible au back-office, par qui en a le droit.
 */
class SourceIncidents implements SourceExport
{
    public function cle(): string
    {
        return 'incidents';
    }

    public function libelle(): string
    {
        return 'Incidents déclarés';
    }

    public function colonnes(): array
    {
        return [
            'Numéro', 'Déclaré le', 'Survenu le', 'Région', 'Centre', 'Site',
            'Natures', 'Gravité', 'Danger immédiat', 'Personnes affectées',
            'Statut', 'Niveau d\'escalade', 'Pris en charge le',
            'Délai de prise en charge (heures)', 'Responsable', 'Résolu le',
        ];
    }

    public function lignes(?int $regionId, string $du, string $au): iterable
    {
        return Incident::query()
            ->whereBetween('declare_le', [$du.' 00:00:00', $au.' 23:59:59'])
            ->when($regionId, fn ($requete) => $requete->where('region_id', $regionId))
            ->with([
                'region:id,nom', 'centre:id,code', 'site:id,code',
                'natures:id,libelle', 'responsable:id,nom,prenoms',
            ])
            ->orderBy('declare_le')
            ->get()
            ->map(fn (Incident $incident) => [
                $incident->numero,
                $incident->declare_le?->format('d/m/Y H:i'),
                $incident->survenu_le?->format('d/m/Y H:i') ?? '',
                $incident->region?->nom,
                $incident->centre?->code ?? '',
                $incident->site?->code ?? '',
                $incident->natures->pluck('libelle')->join(', '),
                $incident->gravite?->libelle(),
                $incident->danger_immediat ? 'oui' : 'non',
                $incident->nb_personnes_affectees ?? '',
                $incident->statut,
                $incident->niveau_escalade,
                $incident->pris_en_charge_le?->format('d/m/Y H:i') ?? 'jamais',
                $this->delaiPriseEnCharge($incident),
                $incident->responsable?->nomComplet() ?? '',
                $incident->resolu_le?->format('d/m/Y H:i') ?? '',
            ])
            ->all();
    }

    /**
     * Le temps écoulé entre la déclaration et la prise en charge.
     *
     * Vide tant que personne ne s'est nommé : écrire zéro laisserait croire à
     * une prise en charge immédiate, ce qui est exactement l'inverse.
     */
    private function delaiPriseEnCharge(Incident $incident): string
    {
        if (! $incident->declare_le || ! $incident->pris_en_charge_le) {
            return '';
        }

        return (string) round($incident->declare_le->floatDiffInHours($incident->pris_en_charge_le), 1);
    }
}
