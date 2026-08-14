USE nexus_traveltech;

ALTER TABLE suppliers MODIFY supplier_type ENUM(
  'hotel','villa','yacht','tour','activity','cruise','car_rental','transfer',
  'ferry','restaurant','cinema','beach','event'
) NOT NULL DEFAULT 'hotel';

ALTER TABLE properties MODIFY property_type ENUM(
  'hotel','villa','yacht','tour','activity','cruise','car_rental','transfer',
  'ferry','restaurant','cinema','beach','event'
) NOT NULL;
