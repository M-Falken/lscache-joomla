# Changelog

Notes de version du fork [M-Falken/lscache-joomla](https://github.com/M-Falken/lscache-joomla).
L'historique Git fait foi pour le détail de chaque changement ; ce fichier ne retient que ce
qui demande une action de la part de l'administrateur du site (changement de comportement,
migration d'un réglage).

## 1.5.37 (2026-09-18)

### Correctif : vider le champ consentement ne le désactivait pas réellement en 1.5.36

La 1.5.36 (ci-dessous) disait que vider **« Cookies portant une décision de consentement »**
suffisait à couper le mécanisme. C'était faux en pratique : `Joomla\Registry\Registry::get()`
traite une valeur enregistrée comme chaîne vide exactement comme une valeur absente, et
revient systématiquement à la valeur par défaut (`cookieconsent_status`) qu'on lui passe en
second paramètre. Résultat : un champ vidé puis enregistré redevenait, dès le rechargement,
indiscernable d'un champ jamais configuré — impossible d'atteindre l'état désactivé en le
vidant, quoi qu'on fasse. Confirmé en direct sur materiel-grand-format.fr.

Corrigé en distinguant les deux cas via `Registry::exists()` (simple `isset()`, sans ce
filtrage) plutôt qu'en se fiant à la valeur retournée par `get()`. **Si vous êtes passé par
la 1.5.36 et avez vidé ce champ sans que « Diagnostic du cache » ne montre la dimension
Consentement comme inactive, mettez à jour vers la 1.5.37 : aucune autre action nécessaire,
le champ vide déjà enregistré est maintenant correctement lu comme désactivé.**

## 1.5.36 (2026-09-18)

### ⚠️ Action requise si vous aviez désactivé « Faire varier le cache selon le consentement »

Le réglage `pagecacheVary` (bouton on/off séparé, onglet Avancé) a été supprimé. Le champ
**« Cookies portant une décision de consentement »** (`consentCookies`) est désormais le seul
réglage : liste vide = mécanisme désactivé, un ou plusieurs noms de cookies = activé.

**Si vous aviez coupé ce mécanisme via l'ancien bouton on/off tout en laissant le champ
« Cookies portant une décision de consentement » à sa valeur par défaut
(`cookieconsent_status`), la mise à jour vers 1.5.36 réactive silencieusement la fragmentation
du cache par consentement** : la valeur de l'ancien bouton, enregistrée en base, n'est plus
consultée par aucun code.

Pour retrouver l'état désactivé : videz le champ « Cookies portant une décision de
consentement » (Cache Options → Avancé) et enregistrez. **⚠️ Cette étape ne fonctionnait pas
avant la 1.5.37, voir l'entrée ci-dessus.**

Contexte : retour de revue d'AndySDH sur la PR upstream
[litespeedtech/lscache-joomla#89](https://github.com/litespeedtech/lscache-joomla/pull/89).
