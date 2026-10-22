# Site web CDMP — cdmp.synapsys.sn

Site statique (HTML/CSS/JS) + un script PHP unique pour la réception des formulaires.

```
*.html                 pages du site
css/style.css          feuille de style commune (hors landing PayKapital, styles intégrés)
js/site.js             envoi des formulaires, modale PayKapital, vidéos YouTube
img/photos/            photos institutionnelles CDMP (optimisées, 1600 px max)
api/envoi.php          réception des 4 formulaires (contact, financement, PayKapital, newsletter)
api/config.php         ⚠ destinataires e-mail + paramètres SMTP à renseigner
api/lib/Mailer.php     envoi MIME (SMTP authentifié ou mail())
docs/sources/          documents de référence transmis par la CDMP
```

## Hébergement actuel : OVH (cdmpofficiel.sn)

- Déploiement automatique : chaque push sur la branche configurée dans OVH (Multisite → Git) déclenche une mise à jour via le webhook GitHub (Content type `application/json`, pas de secret).
- `.htaccess` (racine) redirige `http://` et `www.` vers `https://cdmpofficiel.sn`.
- Hébergement mutualisé OVH = Apache + PHP : la configuration nginx ci-dessous ne s'applique pas. Dans `api/config.php`, utiliser `'transport' => 'mail'` avec un `from_email` en `@cdmpofficiel.sn`.

## Mise en ligne des formulaires

Le serveur actuel (nginx) ne sert que des fichiers statiques : **PHP 8.0+ (PHP-FPM) doit être activé** pour `api/envoi.php`. Exemple nginx :

```nginx
location = /api/envoi.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/api/envoi.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # adapter à la version installée
    client_max_body_size 30M;
}
location ^~ /api/ { deny all; }   # config, lib et journaux non accessibles
```

Côté PHP : `upload_max_filesize = 12M` et `post_max_size = 30M` (pièces jointes de la demande de financement).

Puis compléter `api/config.php` :

1. **Destinataires** de chaque formulaire (adresses définitives à transmettre par la CDMP — en attendant tout part vers `contact@cdmp.sn`).
2. **SMTP** : hôte, port, identifiant et mot de passe d'une boîte d'envoi (`from_email` doit appartenir au domaine de cette boîte).

### Recette à effectuer après configuration

Pour chaque formulaire, soumettre un envoi test et vérifier la réception effective (y compris les pièces jointes et l'accusé de réception envoyé au demandeur) :

| Formulaire | Page | Pièces jointes |
|---|---|---|
| Contact | `contact.html` | — |
| Demande de financement | `demande-financement.html` | RCCM, NINEA, états financiers, autres |
| PayKapital | `paykapital.html` (boutons « Devenir partenaire », « Rejoindre l'écosystème », « Prendre contact ») | — |
| Newsletter | pied de page de chaque page | — |

En cas d'échec, consulter `api/logs/envois.log`.

## Ajouter une vidéo PayKapital

Dans `paykapital.html`, section `#videos`, renseigner l'identifiant YouTube dans l'attribut `data-youtube-id` d'une carte (pour `https://www.youtube.com/watch?v=AbCdEf12345` → `data-youtube-id="AbCdEf12345"`). La vidéo se lit directement sur la page.

## Logos « Ils nous ont fait confiance »

Page d'accueil, bloc `partners-grid` : remplacer le contenu de chaque `.partner` par `<img src="img/partenaires/nom.png" alt="Nom du partenaire"/>` dès réception des logos.
