UPDATE buildings 
SET owner_id = 1 
WHERE country_code = 'US' 
  AND (type = 'hospital' OR name LIKE '%kórház%' OR name LIKE '%Hospital%');
