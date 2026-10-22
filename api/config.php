<?php
/**
 * Configuration des circuits de réception des formulaires du site CDMP.
 *
 * ⚠ À COMPLÉTER avec les adresses e-mail définitives transmises par la CDMP.
 * Tant qu'une adresse n'est pas confirmée, les messages partent vers
 * l'adresse générale contact@cdmp.sn.
 *
 * Plusieurs destinataires possibles : ['a@cdmp.sn', 'b@cdmp.sn'].
 */
return [

    // Destinataires par formulaire
    'recipients' => [
        'contact'     => ['contact@cdmp.sn'],   // Formulaire « Contactez-nous »
        'financement' => ['contact@cdmp.sn'],   // Formulaire « Demande de financement »
        'paykapital'  => ['contact@cdmp.sn'],   // Formulaire PayKapital (adresse à confirmer)
        'newsletter'  => ['contact@cdmp.sn'],   // Inscriptions newsletter (pied de page)
    ],

    // Expéditeur technique. Doit appartenir au domaine du serveur d'envoi
    // (sinon les messages risquent d'être classés en spam).
    'from_email' => 'no-reply@cdmp.sn',
    'from_name'  => 'Site web CDMP',

    // Mode d'envoi : 'smtp' (recommandé) ou 'mail' (fonction mail() de PHP,
    // nécessite un serveur de messagerie local configuré sur l'hébergement).
    'transport' => 'smtp',

    'smtp' => [
        'host'       => 'smtp.example.com', // ⚠ à renseigner
        'port'       => 587,                // 587 = STARTTLS, 465 = SSL
        'encryption' => 'tls',              // 'tls', 'ssl' ou '' (aucun)
        'username'   => '',                 // ⚠ à renseigner
        'password'   => '',                 // ⚠ à renseigner
        'timeout'    => 20,
    ],

    // Envoi d'un accusé de réception automatique au demandeur
    'send_acknowledgement' => true,

    // Pièces jointes : taille maximale par fichier et au total (en octets)
    'max_file_size'  => 10 * 1024 * 1024,
    'max_total_size' => 25 * 1024 * 1024,
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],

    // Journal des envois (diagnostic). Laisser '' pour désactiver.
    'log_file' => __DIR__ . '/logs/envois.log',
];
