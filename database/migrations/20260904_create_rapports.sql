CREATE TABLE IF NOT EXISTS rapports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL DEFAULT 'journalier',
    date_rapport DATE NOT NULL,
    date_debut DATETIME NOT NULL,
    date_fin DATETIME NOT NULL,
    filtres_json LONGTEXT NULL,
    filter_hash CHAR(64) NOT NULL,
    statut ENUM('en_cours','genere','erreur','regenere') NOT NULL DEFAULT 'en_cours',
    nombre_commandes INT UNSIGNED NOT NULL DEFAULT 0,
    nombre_livraisons INT UNSIGNED NOT NULL DEFAULT 0,
    nombre_clients INT UNSIGNED NOT NULL DEFAULT 0,
    quantite_commandee DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantite_livree DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantite_restante DECIMAL(12,2) NOT NULL DEFAULT 0,
    pdf_path VARCHAR(500) NULL,
    excel_path VARCHAR(500) NULL,
    message_erreur TEXT NULL,
    generated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rapport_identity (type, date_rapport, filter_hash),
    KEY idx_rapport_period (date_debut, date_fin),
    KEY idx_rapport_status (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_bl_report_date_status ON bons_livraison (date_production, statut);
