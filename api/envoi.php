<?php
/**
 * Point de réception unique des formulaires du site CDMP.
 *
 * Reçoit un POST (multipart/form-data) contenant un champ « formulaire »
 * (contact | financement | paykapital | newsletter), valide les données,
 * puis transmet le message (et les pièces jointes) aux destinataires
 * définis dans config.php. Répond en JSON : {"ok": bool, "message": string}.
 */
declare(strict_types=1);

require __DIR__ . '/lib/Mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function repondre(bool $ok, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(false, 'Méthode non autorisée.', 405);
}

// Requête plus volumineuse que post_max_size : PHP vide $_POST et $_FILES.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    repondre(false, 'Les fichiers envoyés sont trop volumineux. Merci de réduire la taille des pièces jointes.', 413);
}

$config = require __DIR__ . '/config.php';

// Champ piège anti-robots : doit rester vide.
if (trim((string)($_POST['site_hp'] ?? '')) !== '') {
    repondre(true, 'Merci, votre demande a bien été envoyée.');
}

/**
 * Description des formulaires : libellés (dans l'ordre d'affichage du mail),
 * champs obligatoires et pièces jointes.
 */
$formulaires = [
    'contact' => [
        'titre' => 'Nouveau message — formulaire de contact',
        'champs' => [
            'nom'       => ['Nom complet', true],
            'email'     => ['Adresse e-mail', true],
            'telephone' => ['Téléphone', false],
            'sujet'     => ['Sujet', true],
            'message'   => ['Message', true],
        ],
        'fichiers' => [],
    ],
    'financement' => [
        'titre' => 'Nouvelle demande de financement',
        'champs' => [
            'nom'             => ['Nom', true],
            'prenom'          => ['Prénom', true],
            'fonction'        => ['Fonction / Qualité', true],
            'nationalite'     => ['Nationalité', false],
            'raison_sociale'  => ['Raison sociale', true],
            'forme_juridique' => ['Forme juridique', true],
            'secteur'         => ["Secteur d'activité", true],
            'rccm'            => ['Numéro RCCM', true],
            'ninea'           => ['NINEA', true],
            'adresse'         => ['Adresse du siège social', true],
            'tel1'            => ['Téléphone principal', true],
            'tel2'            => ['Téléphone secondaire', false],
            'email'           => ['Adresse e-mail', true],
            'site_web'        => ['Site web', false],
            'besoin'          => ['Besoin en financement', true],
            'montant'         => ['Montant estimatif du besoin (FCFA)', false],
            'objet'           => ['Objet de la demande', true],
            'projet'          => ['Présentation du projet / besoin spécifique', true],
            'commentaires'    => ['Commentaires complémentaires', false],
        ],
        'fichiers' => [
            'fichier_rccm'   => ['Registre du Commerce et du Crédit Mobilier (RCCM)', true],
            'fichier_ninea'  => ['NINEA', true],
            'fichier_etats'  => ['États financiers (3 derniers exercices)', true],
            'fichier_autres' => ['Autres documents', false],
        ],
    ],
    'paykapital' => [
        'titre' => "Nouvelle demande — Rejoindre l'écosystème PayKapital",
        'champs' => [
            'profil'        => ['Vous êtes', true],
            'donneur_ordre' => ["Grande entreprise ou donneur d'ordre visé", false],
            'organisation'  => ["Nom de l'organisation", true],
            'nom_complet'   => ['Prénom et nom', true],
            'fonction'      => ['Fonction', true],
            'email'         => ['Adresse e-mail professionnelle', true],
            'telephone'     => ['Téléphone', true],
            'solutions'     => ['Solution(s) qui vous intéresse(nt)', false],
            'message'       => ['Message', false],
            'consentement'  => ['Consentement au traitement des données', true],
        ],
        'fichiers' => [],
    ],
    'newsletter' => [
        'titre' => 'Nouvelle inscription à la newsletter',
        'champs' => [
            'email' => ['Adresse e-mail', true],
        ],
        'fichiers' => [],
    ],
];

