<?php
// Si le serveur web charge index.php par défaut, on sert le fichier principal index.html
if (file_exists(__DIR__ . '/index.html')) {
    readfile(__DIR__ . '/index.html');
    exit;
}
