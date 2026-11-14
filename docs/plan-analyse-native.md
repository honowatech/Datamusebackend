# Plan : analyse de données native, l'IA en dernier recours

Objectif : Datamuse doit savoir analyser les données par elle-même. L'IA n'est sollicitée que lorsque les possibilités natives sont épuisées.

## Constat de départ

Dès qu'on sort des tableaux de bord d'enquête, tout passe par le LLM : le chat fait générer le SQL puis du Python par l'IA, et les verbatims comme les synthèses sont délégués.

Le moteur statistique existe pourtant déjà : Pyodide charge pandas, numpy et scipy dans le navigateur, mais il n'exécute que du code écrit par le LLM. Le natif existant est mince :

- les statistiques d'enquête sont calculées en mémoire PHP ;
- le khi² des tableaux croisés est approximé ;
- l'écart-type du profilage vaut 0 sur SQLite ;
- il n'y a ni pondération ni intervalle de confiance.

## Le pari : le calcul dans le navigateur

L'hébergement mutualisé n'exécute ni Python ni, a priori, de workers. Le fichier SQLite matérialisé (par enquête ou par jeu importé) est donc téléchargé et tout est calculé localement dans un Web Worker. Le serveur ne sert que des données, le coût par analyse est nul, et cela fonctionne hors ligne.

## Les sept chantiers

1. **Dictionnaire de variables.** Chaque colonne reçoit un niveau de mesure (nominale, ordinale, numérique, date, géo, choix multiple, texte), tiré du schéma DFS pour les enquêtes et inféré par profilage pour les fichiers importés. C'est lui qui dit quelles analyses sont valides.
2. **Catalogue de recettes déterministes.** Des recettes versionnées et testées remplacent le code généré :
   - tests choisis automatiquement selon les types (khi² exact, Fisher, t, Mann-Whitney, ANOVA, Kruskal-Wallis, corrélations) ;
   - tailles d'effet et intervalles de confiance ;
   - régressions linéaire et logistique, ACP/ACM, segmentation.
3. **Rigueur d'enquête.** Pondération et redressement par calage sur marges, marges d'erreur, suivi des quotas, analyse de cohortes sur les suivis J+4/J+7/J+14.
4. **Découverte automatique sans IA.** La plateforme croise toutes les paires de variables, corrige les comparaisons multiples et classe les résultats par taille d'effet. Elle rédige ensuite les « faits saillants » par gabarits de phrases en français et en anglais.
5. **Explorateur sans code.** Tableau croisé dynamique, segments enregistrés et variables calculées avec le langage Logic déjà implémenté. Une barre de questions à grammaire fixe (« moyenne revenu par région ») produit le SQL sans LLM.
6. **Verbatims natifs.** Fréquences, n-grammes, cooccurrences, mots spécifiques par segment, codage par dictionnaire à partir du codebook existant.
7. **Rapports natifs.** Assemblés à partir des analyses enregistrées et des gabarits ; l'IA ne fait plus que reformuler, sur demande.

## L'escalier vers l'IA

| Marche | Qui répond |
|---|---|
| 1. Tableaux de bord, explorateur, recettes | Natif |
| 2. Faits saillants automatiques | Natif |
| 3. Barre de questions à grammaire | Natif |
| 4. Question hors grammaire, découverte de thèmes, interprétation libre | IA, alimentée par les résultats natifs |

Chaque réponse IA utile (une requête SQL, une analyse) peut être enregistrée comme recette réutilisable : le recours à l'IA diminue avec l'usage. L'indicateur de pilotage est la part des questions résolues sans IA.

## Phasage

Les durées sont des ordres de grandeur pour un développeur, à affiner après la phase 1.

| Phase | Contenu | Chantiers | Durée |
|---|---|---|---|
| 1. Socle | Dictionnaire de variables, moteur local, recettes univariées et bivariées, correction du khi² et de l'écart-type | 1, 2 (première tranche) | 3 à 4 semaines |
| 2. Enquête | Pondération, intervalles de confiance, cohortes, explorateur | 3, 5 | 3 semaines |
| 3. Découverte | Faits saillants, rédaction par gabarits, verbatims natifs | 4, 6 | 3 semaines |
| 4. Bascule | Barre de questions, rapports natifs, escalier IA et capitalisation | 5 (fin), 7 | 2 semaines |

## Contrat d'API de l'analyse

Routes placées à côté des routes `stats/*` d'enquête, même préfixe et mêmes autorisations.

Phase 1 :

- `GET …/analysis/dictionary` : dictionnaire de variables du jeu de données.
- `GET …/analysis/dataset` : fichier SQLite matérialisé de l'enquête, avec `ETag`.

Chantier 3 :

- `GET` / `PUT …/analysis/weighting` : plan de pondération (marges cibles, bornage des poids).
- `GET …/analysis/quotas` : avancement des quotas.
- `GET …/analysis/cohorts` : rétention par vague de suivi.

## Risques à lever d'abord

- **Poids de Pyodide.** Plusieurs dizaines de Mo, aujourd'hui chargés depuis un CDN : à auto-héberger et mettre en cache, ou à remplacer par une solution plus légère. L'essai de faisabilité de la phase 1 tranche.
- **Gros jeux de données sur petits appareils.** Les statistiques serveur actuelles restent le repli pour les agrégats simples.
- **Lecture du SQLite côté navigateur.** À valider sur un vrai jeu de données.
- **Exécution des jobs en production.** Elle conditionne la fraîcheur des fichiers matérialisés.
