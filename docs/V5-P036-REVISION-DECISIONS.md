# ATLAS V5 — P036 / B06 : rejets et révisions

- Refus motivé par un administrateur distinct, en transaction.
- Le demandeur peut corriger un brouillon ou un refus : statut draft, numéro de révision incrémenté, décision précédente invalidée.
- Après approbation, la demande est immuable pour cet incrément.
- Piste d'audit enregistrée à chaque transition.
- Limites : approbation par délégations/ABAC P019, engagements budgétaires et commandes fournisseur non implémentés. Nécessitent validation métier, tests SQL concurrents et staging.
