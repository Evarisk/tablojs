-- ============================================================================
-- tablojs — Table: tablojs_unit
-- Dictionnaire des unités de vente/achat (m, kg, pièce, litre…)
-- ============================================================================

CREATE TABLE tablojs_unit (
  rowid       INT          AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(16)  NOT NULL UNIQUE COMMENT 'Code court (ex: KG, M, PCE, L, T)',
  label       VARCHAR(64)  NOT NULL         COMMENT 'Libellé (ex: Kilogramme, Mètre, Pièce)',
  label_short VARCHAR(8)   NOT NULL         COMMENT 'Symbole (ex: kg, m, pce)',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  rang        INT          NOT NULL DEFAULT 0 COMMENT 'Ordre d affichage'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unités de mesure / vente (dictionnaire)';
