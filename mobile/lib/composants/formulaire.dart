import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../theme.dart';
import 'gros_bouton.dart';

/// LES BRIQUES DES FORMULAIRES DU TERRAIN (cadrage, section 14).
///
/// Pensées pour un utilisateur non informaticien, sur un petit écran : de
/// grandes zones à toucher, un choix par ligne, un clavier numérique dès qu'un
/// chiffre est attendu, et des champs facultatifs vraiment facultatifs.

/// Un bloc du formulaire, avec son titre.
class SectionFormulaire extends StatelessWidget {
  const SectionFormulaire({super.key, required this.titre, required this.enfants});

  final String titre;
  final List<Widget> enfants;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(titre, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              ...enfants,
            ],
          ),
        ),
      ),
    );
  }
}

/// Un seul choix possible, une grande ligne par option.
class ChoixUnique<T> extends StatelessWidget {
  const ChoixUnique({super.key, required this.options, required this.valeur, required this.onChange, this.erreur});

  final Map<T, String> options;
  final T? valeur;
  final ValueChanged<T> onChange;
  final String? erreur;

  @override
  Widget build(BuildContext context) {
    final schema = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final option in options.entries)
          ListTile(
            minTileHeight: hauteurBouton,
            contentPadding: const EdgeInsets.symmetric(horizontal: 8),
            selected: option.key == valeur,
            selectedTileColor: schema.primaryContainer,
            leading: Icon(option.key == valeur ? Icons.radio_button_checked : Icons.radio_button_unchecked),
            title: Text(option.value),
            onTap: () => onChange(option.key),
          ),
        if (erreur != null) _TexteErreur(erreur!),
      ],
    );
  }
}

/// Plusieurs choix possibles : une case à cocher par ligne.
class ChoixMultiples<T> extends StatelessWidget {
  const ChoixMultiples({super.key, required this.options, required this.valeurs, required this.onChange, this.erreur});

  final Map<T, String> options;
  final Set<T> valeurs;
  final ValueChanged<Set<T>> onChange;
  final String? erreur;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final option in options.entries)
          CheckboxListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 8),
            controlAffinity: ListTileControlAffinity.leading,
            value: valeurs.contains(option.key),
            title: Text(option.value),
            onChanged: (coche) => onChange(
              coche == true ? {...valeurs, option.key} : valeurs.where((v) => v != option.key).toSet(),
            ),
          ),
        if (erreur != null) _TexteErreur(erreur!),
      ],
    );
  }
}

/// Un champ de texte, sur une ou plusieurs lignes.
class ChampTexte extends StatelessWidget {
  const ChampTexte({super.key, required this.libelle, required this.controleur, this.lignes = 1, this.erreur, this.aide});

  final String libelle;
  final TextEditingController controleur;
  final int lignes;
  final String? erreur;
  final String? aide;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: TextField(
        controller: controleur,
        minLines: lignes,
        maxLines: lignes == 1 ? 1 : lignes + 4,
        textCapitalization: TextCapitalization.sentences,
        decoration: InputDecoration(labelText: libelle, errorText: erreur, helperText: aide, helperMaxLines: 3),
      ),
    );
  }
}

/// Un nombre entier : le clavier numérique s'ouvre de lui-même.
class ChampNombre extends StatelessWidget {
  const ChampNombre({super.key, required this.libelle, required this.controleur, this.erreur});

  final String libelle;
  final TextEditingController controleur;
  final String? erreur;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: TextField(
        controller: controleur,
        keyboardType: TextInputType.number,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        style: const TextStyle(fontSize: 20),
        decoration: InputDecoration(labelText: libelle, errorText: erreur),
      ),
    );
  }
}

/// La date des données gardées sur le téléphone, ou l'explication de leur absence.
class BandeauDonnees extends StatelessWidget {
  const BandeauDonnees({super.key, required this.misAJourLe});

  final DateTime? misAJourLe;

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final date = misAJourLe;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Text(
        date == null ? textes.donneesAbsentes : textes.donneesDu(dateHeureLisible(date)),
        style: Theme.of(context).textTheme.bodySmall,
      ),
    );
  }
}

/// Les photos d'une fiche : vignettes, retrait, et prise d'une nouvelle photo.
class SelecteurPhotos extends StatelessWidget {
  const SelecteurPhotos({
    super.key,
    required this.photos,
    required this.onPrendre,
    required this.onRetirer,
    this.libelleBouton,
  });

