/// L'HEURE DU TÉLÉPHONE, AVEC SON DÉCALAGE.
///
/// `DateTime.toIso8601String()` omet le décalage d'une heure locale :
/// « 2026-09-14T07:42:00.000 » ne dit pas de quel fuseau il s'agit, et le
/// serveur l'interpréterait dans le sien. Or l'heure retenue est celle du
/// téléphone au moment du geste (cadrage, section 11.7) : elle part sans
/// ambiguïté, décalage compris.
String horodatageIso(DateTime moment) {
  final local = moment.toLocal();
  final decalage = local.timeZoneOffset;
  final signe = decalage.isNegative ? '-' : '+';
  final minutes = decalage.inMinutes.abs();

  return '${local.year.toString().padLeft(4, '0')}-${_deux(local.month)}-${_deux(local.day)}'
      'T${_deux(local.hour)}:${_deux(local.minute)}:${_deux(local.second)}'
      '$signe${_deux(minutes ~/ 60)}:${_deux(minutes % 60)}';
}

/// Une date lisible par un agent : « 14/09/2026 à 07:42 ».
String dateHeureLisible(DateTime moment) {
  final local = moment.toLocal();

  return '${_deux(local.day)}/${_deux(local.month)}/${local.year} à ${_deux(local.hour)}:${_deux(local.minute)}';
}

/// Une heure lisible par un agent : « 07:42 ».
String heureLisible(DateTime moment) {
  final local = moment.toLocal();

  return '${_deux(local.hour)}:${_deux(local.minute)}';
}

/// Une date du serveur (« 2026-09-15 » ou « 2026-09-15T00:00:00.000000Z »)
/// lue comme un jour, sans décalage horaire : « 15/09/2026 ».
String jourLisible(Object? valeur) {
  final texte = '${valeur ?? ''}';

  if (texte.length < 10) {
    return '—';
  }

  final morceaux = texte.substring(0, 10).split('-');

  return morceaux.length == 3 ? '${morceaux[2]}/${morceaux[1]}/${morceaux[0]}' : '—';
}

/// La date du jour, au format du serveur : « 2026-09-15 ».
String dateDuJour(DateTime moment) => horodatageIso(moment).substring(0, 10);

String _deux(int nombre) => nombre.toString().padLeft(2, '0');
