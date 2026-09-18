import { Navigate, Route, Routes } from 'react-router-dom';
import { Disposition } from './composants/Disposition';
import { Garde, GardeVisiteur } from './auth/Garde';
import { Connexion } from './pages/Connexion';
import { AccepterCharte, ChangerMotDePasse } from './pages/PremiereConnexion';
import { TableauBord } from './pages/tableauBord/TableauBord';
import { Cartographie } from './pages/Cartographie';
import { AccesRefuse, Introuvable } from './pages/AccesRefuse';
import { Alertes } from './pages/Alertes';
import { ListeIncidents } from './pages/incidents/ListeIncidents';
import { IncidentsEnRetard } from './pages/incidents/IncidentsEnRetard';
import { FicheIncident } from './pages/incidents/FicheIncident';
import { ListeRapports } from './pages/rapports/ListeRapports';
import { RapportsAViser } from './pages/rapports/RapportsAViser';
import { FicheRapport } from './pages/rapports/FicheRapport';
import { Presences } from './pages/presences/Presences';
import { ListeFeuilles } from './pages/presences/ListeFeuilles';
import { FicheFeuille } from './pages/presences/FicheFeuille';
import { ArriveesDuJour } from './pages/presences/ArriveesDuJour';
import { Ecarts } from './pages/presences/Ecarts';
import { Appreciations } from './pages/Appreciations';
import { Exports } from './pages/Exports';
import { ListeKits } from './pages/kits/ListeKits';
import { KitsNonRestitues } from './pages/kits/KitsNonRestitues';
import { FicheKit } from './pages/kits/FicheKit';
import { EspaceVolontaires } from './pages/volontaires/EspaceVolontaires';
import { Registre } from './pages/volontaires/Registre';
import { ImportVolontaires } from './pages/volontaires/ImportVolontaires';
import { Qualification } from './pages/volontaires/Qualification';
import { RemiseIdentifiants } from './pages/volontaires/RemiseIdentifiants';
import { Vagues } from './pages/Vagues';
import { PlanifierVague } from './pages/vagues/PlanifierVague';
import { FicheVague } from './pages/vagues/FicheVague';
import { Tournees } from './pages/vagues/Tournees';
import { Equipes } from './pages/Equipes';
import { Referentiel } from './pages/referentiel/Referentiel';
import { ListeCentres } from './pages/referentiel/ListeCentres';
import { FicheCentre } from './pages/referentiel/FicheCentre';
import { ListeSites } from './pages/referentiel/ListeSites';
import { FicheSite } from './pages/referentiel/FicheSite';
import { ImportCentresSites } from './pages/referentiel/ImportCentresSites';
import { Territoire } from './pages/referentiel/Territoire';
import { ComptesAdministration } from './pages/administration/ComptesAdministration';
import { NomenclaturesIncident } from './pages/administration/NomenclaturesIncident';
import { Synchronisations } from './pages/administration/Synchronisations';
import { Parametres } from './pages/Parametres';
import { Journal } from './pages/Journal';

/**
 * LES ROUTES.
 *
 * Trois familles, et la distinction compte :
 *
 *   - VISITEUR : la connexion, interdite à qui est déjà entré ;
 *   - PREMIÈRE CONNEXION : les obligations à lever, hors de la mise en page —
 *     un agent qui n'a pas accepté la charte ne doit pas voir un menu qui lui
 *     promet des écrans que le serveur lui refusera ;
 *   - BACK-OFFICE : tout le reste, sous garde et dans la mise en page.
 *
 * Chaque page porte sa permission. Ce garde n'est qu'une politesse : une
 * adresse tapée à la main atteint la page, et c'est le serveur qui refuse les
 * données. Aucune page n'affiche quoi que ce soit que l'API ne lui a pas rendu.
 */
function Protege({ permission, permissions, children }) {
    return (
        <Garde permission={permission} permissions={permissions}>
            {children}
        </Garde>
    );
}

