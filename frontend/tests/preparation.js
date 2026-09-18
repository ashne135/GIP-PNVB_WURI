import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach } from 'vitest';

/*
 * LA PATIENCE DES ATTENTES.
 *
 * `findBy…` abandonne au bout d'une seconde par défaut. Vingt-deux fichiers
 * tournent en parallèle, chacun montant React, React Query et jsdom : sur un
 * poste chargé, une seconde ne suffit pas toujours, et des tests JUSTES
 * échouaient une fois sur trois. Un échec qu'on rejoue jusqu'à ce qu'il passe
 * n'apprend plus rien.
 *
 * Cela n'affaiblit aucune vérification : une attente qui ne sera jamais
 * satisfaite échoue toujours, cinq secondes plus tard.
 */
configure({ asyncUtilTimeout: 5000 });

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});
