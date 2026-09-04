<?php

declare(strict_types=1);

namespace App\Application\Reports;

use PDO;
use Throwable;

final class ReportManager
{
    public function __construct(private PDO $pdo, private string $storageDirectory) {}

    public function generate(array $filters, bool $regenerate = false): array
    {
        $date = (string)($filters['date_debut'] ?? date('Y-m-d'));
        $normalized = array_filter([
            'client_id' => $filters['client_id'] ?? null, 'chantier_id' => $filters['chantier_id'] ?? null,
            'chauffeur_id' => $filters['chauffeur_id'] ?? null, 'commande_id' => $filters['commande_id'] ?? null,
        ], static fn($value) => $value !== null && $value !== '');
        ksort($normalized);
        $hash = hash('sha256', json_encode($normalized));

        $find = $this->pdo->prepare("SELECT * FROM rapports WHERE type='journalier' AND date_rapport=? AND filter_hash=?");
        $find->execute([$date, $hash]); $existing = $find->fetch();
        if ($existing && !$regenerate && in_array($existing['statut'], ['genere','regenere'], true)) return $existing;

        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO rapports(type,date_rapport,date_debut,date_fin,filtres_json,filter_hash,statut)
                    VALUES('journalier',?,?,?,?,?,'en_cours')
                    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),statut='en_cours',message_erreur=NULL,updated_at=NOW()";
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$date, $date.' 00:00:00', (string)($filters['date_fin'] ?? $date).' 23:59:59', json_encode($normalized), $hash]);
            $id = (int)$this->pdo->lastInsertId(); $this->pdo->commit();
        } catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }

        try {
            $report = (new ReportService($this->pdo))->build($filters);
            $basename = 'rapport-' . $date . '-' . substr($hash, 0, 10);
            $paths = (new ReportExporter())->export($report, $this->storageDirectory, $basename);
            $i = $report['indicateurs'];
            $update = $this->pdo->prepare("UPDATE rapports SET statut=?,nombre_commandes=?,nombre_livraisons=?,nombre_clients=?,quantite_commandee=?,quantite_livree=?,quantite_restante=?,pdf_path=?,excel_path=?,generated_at=NOW() WHERE id=?");
            $update->execute([$regenerate?'regenere':'genere',$i['nombre_commandes'],$i['nombre_livraisons'],$i['nombre_clients'],$i['quantite_commandee'],$i['quantite_livree'],$i['quantite_restante'],$paths['pdf'],$paths['excel'],$id]);
        } catch (Throwable $error) {
            $failed = $this->pdo->prepare("UPDATE rapports SET statut='erreur',message_erreur=? WHERE id=?"); $failed->execute([$error->getMessage(),$id]); throw $error;
        }
        $find = $this->pdo->prepare('SELECT * FROM rapports WHERE id=?'); $find->execute([$id]); return $find->fetch();
    }
}
