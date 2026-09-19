**Analyse indisponible en mode rejeu.**

Le serveur tourne avec `LLM_DRIVER=replay` : aucune requête n'est envoyée à un modèle, et le corpus de réponses versionné dans le dépôt ne couvre que les trois questions de démonstration de la source MunaGo (taux d'acompte par quartier, freins les plus cités, durée moyenne d'entretien par enquêteur).

Plutôt que de produire un commentaire vraisemblable mais inventé sur ces données, le rejeu s'abstient. Pour obtenir une véritable analyse, repassez le serveur en `LLM_DRIVER=live` avec une clé `GEMINI_API_KEY` ou `DEEPSEEK_API_KEY`.
