
-- --------------------------------------------------------
-- Migration: photo clarity notes
--
-- WHAT
--   While an applicant adds the photos of their requirements, their own phone or computer checks each
--   photo (js/photo-quality.js): is it blurry, too dark, washed out by glare, or too small to read?
--   The applicant is warned and may retake it or choose "Use anyway". The result is saved with each photo,
--   so the office can see which photos were warned about (the side-by-side review page and the record page).
--
--   applicant_documents.quality_flags    NULL  not checked (photos sent before this, or the browser could not look at it)
--                                        'ok'  nothing wrong found
--                                        'blurry', 'dark', 'glare', 'small' (comma separated): what the applicant was
--                                              warned about and sent anyway
--   applicant_documents.quality_metrics  the numbers behind it (s sharpness, b brightness, g glare %, l long side px,
--                                        t squares holding detail), e.g. 's=0.02,b=98,g=0,l=2000,t=900'.
--                                        Used to tune the limits in js/photo-quality.js on real photos.
--
-- It is only a hint for the office. Nothing is approved or refused because of it.
-- Existing rows are not changed: photos sent before this have NULL (shown as "not checked", i.e. nothing).
--
-- HOW TO RUN
--   Not required: includes/photo_quality.php (photo_quality_ensure()) adds the columns the first time an
--   application is submitted. To add them by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE `applicant_documents`
  ADD COLUMN IF NOT EXISTS `quality_flags` varchar(40) DEFAULT NULL COMMENT 'Clarity check on the applicant''s device: NULL not checked, ok, or what they were warned about',
  ADD COLUMN IF NOT EXISTS `quality_metrics` varchar(80) DEFAULT NULL COMMENT 'The numbers behind the clarity check, for tuning the limits';
