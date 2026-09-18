# Changelog

Notes de version du fork [M-Falken/lscache-joomla](https://github.com/M-Falken/lscache-joomla).
L'historique Git fait foi pour le détail de chaque changement ; ce fichier ne retient que ce
qui demande une action de la part de l'administrateur du site (changement de comportement,
migration d'un réglage).

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
consentement » (Cache Options → Avancé) et enregistrez. L'encadré « Diagnostic du cache » de
l'administration doit alors afficher la dimension Consentement comme inactive.

Contexte : retour de revue d'AndySDH sur la PR upstream
[litespeedtech/lscache-joomla#89](https://github.com/litespeedtech/lscache-joomla/pull/89).
