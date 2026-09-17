<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 12mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #1a1a1a; }
        .entete { border-bottom: 2px solid #0d5a62; padding-bottom: 8px; margin-bottom: 10px; }
        .entete h1 { font-size: 13pt; margin: 0 0 2px; color: #0d5a62; }
        .entete .projet { font-size: 8.5pt; color: #555; }
        .meta { font-size: 8pt; color: #555; margin-bottom: 10px; }
        .meta strong { color: #1a1a1a; }
        .consigne { background: #f2f6f6; border-left: 3px solid #0d5a62;
                    padding: 6px 9px; font-size: 8pt; margin-bottom: 12px; }
        table.liste { width: 100%; border-collapse: collapse; }
        table.liste th { background: #eef3f3; text-align: left; font-size: 7.5pt;
                         text-transform: uppercase; letter-spacing: .3px; color: #40555a;
                         border-bottom: 1px solid #cbd7d9; padding: 4px 5px; }
        table.liste td { border-bottom: 1px solid #e8eced; padding: 4px 5px; vertical-align: top; }
        table.liste td.mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; }
        .synthese td { padding: 3px 6px; font-size: 8pt; }
        .synthese .nombre { font-weight: bold; font-size: 10pt; }
        .pied { position: fixed; bottom: -12mm; left: 0; right: 0;
                font-size: 7pt; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <h1>État des accès des volontaires</h1>
        <div class="projet">GIP-PNVB — Projet WURI</div>
    </div>

    <div class="meta">
        Édité le <strong>{{ $genere_le->format('d/m/Y à H:i') }}</strong>
        par <strong>{{ $genere_par }}</strong> —
        <strong>{{ $lignes->count() }}</strong> comptes
        @if ($filtres !== '')
            · filtres : {{ $filtres }}
        @endif
    </div>

    <div class="consigne">
        Ce document ne porte AUCUN mot de passe : il sert à suivre qui dispose de son accès.
        Les mots de passe se remettent par le bordereau nominatif de formation, contre signature.
    </div>

    <table class="liste synthese" style="margin-bottom: 12px; width: auto;">
        <tr>
            @foreach ($repartition as $etat)
                <td>
                    <span class="nombre">{{ $etat['nombre'] }}</span><br>
                    {{ $etat['libelle'] }}
                </td>
            @endforeach
        </tr>
    </table>

    <table class="liste">
        <thead>
            <tr>
                <th>Matricule</th>
                <th>Nom et prénoms</th>
                <th>Catégorie</th>
                <th>Téléphone</th>
                <th>Courriel</th>
                <th>Accès</th>
                <th>Remise</th>
                <th>Première connexion</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lignes as $ligne)
                <tr>
                    <td class="mono">{{ $ligne['matricule'] }}</td>
                    <td>{{ $ligne['nom_complet'] }}</td>
                    <td>{{ $ligne['categorie'] }}</td>
                    <td class="mono">{{ $ligne['telephone'] }}</td>
                    <td>{{ $ligne['email'] }}</td>
                    <td>{{ $ligne['acces'] }}</td>
                    <td>{{ $ligne['remise'] }}</td>
                    <td>{{ $ligne['premiere_connexion'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pied">
        GIP-PNVB · Projet WURI — état des accès édité le {{ $genere_le->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