export function App() {
    return (
        <Routes>
            <Route
                path="/connexion"
                element={
                    <GardeVisiteur>
                        <Connexion />
                    </GardeVisiteur>
                }
            />

            <Route path="/premiere-connexion/mot-de-passe" element={<ChangerMotDePasse />} />
            <Route path="/premiere-connexion/charte" element={<AccepterCharte />} />

            <Route
                element={
                    <Garde>
                        <Disposition />
                    </Garde>
                }
            >
                <Route index element={<Protege permission="tableau_bord.consulter"><TableauBord /></Protege>} />

                <Route path="/cartographie" element={<Protege permission="tableau_bord.consulter"><Cartographie /></Protege>} />
                <Route path="/alertes" element={<Protege permission="alertes.consulter"><Alertes /></Protege>} />

                <Route path="/incidents" element={<Protege permission="incidents.consulter"><ListeIncidents /></Protege>} />
                <Route path="/incidents/en-retard" element={<Protege permission="incidents.consulter"><IncidentsEnRetard /></Protege>} />
                <Route path="/incidents/:id" element={<Protege permission="incidents.consulter"><FicheIncident /></Protege>} />

                <Route path="/rapports" element={<Protege permission="rapports.consulter"><ListeRapports /></Protege>} />
                <Route path="/rapports/a-viser" element={<Protege permission="rapports.consulter"><RapportsAViser /></Protege>} />
                <Route path="/rapports/:id" element={<Protege permission="rapports.consulter"><FicheRapport /></Protege>} />
                <Route path="/appreciations" element={<Protege permission="appreciations.consulter_equipe"><Appreciations /></Protege>} />

                <Route
                    path="/presences"
                    element={
                        <Protege permissions={['presence.consulter_feuille', 'presence.consulter_carte', 'ecarts.consulter']}>
                            <Presences />
                        </Protege>
                    }
                >
                    <Route index element={<Protege permission="presence.consulter_feuille"><ListeFeuilles /></Protege>} />
                    <Route path="feuilles/:id" element={<Protege permission="presence.consulter_feuille"><FicheFeuille /></Protege>} />
                    <Route path="carte" element={<Protege permission="presence.consulter_carte"><ArriveesDuJour /></Protege>} />
                    <Route path="ecarts" element={<Protege permission="ecarts.consulter"><Ecarts /></Protege>} />
                </Route>

                <Route path="/exports" element={<Protege permission="exports.generer"><Exports /></Protege>} />

                <Route path="/kits" element={<Protege permission="kits.consulter"><ListeKits /></Protege>} />
                <Route path="/kits/non-restitues" element={<Protege permission="kits.consulter"><KitsNonRestitues /></Protege>} />
                <Route path="/kits/:id" element={<Protege permission="kits.consulter"><FicheKit /></Protege>} />

                <Route element={<Protege permission="volontaires.consulter"><EspaceVolontaires /></Protege>}>
                    <Route path="/volontaires" element={<Registre />} />
                    <Route path="/volontaires/import" element={<Protege permission="volontaires.importer"><ImportVolontaires /></Protege>} />
                    <Route path="/volontaires/a-qualifier" element={<Protege permission="volontaires.qualifier"><Qualification /></Protege>} />
                    <Route path="/volontaires/identifiants" element={<Protege permission="comptes.consulter"><RemiseIdentifiants /></Protege>} />
                </Route>
                <Route path="/vagues" element={<Protege permission="vagues.consulter"><Vagues /></Protege>} />
                <Route path="/vagues/planifier" element={<Protege permission="vagues.planifier"><PlanifierVague /></Protege>} />
                <Route path="/vagues/:id" element={<Protege permission="vagues.consulter"><FicheVague /></Protege>} />
                <Route path="/equipes" element={<Protege permission="affectations.consulter"><Equipes /></Protege>} />
                <Route path="/tournees" element={<Protege permission="affectations.consulter"><Tournees /></Protege>} />

                <Route element={<Protege permission="referentiel.consulter"><Referentiel /></Protege>}>
                    <Route path="/centres" element={<ListeCentres />} />
                    <Route path="/centres/import" element={<Protege permission="referentiel.importer"><ImportCentresSites /></Protege>} />
                    <Route path="/centres/:id" element={<FicheCentre />} />
                    <Route path="/sites" element={<ListeSites />} />
                    <Route path="/sites/:id" element={<FicheSite />} />
                </Route>

                <Route path="/territoire" element={<Protege permission="referentiel.consulter"><Territoire /></Protege>} />

                <Route path="/administration/comptes" element={<Protege permission="roles.attribuer"><ComptesAdministration /></Protege>} />
                <Route path="/administration/incidents" element={<Protege permission="incidents.nomenclatures"><NomenclaturesIncident /></Protege>} />
                <Route path="/administration/synchronisations" element={<Protege permission="journal.consulter"><Synchronisations /></Protege>} />
                <Route path="/parametres" element={<Protege permission="parametres.consulter"><Parametres /></Protege>} />
                <Route path="/journal" element={<Protege permission="journal.consulter"><Journal /></Protege>} />

                <Route path="/acces-refuse" element={<AccesRefuse />} />
                <Route path="*" element={<Introuvable />} />
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}
