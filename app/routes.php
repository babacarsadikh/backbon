<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        // CORS Pre-Flight OPTIONS Request Handler
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('🚀 API Districobon fonctionne');
        return $response;
    });

    // Routes pour les clients
    $app->group('/clients', function (Group $group) {
        // Inclure les routes des clients
        $clientRoutes = require __DIR__ . '/routes/clientroute.php';
        $clientRoutes($group);
    });

    // Routes pour les chauffeurs
    $app->group('/chauffeurs', function (Group $group) {
        // Inclure les routes des chauffeurs
        $chauffeurRoutes = require __DIR__ . '/routes/chauffeurroute.php';
        $chauffeurRoutes($group);
    });
    // Routes pour les chantiers
    $app->group('/chantiers', function (Group $group) {
        $chantierRoutes = require __DIR__ . '/routes/chantierroute.php';
        $chantierRoutes($group);
    });

    // Routes pour les camions
    $app->group('/camions', function (Group $group) {
        // Inclure les routes des camions
        $camionRoutes = require __DIR__ . '/routes/camionroute.php';
        $camionRoutes($group);
    });

    // Routes pour les commandes
    $app->group('/commandes', function (Group $group) {
        // Inclure les routes des commandes
        $commandeRoutes = require __DIR__ . '/routes/commanderoute.php';
        $commandeRoutes($group);
    });

    // Routes pour les bons de livraison
    $app->group('/livraisons', function (Group $group) {
        // Inclure les routes des livraisons
        $livraisonRoutes = require __DIR__ . '/routes/livraisonroute.php';
        $livraisonRoutes($group);
    });

    // // Routes pour les opérateurs
    // $app->group('/operateurs', function (Group $group) {
    //     // Inclure les routes des opérateurs
    //     $operateurRoutes = require __DIR__ . '/routes/operateurroute.php';
    //     $operateurRoutes($group);
    // });

    // Routes pour les formules de béton
    $app->group('/formules', function (Group $group) {
        // Inclure les routes des formules
        $formuleRoutes = require __DIR__ . '/routes/formuleroute.php';
        $formuleRoutes($group);
    });
      // Routes d'authentification
    $app->group('/auth', function (Group $group) {
        $authRoutes = require __DIR__ . '/routes/authroute.php';
        $authRoutes($group);
    });

};