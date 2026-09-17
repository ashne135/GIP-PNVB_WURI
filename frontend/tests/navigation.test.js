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

        expect(menu).toHaveLength(4);
        expect(menu.flatMap((r) => r.liens)).toHaveLength(10);
    });

    it('ne montre rien du tout à un compte sans permission', () => {
        expect(menuPour(porteur())).toEqual([]);
    });
});
