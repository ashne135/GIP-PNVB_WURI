import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PhotosJointes } from '../src/composants/Photos';

/**
 * LES PHOTOS DU TERRAIN, DANS LE BACK-OFFICE.
 *
 * Elles ne sont pas servies par une adresse publique : le navigateur les
 * demande avec son jeton et les affiche depuis la mémoire. Ce qui compte ici :
 * la photo est bien demandée au serveur, son rôle est nommé en clair, et un
 * refus ne laisse pas un trou muet dans la fiche.
 */
const appels = vi.hoisted(() => ({ telecharger: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { telecharger: appels.telecharger },
}));

beforeEach(() => {
    appels.telecharger.mockReset();
    // jsdom ne sait pas fabriquer d'URL d'objet : le navigateur, si.
    URL.createObjectURL = vi.fn(() => 'blob:photo-1');
    URL.revokeObjectURL = vi.fn();
});

describe('les photos jointes', () => {
    it('demande la photo au serveur, avec le jeton, et nomme son rôle', async () => {
        appels.telecharger.mockResolvedValue({ fichier: new Blob(['x'], { type: 'image/jpeg' }), nom: 'photo.jpg' });

        render(
            <PhotosJointes
                pieces={[
                    {
                        id: 4,
                        role: 'constat_source',
                        taille_octets: 204800,
                        horodatage_telephone: '2026-09-16T08:12:00.000000Z',
                    },
                ]}
            />,
        );

        const image = await screen.findByRole('img');

        expect(image).toHaveAttribute('src', 'blob:photo-1');
        expect(appels.telecharger).toHaveBeenCalledWith('/pieces-jointes/4');
        expect(screen.getByText('Constat — celui qui remet le kit')).toBeInTheDocument();
        expect(screen.getByText(/200 Ko/)).toBeInTheDocument();
    });

    it('dit ce qui se passe quand une photo ne peut pas être affichée', async () => {
        appels.telecharger.mockRejectedValue(new Error('Vous n’avez pas accès à ce fichier.'));

        render(<PhotosJointes pieces={[{ id: 5, role: 'preuve_incident' }]} />);

        expect(await screen.findByText(/n’a pas pu être affichée/)).toBeInTheDocument();
    });

    it('dit franchement qu’aucune photo n’est arrivée', () => {
        render(<PhotosJointes pieces={[]} vide="Aucune photo reçue pour cette déclaration." />);

        expect(screen.getByText('Aucune photo reçue pour cette déclaration.')).toBeInTheDocument();
        expect(appels.telecharger).not.toHaveBeenCalled();
    });
});
