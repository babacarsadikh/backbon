<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire tous les camions
    $group->get('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT c.*, ch.nom as chauffeur_nom, ch.prenom as chauffeur_prenom
            FROM camions c
            LEFT JOIN chauffeurs ch ON c.id = ch.camion_id
            ORDER BY c.plaque_immatriculation
        ");
        $camions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($camions));
        return $response->withHeader('Content-Type', 'application/json');
    });
 // Camions disponibles
    $group->get('/disponibles', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT c.* 
            FROM camions c
            WHERE c.etat = 'disponible' 
            AND c.id NOT IN (SELECT camion_id FROM chauffeurs WHERE camion_id IS NOT NULL)
            ORDER BY c.plaque_immatriculation
        ");
        $camions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($camions));
        return $response->withHeader('Content-Type', 'application/json');
    });
    // Lire un camion spécifique
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT c.*, ch.nom as chauffeur_nom, ch.prenom as chauffeur_prenom, ch.telephone as chauffeur_telephone
            FROM camions c
            LEFT JOIN chauffeurs ch ON c.id = ch.camion_id
            WHERE c.id = ?
        ");
        $stmt->execute([$args['id']]);
        $camion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$camion) {
            $response->getBody()->write(json_encode(['error' => 'Camion non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($camion));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ajouter un camion
    $group->post('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation des champs obligatoires
        if (empty($params['plaque_immatriculation']) ) {
            $response->getBody()->write(json_encode(['error' => 'Plaque d\'immatriculation  obligatoires']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO camions (plaque_immatriculation, modele, capacite, date_mise_en_service, etat) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $params['plaque_immatriculation'],
                $params['modele'] ?? null,
                $params['capacite'],
                $params['date_mise_en_service'] ?? null,
                $params['etat'] ?? 'disponible'
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Camion ajouté avec succès',
                'id' => $pdo->lastInsertId()
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            // Vérifier si c'est une violation de contrainte unique (plaque)
            if ($e->getCode() == 23000) {
                $response->getBody()->write(json_encode(['error' => 'Un camion avec cette plaque existe déjà']));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de l\'ajout du camion: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Modifier un camion
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si le camion existe
        $stmt = $pdo->prepare("SELECT id FROM camions WHERE id = ?");
        $stmt->execute([$args['id']]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Camion non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE camions 
                SET plaque_immatriculation = ?, marque = ?, modele = ?, capacite = ?, 
                    date_mise_en_service = ?, etat = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['plaque_immatriculation'] ?? '',
                $params['marque'] ?? null,
                $params['modele'] ?? null,
                $params['capacite'] ?? 0,
                $params['date_mise_en_service'] ?? null,
                $params['etat'] ?? 'disponible',
                $args['id']
            ]);

            $response->getBody()->write(json_encode(['message' => 'Camion mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            // Vérifier si c'est une violation de contrainte unique (plaque)
            if ($e->getCode() == 23000) {
                $response->getBody()->write(json_encode(['error' => 'Un camion avec cette plaque existe déjà']));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Supprimer un camion
    $group->delete('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);

        try {
            // Vérifier si le camion a des chauffeurs assignés
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM chauffeurs WHERE camion_id = ?");
            $stmt->execute([$args['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Impossible de supprimer ce camion car il est assigné à un chauffeur'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Vérifier si le camion a des livraisons
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM bons_livraison WHERE camion_id = ?");
            $stmt->execute([$args['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Impossible de supprimer ce camion car il a des livraisons associées'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Supprimer le camion
            $stmt = $pdo->prepare("DELETE FROM camions WHERE id = ?");
            $stmt->execute([$args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Camion non trouvé']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Camion supprimé avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la suppression: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des camions
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT c.*, ch.nom as chauffeur_nom, ch.prenom as chauffeur_prenom
            FROM camions c
            LEFT JOIN chauffeurs ch ON c.id = ch.camion_id
            WHERE c.plaque_immatriculation LIKE ? OR c.marque LIKE ? OR c.modele LIKE ?
            ORDER BY c.plaque_immatriculation
        ");
        $stmt->execute([$term, $term, $term]);
        $camions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($camions));
        return $response->withHeader('Content-Type', 'application/json');
    });

   

    // Changer l'état d'un camion
    $group->patch('/{id}/etat', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        if (empty($params['etat'])) {
            $response->getBody()->write(json_encode(['error' => 'Le nouvel état est obligatoire']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $etatsValides = ['disponible', 'en_livraison', 'en_maintenance'];
        if (!in_array($params['etat'], $etatsValides)) {
            $response->getBody()->write(json_encode(['error' => 'État invalide']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("UPDATE camions SET etat = ? WHERE id = ?");
            $stmt->execute([$params['etat'], $args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Camion non trouvé']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'État du camion mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });
};