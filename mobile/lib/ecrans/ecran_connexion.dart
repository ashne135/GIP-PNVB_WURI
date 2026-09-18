import 'package:flutter/material.dart';

import '../api/client_api.dart';
import '../composants/cadre_ecran.dart';
import '../composants/gros_bouton.dart';
import '../l10n/textes.dart';
import '../session/controleur_session.dart';

/// LA CONNEXION, par numéro de téléphone (cadrage, section 6).
///
/// C'est le seul écran qui exige le réseau à coup sûr : le serveur vérifie le
/// mot de passe et délivre le jeton. L'écran le dit d'emblée.
class EcranConnexion extends StatefulWidget {
  const EcranConnexion({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranConnexion> createState() => _EtatEcranConnexion();
}

class _EtatEcranConnexion extends State<EcranConnexion> {
  final _telephone = TextEditingController();
  final _motDePasse = TextEditingController();
  ErreurApi? _erreur;
  bool _enCours = false;

  static const _champs = ['telephone', 'mot_de_passe'];

  Future<void> _connecter() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      await widget.session.connecter(_telephone.text, _motDePasse.text);
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
    _telephone.dispose();
    _motDePasse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final erreur = _erreur;

    return CadreEcran(
      titre: textes.connexionTitre,
      enfants: [
        // Le logo du Programme : l'agent doit reconnaître d'un coup d'œil
        // l'application officielle, sur un téléphone qui en porte cinquante.
        Center(
          child: Image.asset(
            'assets/logo-pnvb.jpg',
            width: 120,
            height: 120,
            fit: BoxFit.contain,
            // Une image qui manque ne doit pas empêcher de se connecter.
            errorBuilder: (_, _, _) => const SizedBox.shrink(),
          ),
        ),
        const SizedBox(height: 20),
        Text(textes.connexionReseauRequis, style: Theme.of(context).textTheme.bodyLarge),
        const SizedBox(height: 24),
        // Le message général quand aucun champ ne peut le porter : mauvais mot
        // de passe, compte fermé, trop de tentatives, pas de réseau.
        if (erreur != null && (!erreur.estValidation || !erreur.erreurs.keys.every(_champs.contains)))
          MessageErreur(erreur.message),
        TextField(
          controller: _telephone,
          // Clavier numérique automatique (cadrage, section 14).
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          style: const TextStyle(fontSize: 20),
          decoration: InputDecoration(
            labelText: textes.connexionTelephone,
            helperText: textes.connexionTelephoneAide,
            errorText: erreur?.erreurDuChamp('telephone'),
            prefixIcon: const Icon(Icons.phone),
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _motDePasse,
          obscureText: true,
          autofillHints: const [AutofillHints.password],
          style: const TextStyle(fontSize: 20),
          decoration: InputDecoration(
            labelText: textes.connexionMotDePasse,
            errorText: erreur?.erreurDuChamp('mot_de_passe'),
            prefixIcon: const Icon(Icons.lock),
          ),
          onSubmitted: (_) => _connecter(),
        ),
        const SizedBox(height: 28),
        GrosBouton(
          icone: Icons.login,
          libelle: _enCours ? textes.enCours : textes.connexionBouton,
          enCours: _enCours,
          onPressed: _connecter,
        ),
      ],
    );
  }
}
