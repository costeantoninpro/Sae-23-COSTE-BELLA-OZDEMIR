<?php
$to = "yt.gamereduction@gmail.com﻿";
$sujet = "Demande de collaboration pour un projet de développement web";
$message = "Bonjour, ceci est une demande de collaboration pour un projet de développement web. Pouvais vous me contacter pour discuter des détails du projet ? Merci.";
$headers = "From: yt.gamereduction@gmail.com";

if (mail($to, $sujet, $message, $headers)) {
    echo "Mail envoyé";
} else {
    echo "Erreur lors de l'envoi";
}
?>


// aminebella30@gmail.com  
// yt.gamereduction@gmail.com
// antonin.coste@edu.univ-fcomte.fr﻿