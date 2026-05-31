-- ============================================================================
-- tablojs — Table: tablojs_product
-- Catalogue produits (séparé des données de mouvement)
-- Normalisé : ref unique par produit, catégorie et unité en FK
-- ============================================================================

CREATE TABLE tablojs_product (
  rowid         INT          AUTO_INCREMENT PRIMARY KEY,
  ref           VARCHAR(64)  NOT NULL UNIQUE               COMMENT 'Référence article (ex: SPEXT1)',
  des           VARCHAR(255) NOT NULL DEFAULT ''           COMMENT 'Désignation complète',
  fk_category   INT          DEFAULT NULL                  COMMENT 'FK → tablojs_product_category.rowid',
  fk_unit       INT          DEFAULT NULL                  COMMENT 'FK → tablojs_unit.rowid (unité de vente)',
  is_transport  TINYINT(1)   NOT NULL DEFAULT 0            COMMENT '1 = ligne transport (exclue des analyses)',
  note          TEXT         DEFAULT NULL                  COMMENT 'Notes libres sur le produit',
  datec         DATETIME     DEFAULT CURRENT_TIMESTAMP     COMMENT 'Date de création de la fiche',
  tms           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalogue produits (fiche produit normalisée)';

ALTER TABLE tablojs_product
  ADD CONSTRAINT fk_product_category FOREIGN KEY (fk_category) REFERENCES tablojs_product_category(rowid) ON DELETE SET NULL,
  ADD CONSTRAINT fk_product_unit     FOREIGN KEY (fk_unit)     REFERENCES tablojs_unit(rowid)             ON DELETE SET NULL;
