# P132 — Portail Housekeeping Next.js, premier écran opérationnel

Cette étape rend visibles dans le portail ATLAS ONE les APIs P132 déjà fusionnées par la PR #68.

## Vues et fonctionnement

- Navigation `Hôtellerie & PMS > Housekeeping` activée **uniquement pour administrateurs** selon le contrat actuel des routes Laravel.
- Sélection de l'hôtel, journée d'exploitation et filtre de chambre ; les chambres sont regroupées visuellement par étage (floor_code).
- Affichage des états de nettoyage : sans mission, sale, en cours, propre, inspectée. La transition doit suivre exactement cet ordre.
- Création d'une mission réelle (POST), progression (POST transition), et actualisation du tableau. Le changement de société remonte le composant ; les API conservent la protection de contexte tenant et le cookie de session.
- Les chambres hors-service/bloquées techniquement restent signalées indépendamment des inspections. Aucune disponibilité de réservation n'est calculée ici.
- Un test Playwright de bout en bout crée un hôtel et une chambre fictifs, puis effectue tous les états du nettoyage jusqu'à l'inspection.

## Limites déclarées

Ce n'est **pas** encore un plan BIM/2D de chaque étage : seulement une grille fonctionnelle regroupée par floor_code. Il manque les plans éditables, les affectations à des agents housekeeping, l'application mobile offline, les notifications, la maintenance intégrée par chambre et le contrôle de disponibilité avec le moteur de réservations.

Aucun déploiement production ni fusion vers `main`. Exiger CI générale complète et revue des permissions avant fusion dans `dev`.