  final List<String> photos;
  final VoidCallback onPrendre;
  final ValueChanged<String> onRetirer;
  final String? libelleBouton;

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (photos.isNotEmpty)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final photo in photos)
                Stack(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.file(File(photo), width: 110, height: 110, fit: BoxFit.cover),
                    ),
                    Positioned(
                      right: 0,
                      top: 0,
                      child: IconButton.filled(
                        tooltip: textes.photoRetirer,
                        onPressed: () => onRetirer(photo),
                        icon: const Icon(Icons.close),
                      ),
                    ),
                  ],
                ),
            ],
          ),
        const SizedBox(height: 8),
        GrosBouton(
          icone: Icons.photo_camera,
          libelle: libelleBouton ?? textes.photoPrendre,
          secondaire: true,
          onPressed: onPrendre,
        ),
      ],
    );
  }
}

class _TexteErreur extends StatelessWidget {
  const _TexteErreur(this.texte);

  final String texte;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 8, top: 4),
      child: Text(texte, style: TextStyle(color: Theme.of(context).colorScheme.error)),
    );
  }
}

/// Les libellés des valeurs que le serveur code.
class Libelles {
  const Libelles._();

  static String production(Textes textes, String? valeur) => switch (valeur) {
        'passable' => textes.productionPassable,
        'peu_satisfaisant' => textes.productionPeuSatisfaisant,
        'satisfaisant' => textes.productionSatisfaisant,
        _ => '—',
      };

  static String anomalie(Textes textes, String valeur) => switch (valeur) {
        'retard' => textes.anomalieRetard,
        'absenteisme' => textes.anomalieAbsenteisme,
        'propos_discourtois' => textes.anomalieProposDiscourtois,
        _ => textes.anomalieAutre,
      };

  static Map<String, String> etatsConstates(Textes textes) => {
        'bon': textes.etatBon,
        'usage': textes.etatUsage,
        'endommage': textes.etatEndommage,
        'incomplet': textes.etatIncomplet,
      };

  static String etatKit(Textes textes, Object? valeur) => switch (valeur) {
        'fonctionnel' => textes.etatKitFonctionnel,
        'panne' => textes.etatKitPanne,
        'perdu' => textes.etatKitPerdu,
        'vole' => textes.etatKitVole,
        'reforme' => textes.etatKitReforme,
        _ => '—',
      };

  static String typeRapport(Textes textes, Object? valeur) => switch (valeur) {
        'aopk' => textes.rapportTypeAopk,
        'opk' => textes.rapportTypeOpk,
        'superviseur' => textes.rapportTypeSuperviseur,
        _ => '—',
      };

  static String statutRapport(Textes textes, Object? valeur) => switch (valeur) {
        'brouillon' => textes.rapportStatutBrouillon,
        'soumis' => textes.rapportStatutSoumis,
        'vise' => textes.rapportStatutVise,
        'rejete' => textes.rapportStatutRejete,
        'clos' => textes.rapportStatutClos,
        _ => '—',
      };

  static Map<String, String> presences(Textes textes) => {
        'present': textes.presencePresent,
        'absent': textes.presenceAbsent,
        'absent_justifie': textes.presenceAbsentJustifie,
      };

  /// La catégorie d'un agent : celle d'une fiche (operateur, assistant) ou
  /// celle d'un tableau de suivi (opk, aopk).
  static String categorie(Textes textes, Object? valeur) => switch (valeur) {
        'superviseur' => textes.categorieSuperviseur,
        'operateur' || 'opk' => textes.categorieOperateur,
        'assistant' || 'aopk' => textes.categorieAssistant,
        _ => '—',
      };

  /// Un nombre du serveur, ou un tiret quand il manque.
  static String nombre(Object? valeur) => valeur == null ? '—' : '$valeur';

  /// Le nom d'une personne, tel que l'API le rend dans une relation chargée.
  static String nomDe(Object? personne) {
    if (personne is! Map) {
      return '—';
    }

    final utilisateur = personne['user'] is Map ? personne['user'] as Map : personne;
    final nom = [utilisateur['prenoms'], utilisateur['nom']].whereType<String>().join(' ').trim();

    return nom.isEmpty ? (personne['matricule'] as String? ?? '—') : nom;
  }
}
