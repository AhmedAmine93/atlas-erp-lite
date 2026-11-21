# ATLAS ONE V5 — P071.01/.04 initial : Form Builder déclaratif

Ce premier incrément **ne constitue pas le Form Builder final**. Il fournit :
- Un contrat de formulaire à version fixe `1` validé côté Laravel, limité à 25 champs.
- Une autorisation d'édition basée sur le rôle **administrateur de la société active**.
- Une liste blanche stricte de types : texte, e-mail, nombre, date, sélection et case à cocher.
- Le rejet des attributs supplémentaires, des identifiants en doublon et des types arbitraires.
- Un aperçu Next.js inerte sans `submit` métier, ni exécution de JS/SQL/HTML dynamique.
- Des tests d'authentification, de permissions et de schémas malveillants.

**Reste à livrer** : UI d'édition des formulaires, stockage/versionnage tenant, rattachement aux processus métiers, prévisualisation de champs conditionnels, validation côté serveur des valeurs soumises et audit. Le composant n'est pas exposé dans la navigation publique tant que ces garanties manquent. Aucune donnée existante modifiée.