$type = (string)($_POST['formulaire'] ?? '');
if (!isset($formulaires[$type])) {
    repondre(false, 'Formulaire inconnu.', 400);
}
$def = $formulaires[$type];

// ---------- Validation des champs ----------
$valeurs = [];
$manquants = [];
foreach ($def['champs'] as $nom => [$libelle, $obligatoire]) {
    $brut = $_POST[$nom] ?? '';
    if (is_array($brut)) {
        $valeur = implode(', ', array_filter(array_map(fn($v) => trim((string)$v), $brut), 'strlen'));
    } else {
        $valeur = trim((string)$brut);
    }
    $valeur = mb_substr($valeur, 0, 5000);
    if ($obligatoire && $valeur === '') {
        $manquants[] = $libelle;
    }
    $valeurs[$nom] = $valeur;
}
if ($manquants) {
    repondre(false, 'Merci de compléter les champs obligatoires : ' . implode(', ', $manquants) . '.', 422);
}

$emailDemandeur = $valeurs['email'] ?? '';
if ($emailDemandeur !== '' && !filter_var($emailDemandeur, FILTER_VALIDATE_EMAIL)) {
    repondre(false, "L'adresse e-mail saisie n'est pas valide.", 422);
}

// ---------- Validation des pièces jointes ----------
$pieces = [];
$total = 0;
foreach ($def['fichiers'] as $nom => [$libelle, $obligatoire]) {
    $fichiers = normaliserFichiers($_FILES[$nom] ?? null);
    $recus = 0;
    foreach ($fichiers as $f) {
        if ($f['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            repondre(false, "Le fichier « {$f['name']} » dépasse la taille autorisée.", 413);
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            repondre(false, "Le fichier « {$f['name']} » n'a pas pu être reçu. Merci de réessayer.", 400);
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $config['allowed_extensions'], true)) {
            repondre(false, "Le format du fichier « {$f['name']} » n'est pas accepté (PDF, JPG ou PNG).", 422);
        }
        if ($f['size'] > $config['max_file_size']) {
            repondre(false, "Le fichier « {$f['name']} » dépasse la taille autorisée.", 413);
        }
        $total += $f['size'];
        $recus++;
        $pieces[] = [
            'path' => $f['tmp_name'],
            'name' => $libelle . ' - ' . basename($f['name']),
        ];
    }
    if ($obligatoire && $recus === 0) {
        $manquants[] = $libelle;
    }
}
if ($manquants) {
    repondre(false, 'Merci de joindre les pièces obligatoires : ' . implode(', ', $manquants) . '.', 422);
}
if ($total > $config['max_total_size']) {
    repondre(false, 'La taille totale des pièces jointes est trop importante. Merci de réduire la taille des fichiers.', 413);
}

// ---------- Composition du message ----------
$lignes = [];
$html = '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">';
foreach ($def['champs'] as $nom => [$libelle]) {
    if ($valeurs[$nom] === '') {
        continue;
    }
    $lignes[] = $libelle . ' : ' . $valeurs[$nom];
    $html .= '<tr><td style="border-bottom:1px solid #e5e7eb;color:#4b5563;vertical-align:top;white-space:nowrap;"><strong>'
        . htmlspecialchars($libelle) . '</strong></td><td style="border-bottom:1px solid #e5e7eb;">'
        . nl2br(htmlspecialchars($valeurs[$nom])) . '</td></tr>';
}
$html .= '</table>';
if ($pieces) {
    $lignes[] = '';
    $lignes[] = 'Pièces jointes : ' . count($pieces);
    $html .= '<p style="font-family:Arial,sans-serif;font-size:14px;">Pièces jointes : ' . count($pieces) . '</p>';
}
$date = date('d/m/Y à H:i');
$origine = (string)($_POST['origine'] ?? '');
$pied = "Envoyé depuis le site web de la CDMP le {$date}" . ($origine !== '' ? " (page : {$origine})" : '') . '.';

$texte = $def['titre'] . "\n\n" . implode("\n", $lignes) . "\n\n" . $pied;
$htmlComplet = '<h2 style="font-family:Arial,sans-serif;color:#123a99;">' . htmlspecialchars($def['titre']) . '</h2>'
    . $html . '<p style="font-family:Arial,sans-serif;font-size:12px;color:#6b7280;">' . htmlspecialchars($pied) . '</p>';

