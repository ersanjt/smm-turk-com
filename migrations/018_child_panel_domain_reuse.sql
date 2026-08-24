-- Allow re-ordering a domain after cancellation (drop unique domain constraint)
ALTER TABLE child_panels DROP INDEX uk_child_panels_domain;
ALTER TABLE child_panels ADD INDEX idx_child_panels_domain (domain);
