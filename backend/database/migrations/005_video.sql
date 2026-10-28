-- Video Studio: tracce musicali nella libreria e video MP4 collegato al post.

ALTER TABLE assets
  MODIFY COLUMN kind ENUM('image','video','document','audio') NOT NULL;

ALTER TABLE content_items
  ADD COLUMN video_asset_id BIGINT UNSIGNED NULL AFTER visual,
  ADD CONSTRAINT fk_content_video FOREIGN KEY (video_asset_id) REFERENCES assets(id) ON DELETE SET NULL;
