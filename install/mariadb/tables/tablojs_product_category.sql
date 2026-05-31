-- ============================================================================
-- tablojs — Table: tablojs_product_category
-- Dictionnaire des catégories de produits (ex: Ferraille, Pièces détachées…)
-- Extensible par l utilisateur
-- ============================================================================

CREATE TABLE tablojs_product_category (
  rowid     INT          AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(32)  NOT NULL UNIQUE COMMENT 'Code court (ex: FERR, PIECE, TRANSPORT)',
  label     VARCHAR(128) NOT NULL         COMMENT 'Libellé affiché (ex: Ferraille)',
  color     VARCHAR(7)   DEFAULT NULL     COMMENT 'Couleur hexadécimale pour l UI (ex: #ef4444)',
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  rang      INT          NOT NULL DEFAULT 0 COMMENT 'Ordre d affichage',
  tms       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catégories de produits (dictionnaire)';
