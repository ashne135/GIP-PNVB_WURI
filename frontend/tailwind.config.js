/** @type {import('tailwindcss').Config} */
export default {
    content: ['./index.html', './src/**/*.{js,jsx}'],
    theme: {
        extend: {
            colors: {
                /**
                 * LA PALETTE, TIRÉE DU LOGO.
                 *
                 * Les trois teintes viennent du fichier `public/logo-pnvb.jpg`,
                 * échantillonnées pixel par pixel et non choisies à l'œil :
                 *
                 *   vert   #028428  (11,6 % de l'image — la carte, les lettres)
                 *   jaune  #F8C314  (10,4 % — le bandeau)
                 *   rouge  #CD0819  (10,7 % — la silhouette, le soleil)
                 *
                 * `pnvb` porte le VERT : c'est la couleur dominante du logo, et
                 * celle de l'identité — barre latérale, boutons, liens actifs.
                 * `or` porte le JAUNE, employé par touches : marque de l'entrée
                 * active, soulignements. Le ROUGE du logo n'habille rien dans
                 * l'interface : il est déjà pris par l'erreur (`brique`), et deux
                 * rouges de sens différent sur un même écran se confondraient.
                 *
                 * Les couleurs SÉMANTIQUES restent distinctes de l'accent, et
                 * doublent toujours un mot : un état ne se lit pas à la teinte.
                 * Réserve assumée : `vert` (succès) et `pnvb` (identité) sont
                 * deux verts. Ils ne se rencontrent jamais dans le même rôle —
                 * l'un est un fond de pastille avec son libellé, l'autre la
                 * couleur du mobilier.
                 *
                 * Les couleurs des GRAPHIQUES ne sont pas ici : elles vivent
                 * dans `src/graphiques/viz.js`, validées par script contre le
                 * blanc. Une identité ne décide pas de la lisibilité d'une donnée.
                 */
                pnvb: {
                    50: '#ECF8EF',
                    100: '#D2EEDB',
                    200: '#A6DCB7',
                    300: '#6FC58C',
                    400: '#33A75F',
                    500: '#0E8F3D',
                    600: '#028428',
                    700: '#026B21',
                    800: '#01551B',
                    900: '#013F14',
                },
                or: {
                    50: '#FEF8E4',
                    100: '#FDEFBE',
                    200: '#FBE288',
                    300: '#F9D34D',
                    400: '#F8C314',
                    500: '#E0AC07',
                    600: '#B98A06',
                    700: '#8E6A05',
                },
                ardoise: {
                    50: '#F6F8F8',
                    100: '#ECF0F1',
                    200: '#D9E0E1',
                    300: '#BCC7C9',
                    400: '#8FA0A3',
                    500: '#6B7D81',
                    600: '#526366',
                    700: '#3E4B4E',
                    800: '#2B3436',
                    900: '#1A2022',
                },
                vert: {
                    50: '#EEF6F1',
                    100: '#D7EADE',
                    600: '#2F7D4F',
                    700: '#256540',
                    800: '#1C4E31',
                },
                ocre: {
                    50: '#FBF4E9',
                    100: '#F6E6CD',
                    300: '#E0BC7B',
                    600: '#B7791F',
                    700: '#8A5313',
                    800: '#6E420F',
                    900: '#4E2F0B',
                },
                brique: {
                    50: '#FBEFED',
                    100: '#F6DBD7',
                    300: '#E0A79E',
                    400: '#D08A7E',
                    600: '#B03A28',
                    700: '#9B2C2C',
                    800: '#7C2323',
                    900: '#5C1A1A',
                },
            },
            fontFamily: {
                sans: ['"IBM Plex Sans"', 'Segoe UI', 'system-ui', 'sans-serif'],
                mono: ['"IBM Plex Mono"', 'ui-monospace', 'Consolas', 'monospace'],
            },
        },
    },
    plugins: [],
};
