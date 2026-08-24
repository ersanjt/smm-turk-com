-- Rebrand service categories: SMM Turk One / SMM Turk Pro
UPDATE services SET category = CONCAT('SMM Turk One — ', category)
WHERE provider = 'smmfollows'
  AND category NOT LIKE 'SMM Turk One%'
  AND category NOT LIKE 'SMM Turk Pro%'
  AND category NOT LIKE 'SMMFA%';

UPDATE services SET category = REPLACE(category, 'SMMFA — ', 'SMM Turk Pro — ')
WHERE provider = 'smmfa' AND category LIKE 'SMMFA%';

UPDATE services SET category = CONCAT('SMM Turk Pro — ', category)
WHERE provider = 'smmfa'
  AND category NOT LIKE 'SMM Turk Pro%'
  AND category NOT LIKE 'SMM Turk One%';
