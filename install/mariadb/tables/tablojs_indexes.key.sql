-- ============================================================================
-- tablojs — Index: tablojs_settings
-- ============================================================================
-- (UNIQUE KEY sur setting_key déjà défini dans la table)


-- ============================================================================
-- tablojs — Index: tablojs_auth_log
-- ============================================================================
ALTER TABLE tablojs_auth_log ADD INDEX idx_auth_log_ts    (ts);
ALTER TABLE tablojs_auth_log ADD INDEX idx_auth_log_event (event);
ALTER TABLE tablojs_auth_log ADD INDEX idx_auth_log_ip    (ip);


-- ============================================================================
-- tablojs — Index: tablojs_dataset
-- ============================================================================
ALTER TABLE tablojs_dataset ADD INDEX idx_dataset_active      (is_active);
ALTER TABLE tablojs_dataset ADD INDEX idx_dataset_generated   (generated_at);


-- ============================================================================
-- tablojs — Index: tablojs_product
-- ============================================================================
ALTER TABLE tablojs_product ADD INDEX idx_product_category  (fk_category);
ALTER TABLE tablojs_product ADD INDEX idx_product_transport (is_transport);
ALTER TABLE tablojs_product ADD FULLTEXT INDEX ft_product_des (des);


-- ============================================================================
-- tablojs — Index: tablojs_product_stat
-- ============================================================================
ALTER TABLE tablojs_product_stat ADD INDEX idx_stat_abc          (fk_dataset, abc);
ALTER TABLE tablojs_product_stat ADD INDEX idx_stat_vente_mt     (fk_dataset, vente_montant);
ALTER TABLE tablojs_product_stat ADD INDEX idx_stat_vente_nb     (fk_dataset, vente_nb);
ALTER TABLE tablojs_product_stat ADD INDEX idx_stat_achat_mt     (fk_dataset, achat_montant);
ALTER TABLE tablojs_product_stat ADD INDEX idx_stat_achat_nb     (fk_dataset, achat_nb);


-- ============================================================================
-- tablojs — Index: tablojs_monthly_vente
-- ============================================================================
ALTER TABLE tablojs_monthly_vente ADD INDEX idx_mv_mois (fk_dataset, mois);


-- ============================================================================
-- tablojs — Index: tablojs_monthly_vente_global
-- ============================================================================
ALTER TABLE tablojs_monthly_vente_global ADD INDEX idx_mvg_mois (fk_dataset, mois);
