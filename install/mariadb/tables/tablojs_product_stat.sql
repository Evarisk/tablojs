-- ============================================================================
-- tablojs — Table: tablojs_product_stat
-- Statistiques de vente/achat/stock PAR DATASET pour chaque produit
-- Séparé de tablojs_product pour ne pas mélanger fiche et mouvements
-- ============================================================================

CREATE TABLE tablojs_product_stat (
  rowid          INT            AUTO_INCREMENT PRIMARY KEY,
  fk_dataset     INT            NOT NULL                       COMMENT 'FK → tablojs_dataset.rowid',
  fk_product     INT            NOT NULL                       COMMENT 'FK → tablojs_product.rowid',

  -- Analyse ABC
  abc            CHAR(1)        DEFAULT 'C'                    COMMENT 'Classe ABC : A (80%), B (95%), C (reste), T (transport)',

  -- Stock
  stock_qty      DECIMAL(18,4)  NOT NULL DEFAULT 0             COMMENT 'Quantité en stock au moment du snapshot',
  mois_stock     DECIMAL(8,2)   DEFAULT NULL                   COMMENT 'Mois de stock restants (stock_qty / cadence mensuelle)',

  -- Ventes sur la période du dataset
  vente_montant  DECIMAL(18,2)  NOT NULL DEFAULT 0             COMMENT 'CA ventes (EUR)',
  vente_nb       INT            NOT NULL DEFAULT 0             COMMENT 'Nombre de lignes de vente',

  -- Achats sur la période du dataset
  achat_montant  DECIMAL(18,2)  NOT NULL DEFAULT 0             COMMENT 'CA achats (EUR)',
  achat_nb       INT            NOT NULL DEFAULT 0             COMMENT 'Nombre de lignes d achat',

  -- Modèle de Wilson
  wilson         JSON           DEFAULT NULL                   COMMENT 'Résultat Wilson : {D_annuel, pu_estime, Q_star, nb_commandes_an, stock_securite}',

  UNIQUE KEY uk_stat_dataset_product (fk_dataset, fk_product),
  CONSTRAINT fk_stat_dataset FOREIGN KEY (fk_dataset) REFERENCES tablojs_dataset(rowid) ON DELETE CASCADE,
  CONSTRAINT fk_stat_product FOREIGN KEY (fk_product) REFERENCES tablojs_product(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Statistiques ventes/achats/stock par dataset et par produit';
