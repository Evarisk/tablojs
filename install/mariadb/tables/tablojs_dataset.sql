-- ============================================================================
-- tablojs — Table: tablojs_dataset
-- Catalogue des snapshots horodatés (imports Excel)
-- Remplace: data/{slug}/_meta.json + data/active.txt
-- ============================================================================

CREATE TABLE tablojs_dataset (
  rowid        INT          AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Identifiant unique du snapshot (ex: 20260523-224300-dataset)',
  label        VARCHAR(128) NOT NULL         COMMENT 'Libellé humain (ex: Export Mai 2026)',
  generated_at DATETIME     NOT NULL         COMMENT 'Date/heure de génération',
  generated_by VARCHAR(64)  NOT NULL DEFAULT 'generate_data.php' COMMENT 'Script générateur et version',
  is_active    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = dataset affiché dans l application',
  sources      JSON         DEFAULT NULL     COMMENT 'Noms des fichiers Excel sources utilisés',
  stats        JSON         DEFAULT NULL     COMMENT 'KPIs globaux du dataset (ca_ventes, nb_refs, etc.)',
  log_output   TEXT         DEFAULT NULL     COMMENT 'Log de génération (sortie console)',
  tms          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Snapshots horodatés issus des imports Excel';
