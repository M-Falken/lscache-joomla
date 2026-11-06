# Changelog

Notes de version du fork [M-Falken/lscache-joomla](https://github.com/M-Falken/lscache-joomla).
L'historique Git fait foi pour le détail de chaque changement ; ce fichier ne retient que ce
qui demande une action de la part de l'administrateur du site (changement de comportement,
migration d'un réglage).

## 1.5.42 (2026-11-06)

### Action requise : purger le cache après la mise à jour

Pour les visiteurs non connectés, les pages VirtueMart liées à la session pouvaient entrer dans
le cache public : panier, compte client, liste des commandes et confirmation de commande. C'était
le cas pour leurs URL non SEF (`index.php?option=com_virtuemart&view=cart`, `view=user`,
`view=orders`, `view=cart&layout=orderdone`), quel que soit l'Itemid, et pour les routes
`/component/virtuemart/…`. Seuls les éléments de menu ajoutés aux exclusions protégeaient les URL
SEF. Le premier visiteur qui provoquait la mise en cache publiait son panier, son formulaire de
commande ou sa confirmation à tous les suivants.

Ces vues, ainsi que `invoice`, `pluginresponse` et `vmplg`, ne sont plus jamais mises en cache.
C'est aussi le cas de toute requête VirtueMart qui porte une tâche (`task`), sauf les
formulaires publics `askquestion` et `recommend`.

Les copies déjà en cache restent servies jusqu'à leur expiration. Purgez tout le cache LiteSpeed
après l'installation.

## 1.5.41 (2026-11-06)

### Blocs ESI traités sur les pages non mises en cache (sans action)

Quand le plugin renonçait à stocker une page après le rendu des modules (code HTTP autre que
200/201, élément de menu sans composant, durée de cache à 0, ou toute page non cachable en mode
ESI « Toujours activé »), il n'envoyait pas `esi=on`. LiteSpeed laissait alors brutes les balises
`<esi:include>` présentes dans le HTML, et les modules concernés n'apparaissaient pas.

Ces pages reçoivent désormais `X-LiteSpeed-Cache-Control: no-cache,esi=on`. Elles ne sont
toujours pas stockées. Si un en-tête `X-LiteSpeed-Cache-Control` a déjà été posé (par le plugin
pour un bloc ESI, ou par une autre extension), il est conservé et seul `esi=on` y est ajouté.
Repris de la PR upstream #98.

## 1.5.40 (2026-11-05)

### Action requise si votre gestionnaire de consentement modifie le HTML côté serveur

Une page rendue pour une session qui a accepté des cookies pouvait partir dans le cache public.
Le visiteur suivant recevait alors un consentement déjà accordé et des cases cochées, avant tout
choix de sa part. Purger ne suffit pas : le prochain miss d'une telle session recrée la copie.

Nouveau champ **« Sessions dont les pages ne sont pas stockées »** (`consentNoCacheSession`,
onglet Avancé) : liste de paires `clé=valeur` séparées par des virgules. **Le champ est vide par
défaut, et le mécanisme reste alors désactivé : sans action de votre part, ce défaut n'est pas
corrigé.** Pour com_gdpr, indiquez les catégories non verrouillées :

```
gdpr_cookie_category_disabled_2=-1,gdpr_cookie_category_disabled_3=-1,gdpr_cookie_category_disabled_4=-1
```

Si le bouton de refus de com_gdpr est réglé sur « hard », ajoutez
`gdpr_generic_cookie_consent_denyall=1` par précaution (cas non mesuré). Enregistrez, puis purgez tout le cache LiteSpeed.

- **Coût :** ces sessions n'alimentent plus le cache. Elles reçoivent toujours les pages déjà en
  cache, mais font un miss sur les autres. Une couverture complète du rebuild limite cet effet.
- **Ne pas combiner, pour les mêmes états, avec « Cookies portant une décision de consentement » :**
  le compartiment de ces visiteurs ne serait jamais stocké et ils feraient toujours un miss.
- **Limite :** ce champ lit la session, pas les cookies. Si votre gestionnaire change le HTML selon
  un cookie, comparez une page rendue avec ce cookie à une page rendue sans.

### Identifiant de session retiré des pages mises en cache (sans action)

com_gdpr écrit le nom et la valeur du cookie de session dans un script de la page. La copie en
cache exposait donc un cookie de session complet à tous les visiteurs servis depuis cette copie.
L'identifiant de la session courante est désormais retiré de toute page stockée, quelle que soit
l'extension qui l'a écrit. Les pages non mises en cache et les blocs ESI ne sont pas concernés.

Avec com_gdpr, `gdprJSessVal` devient vide dans ces pages. Vérifiez deux réglages de com_gdpr :

- `show_consent_id` : s'il est actif, l'identifiant de consentement affiché sera vide ;
- `always_check_categories_session` : s'il est inactif, les choix enregistrés dans le navigateur
  seront renvoyés au serveur moins souvent.

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
