import { describe, expect, it } from 'vitest';
import { etatCarte } from '../src/graphiques/etatCarte';
import { dateCourte, dernierJourActif, debutPeriode } from '../src/pages/tableauBord/outils';

/**
 * CE QUE LA CARTE SAIT DIRE, ET LE JOUR QUE LE TABLEAU DE BORD MONTRE.
 *
 * La carte elle-même n'est pas montée ici : Leaflet a besoin d'un vrai
 * navigateur pour mesurer son conteneur. Ce qui est testé, c'est ce qu'elle
 * annonce — et c'est là que se joue l'honnêteté d'une carte vide.
 */
const douzeRegions = Array.from({ length: 12 }, (_, rang) => ({ code: `R${rang}`, nom: `Région ${rang}`, contour_geojson: null }));

describe('l’état de la carte', () => {
    it('dit en chiffres ce qui manque quand rien n’est chargé', () => {
        const etat = etatCarte({ regions: douzeRegions, sitesCarte: { total_sites: 12294, localises: 0, sites: [] } });

        expect(etat.vide).toBe(true);
        // Espace insécable fine du format français entre les milliers.
        expect(etat.resume.replace(/\s/g, ' ')).toBe('0 contour de région sur 12 · 0 site localisé sur 12 294');
        expect(etat.message).toContain('Aucun contour de région ni aucune coordonnée de site');
    });

    it('signale une carte partielle sans la dire vide', () => {
        const regions = douzeRegions.map((region, rang) => (rang < 3 ? { ...region, contour_geojson: { type: 'Polygon', coordinates: [] } } : region));
        const etat = etatCarte({ regions, sitesCarte: { total_sites: 100, localises: 40, sites: [] } });

        expect(etat.vide).toBe(false);
        expect(etat.partielle).toBe(true);
        expect(etat.message).toBeNull();
        expect(etat.resume).toContain('3 contours de région sur 12');
        expect(etat.resume).toContain('40 sites localisés sur 100');
    });
});

describe('le jour montré par le tableau de bord', () => {
    it('prend le dernier jour où quelque chose a été enregistré, pas simplement hier', () => {
        expect(dernierJourActif([
            { date: '2026-09-10', enregistrements: 120 },
            { date: '2026-09-11', enregistrements: 90 },
            { date: '2026-09-12', enregistrements: 0 },
        ])).toBe('2026-09-11');
    });

    it('ramène une date-heure ISO à AAAA-MM-JJ, seule forme que l’API retrouve comme filtre', () => {
        expect(dernierJourActif([{ date: '2026-09-11T00:00:00.000000Z', enregistrements: 90 }])).toBe('2026-09-11');
    });

    it('ne montre aucun jour quand la période est vide', () => {
        expect(dernierJourActif([])).toBeNull();
        expect(dernierJourActif(undefined)).toBeNull();
    });

    it('calcule le début de période', () => {
        expect(debutPeriode(7, Date.parse('2026-09-14T10:00:00Z'))).toBe('2026-09-07');
    });

    it('affiche le bon jour sur l’axe, quel que soit le fuseau du navigateur', () => {
        expect(dateCourte('2026-09-03')).toBe('03/09');
        expect(dateCourte('2026-09-03T00:00:00.000000Z')).toBe('03/09');
    });
});
