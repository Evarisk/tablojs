-- ============================================================================
-- tablojs — Table: tablojs_monthly_vente
-- Ventes mensuelles agrégées par produit et par dataset
-- Remplace: le champ monthly_ventes (JSON) dans produits.json
-- ============================================================================

CREATE TABLE tablojs_monthly_vente (
  rowid         BIGINT         AUTO_INCREMENT PRIMARY KEY,
  fk_dataset    INT            NOT NULL              COMMENT 'FK → tablojs_dataset.rowid',
  fk_product    INT            NOT NULL              COMMENT 'FK → tablojs_product.rowid',
  mois          CHAR(7)        NOT NULL              COMMENT 'Période YYYY-MM (ex: 2025-06)',
  montant       DECIMAL(18,2)  NOT NULL DEFAULT 0    COMMENT 'CA ventes du mois pour ce produit (EUR)',

  UNIQUE KEY uk_monthly (fk_dataset, fk_product, mois),
  CONSTRAINT fk_mv_dataset FOREIGN KEY (fk_dataset) REFERENCES tablojs_dataset(rowid) ON DELETE CASCADE,
  CONSTRAINT fk_mv_product FOREIGN KEY (fk_product) REFERENCES tablojs_product(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ventes mensuelles par produit et par dataset';


-- ============================================================================
-- tablojs — Table: tablojs_monthly_vente_global
-- Ventes mensuelles totales (toutes références confondues) par dataset
-- Remplace: monthly_ventes.json
-- ============================================================================

CREATE TABLE tablojs_monthly_vente_global (
  rowid         INT            AUTO_INCREMENT PRIMARY KEY,
  fk_dataset    INT            NOT NULL              COMMENT 'FK → tablojs_dataset.rowid',
  mois          CHAR(7)        NOT NULL              COMMENT 'Période YYYY-MM',
  montant       DECIMAL(18,2)  NOT NULL DEFAULT 0    COMMENT 'CA ventes total du mois (EUR)',

  UNIQUE KEY uk_mvg (fk_dataset, mois),
  CONSTRAINT fk_mvg_dataset FOREIGN KEY (fk_dataset) REFERENCES tablojs_dataset(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ventes mensuelles globales par dataset';
