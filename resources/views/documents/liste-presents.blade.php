<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 12mm 20mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1a1a1a; }
        .entete { border-bottom: 2px solid #0d5a62; padding-bottom: 7px; margin-bottom: 12px; }
        .entete h1 { font-size: 13pt; margin: 0 0 2px; color: #0d5a62; }
        .entete .projet { font-size: 8.5pt; color: #555; }
        .meta { font-size: 8pt; color: #555; margin-bottom: 10px; }
        .meta strong { color: #1a1a1a; }
        .totaux { background: #f2f6f6; border-left: 3px solid #0d5a62;
                  padding: 6px 9px; font-size: 8.5pt; margin-bottom: 12px; }
        .feuille { margin-bottom: 14px; page-break-inside: avoid; }
        .feuille h2 { font-size: 10pt; margin: 0 0 4px; color: #0d5a62;
                      border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        .feuille .contexte { font-size: 8pt; color: #555; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        th { background: #edf1f1; text-align: left; padding: 4px 6px;
             border-bottom: 1px solid #bbb; font-size: 7.5pt;
             text-transform: uppercase; letter-spacing: .04em; color: #555; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e5e5; }
        .present { color: #2d6a4a; font-weight: bold; }
        .absent { color: #9c3323; font-weight: bold; }
        .justifie { color: #8a5313; font-weight: bold; }
        .gris { color: #888; }
        .brouillon { background: #f8e6e2; border: 1px solid #9c3323;
                     padding: 5px 8px; font-size: 8pt; color: #9c3323; margin-bottom: 6px; }
        .signature { margin-top: 10px; font-size: 8pt; color: #444; }
        .pied { position: fixed; bottom: -12mm; left: 0; right: 0;
                font-size: 7pt; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <h1>Liste des présents</h1>
        <div class="projet">GIP-PNVB — Projet WURI — Plateforme de gestion des volontaires</div>
    </div>

    <div class="meta">
        {{ $intitule }}<br>
        Document généré le <strong>{{ $genere_le->format('d/m/Y à H:i') }}</strong>
        par <strong>{{ $genere_par }}</strong>
    </div>

    <div class="totaux">
        {{ $totaux['feuilles'] }} feuille(s) · {{ $totaux['agents'] }} agents ·
        <strong>{{ $totaux['presents'] }} présents</strong> ·
        {{ $totaux['absents'] }} absents ·
        {{ $totaux['absents_justifies'] }} absences justifiées
    </div>

    @foreach ($feuilles as $feuille)
        <div class="feuille">
            <h2>{{ $feuille->site?->code }} — {{ $feuille->site?->nom }}</h2>

            <div class="contexte">
                Centre {{ $feuille->centre?->code }} ·
                Date {{ $feuille->date_presence?->format('d/m/Y') }} ·
                Superviseur validant : <strong>{{ $feuille->superviseur?->user?->nomComplet() ?? '—' }}</strong>
                @if ($feuille->valide_le)
                    · Validée le {{ $feuille->valide_le->format('d/m/Y à H:i') }}
                    @if ($feuille->distance_site_metres !== null)
                        à {{ $feuille->distance_site_metres }} m du site
                    @endif
                @endif
            </div>

            @if (! $feuille->estValidee())
                <div class="brouillon">
                    Cette feuille n'est pas validée : elle ne fait pas foi.
                </div>
            @endif

            @if ($feuille->statut === 'corrigee')
                <div class="brouillon">
                    Feuille corrigée après validation le
                    {{ $feuille->corrige_le?->format('d/m/Y à H:i') }} —
                    motif : {{ $feuille->motif_correction }}
                </div>
            @endif

            <table>
                <thead>
                    <tr>
                        <th>Matricule</th>
                        <th>Nom et prénoms</th>
                        <th>Catégorie</th>
                        <th>Présence</th>
                        <th>Motif</th>
                        <th>Arrivée signalée</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($feuille->lignes as $ligne)
                        <tr>
                            <td>{{ $ligne->volontaire?->matricule }}</td>
                            <td>{{ $ligne->volontaire?->user?->nomComplet() }}</td>
                            <td>{{ $ligne->categorie?->value }}</td>
                            <td class="{{ $ligne->statut?->value === 'present' ? 'present'
                                : ($ligne->statut?->value === 'absent_justifie' ? 'justifie' : 'absent') }}">
                                {{ $ligne->statut?->libelle() }}
                            </td>
                            <td>{{ $ligne->motif_absence ?? '—' }}</td>
                            <td class="{{ $ligne->heure_arrivee_signalee ? '' : 'gris' }}">
                                @if ($ligne->heure_arrivee_signalee)
                                    {{ $ligne->heure_arrivee_signalee->format('H:i') }}
                                    @if ($ligne->distance_signalee !== null)
                                        ({{ $ligne->distance_signalee }} m)
                                    @endif
                                @else
                                    aucune
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="signature">
                Signature du superviseur : ______________________________
            </div>
        </div>
    @endforeach

    <div class="pied">
        GIP-PNVB — pièce justificative — généré le {{ $genere_le->format('d/m/Y H:i') }}
    </div>
</body>
</html>
