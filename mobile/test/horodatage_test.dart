import 'package:flutter_test/flutter_test.dart';
import 'package:volontaires/outils/horodatage.dart';

void main() {
  test("l'heure du geste part avec son décalage, et désigne le même instant", () {
    final geste = DateTime(2026, 9, 14, 7, 42, 5);
    final iso = horodatageIso(geste);

    expect(iso, matches(RegExp(r'^2026-09-14T07:42:05[+-]\d\d:\d\d$')));
    expect(DateTime.parse(iso).isAtSameMomentAs(geste), isTrue);
  });

  test('une date lisible par un agent', () {
    expect(dateHeureLisible(DateTime(2026, 9, 4, 7, 5)), '04/09/2026 à 07:05');
  });
}
