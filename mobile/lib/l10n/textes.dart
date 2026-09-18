import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'textes_fr.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of Textes
/// returned by `Textes.of(context)`.
///
/// Applications need to include `Textes.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/textes.dart';
///
/// return MaterialApp(
///   localizationsDelegates: Textes.localizationsDelegates,
///   supportedLocales: Textes.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the Textes.supportedLocales
/// property.
abstract class Textes {
  Textes(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static Textes of(BuildContext context) {
    return Localizations.of<Textes>(context, Textes)!;
  }

  static const LocalizationsDelegate<Textes> delegate = _TextesDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[Locale('fr')];

  /// No description provided for @titreApplication.
  ///
  /// In fr, this message translates to:
  /// **'PNVB Volontaires'**
  String get titreApplication;

  /// No description provided for @demarrageImpossibleTitre.
  ///
  /// In fr, this message translates to:
  /// **'L’application n’a pas pu démarrer'**
  String get demarrageImpossibleTitre;

  /// No description provided for @demarrageImpossibleTexte.
  ///
  /// In fr, this message translates to:
  /// **'Ses données sur ce téléphone n’ont pas pu être ouvertes. Réessayez ; si le problème continue, prévenez votre superviseur.'**
  String get demarrageImpossibleTexte;

  /// No description provided for @reseauEnLigne.
  ///
  /// In fr, this message translates to:
  /// **'Réseau disponible'**
  String get reseauEnLigne;

  /// No description provided for @reseauHorsLigne.
  ///
  /// In fr, this message translates to:
  /// **'Hors ligne — votre travail reste enregistré sur le téléphone'**
  String get reseauHorsLigne;

  /// No description provided for @connexionTitre.
  ///
  /// In fr, this message translates to:
  /// **'Connexion'**
  String get connexionTitre;

  /// No description provided for @connexionReseauRequis.
  ///
  /// In fr, this message translates to:
  /// **'La première connexion demande du réseau. Ensuite, l’application fonctionne sans.'**
  String get connexionReseauRequis;

  /// No description provided for @connexionTelephone.
  ///
  /// In fr, this message translates to:
  /// **'Numéro de téléphone'**
  String get connexionTelephone;

  /// No description provided for @connexionTelephoneAide.
  ///
  /// In fr, this message translates to:
  /// **'Exemple : 70 12 34 56'**
  String get connexionTelephoneAide;

  /// No description provided for @connexionMotDePasse.
  ///
  /// In fr, this message translates to:
  /// **'Mot de passe'**
  String get connexionMotDePasse;

  /// No description provided for @connexionBouton.
  ///
  /// In fr, this message translates to:
  /// **'Se connecter'**
  String get connexionBouton;

  /// No description provided for @enCours.
  ///
  /// In fr, this message translates to:
  /// **'Patientez…'**
  String get enCours;

  /// No description provided for @motDePasseTitre.
  ///
  /// In fr, this message translates to:
  /// **'Votre mot de passe'**
  String get motDePasseTitre;

  /// No description provided for @motDePasseExplication.
  ///
  /// In fr, this message translates to:
  /// **'Le mot de passe qui vous a été remis est provisoire. Choisissez-en un que vous êtes seul à connaître.'**
  String get motDePasseExplication;

  /// No description provided for @motDePasseActuel.
  ///
  /// In fr, this message translates to:
  /// **'Mot de passe actuel'**
  String get motDePasseActuel;

  /// No description provided for @motDePasseNouveau.
  ///
  /// In fr, this message translates to:
  /// **'Nouveau mot de passe'**
  String get motDePasseNouveau;

  /// No description provided for @motDePasseRegle.
  ///
  /// In fr, this message translates to:
  /// **'Au moins 8 caractères, dont au moins une lettre et un chiffre.'**
  String get motDePasseRegle;

  /// No description provided for @motDePasseConfirmation.
  ///
  /// In fr, this message translates to:
  /// **'Confirmez le nouveau mot de passe'**
  String get motDePasseConfirmation;

  /// No description provided for @motDePasseBouton.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrer et continuer'**
  String get motDePasseBouton;

  /// No description provided for @obligationReseau.
  ///
  /// In fr, this message translates to:
  /// **'Cette étape demande du réseau.'**
  String get obligationReseau;

  /// No description provided for @charteTitre.
  ///
  /// In fr, this message translates to:
  /// **'Charte du volontaire'**
  String get charteTitre;

  /// No description provided for @charteChargement.
  ///
  /// In fr, this message translates to:
  /// **'Chargement de la charte…'**
  String get charteChargement;

  /// No description provided for @charteProvisoireTitre.
  ///
  /// In fr, this message translates to:
  /// **'Texte provisoire — sans valeur juridique'**
  String get charteProvisoireTitre;

  /// No description provided for @charteProvisoireTexte.
  ///
  /// In fr, this message translates to:
  /// **'Ce texte n’a pas été rédigé par un juriste. Il doit être remplacé par la charte officielle du GIP-PNVB avant toute mise en service sur le terrain.'**
  String get charteProvisoireTexte;

  /// No description provided for @charteAccepter.
  ///
  /// In fr, this message translates to:
  /// **'J’accepte la charte'**
  String get charteAccepter;

  /// No description provided for @charteRefuser.
  ///
  /// In fr, this message translates to:
  /// **'Refuser et quitter'**
  String get charteRefuser;

  /// No description provided for @charteVersion.
  ///
  /// In fr, this message translates to:
  /// **'Version {version}. Votre acceptation est enregistrée avec sa date et la version du texte.'**
  String charteVersion(String version);

  /// No description provided for @reessayer.
  ///
  /// In fr, this message translates to:
  /// **'Réessayer'**
  String get reessayer;

  /// No description provided for @accueilBonjour.
  ///
  /// In fr, this message translates to:
  /// **'Bonjour {nom}'**
  String accueilBonjour(String nom);

  /// No description provided for @accueilMission.
  ///
  /// In fr, this message translates to:
  /// **'Ma mission'**
  String get accueilMission;

  /// No description provided for @accueilAucuneMission.
  ///
  /// In fr, this message translates to:
  /// **'Aucune mission en cours.'**
  String get accueilAucuneMission;

  /// No description provided for @accueilSiteDuJour.
  ///
  /// In fr, this message translates to:
  /// **'Site du jour : {site}'**
  String accueilSiteDuJour(String site);

  /// No description provided for @accueilKitAbsent.
  ///
  /// In fr, this message translates to:
  /// **'Le kit ne passe pas sur votre site aujourd’hui.'**
  String get accueilKitAbsent;

  /// No description provided for @accueilItineraire.
  ///
  /// In fr, this message translates to:
  /// **'Itinéraire vers le site'**
  String get accueilItineraire;

  /// No description provided for @accueilItineraireImpossible.
  ///
  /// In fr, this message translates to:
  /// **'Ce site n’a pas de coordonnées : aucun itinéraire possible.'**
  String get accueilItineraireImpossible;

  /// No description provided for @itineraireEchec.
  ///
  /// In fr, this message translates to:
  /// **'Aucune application de cartes n’a pu s’ouvrir sur ce téléphone.'**
  String get itineraireEchec;

  /// No description provided for @accueilInformationsDu.
  ///
  /// In fr, this message translates to:
  /// **'Informations du {date}'**
  String accueilInformationsDu(String date);

  /// No description provided for @fileTitre.
  ///
  /// In fr, this message translates to:
  /// **'Travail à envoyer'**
  String get fileTitre;

  /// No description provided for @fileEnAttente.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =0{Tout est envoyé.} =1{1 élément attend d’être envoyé.} other{{nombre} éléments attendent d’être envoyés.}}'**
  String fileEnAttente(int nombre);

  /// No description provided for @fileRejetes.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =1{1 élément refusé par le serveur} other{{nombre} éléments refusés par le serveur}}'**
  String fileRejetes(int nombre);

  /// No description provided for @fileDernierEnvoi.
  ///
  /// In fr, this message translates to:
  /// **'Dernier envoi : {date}'**
  String fileDernierEnvoi(String date);

  /// No description provided for @fileJamaisEnvoye.
  ///
  /// In fr, this message translates to:
  /// **'Aucun envoi pour le moment.'**
  String get fileJamaisEnvoye;

  /// No description provided for @fileEnvoyer.
  ///
  /// In fr, this message translates to:
  /// **'Envoyer maintenant'**
  String get fileEnvoyer;

  /// No description provided for @envoiTermine.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =0{Rien à envoyer : tout est déjà sur le serveur.} =1{1 élément envoyé.} other{{nombre} éléments envoyés.}}'**
  String envoiTermine(int nombre);

  /// No description provided for @envoiHorsLigne.
  ///
  /// In fr, this message translates to:
  /// **'Pas de réseau : votre travail reste sur le téléphone et partira tout seul.'**
  String get envoiHorsLigne;

  /// No description provided for @envoiPartiel.
  ///
  /// In fr, this message translates to:
  /// **'Envoi incomplet : le reste partira à la prochaine tentative.'**
  String get envoiPartiel;

  /// No description provided for @sessionExpiree.
  ///
  /// In fr, this message translates to:
  /// **'Votre session a expiré. Reconnectez-vous pour envoyer votre travail : il reste gardé sur le téléphone.'**
  String get sessionExpiree;

  /// No description provided for @seReconnecter.
  ///
  /// In fr, this message translates to:
  /// **'Se reconnecter'**
  String get seReconnecter;

  /// No description provided for @accesFerme.
  ///
  /// In fr, this message translates to:
  /// **'Votre accès est fermé. Le travail fait pendant votre mission peut encore être envoyé pendant quelques jours.'**
  String get accesFerme;

  /// No description provided for @deconnexion.
  ///
  /// In fr, this message translates to:
  /// **'Se déconnecter'**
  String get deconnexion;

  /// No description provided for @deconnexionAvertissementTitre.
  ///
  /// In fr, this message translates to:
  /// **'Du travail n’est pas encore envoyé'**
  String get deconnexionAvertissementTitre;

  /// No description provided for @deconnexionNonEnvoyes.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =1{1 élément n’est pas encore envoyé.} other{{nombre} éléments ne sont pas encore envoyés.}}'**
  String deconnexionNonEnvoyes(int nombre);

  /// No description provided for @deconnexionGarde.
  ///
  /// In fr, this message translates to:
  /// **'Il reste gardé sur ce téléphone et partira à votre prochaine connexion.'**
  String get deconnexionGarde;

  /// No description provided for @annuler.
  ///
  /// In fr, this message translates to:
  /// **'Annuler'**
  String get annuler;

  /// No description provided for @actionsTitre.
  ///
  /// In fr, this message translates to:
  /// **'Mon travail'**
  String get actionsTitre;

  /// No description provided for @actionSignal.
  ///
  /// In fr, this message translates to:
  /// **'Je suis arrivé / Je pars'**
  String get actionSignal;

  /// No description provided for @actionRapport.
  ///
  /// In fr, this message translates to:
  /// **'Mon rapport du jour'**
  String get actionRapport;

  /// No description provided for @actionVisas.
  ///
  /// In fr, this message translates to:
  /// **'Rapports à viser'**
  String get actionVisas;

  /// No description provided for @actionFeuilles.
  ///
  /// In fr, this message translates to:
  /// **'Feuilles de présence'**
  String get actionFeuilles;

  /// No description provided for @actionIncident.
  ///
  /// In fr, this message translates to:
  /// **'Déclarer un incident'**
  String get actionIncident;

  /// No description provided for @actionKit.
  ///
  /// In fr, this message translates to:
  /// **'Mon kit'**
  String get actionKit;

  /// No description provided for @actionAlertes.
  ///
  /// In fr, this message translates to:
  /// **'Alertes'**
  String get actionAlertes;

  /// No description provided for @actionAppreciations.
  ///
  /// In fr, this message translates to:
  /// **'Mes appréciations'**
  String get actionAppreciations;

  /// No description provided for @actualiserDonnees.
  ///
  /// In fr, this message translates to:
  /// **'Actualiser mes données'**
  String get actualiserDonnees;

  /// No description provided for @donneesDu.
  ///
  /// In fr, this message translates to:
  /// **'Données du {date}'**
  String donneesDu(String date);

  /// No description provided for @donneesAbsentes.
  ///
  /// In fr, this message translates to:
  /// **'Ces informations ne sont pas encore sur le téléphone. Connectez-vous au réseau, puis appuyez sur « Actualiser mes données » depuis l’accueil.'**
  String get donneesAbsentes;

  /// No description provided for @preparationHorsLigne.
  ///
  /// In fr, this message translates to:
  /// **'Pas de réseau : les données gardées sur le téléphone restent utilisables.'**
  String get preparationHorsLigne;

  /// No description provided for @preparationTerminee.
  ///
  /// In fr, this message translates to:
  /// **'Données du jour mises à jour.'**
  String get preparationTerminee;

  /// No description provided for @enregistre.
  ///
  /// In fr, this message translates to:
  /// **'Enregistré sur le téléphone. Il partira dès que le réseau le permettra.'**
  String get enregistre;

  /// No description provided for @champObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Ce champ est obligatoire.'**
  String get champObligatoire;

  /// No description provided for @positionRecherche.
  ///
  /// In fr, this message translates to:
  /// **'Recherche de la position…'**
  String get positionRecherche;

  /// No description provided for @valider.
  ///
  /// In fr, this message translates to:
  /// **'Valider'**
  String get valider;

  /// No description provided for @facultatif.
  ///
  /// In fr, this message translates to:
  /// **'(facultatif)'**
  String get facultatif;

  /// No description provided for @oui.
  ///
  /// In fr, this message translates to:
  /// **'Oui'**
  String get oui;

  /// No description provided for @non.
  ///
  /// In fr, this message translates to:
  /// **'Non'**
  String get non;

  /// No description provided for @jeNeSaisPas.
  ///
  /// In fr, this message translates to:
  /// **'Je ne sais pas'**
  String get jeNeSaisPas;

  /// No description provided for @signalTitre.
  ///
  /// In fr, this message translates to:
  /// **'Arrivée et départ'**
  String get signalTitre;

  /// No description provided for @signalExplication.
  ///
  /// In fr, this message translates to:
  /// **'Appuyez en arrivant sur le site, puis en partant. Ce signal prévient votre superviseur : il ne remplace pas la feuille de présence.'**
  String get signalExplication;

  /// No description provided for @signalArrivee.
  ///
  /// In fr, this message translates to:
  /// **'JE SUIS ARRIVÉ'**
  String get signalArrivee;

  /// No description provided for @signalDepart.
  ///
  /// In fr, this message translates to:
  /// **'JE PARS'**
  String get signalDepart;

  /// No description provided for @signalArriveeEnregistree.
  ///
  /// In fr, this message translates to:
  /// **'Arrivée enregistrée à {heure}.'**
  String signalArriveeEnregistree(String heure);

  /// No description provided for @signalDepartEnregistre.
  ///
  /// In fr, this message translates to:
  /// **'Départ enregistré à {heure}.'**
  String signalDepartEnregistre(String heure);

  /// No description provided for @siteDuJourInconnu.
  ///
  /// In fr, this message translates to:
  /// **'Le site du jour n’est pas connu sur le téléphone : le serveur le retrouvera.'**
  String get siteDuJourInconnu;

  /// No description provided for @signalDansZone.
  ///
  /// In fr, this message translates to:
  /// **'Vous êtes dans la zone du site, à {distance} m.'**
  String signalDansZone(int distance);

  /// No description provided for @signalHorsZone.
  ///
  /// In fr, this message translates to:
  /// **'Vous êtes à {distance} m du site, au-delà de sa zone de {rayon} m.'**
  String signalHorsZone(int distance, int rayon);

  /// No description provided for @signalHorsZoneRefus.
  ///
  /// In fr, this message translates to:
  /// **'Rapprochez-vous du site pour signaler votre arrivée. Si vous êtes bien sur place, prévenez votre superviseur : lui seul peut vous marquer présent sur la feuille.'**
  String get signalHorsZoneRefus;

  /// No description provided for @signalHorsZoneToleree.
  ///
  /// In fr, this message translates to:
  /// **'Votre superviseur verra cet écart. Vous pouvez signaler quand même.'**
  String get signalHorsZoneToleree;

  /// No description provided for @signalSiteNonLocalise.
  ///
  /// In fr, this message translates to:
  /// **'Ce site n’a pas encore de coordonnées : la distance ne peut pas être vérifiée.'**
  String get signalSiteNonLocalise;

  /// No description provided for @incidentNature.
  ///
  /// In fr, this message translates to:
  /// **'Que s’est-il passé ?'**
  String get incidentNature;

  /// No description provided for @incidentNatureObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Cochez au moins une nature d’incident.'**
  String get incidentNatureObligatoire;

  /// No description provided for @incidentRecit.
  ///
  /// In fr, this message translates to:
  /// **'Racontez ce qui s’est passé'**
  String get incidentRecit;

  /// No description provided for @incidentRecitCourt.
  ///
  /// In fr, this message translates to:
  /// **'Quelques phrases au moins : le récit doit être exploitable.'**
  String get incidentRecitCourt;

  /// No description provided for @incidentGravite.
  ///
  /// In fr, this message translates to:
  /// **'Gravité'**
  String get incidentGravite;

  /// No description provided for @incidentGraviteObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Choisissez le niveau de gravité.'**
  String get incidentGraviteObligatoire;

  /// No description provided for @incidentDanger.
  ///
  /// In fr, this message translates to:
  /// **'Danger grave ou immédiat'**
  String get incidentDanger;

  /// No description provided for @incidentEnCours.
  ///
  /// In fr, this message translates to:
  /// **'L’incident est-il toujours en cours ?'**
  String get incidentEnCours;

  /// No description provided for @incidentLieu.
  ///
  /// In fr, this message translates to:
  /// **'Où ?'**
  String get incidentLieu;

  /// No description provided for @incidentLieuPrecision.
  ///
  /// In fr, this message translates to:
  /// **'Précisez le lieu (facultatif)'**
  String get incidentLieuPrecision;

  /// No description provided for @incidentImpacts.
  ///
  /// In fr, this message translates to:
  /// **'Conséquences (facultatif)'**
  String get incidentImpacts;

  /// No description provided for @incidentPersonnesAffectees.
  ///
  /// In fr, this message translates to:
  /// **'Nombre de personnes touchées (facultatif)'**
  String get incidentPersonnesAffectees;

  /// No description provided for @incidentMesures.
  ///
  /// In fr, this message translates to:
  /// **'Mesures déjà prises (facultatif)'**
  String get incidentMesures;

  /// No description provided for @incidentMesuresPrecisions.
  ///
  /// In fr, this message translates to:
  /// **'Précisions sur les mesures (facultatif)'**
  String get incidentMesuresPrecisions;

  /// No description provided for @incidentInformes.
  ///
  /// In fr, this message translates to:
  /// **'Personnes déjà prévenues (facultatif)'**
  String get incidentInformes;

  /// No description provided for @incidentEnregistrer.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrer la déclaration'**
  String get incidentEnregistrer;

  /// No description provided for @lieuSite.
  ///
  /// In fr, this message translates to:
  /// **'Sur le site'**
  String get lieuSite;

  /// No description provided for @lieuTrajetAller.
  ///
  /// In fr, this message translates to:
  /// **'Sur le trajet aller'**
  String get lieuTrajetAller;

  /// No description provided for @lieuTrajetRetour.
  ///
  /// In fr, this message translates to:
  /// **'Sur le trajet retour'**
  String get lieuTrajetRetour;

  /// No description provided for @lieuAutre.
  ///
  /// In fr, this message translates to:
  /// **'Ailleurs'**
  String get lieuAutre;

  /// No description provided for @photosTitre.
  ///
  /// In fr, this message translates to:
  /// **'Photos (facultatif)'**
  String get photosTitre;

  /// No description provided for @photoPrendre.
  ///
  /// In fr, this message translates to:
  /// **'Prendre une photo'**
  String get photoPrendre;

  /// No description provided for @photoRetirer.
  ///
  /// In fr, this message translates to:
  /// **'Retirer la photo'**
  String get photoRetirer;

  /// No description provided for @photoImpossible.
  ///
  /// In fr, this message translates to:
  /// **'La photo n’a pas pu être prise : {message}'**
  String photoImpossible(String message);

  /// No description provided for @kitAucun.
  ///
  /// In fr, this message translates to:
  /// **'Aucun kit ne vous est confié sur le téléphone.'**
  String get kitAucun;

  /// No description provided for @kitEtat.
  ///
  /// In fr, this message translates to:
  /// **'État : {etat}'**
  String kitEtat(String etat);

  /// No description provided for @kitPanne.
  ///
  /// In fr, this message translates to:
  /// **'Signaler une panne'**
  String get kitPanne;

  /// No description provided for @kitPerteVol.
  ///
  /// In fr, this message translates to:
  /// **'Déclarer une perte ou un vol'**
  String get kitPerteVol;

  /// No description provided for @kitRestitution.
  ///
  /// In fr, this message translates to:
  /// **'Restituer le kit'**
  String get kitRestitution;

  /// No description provided for @kitAutresMouvements.
  ///
  /// In fr, this message translates to:
  /// **'La remise, le transfert à un autre agent et le changement de site se déclarent au back-office.'**
  String get kitAutresMouvements;

  /// No description provided for @suiviReponseAgent.
  ///
  /// In fr, this message translates to:
  /// **'Réponse de l’agent, le {date} : {reponse}'**
  String suiviReponseAgent(String date, String reponse);

  /// No description provided for @kitEtatConstate.
  ///
  /// In fr, this message translates to:
  /// **'État constaté du kit'**
  String get kitEtatConstate;

  /// No description provided for @kitEtatObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Constatez l’état du kit : c’est lui qui engage la responsabilité de chacun.'**
  String get kitEtatObligatoire;

  /// No description provided for @etatBon.
  ///
  /// In fr, this message translates to:
  /// **'Bon'**
  String get etatBon;

  /// No description provided for @etatUsage.
  ///
  /// In fr, this message translates to:
  /// **'Usagé'**
  String get etatUsage;

  /// No description provided for @etatEndommage.
  ///
  /// In fr, this message translates to:
  /// **'Endommagé'**
  String get etatEndommage;

  /// No description provided for @etatIncomplet.
  ///
  /// In fr, this message translates to:
  /// **'Incomplet'**
  String get etatIncomplet;

  /// No description provided for @kitCirconstance.
  ///
  /// In fr, this message translates to:
  /// **'Perte ou vol ?'**
  String get kitCirconstance;

  /// No description provided for @circonstancePerte.
  ///
  /// In fr, this message translates to:
  /// **'Perte'**
  String get circonstancePerte;

  /// No description provided for @circonstanceVol.
  ///
  /// In fr, this message translates to:
  /// **'Vol'**
  String get circonstanceVol;

  /// No description provided for @kitCommentaire.
  ///
  /// In fr, this message translates to:
  /// **'Commentaire (facultatif)'**
  String get kitCommentaire;

  /// No description provided for @kitPhotoSource.
  ///
  /// In fr, this message translates to:
  /// **'Photo de celui qui remet le kit'**
  String get kitPhotoSource;

  /// No description provided for @kitPhotoDestination.
  ///
  /// In fr, this message translates to:
  /// **'Photo de celui qui reçoit le kit'**
  String get kitPhotoDestination;

  /// No description provided for @kitPhotosConseil.
  ///
  /// In fr, this message translates to:
  /// **'Les deux photos engagent la responsabilité de chacun en cas de perte ou de casse.'**
  String get kitPhotosConseil;

  /// No description provided for @kitEnregistrer.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrer le mouvement'**
  String get kitEnregistrer;

  /// No description provided for @alertesAucune.
  ///
  /// In fr, this message translates to:
  /// **'Aucune alerte.'**
  String get alertesAucune;

  /// No description provided for @alerteNonLue.
  ///
  /// In fr, this message translates to:
  /// **'Non lue'**
  String get alerteNonLue;

  /// No description provided for @alerteMarquerLue.
  ///
  /// In fr, this message translates to:
  /// **'J’ai lu cette alerte'**
  String get alerteMarquerLue;

  /// No description provided for @alerteLue.
  ///
  /// In fr, this message translates to:
  /// **'Lue'**
  String get alerteLue;

  /// No description provided for @appreciationsAucune.
  ///
  /// In fr, this message translates to:
  /// **'Aucune appréciation ne vous concerne.'**
  String get appreciationsAucune;

  /// No description provided for @appreciationDu.
  ///
  /// In fr, this message translates to:
  /// **'Rapport du {date}, par {auteur}'**
  String appreciationDu(String date, String auteur);

  /// No description provided for @appreciationProduction.
  ///
  /// In fr, this message translates to:
  /// **'Production : {valeur}'**
  String appreciationProduction(String valeur);

  /// No description provided for @appreciationAnomalies.
  ///
  /// In fr, this message translates to:
  /// **'Anomalies : {valeurs}'**
  String appreciationAnomalies(String valeurs);

  /// No description provided for @appreciationObservation.
  ///
  /// In fr, this message translates to:
  /// **'Observation : {texte}'**
  String appreciationObservation(String texte);

  /// No description provided for @appreciationRepondre.
  ///
  /// In fr, this message translates to:
  /// **'Répondre'**
  String get appreciationRepondre;

  /// No description provided for @appreciationVotreReponse.
  ///
  /// In fr, this message translates to:
  /// **'Votre observation'**
  String get appreciationVotreReponse;

  /// No description provided for @appreciationReponseNonModifiable.
  ///
  /// In fr, this message translates to:
  /// **'Votre réponse sera horodatée et ne pourra plus être modifiée, ni par vous ni par votre supérieur.'**
  String get appreciationReponseNonModifiable;

  /// No description provided for @appreciationEnvoyerReponse.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrer ma réponse'**
  String get appreciationEnvoyerReponse;

  /// No description provided for @appreciationReponseEnAttente.
  ///
  /// In fr, this message translates to:
  /// **'Réponse enregistrée, en attente d’envoi'**
  String get appreciationReponseEnAttente;

  /// No description provided for @appreciationVotreReponseDu.
  ///
  /// In fr, this message translates to:
  /// **'Votre réponse du {date}'**
  String appreciationVotreReponseDu(String date);

  /// No description provided for @productionPassable.
  ///
  /// In fr, this message translates to:
  /// **'Passable'**
  String get productionPassable;

  /// No description provided for @productionPeuSatisfaisant.
  ///
  /// In fr, this message translates to:
  /// **'Peu satisfaisant'**
  String get productionPeuSatisfaisant;

  /// No description provided for @productionSatisfaisant.
  ///
  /// In fr, this message translates to:
  /// **'Satisfaisant'**
  String get productionSatisfaisant;

  /// No description provided for @anomalieRetard.
  ///
  /// In fr, this message translates to:
  /// **'Retard'**
  String get anomalieRetard;

  /// No description provided for @anomalieAbsenteisme.
  ///
  /// In fr, this message translates to:
  /// **'Absentéisme'**
  String get anomalieAbsenteisme;

  /// No description provided for @anomalieProposDiscourtois.
  ///
  /// In fr, this message translates to:
  /// **'Propos discourtois'**
  String get anomalieProposDiscourtois;

  /// No description provided for @anomalieAutre.
  ///
  /// In fr, this message translates to:
  /// **'Autre'**
  String get anomalieAutre;

  /// No description provided for @alertesNonLues.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =0{Aucune alerte non lue} =1{1 alerte non lue} other{{nombre} alertes non lues}}'**
  String alertesNonLues(int nombre);

  /// No description provided for @visasEnAttente.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =0{Aucun rapport à viser} =1{1 rapport à viser} other{{nombre} rapports à viser}}'**
  String visasEnAttente(int nombre);

  /// No description provided for @feuillesAValider.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =0{Aucune feuille à valider} =1{1 feuille à valider} other{{nombre} feuilles à valider}}'**
  String feuillesAValider(int nombre);

  /// No description provided for @preparationEchecs.
  ///
  /// In fr, this message translates to:
  /// **'Certaines données n’ont pas pu être mises à jour : {details}'**
  String preparationEchecs(String details);

  /// No description provided for @confirmer.
  ///
  /// In fr, this message translates to:
  /// **'Confirmer'**
  String get confirmer;

  /// No description provided for @sansPositionTitre.
  ///
  /// In fr, this message translates to:
  /// **'Position introuvable'**
  String get sansPositionTitre;

  /// No description provided for @sansPositionContinuer.
  ///
  /// In fr, this message translates to:
  /// **'Continuer sans position'**
  String get sansPositionContinuer;

  /// No description provided for @kitMouvementEnAttente.
  ///
  /// In fr, this message translates to:
  /// **'Déclaration enregistrée sur le téléphone, en attente d’envoi : {mouvement}'**
  String kitMouvementEnAttente(String mouvement);

  /// No description provided for @etatKitPanne.
  ///
  /// In fr, this message translates to:
  /// **'En panne'**
  String get etatKitPanne;

  /// No description provided for @etatKitPerdu.
  ///
  /// In fr, this message translates to:
  /// **'Perdu'**
  String get etatKitPerdu;

  /// No description provided for @etatKitVole.
  ///
  /// In fr, this message translates to:
  /// **'Volé'**
  String get etatKitVole;

  /// No description provided for @etatKitReforme.
  ///
  /// In fr, this message translates to:
  /// **'Réformé'**
  String get etatKitReforme;

  /// No description provided for @rapportTypeAopk.
  ///
  /// In fr, this message translates to:
  /// **'Rapport d’accueil (A-OPK)'**
  String get rapportTypeAopk;

  /// No description provided for @rapportTypeOpk.
  ///
  /// In fr, this message translates to:
  /// **'Rapport de production (opérateur)'**
  String get rapportTypeOpk;

  /// No description provided for @rapportTypeSuperviseur.
  ///
  /// In fr, this message translates to:
  /// **'Rapport de centre (superviseur)'**
  String get rapportTypeSuperviseur;

  /// No description provided for @rapportStatutBrouillon.
  ///
  /// In fr, this message translates to:
  /// **'Brouillon'**
  String get rapportStatutBrouillon;

  /// No description provided for @rapportStatutSoumis.
  ///
  /// In fr, this message translates to:
  /// **'Signé, en attente de visa'**
  String get rapportStatutSoumis;

  /// No description provided for @rapportStatutVise.
  ///
  /// In fr, this message translates to:
  /// **'Visé'**
  String get rapportStatutVise;

  /// No description provided for @rapportStatutRejete.
  ///
  /// In fr, this message translates to:
  /// **'Renvoyé pour correction'**
  String get rapportStatutRejete;

  /// No description provided for @rapportStatutClos.
  ///
  /// In fr, this message translates to:
  /// **'Clos'**
  String get rapportStatutClos;

  /// No description provided for @rapportJournee.
  ///
  /// In fr, this message translates to:
  /// **'Journée du {date}'**
  String rapportJournee(String date);

  /// No description provided for @rapportMotifRejet.
  ///
  /// In fr, this message translates to:
  /// **'Motif du renvoi : {motif}'**
  String rapportMotifRejet(String motif);

  /// No description provided for @rapportNonModifiable.
  ///
  /// In fr, this message translates to:
  /// **'Ce rapport est signé : il n’est plus modifiable. Il ne le redevient que s’il vous est renvoyé pour correction.'**
  String get rapportNonModifiable;

  /// No description provided for @rapportSigneEnAttente.
  ///
  /// In fr, this message translates to:
  /// **'Signé sur le téléphone, en attente d’envoi.'**
  String get rapportSigneEnAttente;

  /// No description provided for @rapportIdentification.
  ///
  /// In fr, this message translates to:
  /// **'Identification'**
  String get rapportIdentification;

  /// No description provided for @rapportIdentificationAide.
  ///
  /// In fr, this message translates to:
  /// **'Pré-remplie depuis votre affectation : vérifiez-la, vous ne la saisissez pas.'**
  String get rapportIdentificationAide;

  /// No description provided for @rapportLigne.
  ///
  /// In fr, this message translates to:
  /// **'{libelle} : {valeur}'**
  String rapportLigne(String libelle, String valeur);

  /// No description provided for @rapportCentre.
  ///
  /// In fr, this message translates to:
  /// **'Centre'**
  String get rapportCentre;

  /// No description provided for @rapportSite.
  ///
  /// In fr, this message translates to:
  /// **'Site'**
  String get rapportSite;

  /// No description provided for @rapportSuperieur.
  ///
  /// In fr, this message translates to:
  /// **'Supérieur désigné'**
  String get rapportSuperieur;

  /// No description provided for @rapportHeureArrivee.
  ///
  /// In fr, this message translates to:
  /// **'Heure d’arrivée'**
  String get rapportHeureArrivee;

  /// No description provided for @rapportHeureDepart.
  ///
  /// In fr, this message translates to:
  /// **'Heure de départ'**
  String get rapportHeureDepart;

  /// No description provided for @rapportHeureNonSaisie.
  ///
  /// In fr, this message translates to:
  /// **'non saisie'**
  String get rapportHeureNonSaisie;

  /// No description provided for @rapportActivites.
  ///
  /// In fr, this message translates to:
  /// **'Activités d’accueil'**
  String get rapportActivites;

  /// No description provided for @rapportPrevu.
  ///
  /// In fr, this message translates to:
  /// **'Prévu'**
  String get rapportPrevu;

  /// No description provided for @rapportRealise.
  ///
  /// In fr, this message translates to:
  /// **'Réalisé'**
  String get rapportRealise;

  /// No description provided for @rapportPrevuRealise.
  ///
  /// In fr, this message translates to:
  /// **'{libelle} — prévu : {prevu}, réalisé : {realise}'**
  String rapportPrevuRealise(String libelle, String prevu, String realise);

  /// No description provided for @activiteAffluence.
  ///
  /// In fr, this message translates to:
  /// **'Affluence'**
  String get activiteAffluence;

  /// No description provided for @activiteJustificatifsRecus.
  ///
  /// In fr, this message translates to:
  /// **'Justificatifs reçus'**
  String get activiteJustificatifsRecus;

  /// No description provided for @activiteJustificatifsTransmis.
  ///
  /// In fr, this message translates to:
  /// **'Justificatifs transmis'**
  String get activiteJustificatifsTransmis;

  /// No description provided for @activitePlaintesEnregistrees.
  ///
  /// In fr, this message translates to:
  /// **'Plaintes enregistrées'**
  String get activitePlaintesEnregistrees;

  /// No description provided for @activitePlaintesReversees.
  ///
  /// In fr, this message translates to:
  /// **'Plaintes reversées'**
  String get activitePlaintesReversees;

  /// No description provided for @affluenceFaible.
  ///
  /// In fr, this message translates to:
  /// **'Faible (1 à 25)'**
  String get affluenceFaible;

  /// No description provided for @affluenceMoyen.
  ///
  /// In fr, this message translates to:
  /// **'Moyenne (25 à 50)'**
  String get affluenceMoyen;

  /// No description provided for @affluenceEleve.
  ///
  /// In fr, this message translates to:
  /// **'Élevée (50 à 100)'**
  String get affluenceEleve;

  /// No description provided for @rapportProduction.
  ///
  /// In fr, this message translates to:
  /// **'Production du kit'**
  String get rapportProduction;

  /// No description provided for @productionObjectif.
  ///
  /// In fr, this message translates to:
  /// **'Objectif du jour'**
  String get productionObjectif;

  /// No description provided for @productionObjectifAide.
  ///
  /// In fr, this message translates to:
  /// **'Figé à l’ouverture du rapport. L’écart et le taux de réalisation sont calculés par le serveur.'**
  String get productionObjectifAide;

  /// No description provided for @productionEnregistrements.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrements réalisés'**
  String get productionEnregistrements;

  /// No description provided for @productionRecepisses.
  ///
  /// In fr, this message translates to:
  /// **'Récépissés transmis'**
  String get productionRecepisses;

  /// No description provided for @productionNonValides.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrements non validés'**
  String get productionNonValides;

  /// No description provided for @productionMotifNonValides.
  ///
  /// In fr, this message translates to:
  /// **'Pourquoi ces enregistrements ne sont-ils pas validés ?'**
  String get productionMotifNonValides;

  /// No description provided for @productionMotifObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Indiquez pourquoi ces enregistrements ne sont pas validés : le chiffre seul ne suffit pas.'**
  String get productionMotifObligatoire;

  /// No description provided for @productionEcart.
  ///
  /// In fr, this message translates to:
  /// **'Écart'**
  String get productionEcart;

  /// No description provided for @productionTaux.
  ///
  /// In fr, this message translates to:
  /// **'Taux de réalisation'**
  String get productionTaux;

  /// No description provided for @productionEtatKit.
  ///
  /// In fr, this message translates to:
  /// **'État du kit'**
  String get productionEtatKit;

  /// No description provided for @etatKitFonctionnel.
  ///
  /// In fr, this message translates to:
  /// **'Fonctionnel'**
  String get etatKitFonctionnel;

  /// No description provided for @etatKitPannePartielle.
  ///
  /// In fr, this message translates to:
  /// **'Panne partielle'**
  String get etatKitPannePartielle;

  /// No description provided for @etatKitPanneTotale.
  ///
  /// In fr, this message translates to:
  /// **'Panne totale'**
  String get etatKitPanneTotale;

  /// No description provided for @rapportEvolution.
  ///
  /// In fr, this message translates to:
  /// **'Évolution des enregistrements'**
  String get rapportEvolution;

  /// No description provided for @rapportEvolutionAide.
  ///
  /// In fr, this message translates to:
  /// **'Consolidée depuis les rapports d’opérateur déjà visés : elle ne se ressaisit pas. Une correction se fait au back-office, avec son motif.'**
  String get rapportEvolutionAide;

  /// No description provided for @evolutionPersonnes.
  ///
  /// In fr, this message translates to:
  /// **'Personnes enregistrées'**
  String get evolutionPersonnes;

  /// No description provided for @evolutionValides.
  ///
  /// In fr, this message translates to:
  /// **'Dossiers validés'**
  String get evolutionValides;

  /// No description provided for @evolutionAReprendre.
  ///
  /// In fr, this message translates to:
  /// **'Dossiers à reprendre'**
  String get evolutionAReprendre;

  /// No description provided for @rapportQualite.
  ///
  /// In fr, this message translates to:
  /// **'Contrôle qualité'**
  String get rapportQualite;

  /// No description provided for @rapportQualiteAide.
  ///
  /// In fr, this message translates to:
  /// **'Le taux de conformité est calculé par le serveur.'**
  String get rapportQualiteAide;

  /// No description provided for @qualiteControles.
  ///
  /// In fr, this message translates to:
  /// **'Dossiers contrôlés'**
  String get qualiteControles;

  /// No description provided for @qualiteConformes.
  ///
  /// In fr, this message translates to:
  /// **'Dossiers conformes'**
  String get qualiteConformes;

  /// No description provided for @qualiteNonConformes.
  ///
  /// In fr, this message translates to:
  /// **'Dossiers non conformes'**
  String get qualiteNonConformes;

  /// No description provided for @qualiteDoublons.
  ///
  /// In fr, this message translates to:
  /// **'Doublons détectés'**
  String get qualiteDoublons;

  /// No description provided for @qualiteErreurs.
  ///
  /// In fr, this message translates to:
  /// **'Erreurs de saisie'**
  String get qualiteErreurs;

  /// No description provided for @qualiteCorrections.
  ///
  /// In fr, this message translates to:
  /// **'Corrections effectuées'**
  String get qualiteCorrections;

  /// No description provided for @qualiteIncidents.
  ///
  /// In fr, this message translates to:
  /// **'Incidents majeurs'**
  String get qualiteIncidents;

  /// No description provided for @rapportLogistique.
  ///
  /// In fr, this message translates to:
  /// **'Situation logistique'**
  String get rapportLogistique;

  /// No description provided for @logistiqueRessource.
  ///
  /// In fr, this message translates to:
  /// **'Ressource'**
  String get logistiqueRessource;

  /// No description provided for @logistiqueDisponible.
  ///
  /// In fr, this message translates to:
  /// **'Disponible'**
  String get logistiqueDisponible;

  /// No description provided for @logistiqueFonctionnelle.
  ///
  /// In fr, this message translates to:
  /// **'Fonctionnelle'**
  String get logistiqueFonctionnelle;

  /// No description provided for @logistiqueBesoin.
  ///
  /// In fr, this message translates to:
  /// **'Besoin'**
  String get logistiqueBesoin;

  /// No description provided for @logistiqueObservation.
  ///
  /// In fr, this message translates to:
  /// **'Observation (facultatif)'**
  String get logistiqueObservation;

  /// No description provided for @logistiqueAjouter.
  ///
  /// In fr, this message translates to:
  /// **'Ajouter une ressource'**
  String get logistiqueAjouter;

  /// No description provided for @rapportDifficultes.
  ///
  /// In fr, this message translates to:
  /// **'Difficultés rencontrées et solutions'**
  String get rapportDifficultes;

  /// No description provided for @rapportDifficulte.
  ///
  /// In fr, this message translates to:
  /// **'Difficulté'**
  String get rapportDifficulte;

  /// No description provided for @rapportSolution.
  ///
  /// In fr, this message translates to:
  /// **'Solution apportée ou proposée (facultatif)'**
  String get rapportSolution;

  /// No description provided for @rapportAjouterDifficulte.
  ///
  /// In fr, this message translates to:
  /// **'Ajouter une difficulté'**
  String get rapportAjouterDifficulte;

  /// No description provided for @rapportPoints.
  ///
  /// In fr, this message translates to:
  /// **'Points à améliorer'**
  String get rapportPoints;

  /// No description provided for @rapportPoint.
  ///
  /// In fr, this message translates to:
  /// **'Point à améliorer'**
  String get rapportPoint;

  /// No description provided for @rapportAjouterPoint.
  ///
  /// In fr, this message translates to:
  /// **'Ajouter un point'**
  String get rapportAjouterPoint;

  /// No description provided for @retirerLigne.
  ///
  /// In fr, this message translates to:
  /// **'Retirer cette ligne'**
  String get retirerLigne;

  /// No description provided for @rapportSuiviAgents.
  ///
  /// In fr, this message translates to:
  /// **'Suivi des agents'**
  String get rapportSuiviAgents;

  /// No description provided for @rapportSuiviAide.
  ///
  /// In fr, this message translates to:
  /// **'La présence vient de la feuille de présence validée : elle ne se saisit pas ici.'**
  String get rapportSuiviAide;

  /// No description provided for @suiviPresence.
  ///
  /// In fr, this message translates to:
  /// **'Présence : {valeur}'**
  String suiviPresence(String valeur);

  /// No description provided for @suiviProduction.
  ///
  /// In fr, this message translates to:
  /// **'Production'**
  String get suiviProduction;

  /// No description provided for @suiviAnomalies.
  ///
  /// In fr, this message translates to:
  /// **'Anomalies (facultatif)'**
  String get suiviAnomalies;

  /// No description provided for @suiviObservation.
  ///
  /// In fr, this message translates to:
  /// **'Observation (facultatif)'**
  String get suiviObservation;

  /// No description provided for @presencePresent.
  ///
  /// In fr, this message translates to:
  /// **'Présent'**
  String get presencePresent;

  /// No description provided for @presenceAbsent.
  ///
  /// In fr, this message translates to:
  /// **'Absent'**
  String get presenceAbsent;

  /// No description provided for @presenceAbsentJustifie.
  ///
  /// In fr, this message translates to:
  /// **'Absent justifié'**
  String get presenceAbsentJustifie;

  /// No description provided for @presenceInconnue.
  ///
  /// In fr, this message translates to:
  /// **'pas encore connue'**
  String get presenceInconnue;

  /// No description provided for @categorieSuperviseur.
  ///
  /// In fr, this message translates to:
  /// **'Superviseur'**
  String get categorieSuperviseur;

  /// No description provided for @categorieOperateur.
  ///
  /// In fr, this message translates to:
  /// **'Opérateur de kit'**
  String get categorieOperateur;

  /// No description provided for @categorieAssistant.
  ///
  /// In fr, this message translates to:
  /// **'A-OPK'**
  String get categorieAssistant;

  /// No description provided for @rapportEnregistrer.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrer sans signer'**
  String get rapportEnregistrer;

  /// No description provided for @rapportSigner.
  ///
  /// In fr, this message translates to:
  /// **'Signer et envoyer'**
  String get rapportSigner;

  /// No description provided for @rapportSignerTitre.
  ///
  /// In fr, this message translates to:
  /// **'Signer le rapport ?'**
  String get rapportSignerTitre;

  /// No description provided for @rapportSignerTexte.
  ///
  /// In fr, this message translates to:
  /// **'Une fois signé, le rapport part à votre supérieur et vous ne pouvez plus le modifier.'**
  String get rapportSignerTexte;

  /// No description provided for @rapportSigne.
  ///
  /// In fr, this message translates to:
  /// **'Rapport signé. Il partira dès que le réseau le permettra.'**
  String get rapportSigne;

  /// No description provided for @visasAide.
  ///
  /// In fr, this message translates to:
  /// **'Vos agents ont signé : leurs chiffres ne remonteront qu’après votre visa.'**
  String get visasAide;

  /// No description provided for @visasAucun.
  ///
  /// In fr, this message translates to:
  /// **'Aucun rapport n’attend votre visa sur le téléphone.'**
  String get visasAucun;

  /// No description provided for @visaSigneLe.
  ///
  /// In fr, this message translates to:
  /// **'Signé le {date}'**
  String visaSigneLe(String date);

  /// No description provided for @visaOuvrir.
  ///
  /// In fr, this message translates to:
  /// **'Lire et viser'**
  String get visaOuvrir;

  /// No description provided for @visaContenuPartiel.
  ///
  /// In fr, this message translates to:
  /// **'Les difficultés et le suivi des agents se lisent au back-office.'**
  String get visaContenuPartiel;

  /// No description provided for @visaCommentaire.
  ///
  /// In fr, this message translates to:
  /// **'Commentaire (facultatif)'**
  String get visaCommentaire;

  /// No description provided for @visaViser.
  ///
  /// In fr, this message translates to:
  /// **'Viser le rapport'**
  String get visaViser;

  /// No description provided for @visaRenvoyerTitre.
  ///
  /// In fr, this message translates to:
  /// **'Renvoyer pour correction'**
  String get visaRenvoyerTitre;

  /// No description provided for @visaMotif.
  ///
  /// In fr, this message translates to:
  /// **'Ce qui doit être corrigé'**
  String get visaMotif;

  /// No description provided for @visaMotifObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Dites ce qui doit être corrigé : un renvoi sans motif est inexploitable.'**
  String get visaMotifObligatoire;

  /// No description provided for @visaRenvoyer.
  ///
  /// In fr, this message translates to:
  /// **'Renvoyer à l’auteur'**
  String get visaRenvoyer;

  /// No description provided for @visaFait.
  ///
  /// In fr, this message translates to:
  /// **'Visa enregistré. Il partira dès que le réseau le permettra.'**
  String get visaFait;

  /// No description provided for @renvoiFait.
  ///
  /// In fr, this message translates to:
  /// **'Renvoi enregistré. Il partira dès que le réseau le permettra.'**
  String get renvoiFait;

  /// No description provided for @feuillesAucune.
  ///
  /// In fr, this message translates to:
  /// **'Aucune feuille de présence du jour sur le téléphone.'**
  String get feuillesAucune;

  /// No description provided for @feuilleAValider.
  ///
  /// In fr, this message translates to:
  /// **'À valider'**
  String get feuilleAValider;

  /// No description provided for @feuilleValidee.
  ///
  /// In fr, this message translates to:
  /// **'Validée'**
  String get feuilleValidee;

  /// No description provided for @feuilleValideeEnAttente.
  ///
  /// In fr, this message translates to:
  /// **'Validée sur le téléphone, en attente d’envoi'**
  String get feuilleValideeEnAttente;

  /// No description provided for @feuilleAgents.
  ///
  /// In fr, this message translates to:
  /// **'{nombre, plural, =0{Aucun agent attendu} =1{1 agent attendu} other{{nombre} agents attendus}}'**
  String feuilleAgents(int nombre);

  /// No description provided for @feuilleOuvrir.
  ///
  /// In fr, this message translates to:
  /// **'Ouvrir la feuille'**
  String get feuilleOuvrir;

  /// No description provided for @feuilleAide.
  ///
  /// In fr, this message translates to:
  /// **'Marquez chaque agent. Le signal d’arrivée vous aide, mais seule votre validation fait foi.'**
  String get feuilleAide;

  /// No description provided for @feuilleDejaValidee.
  ///
  /// In fr, this message translates to:
  /// **'Cette feuille est validée : seul le chef d’antenne régional peut la corriger.'**
  String get feuilleDejaValidee;

  /// No description provided for @feuilleSignal.
  ///
  /// In fr, this message translates to:
  /// **'Signal d’arrivée à {heure}, à {distance} m du site {zone}'**
  String feuilleSignal(String heure, String distance, String zone);

  /// No description provided for @feuilleSignalDansZone.
  ///
  /// In fr, this message translates to:
  /// **'(dans la zone)'**
  String get feuilleSignalDansZone;

  /// No description provided for @feuilleSignalHorsZone.
  ///
  /// In fr, this message translates to:
  /// **'(hors de la zone)'**
  String get feuilleSignalHorsZone;

  /// No description provided for @feuilleAucunSignal.
  ///
  /// In fr, this message translates to:
  /// **'Aucun signal d’arrivée'**
  String get feuilleAucunSignal;

  /// No description provided for @feuilleMotif.
  ///
  /// In fr, this message translates to:
  /// **'Motif de l’absence'**
  String get feuilleMotif;

  /// No description provided for @feuilleMotifObligatoire.
  ///
  /// In fr, this message translates to:
  /// **'Une absence justifiée exige un motif.'**
  String get feuilleMotifObligatoire;

  /// No description provided for @feuilleNonMarque.
  ///
  /// In fr, this message translates to:
  /// **'Marquez la présence de chaque agent avant de valider.'**
  String get feuilleNonMarque;

  /// No description provided for @feuilleValider.
  ///
  /// In fr, this message translates to:
  /// **'Valider la feuille'**
  String get feuilleValider;

  /// No description provided for @feuilleConfirmationTitre.
  ///
  /// In fr, this message translates to:
  /// **'Valider la feuille ?'**
  String get feuilleConfirmationTitre;

  /// No description provided for @feuilleConfirmationTexte.
  ///
  /// In fr, this message translates to:
  /// **'Votre position est enregistrée avec la validation. Une feuille validée ne se modifie plus : seul le chef d’antenne régional peut la corriger.'**
  String get feuilleConfirmationTexte;

  /// No description provided for @feuilleValideeEnregistree.
  ///
  /// In fr, this message translates to:
  /// **'Feuille validée. Elle partira dès que le réseau le permettra.'**
  String get feuilleValideeEnregistree;

  /// No description provided for @sansPositionFeuille.
  ///
  /// In fr, this message translates to:
  /// **'Sans position, la distance entre vous et le site ne pourra pas être vérifiée.'**
  String get sansPositionFeuille;
}

class _TextesDelegate extends LocalizationsDelegate<Textes> {
  const _TextesDelegate();

  @override
  Future<Textes> load(Locale locale) {
    return SynchronousFuture<Textes>(lookupTextes(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['fr'].contains(locale.languageCode);

  @override
  bool shouldReload(_TextesDelegate old) => false;
}

Textes lookupTextes(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'fr':
      return TextesFr();
  }

  throw FlutterError(
    'Textes.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
