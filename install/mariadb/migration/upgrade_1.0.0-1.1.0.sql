-- ============================================================================
-- tablojs — Script de migration : 1.0.0 → 1.1.0
-- Placeholder pour futures évolutions du modèle de données.
--
-- Convention de nommage : upgrade_ANCIENNE_VERSION-NOUVELLE_VERSION.sql
-- Toujours utiliser ALTER TABLE ... ADD COLUMN IF NOT EXISTS (MariaDB 10.2+)
-- pour garantir l idempotence.
--
-- Exemple d ajout de colonne future :
-- ALTER TABLE tablojs_product ADD COLUMN IF NOT EXISTS barcode VARCHAR(64) DEFAULT NULL AFTER des;
-- ============================================================================

-- (Pas de migration pour la version initiale 1.0.0)
SELECT 'Migration 1.0.0 -> 1.1.0 : aucune modification requise' AS info;