$sujet = '[Site CDMP] ' . $def['titre'];
$nomDemandeur = $valeurs['nom_complet'] ?? trim(($valeurs['prenom'] ?? '') . ' ' . ($valeurs['nom'] ?? ''));
if (!empty($valeurs['raison_sociale'])) {
    $sujet .= ' — ' . $valeurs['raison_sociale'];
} elseif (!empty($valeurs['organisation'])) {
    $sujet .= ' — ' . $valeurs['organisation'];
} elseif ($nomDemandeur !== '') {
    $sujet .= ' — ' . $nomDemandeur;
}

$mailer = new Mailer($config);
try {
    $mailer->send(
        $config['recipients'][$type],
        $sujet,
        $texte,
        $htmlComplet,
        $pieces,
        $emailDemandeur !== '' ? $emailDemandeur : null
    );
    journaliser($config, "OK {$type} -> " . implode(',', $config['recipients'][$type]));
} catch (Throwable $e) {
    journaliser($config, "ERREUR {$type} : " . $e->getMessage());
    repondre(false, "Votre demande n'a pas pu être envoyée en raison d'un incident technique. Merci de réessayer ou de nous écrire à contact@cdmp.sn.", 500);
}

// Accusé de réception au demandeur (un échec ici ne bloque pas la demande).
if ($config['send_acknowledgement'] && $emailDemandeur !== '' && $type !== 'newsletter') {
    $estPk = $type === 'paykapital';
    $ackTexte = $estPk
        ? "Bonjour,\n\nMerci pour votre intérêt. Notre équipe vous recontactera très prochainement.\n\nL'équipe PayKapital — CDMP"
        : "Bonjour,\n\nNous avons bien reçu votre demande. Nos équipes l'analysent et reviendront vers vous dans les meilleurs délais.\n\nCaisse des Marchés Publics (CDMP)\nAlmadies Zone 12, TF N°10820 NGA, Dakar — Sénégal\n+221 33 824 20 38";
    try {
        $mailer->send(
            [$emailDemandeur],
            $estPk ? 'PayKapital — Nous avons bien reçu votre demande' : 'CDMP — Nous avons bien reçu votre demande',
            $ackTexte,
            '<p style="font-family:Arial,sans-serif;font-size:14px;">' . nl2br(htmlspecialchars($ackTexte)) . '</p>'
        );
    } catch (Throwable $e) {
        journaliser($config, "ERREUR accusé {$type} : " . $e->getMessage());
    }
}

$confirmation = match ($type) {
    'paykapital' => 'Merci pour votre intérêt. Notre équipe vous recontactera très prochainement.',
    'newsletter' => 'Merci, votre inscription a bien été enregistrée.',
    'financement' => 'Merci, votre demande de financement a bien été transmise. Nos équipes vous répondront dans les meilleurs délais.',
    default => 'Merci, votre message a bien été envoyé. Nous vous répondrons dans les meilleurs délais.',
};
repondre(true, $confirmation);

// ---------- Fonctions utilitaires ----------

/** Ramène $_FILES[x] (simple ou multiple) à une liste de fichiers. */
function normaliserFichiers(?array $entree): array
{
    if (!$entree || !isset($entree['name'])) {
        return [];
    }
    if (!is_array($entree['name'])) {
        return [$entree];
    }
    $liste = [];
    foreach ($entree['name'] as $i => $nom) {
        $liste[] = [
            'name' => $nom,
            'tmp_name' => $entree['tmp_name'][$i],
            'size' => $entree['size'][$i],
            'error' => $entree['error'][$i],
        ];
    }
    return $liste;
}

function journaliser(array $config, string $ligne): void
{
    if (empty($config['log_file'])) {
        return;
    }
    $dir = dirname($config['log_file']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($config['log_file'], '[' . date('Y-m-d H:i:s') . '] ' . $ligne . "\n", FILE_APPEND);
}
