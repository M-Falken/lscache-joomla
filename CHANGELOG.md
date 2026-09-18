# Changelog

Notes de version du fork [M-Falken/lscache-joomla](https://github.com/M-Falken/lscache-joomla).
L'historique Git fait foi pour le détail de chaque changement ; ce fichier ne retient que ce
qui demande une action de la part de l'administrateur du site (changement de comportement,
migration d'un réglage).

## 1.5.38 (2026-09-18)

### Correctif définitif : le champ consentement ne s'enregistrait jamais vide

Le correctif 1.5.37 (ci-dessous) était incomplet — confirmé en direct sur
materiel-grand-format.fr, vider le champ et enregistrer réaffichait toujours
`cookieconsent_status`. La vraie cause n'était pas la lecture mais
l'enregistrement : le champ XML déclarait `default="cookieconsent_status"`,
et **le formulaire Joomla lui-même** (`Form::filter()`, cœur de Joomla)
applique `$input->get($cle, $defautXML)` sur la valeur **soumise**, avant même
qu'elle atteigne notre code ou la base de données. Avec un défaut XML non
vide, Joomla remplaçait donc silencieusement toute soumission vide par ce
défaut au moment même de la sauvegarde — la valeur vide n'était jamais
enregistrée, quoi qu'ait fait le correctif 1.5.37 côté lecture.

Corrigé en retirant le défaut XML non vide du champ (`default=""`). Le champ
est maintenant vide par défaut, y compris sur une installation neuve : le
mécanisme est désactivé tant qu'aucun nom de cookie n'est explicitement
renseigné et enregistré. **Ce n'est plus « actif par défaut avec
`cookieconsent_status`, désactivable en vidant le champ » — c'est
« désactivé par défaut, activable en renseignant un nom »**, conformément à
ce que demandait AndySDH sur la PR #89. Si vous voulez ce mécanisme, indiquez
le nom du cookie de votre gestionnaire de consentement (`cookieconsent_status`
pour la plupart d'entre eux) dans ce champ.

**Si vous êtes passé par la 1.5.36 ou la 1.5.37 : mettez à jour vers la
1.5.38, videz le champ « Cookies portant une décision de consentement » si ce
n'est pas déjà fait, et enregistrez. Vérifiez ensuite que « Diagnostic du
cache » affiche la dimension Consentement comme inactive.**

## 1.5.37 (2026-09-18)

### Correctif incomplet : voir la 1.5.38 ci-dessus

Cette version essayait de corriger la lecture d'un champ enregistré vide via
`Registry::exists()`, en pensant que le problème se situait uniquement à la
relecture de la valeur enregistrée. En réalité, la valeur vide n'était jamais
enregistrée du tout (le formulaire Joomla la remplaçait par le défaut XML
avant la sauvegarde) — ce correctif n'avait donc aucun effet observable.
Passez directement à la 1.5.38.

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
avant la 1.5.38, voir les entrées ci-dessus.**

Contexte : retour de revue d'AndySDH sur la PR upstream
[litespeedtech/lscache-joomla#89](https://github.com/litespeedtech/lscache-joomla/pull/89).
