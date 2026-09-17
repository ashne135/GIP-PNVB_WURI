<x-mail::message>
# Bonjour {{ $nomComplet }},

Votre compte est ouvert sur la plateforme de gestion des volontaires du
GIP-PNVB, pour le projet WURI.

**Votre identifiant de connexion est votre numéro de téléphone :**

<x-mail::panel>
Identifiant : **{{ $identifiant }}**
Mot de passe provisoire : **{{ $motDePasse }}**
</x-mail::panel>

Ce mot de passe ne sert **qu'une seule fois**. À votre première connexion, la
plateforme vous demandera d'en choisir un nouveau, que vous serez seul à
connaître.

Ne communiquez ce mot de passe à personne, pas même à un collègue ou à un
responsable.

Si vous n'arrivez pas à vous connecter, prévenez votre superviseur.

Merci,<br>
L'équipe GIP-PNVB
</x-mail::message>
