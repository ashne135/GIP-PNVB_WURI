import 'package:flutter/material.dart';

import '../api/client_api.dart';
import '../composants/cadre_ecran.dart';
import '../composants/gros_bouton.dart';
import '../l10n/textes.dart';
import '../session/controleur_session.dart';

/// LE CHANGEMENT DU MOT DE PASSE INITIAL, obligatoire à la première connexion
/// (cadrage, section 6). Le serveur l'impose ; cet écran le rend franchissable.
class EcranMotDePasse extends StatefulWidget {
  const EcranMotDePasse({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranMotDePasse> createState() => _EtatEcranMotDePasse();
}

class _EtatEcranMotDePasse extends State<EcranMotDePasse> {
  final _actuel = TextEditingController();
  final _nouveau = TextEditingController();
  final _confirmation = TextEditingController();
  ErreurApi? _erreur;
  bool _enCours = false;

  static const _champs = ['mot_de_passe_actuel', 'nouveau_mot_de_passe'];

  Future<void> _enregistrer() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      await widget.session.changerMotDePasse(_actuel.text, _nouveau.text, _confirmation.text);
    } on ErreurApi catch (erreur) {
      if (mounted) {
        setState(() => _erreur = erreur);
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  @override
  void dispose() {
    _actuel.dispose();
    _nouveau.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final erreur = _erreur;

    return CadreEcran(
      titre: textes.motDePasseTitre,
      enfants: [
        Text(textes.motDePasseExplication, style: Theme.of(context).textTheme.bodyLarge),
        const SizedBox(height: 8),
        Text(textes.obligationReseau, style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 24),
        if (erreur != null && (!erreur.estValidation || !erreur.erreurs.keys.every(_champs.contains)))
          MessageErreur(erreur.message),
        TextField(
          controller: _actuel,
          obscureText: true,
          decoration: InputDecoration(
            labelText: textes.motDePasseActuel,
            errorText: erreur?.erreurDuChamp('mot_de_passe_actuel'),
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _nouveau,
          obscureText: true,
          decoration: InputDecoration(
            labelText: textes.motDePasseNouveau,
            // La règle réelle du serveur, pas une règle inventée ici.
            helperText: textes.motDePasseRegle,
            helperMaxLines: 2,
            errorText: erreur?.erreurDuChamp('nouveau_mot_de_passe'),
            errorMaxLines: 3,
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _confirmation,
          obscureText: true,
          decoration: InputDecoration(labelText: textes.motDePasseConfirmation),
          onSubmitted: (_) => _enregistrer(),
        ),
        const SizedBox(height: 28),
        GrosBouton(
          icone: Icons.check,
          libelle: _enCours ? textes.enCours : textes.motDePasseBouton,
          enCours: _enCours,
          onPressed: _enregistrer,
        ),
        const SizedBox(height: 16),
        GrosBouton(
          icone: Icons.logout,
          libelle: textes.deconnexion,
          secondaire: true,
          onPressed: widget.session.deconnecter,
        ),
      ],
    );
  }
}
