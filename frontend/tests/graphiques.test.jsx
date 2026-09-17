import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { graduations, indexLePlusProche } from '../src/graphiques/echelles';
import { classeCouverture } from '../src/graphiques/viz';
import { CourbeJournaliere } from '../src/graphiques/CourbeJournaliere';
import { BarresHorizontales } from '../src/graphiques/BarresHorizontales';

/**
 * LES GRAPHIQUES DU TABLEAU DE BORD.
 *
 * Ce qu'on vérifie ici, c'est ce qui trompe le lecteur quand ça casse : des
 * graduations qui nomment des valeurs que la courbe n'atteint pas, un second
 * axe, une région sans population dessinée comme un zéro, une valeur
 * inaccessible sans souris.
 */

describe('les échelles', () => {
    it('donne des graduations rondes qui couvrent le maximum', () => {
        expect(graduations(137)).toEqual({ max: 150, pas: 50, valeurs: [0, 50, 100, 150] });
        expect(graduations(1000).valeurs).toEqual([0, 250, 500, 750, 1000]);
        expect(graduations(0)).toEqual({ max: 1, pas: 1, valeurs: [0, 1] });
    });

    it('aimante le réticule à la position la plus proche', () => {
        expect(indexLePlusProche(49, [0, 40, 80])).toBe(1);
        expect(indexLePlusProche(12, [])).toBeNull();
    });

    it('range un taux dans sa classe, et laisse une valeur non mesurable hors classe', () => {
        expect(classeCouverture(0).libelle).toBe('moins de 10 %');
        expect(classeCouverture(25).libelle).toBe('25 à 50 %');
        expect(classeCouverture(130).libelle).toBe('75 % et plus');
        expect(classeCouverture(null)).toBeNull();
    });
});

const jours = [
    { date: '2026-09-01', valeur: 120 },
    { date: '2026-09-02', valeur: 180 },
    { date: '2026-09-03', valeur: 95 },
];

describe('la courbe journalière', () => {
    it('trace une seule série sur un seul axe vertical', () => {
        const { container } = render(<CourbeJournaliere titre="Enregistrements par jour" jours={jours} libelleValeur="enregistrements" />);

        expect(container.querySelectorAll('[data-serie]')).toHaveLength(1);
        // Jamais de second axe : deux échelles inventent une corrélation.
        expect(container.querySelectorAll('[data-axe="y"]')).toHaveLength(1);
    });

    it('ne gradue que des valeurs que la courbe peut atteindre', () => {
        const { container } = render(<CourbeJournaliere titre="Enregistrements" jours={jours} libelleValeur="enreg." />);

        const libelles = [...container.querySelectorAll('[data-axe="y"] text')].map((t) => t.textContent);

        expect(libelles).toEqual(['0', '50', '100', '150', '200']);
    });

    it('donne au clavier le même réticule qu’à la souris', () => {
        render(<CourbeJournaliere titre="Enregistrements" jours={jours} libelleValeur="enregistrements" />);

        const zone = screen.getByRole('img').parentElement;
        fireEvent.focus(zone);

        expect(screen.getByRole('status')).toHaveTextContent('95 enregistrements');

        fireEvent.keyDown(zone, { key: 'ArrowLeft' });

        expect(screen.getByRole('status')).toHaveTextContent('180 enregistrements');
        expect(screen.getByRole('status')).toHaveTextContent('2026-09-02');
    });

    it('offre une vue en tableau avec chaque valeur', () => {
        render(<CourbeJournaliere titre="Enregistrements" jours={jours} libelleValeur="Enregistrements" />);

        fireEvent.click(screen.getByRole('button', { name: 'Afficher le tableau' }));

        expect(screen.getAllByRole('row')).toHaveLength(4);
        expect(screen.getByText('180')).toBeInTheDocument();
    });

    it('dit franchement qu’il n’y a rien sur la période', () => {
        render(<CourbeJournaliere titre="Enregistrements" jours={[]} libelleValeur="enreg." />);

        expect(screen.getByText('Aucune donnée sur cette période.')).toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });
});

describe('les barres horizontales', () => {
    const regions = [
        { cle: 'EST', libelle: 'Est', valeur: 12.5 },
        { cle: 'CEN', libelle: 'Centre', valeur: 50 },
        { cle: 'SAH', libelle: 'Sahel', valeur: null },
    ];

    it('classe du plus grand au plus petit, avec la même couleur pour toutes', () => {
        const { container } = render(<BarresHorizontales titre="Couverture" lignes={regions} maximum={100} />);

        const barres = [...container.querySelectorAll('[data-barre]')];

        expect(barres.map((b) => b.dataset.barre)).toEqual(['CEN', 'EST']);
        expect(new Set(barres.map((b) => b.style.background)).size).toBe(1);
        expect(barres[0].style.width).toBe('50%');
        expect(barres[1].style.width).toBe('12.5%');
    });

    it('ne dessine jamais une valeur non mesurable comme un zéro', () => {
        const { container } = render(<BarresHorizontales titre="Couverture" lignes={regions} maximum={100} />);

        expect(container.querySelector('[data-barre="SAH"]')).toBeNull();
        expect(screen.getByText('non mesurable')).toBeInTheDocument();
    });

    it('rend chaque valeur accessible sans souris', () => {
        render(<BarresHorizontales titre="Couverture" lignes={regions} maximum={100} formatValeur={(v) => `${v} %`} />);

        expect(screen.getByLabelText('Centre : 50 %')).toHaveAttribute('tabindex', '0');
    });
});
