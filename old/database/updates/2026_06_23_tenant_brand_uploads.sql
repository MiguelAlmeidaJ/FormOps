-- FormOps: campos de upload da identidade visual por tenant.

ALTER TABLE tenants ADD COLUMN logo_path VARCHAR(255) NULL AFTER logo;
ALTER TABLE tenants ADD COLUMN cover_image_path VARCHAR(255) NULL AFTER logo_path;
