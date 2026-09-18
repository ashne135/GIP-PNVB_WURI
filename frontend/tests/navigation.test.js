import { describe, expect, it } from 'vitest';
import { menuPour } from '../src/navigation/entrees';

/**
 * LE MENU SUIT LES PERMISSIONS.
 *
 * Rappel : cela ne protège rien — le serveur revérifie tout. Mais proposer une
 * page qui répondra « action non autorisée » est une promesse qu'on ne tient
 * pas, et un menu qui ment finit par ne plus être lu.
 */

function porteur(...permissions) {
    const ensemble = new Set(permissions);

    return (...requises) => requises.some((p) => ensemble.has(p));
}

describe('le menu', () => {
    it('ne montre au volontaire que ce qui le concerne', () => {
        const menu = menuPour(porteur('rapports.consulter', 'incidents.consulter'));

        const libelles = menu.flatMap((r) => r.liens.map((l) => l.libelle));

        expect(libelles).toContain('Rapports journaliers');
        expect(libelles).toContain('Incidents');
        expect(libelles).not.toContain('Parc de kits');
        expect(libelles).not.toContain('Paramètres');
    });

    it('fait disparaître une rubrique entièrement vide', () => {
        const menu = menuPour(porteur('tableau_bord.consulter'));

        expect(menu.map((r) => r.titre)).toEqual(['Pilotage']);
    });

    it('ouvre tout à un administrateur national', () => {
        const menu = menuPour(
            porteur(
                'tableau_bord.consulter',
                'alertes.consulter',
                'presence.consulter_feuille',
                'rapports.consulter',
                'incidents.consulter',
                'vagues.consulter',
                'volontaires.consulter',
                'kits.consulter',
                'referentiel.consulter',
                'parametres.consulter',
            ),
        );

        // Pilotage, Terrain, Déploiement, Référentiel, et Administration pour les
        // seuls Paramètres : les comptes, les listes et les synchronisations
        // demandent chacun leur propre droit.
        expect(menu.map((r) => r.titre)).toEqual(['Pilotage', 'Terrain', 'Déploiement', 'Référentiel', 'Administration']);
        expect(menu.flatMap((r) => r.liens)).toHaveLength(12);
        expect(menu.at(-1).liens.map((l) => l.libelle)).toEqual(['Paramètres']);

        // La cartographie suit le droit du tableau de bord : c'est la même
        // mesure, montrée autrement.
        expect(menu[0].liens.map((l) => l.libelle)).toContain('Cartographie');
    });

    it('réserve les comptes d’administration au porteur de roles.attribuer', () => {
        const libelles = (menu) => menu.flatMap((r) => r.liens.map((l) => l.libelle));

        expect(libelles(menuPour(porteur('parametres.consulter')))).not.toContain('Comptes d’administration');
        expect(libelles(menuPour(porteur('roles.attribuer', 'journal.consulter')))).toEqual([
            'Comptes d’administration',
            'Synchronisations',
            'Journal d’activité',
        ]);
    });

    it('ouvre les présences au seul porteur des écarts', () => {
        const menu = menuPour(porteur('ecarts.consulter'));

        expect(menu.flatMap((r) => r.liens.map((l) => l.chemin))).toEqual(['/presences']);
    });

    it('ne montre rien du tout à un compte sans permission', () => {
        expect(menuPour(porteur())).toEqual([]);
    });
});
