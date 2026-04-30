<?php
require_once __DIR__ . '/../../session_utils.php';

$user = requireSessionUser('Form/demandeur/dem.php');

function redirectToMap($latitude, $longitude)
{
    // Cette page ne cree plus de demande en base.
    // Elle sert uniquement a envoyer le demandeur vers la carte avec sa position.
    $query = http_build_query([
        'user_lat' => $latitude,
        'user_lng' => $longitude,
    ]);

    header('Location: ' . appUrl('Form/demandeur/carte.php?' . $query));
    exit;
}

$latitude = trim($user['latitude'] ?? '');
$longitude = trim($user['longitude'] ?? '');

redirectToMap($latitude, $longitude);
